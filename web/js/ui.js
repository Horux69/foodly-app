// Construcción de interfaz.
//
// `h` reemplaza a las plantillas con innerHTML. La diferencia no es de
// estilo: el texto entra por `textContent`, así que un producto que se llame
// `<img onerror="...">` se ve como ese texto en vez de ejecutarse. Con
// innerHTML, cualquiera con permiso de menú podía inyectar código en la
// sesión de sus compañeros.

import { icon } from './icons.js';

export function h(tag, props = {}, ...children) {
  const el = document.createElement(tag);

  for (const [key, value] of Object.entries(props)) {
    if (value === null || value === undefined || value === false) continue;
    if (key === 'class') el.className = value;
    else if (key === 'dataset') Object.assign(el.dataset, value);
    else if (key.startsWith('on')) el.addEventListener(key.slice(2).toLowerCase(), value);
    else if (value === true) el.setAttribute(key, '');
    else el.setAttribute(key, value);
  }

  append(el, children);
  return el;
}

function append(el, children) {
  for (const child of children.flat(Infinity)) {
    if (child === null || child === undefined || child === false) continue;
    el.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
}

export function clear(el) {
  el.replaceChildren();
  return el;
}

export function render(el, ...children) {
  clear(el);
  append(el, children);
  return el;
}

// ---------- superficies ----------

export function card(...children) {
  return h('div', { class: 'superficie p-4' }, children);
}

export function titledCard(title, ...children) {
  return card(
    h('h2', { class: 'text-base font-semibold text-stone-900 mb-3' }, title),
    children
  );
}

/** Encabezado de pantalla: título, apoyo y acciones a la derecha. */
export function pageHeader(titulo, { hint, actions } = {}) {
  return h(
    'div',
    { class: 'flex flex-wrap items-start justify-between gap-3 mb-4' },
    h(
      'div',
      {},
      h('h1', { class: 'text-xl font-semibold tracking-tight text-stone-900' }, titulo),
      hint ? h('p', { class: 'text-sm text-stone-500 mt-0.5' }, hint) : null
    ),
    actions ? h('div', { class: 'flex flex-wrap gap-2' }, actions) : null
  );
}

// ---------- controles ----------

const VARIANTES = {
  primary: 'boton boton-principal',
  secondary: 'boton boton-secundario',
  danger: 'boton boton-peligro',
};

export function button(label, { variant = 'primary', onClick, type = 'button', iconName, full, ...rest } = {}) {
  return h(
    'button',
    {
      type,
      class: `${VARIANTES[variant]} ${full ? 'w-full' : ''}`,
      onClick,
      ...rest,
    },
    iconName ? icon(iconName, { size: 18 }) : null,
    h('span', {}, label)
  );
}

export function field(label, input, hint) {
  return h(
    'label',
    { class: 'block' },
    h('span', { class: 'block text-sm font-medium text-stone-700 mb-1.5' }, label),
    input,
    hint ? h('span', { class: 'block text-xs text-stone-500 mt-1' }, hint) : null
  );
}

export function input(props = {}) {
  return h('input', { class: 'campo', ...props });
}

export function select(options, props = {}) {
  return h(
    'select',
    { class: 'campo', ...props },
    options.map((o) => h('option', { value: o.value, selected: o.selected }, o.label))
  );
}

/** Casilla con área de toque cómoda, no un cuadradito de 13px. */
export function checkbox(label, props = {}) {
  return h(
    'label',
    { class: 'flex items-center gap-2.5 text-sm text-stone-700 cursor-pointer py-1.5' },
    h('input', { type: 'checkbox', class: 'w-4 h-4 rounded border-stone-300 accent-amber-700', ...props }),
    label
  );
}

const TONOS = {
  neutral: 'bg-stone-100 text-stone-700',
  info: 'bg-sky-50 text-sky-800',
  warn: 'bg-amber-50 text-amber-800',
  ok: 'bg-emerald-50 text-emerald-800',
  danger: 'bg-red-50 text-red-800',
};

export function badge(text, tone = 'neutral', iconName) {
  return h(
    'span',
    { class: `inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium ${TONOS[tone]}` },
    iconName ? icon(iconName, { size: 12 }) : null,
    text
  );
}

// ---------- estados ----------

/** Esqueleto: reserva el espacio que van a ocupar los datos. */
export function skeleton({ rows = 3 } = {}) {
  return h(
    'div',
    { class: 'space-y-3' },
    Array.from({ length: rows }, () =>
      h(
        'div',
        { class: 'superficie p-4 space-y-2.5' },
        h('div', { class: 'esqueleto h-4 w-1/3' }),
        h('div', { class: 'esqueleto h-3 w-2/3' }),
        h('div', { class: 'esqueleto h-3 w-1/2' })
      )
    )
  );
}

export function loading(text = 'Cargando…') {
  return h(
    'div',
    { class: 'flex items-center gap-3 text-sm text-stone-500 p-8 justify-center' },
    h('span', { class: 'w-4 h-4 border-2 border-stone-300 border-t-stone-600 rounded-full animate-spin' }),
    text
  );
}

/** Un vacío debe decir qué hacer, no solo que no hay nada. */
export function empty(title, hint, action, iconName = 'vacio') {
  return h(
    'div',
    { class: 'text-center px-6 py-12' },
    h(
      'div',
      { class: 'inline-flex items-center justify-center w-12 h-12 rounded-full bg-stone-100 text-stone-400 mb-3' },
      icon(iconName, { size: 24 })
    ),
    h('p', { class: 'text-stone-900 font-medium' }, title),
    hint ? h('p', { class: 'text-sm text-stone-500 mt-1 max-w-sm mx-auto' }, hint) : null,
    action ? h('div', { class: 'mt-5' }, action) : null
  );
}

export function errorBox(message, onRetry) {
  return h(
    'div',
    { class: 'bg-red-50 border border-red-200 rounded-xl p-4 flex items-start gap-3' },
    icon('alerta', { size: 20, class: 'text-red-600 mt-0.5' }),
    h(
      'div',
      { class: 'flex-1' },
      h('p', { class: 'text-sm text-red-800' }, message),
      onRetry
        ? h('div', { class: 'mt-3' }, button('Reintentar', { variant: 'secondary', onClick: onRetry }))
        : null
    )
  );
}

// ---------- avisos ----------

let toastTimer = null;

export function toast(message, kind = 'error') {
  const host = document.getElementById('toast-host');
  const estilo = {
    error: ['bg-red-600', 'alerta'],
    ok: ['bg-emerald-700', 'check'],
    info: ['bg-stone-900', 'alerta'],
  }[kind];

  render(
    host,
    h(
      'div',
      {
        class: `aparece flex items-center gap-2.5 px-4 py-3 rounded-xl text-white shadow-lg text-sm max-w-sm ${estilo[0]}`,
        role: 'status',
      },
      icon(estilo[1], { size: 18 }),
      h('span', {}, message)
    )
  );

  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => clear(host), 4500);
}

