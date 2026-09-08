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

async function request(path, options = {}) {
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
};

export function query(params) {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined && value !== '') search.set(key, value);
  }
  const text = search.toString();
  return text ? `?${text}` : '';
}
