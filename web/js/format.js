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
