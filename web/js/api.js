// Cliente de la API y sesión.
//
// El token se guarda en localStorage. Es lo razonable para un panel interno
// servido desde el mismo origen y sin paso de compilación; si esto llegara a
// exponerse a internet abierto, conviene mover la sesión a una cookie HttpOnly.

const BASE = '/api/v1';
const TOKEN_KEY = 'resto_token';

export function getToken() {
  return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token) {
  localStorage.setItem(TOKEN_KEY, token);
}

export function clearToken() {
  localStorage.removeItem(TOKEN_KEY);
}

/** Error con el mensaje que devolvió la API, para poder mostrarlo tal cual. */
export class ApiError extends Error {
  constructor(message, status) {
    super(message);
    this.status = status;
  }
}

/**
 * Cuándo vence el token, leyendo su carga útil.
 *
 * No es una comprobación de seguridad —el token lo valida el servidor con la
 * firma— sino saber cuándo pedir uno nuevo. Devuelve null si no se puede
 * leer, y entonces no se renueva nada: el servidor dirá lo suyo.
 */
function venceEn(token) {
  try {
    const carga = JSON.parse(atob(token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')));
    return typeof carga.exp === 'number' ? carga.exp * 1000 : null;
  } catch {
    return null;
  }
}

// Se renueva con diez minutos de margen: un pedido no dura más que eso, así
// que la sesión no se corta a mitad de uno.
const MARGEN_RENOVACION_MS = 10 * 60 * 1000;
let renovacionEnCurso = null;

/**
 * Renueva el token antes de que venza, si hace falta.
 *
 * El token dura ocho horas y un turno puede ser más largo: sin esto, a
 * alguien se le cierra la sesión en mitad de un pedido. Se hace con `fetch`
 * directo y no con `request` para no entrar en bucle, y una sola vez aunque
 * varias peticiones salgan a la vez.
 *
 * Si la renovación falla no se hace nada: la petición que venía detrás
 * recibirá su 401 y el manejo de siempre mandará al ingreso.
 */
async function renovarSiHaceFalta() {
  const token = getToken();
  if (!token) return;

  const vence = venceEn(token);
  if (vence === null || vence - Date.now() > MARGEN_RENOVACION_MS) return;

  renovacionEnCurso ??= (async () => {
    try {
      const res = await fetch(`${BASE}/auth/refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      });
      if (!res.ok) return;
      const { access_token: nuevo } = await res.json();
      if (nuevo) setToken(nuevo);
    } catch {
      // Sin red: la petición de abajo fallará por su cuenta y lo dirá.
    } finally {
      renovacionEnCurso = null;
    }
  })();

  await renovacionEnCurso;
}

async function request(path, options = {}) {
  // Antes de cada petición, no después de un 401: el 401 ya perdió el
  // pedido que se estaba mandando. Se saltan las dos que no pueden
  // renovarse: la propia renovación (sería un bucle) y el ingreso, que
  // todavía no tiene token.
  if (path !== '/auth/refresh' && path !== '/auth/login') await renovarSiHaceFalta();

  const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;

  let res;
  try {
    res = await fetch(`${BASE}${path}`, { ...options, headers });
  } catch {
    throw new ApiError('No se pudo conectar con el servidor. ¿Está encendido?', 0);
  }

  // 401 es sesión vencida o revocada: se vuelve al ingreso.
  if (res.status === 401) {
    clearToken();
    window.location.hash = '#/ingresar';
    throw new ApiError('La sesión expiró. Vuelve a ingresar.', 401);
  }

  if (!res.ok) throw new ApiError(await readError(res), res.status);
  return res.status === 204 ? null : res.json();
}

/** La API devuelve `detail` como texto, o como lista cuando falla la validación. */
async function readError(res) {
  try {
    const body = await res.json();
    if (typeof body.detail === 'string') return body.detail;
    if (Array.isArray(body.detail)) {
      return body.detail.map((d) => `${d.loc?.slice(1).join('.') ?? ''} ${d.msg}`.trim()).join('. ');
    }
  } catch {
    // sin cuerpo JSON
  }
  return `Error ${res.status}`;
}

export const api = {
  get: (path) => request(path),
  post: (path, body) => request(path, { method: 'POST', body: JSON.stringify(body ?? {}) }),
  patch: (path, body) => request(path, { method: 'PATCH', body: JSON.stringify(body ?? {}) }),
  put: (path, body) => request(path, { method: 'PUT', body: JSON.stringify(body ?? {}) }),
  // Sin cuerpo: lo que se borra ya está en la ruta. La API responde 204 y
  // `request` lo traduce a null.
  delete: (path) => request(path, { method: 'DELETE' }),
};

/**
 * Identificador de un intento, para que un reintento no cobre ni pida dos
 * veces. El backend ya lo acepta (`OrderService::createOrder`,
 * `PaymentService::registerPayment`): guarda la llave junto al pedido o al
 * pago y, si vuelve la misma, devuelve el que ya existe en vez de crear otro.
 *
 * `crypto.randomUUID` solo existe en contexto seguro, y una tableta de
 * mostrador entra por `http://192.168.x.x`: ahí el respaldo es
 * `getRandomValues`, que sí está siempre. No hace falta que sea impredecible
 * —no es un secreto—, solo que no se repita.
 */
export function uuid() {
  if (typeof crypto?.randomUUID === 'function') return crypto.randomUUID();

  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40; // versión 4
  bytes[8] = (bytes[8] & 0x3f) | 0x80; // variante RFC 4122
  const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function query(params) {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined && value !== '') search.set(key, value);
  }
  const text = search.toString();
  return text ? `?${text}` : '';
}
