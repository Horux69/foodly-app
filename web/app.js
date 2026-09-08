// Utilidades compartidas: sesion, llamadas a la API y formato.
//
// El token se guarda en localStorage. Es lo razonable para un panel interno
// servido desde el mismo origen y sin paso de build; si algun dia esto se
// expone a internet abierto, conviene mover la sesion a una cookie HttpOnly.

const API = '/api/v1';
const TOKEN_KEY = 'resto_token';

function getToken() {
  return localStorage.getItem(TOKEN_KEY);
}

function setToken(token) {
  localStorage.setItem(TOKEN_KEY, token);
}

function logout() {
  localStorage.removeItem(TOKEN_KEY);
  window.location.href = '/index.html';
}

// Lanza un Error con el mensaje que devuelve la API, para que las pantallas
// muestren la razon real ("El canal 'whatsapp' no esta habilitado") y no un
// generico. Un 401 significa token vencido o revocado: se vuelve al login.
async function api(path, options = {}) {
  const token = getToken();
  const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const res = await fetch(`${API}${path}`, { ...options, headers });

  if (res.status === 401) {
    logout();
    throw new Error('Sesion expirada');
  }

  if (!res.ok) {
    let message = `Error ${res.status}`;
    try {
      const body = await res.json();
      if (typeof body.detail === 'string') {
        message = body.detail;
      } else if (Array.isArray(body.detail)) {
        message = body.detail.map((d) => d.msg).join(', ');
      }
    } catch {
      // respuesta sin cuerpo JSON: se queda el mensaje generico
    }
    throw new Error(message);
  }

  return res.status === 204 ? null : res.json();
}

// Exige sesion y devuelve el contexto del usuario. Toda pantalla operativa
// arranca con esto: define que puede hacer y como opera el restaurante.
async function requireSession() {
  if (!getToken()) {
    window.location.href = '/index.html';
    throw new Error('Sin sesion');
  }
  return api('/auth/me');
}

let _currency = 'COP';

function setCurrency(code) {
  _currency = code;
}

function money(value) {
  const number = Number(value);
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: _currency,
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(number);
}

function can(me, permission) {
  return me.permissions.includes(permission);
}

// Barra superior comun. Solo ofrece las pantallas que los permisos permiten.
function renderNav(me, active) {
  const links = [
    { href: '/pedidos.html', label: 'Pedidos', permission: 'orders.create' },
    { href: '/cocina.html', label: 'Cocina', permission: 'orders.view' },
  ].filter((l) => can(me, l.permission));

  const items = links
    .map(
      (l) =>
        `<a href="${l.href}" class="px-3 py-2 rounded-md text-sm font-medium ${
          l.href === active ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'
        }">${l.label}</a>`
    )
    .join('');

  return `
    <header class="bg-white border-b border-slate-200">
      <div class="max-w-7xl mx-auto px-4 h-14 flex items-center justify-between gap-4">
        <div class="flex items-center gap-4 min-w-0">
          <span class="font-semibold text-slate-900 truncate">${me.tenant_name}</span>
          <nav class="flex gap-1">${items}</nav>
        </div>
        <div class="flex items-center gap-3 text-sm text-slate-500 min-w-0">
          <span class="truncate">${me.name} · ${me.branch_name || 'sin sucursal'}</span>
          <button onclick="logout()" class="text-slate-600 hover:text-slate-900 font-medium">Salir</button>
        </div>
      </div>
    </header>`;
}

function toast(message, kind = 'error') {
  const el = document.getElementById('toast');
  if (!el) return;
  const colors = kind === 'error' ? 'bg-red-600' : 'bg-emerald-600';
  el.className = `fixed bottom-4 right-4 z-50 px-4 py-3 rounded-lg text-white shadow-lg ${colors}`;
  el.textContent = message;
  el.classList.remove('hidden');
  clearTimeout(el._timer);
  el._timer = setTimeout(() => el.classList.add('hidden'), 4000);
}
