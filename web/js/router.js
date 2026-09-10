// Navegación sin recargar la página.
//
// Usa el hash (#/pedidos) y no la History API a propósito: así el servidor
// puede seguir sirviendo un único index.html sin ninguna regla de reescritura.
//
// Cada ruta declara el permiso que necesita. La navegación no es seguridad
// —el backend rechaza por su cuenta— pero evita ofrecerle a alguien una
// pantalla que va a rebotar.
//
// Una ruta puede además declarar `visible()`, para las pantallas que
// dependen de cómo opera el restaurante y no de quién eres: Domicilios solo
// existe si el canal está activo. Es configuración, no un condicional sobre
// el tenant.

import { toast } from './ui.js';
import { can } from './session.js';

const rutas = new Map();
let outlet = null;
let alCambiar = () => {};
let vistaActiva = null;

export function define(routes) {
  for (const route of routes) rutas.set(route.path, route);
}

export function start(elemento, onChange) {
  outlet = elemento;
  alCambiar = onChange;
  window.addEventListener('hashchange', resolver);
  resolver();
}

export function go(path, { replace = false } = {}) {
  const destino = `#/${path}`;
  if (window.location.hash === destino) return resolver();
  if (replace) window.location.replace(destino);
  else window.location.hash = destino;
}

/** Vuelve a montar la vista actual: cambiar de sucursal cambia lo que pinta. */
export function reload() {
  return resolver();
}

export function current() {
  return window.location.hash.replace(/^#\/?/, '').split('?')[0] || '';
}

const alcanzable = (route) => (!route.permission || can(route.permission)) && (route.visible?.() ?? true);

/** Primera ruta que el usuario sí puede abrir, para no dejarlo en el vacío. */
export function firstAllowed() {
  for (const route of rutas.values()) {
    if (!route.public && alcanzable(route)) return route.path;
  }
  return null;
}

export function menuRoutes() {
  return [...rutas.values()].filter((r) => r.label && alcanzable(r));
}

async function resolver() {
  const path = current();
  const route = rutas.get(path);

  if (!route) {
    const destino = firstAllowed() ?? 'ingresar';
    return go(destino, { replace: true });
  }

  // Cada vista puede dejar cosas corriendo (auto-refresco, temporizadores).
  // Se les avisa al salir para que no sigan trabajando en segundo plano.
  if (vistaActiva?.destroy) {
    try {
      vistaActiva.destroy();
    } catch {
      // una vista mal terminada no debe impedir entrar a la siguiente
    }
  }
  vistaActiva = null;

  if (!route.public && !alcanzable(route)) {
    toast(
      route.permission && !can(route.permission)
        ? 'No tienes permiso para esa pantalla'
        : 'Esa pantalla no está disponible en este restaurante'
    );
    return go(firstAllowed() ?? 'ingresar', { replace: true });
  }

  alCambiar(route);

  try {
    vistaActiva = (await route.view(outlet)) ?? null;
  } catch (error) {
    toast(error.message);
  }
}