/** Confirmación para lo que no se deshace de un clic. `await confirm(...)`. */
export function confirm({ title, message, confirmLabel = 'Confirmar', variant = 'danger' }) {
  return new Promise((resolve) => {
    const close = (answer) => {
      overlay.remove();
      document.removeEventListener('keydown', onKey);
      resolve(answer);
    };
    const onKey = (e) => e.key === 'Escape' && close(false);

    const confirmar = button(confirmLabel, { variant, onClick: () => close(true) });

    const overlay = h(
      'div',
      {
        class: 'fixed inset-0 z-50 bg-stone-900/40 backdrop-blur-[2px] flex items-center justify-center p-4',
        onClick: (e) => e.target === overlay && close(false),
      },
      h(
        'div',
        { class: 'aparece bg-white rounded-xl max-w-sm w-full p-5 shadow-xl', role: 'dialog', 'aria-modal': 'true' },
        h(
          'div',
          { class: 'flex items-start gap-3' },
          h(
            'div',
            { class: 'inline-flex items-center justify-center w-9 h-9 rounded-full bg-red-50 text-red-600 shrink-0' },
            icon('alerta', { size: 18 })
          ),
          h(
            'div',
            {},
            h('h3', { class: 'font-semibold text-stone-900' }, title),
            message ? h('p', { class: 'text-sm text-stone-600 mt-1' }, message) : null
          )
        ),
        h(
          'div',
          { class: 'flex gap-2 mt-5' },
          button('Cancelar', { variant: 'secondary', onClick: () => close(false), full: true }),
          confirmar
        )
      )
    );

    document.body.append(overlay);
    document.addEventListener('keydown', onKey);
    confirmar.focus();
  });
}
