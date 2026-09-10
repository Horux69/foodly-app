// Formato de números, dinero y tiempo. Un solo lugar para que toda la
// interfaz muestre las cifras igual.

let moneda = 'COP';

export function setCurrency(code) {
  moneda = code;
}

export function money(value) {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: moneda,
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(Number(value));
}

/** Con decimales, para cifras donde los centavos importan (impuestos). */
export function moneyExact(value) {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: moneda,
    minimumFractionDigits: 2,
  }).format(Number(value));
}

/**
 * Los importes viajan como cadena decimal ("18000.00") y aquí se suman en
 * centavos enteros, igual que hace `Core\Money` en el backend: convertir en
 * los bordes y no arrastrar un float por el medio, que es de donde salen los
 * 0.30000000000000004.
 *
 * Sumar no es calcular un total: la cifra que manda sigue siendo la que
 * devuelve el backend. Esto solo sirve para proponer un importe que el
 * cajero todavía puede corregir. Dividir, que es la operación que sí pierde
 * centavos, se hace en `Domain\BillSplit` y no aquí.
 */
export function aCentavos(valor) {
  return Math.round(Number(valor) * 100);
}

export function desdeCentavos(centavos) {
  return (centavos / 100).toFixed(2);
}

export function number(value) {
  return new Intl.NumberFormat('es-CO').format(Number(value));
}

export function percent(fraction) {
  return `${(Number(fraction) * 100).toFixed(2).replace(/\.?0+$/, '')} %`;
}

export function time(iso) {
  return new Date(iso).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' });
}

export function date(iso) {
  return new Date(iso).toLocaleDateString('es-CO', { day: '2-digit', month: 'short' });
}

/** Minutos transcurridos desde una marca de tiempo. */
export function minutesSince(iso) {
  return Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
}

export function isoDate(d) {
  return d.toISOString().slice(0, 10);
}

/** "hace 3 min" — para que cocina lea la espera de un vistazo. */
export function elapsed(iso) {
  const mins = minutesSince(iso);
  if (mins < 1) return 'recién';
  if (mins === 1) return 'hace 1 minuto';
  if (mins < 60) return `hace ${mins} minutos`;
  const horas = Math.floor(mins / 60);
  return horas === 1 ? 'hace 1 hora' : `hace ${horas} horas`;
}

// ---------------------------------------------------------------------
// Vuelto (F7.6)
// ---------------------------------------------------------------------
//
// Vive aquí y no en el backend a propósito: el vuelto no se guarda en
// ninguna parte —no es un cobro, es plata que sale del cajón por un cobro
// que ya se registró entero— y se recalcula con cada tecla que escribe el
// cajero. Un viaje al servidor por pulsación, con cola en la caja, sería
// peor que la resta. Lo que sí es del backend es lo que se cobra, y eso no
// cambia: el vuelto no viaja en ninguna petición.
//
// Es el mismo criterio de `propinaSugerida`: la aritmética del pedido vive
// en `Domain\OrderTotalsCalculator`; una ayuda de pantalla que no se
// persiste, no.

/** Los billetes con los que se paga en Colombia. */
export const BILLETES = [2000, 5000, 10000, 20000, 50000, 100000];

/** Lo que hay que devolver. Cero cuando entregó justo o de menos. */
export const vuelto = (recibido, aCobrar) => Math.max(0, Number(recibido || 0) - Number(aCobrar || 0));

/** Lo que falta cuando entregó menos: no es un error, es un cobro parcial. */
export const falta = (recibido, aCobrar) => Math.max(0, Number(aCobrar || 0) - Number(recibido || 0));

/**
 * Los billetes que tiene sentido ofrecer como atajo: el importe exacto y
 * los que alcanzan a cubrirlo. Ofrecer 2.000 para una cuenta de 80.000 es
 * ruido en una pantalla donde hay cola.
 */
export function billetesUtiles(aCobrar) {
  const monto = Number(aCobrar || 0);
  return [monto, ...BILLETES.filter((b) => b > monto)].slice(0, 4);
}
