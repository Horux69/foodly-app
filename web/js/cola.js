// Cola de pedidos tomados sin red.
//
// Cuando el POST de un pedido no llega al servidor, en vez de perderlo se
// guarda aquí y se reintenta solo. Esto es seguro **únicamente** porque el
// cuerpo ya lleva su `idempotency_key` (F0.2): reenviar sin esa llave sería
// duplicar pedidos cada vez que la respuesta se pierde de vuelta —el caso
// más común de todos, porque la petición sí llegó.
//
// Se guarda en localStorage y no en memoria a propósito: quien se queda sin
// red también cierra la aplicación, y un pedido que solo vivía en una
// variable se pierde con la pestaña.

import { api, ApiError } from './api.js';

const COLA_KEY = 'resto_cola_pedidos';
const RECHAZOS_KEY = 'resto_cola_rechazos';

// El evento `online` dice que hay enlace, no que haya internet: el wifi del
// local puede estar arriba y el módem abajo. Por eso, además del evento, se
// reintenta cada tanto mientras quede algo.
const REINTENTO_MS = 30_000;

let reintento = null;
let alVolverLaRed = null;
let enviando = false;
const oyentes = new Set();

function leer(clave) {
  try {
    const crudo = localStorage.getItem(clave);
    const valor = crudo ? JSON.parse(crudo) : [];
    return Array.isArray(valor) ? valor : [];
  } catch {
    // Modo privado, almacenamiento lleno o un JSON corrupto: mejor una cola
    // vacía que una pantalla que no arranca.
    return [];
  }
}

function escribir(clave, valor) {
  try {
    localStorage.setItem(clave, JSON.stringify(valor));
  } catch {
    // Sin dónde guardar, la cola vale para esta sesión.
  }
}

/** Lo que espera para salir, en el orden en que se tomó. */
export function pendientes() {
  return leer(COLA_KEY);
}

/**
 * Lo que el servidor rechazó de plano y ya no se va a reintentar. Se guarda
 * porque un pedido perdido en silencio es peor que uno rechazado: alguien
 * tiene que enterarse y volver a tomarlo.
 */
export function rechazados() {
  return leer(RECHAZOS_KEY);
}

export function olvidarRechazos() {
  escribir(RECHAZOS_KEY, []);
  avisar();
}

/** Para que la interfaz repinte el contador sin preguntar cada segundo. */
export function suscribir(fn) {
  oyentes.add(fn);
  return () => oyentes.delete(fn);
}

function avisar() {
  for (const fn of oyentes) fn();
}

/**
 * Guarda un intento que no salió.
 *
 * `url` y `cuerpo` se congelan tal cual: la url ya lleva el `branch_id` de
 * la sucursal en que se tomó, así que la cola no cambia de sede si quien la
 * vacía está mirando otra.
 */
export function encolar(url, cuerpo, descripcion) {
  const cola = pendientes();
  cola.push({ id: cuerpo.idempotency_key, url, cuerpo, descripcion, creado: new Date().toISOString() });
  escribir(COLA_KEY, cola);
  avisar();
  programarReintento();
  return cola.length;
}

function quitar(id) {
  escribir(
    COLA_KEY,
    pendientes().filter((e) => e.id !== id)
  );
}

function rechazar(entrada, motivo) {
  quitar(entrada.id);
  escribir(RECHAZOS_KEY, [...rechazados(), { ...entrada, motivo }]);
}

/**
 * Intenta vaciar la cola, en orden.
 *
 * Tres desenlaces por entrada, y la diferencia importa:
 *
 * - Sale: se borra. Si el servidor ya la tenía de un intento anterior,
 *   reconoce la llave y devuelve el mismo pedido en vez de crear otro.
 * - No hay red (`status` 0) o la sesión venció (401): se para y se deja
 *   todo como está. No es culpa del pedido.
 * - El servidor la rechazó (4xx): se descarta y se anota. Un pedido con un
 *   producto ya archivado o una sucursal cerrada nunca va a entrar, y
 *   dejarlo al frente taparía a los que sí pueden. Un 5xx, en cambio, se
 *   conserva: el servidor puede estar de vuelta en un minuto.
 */
export async function reenviar() {
  const resultado = { enviados: [], descartados: [] };
  if (enviando) return resultado;

  enviando = true;
  try {
    for (const entrada of pendientes()) {
      try {
        await api.post(entrada.url, entrada.cuerpo);
        quitar(entrada.id);
        resultado.enviados.push(entrada);
      } catch (error) {
        const status = error instanceof ApiError ? error.status : 0;
        if (status === 0 || status === 401 || status >= 500) break;
        rechazar(entrada, error.message);
        resultado.descartados.push({ ...entrada, motivo: error.message });
      }
    }
  } finally {
    enviando = false;
  }

  if (resultado.enviados.length || resultado.descartados.length) avisar();
  programarReintento();
  return resultado;
}

function programarReintento() {
  const hay = pendientes().length > 0;
  if (hay && reintento === null) {
    reintento = setInterval(reenviar, REINTENTO_MS);
  } else if (!hay && reintento !== null) {
    clearInterval(reintento);
    reintento = null;
  }
}

/** Se llama una vez al arrancar la aplicación. */
export function arrancar() {
  alVolverLaRed = () => reenviar();
  window.addEventListener('online', alVolverLaRed);
  if (pendientes().length > 0) {
    programarReintento();
    reenviar();
  }
}

/** Para las pruebas: deja el módulo sin temporizadores ni oyentes colgando. */
export function detener() {
  if (reintento !== null) clearInterval(reintento);
  if (alVolverLaRed) window.removeEventListener('online', alVolverLaRed);
  reintento = null;
  alVolverLaRed = null;
  oyentes.clear();
}
