// Construcción de interfaz.
//
// `h` reemplaza a las plantillas con innerHTML que usaba la versión anterior.
// La diferencia no es de estilo: el texto entra por `textContent`, así que un
// producto que se llame `<img onerror="...">` se ve como ese texto en vez de
// ejecutarse. Con innerHTML, cualquiera con permiso de menú podía inyectar
// código en la sesión de sus compañeros.

/**
 * h('div', {class: 'x'}, 'texto', otroNodo)
 * Los hijos de tipo string se insertan como texto, nunca como HTML.
 */
export function h(tag, props = {}, ...children) {
  const el = document.createElement(tag);

  for (const [key, value] of Object.entries(props)) {
    if (value === null || value === undefined || value === false) continue;
    if (key === 'class') el.className = value;
    else if (key === 'dataset') Object.assign(el.dataset, value);
    else if (key.startsWith('on')) el.addEventListener(key.slice(2).toLowerCase(), value);
    else if (key === 'html') el.innerHTML = value; // solo para SVG propio, nunca para datos
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

// ---------- piezas reutilizables ----------

export function card(...children) {
  return h('div', { class: 'bg-white rounded-xl border border-slate-200 p-4' }, children);
}

export function titledCard(title, ...children) {
  return card(h('h2', { class: 'font-semibold text-slate-900 mb-3' }, title), children);
}

const BUTTON_STYLES = {
  primary: 'bg-slate-900 text-white hover:bg-slate-800',
  secondary: 'border border-slate-300 text-slate-700 hover:border-slate-900',
  danger: 'border border-red-300 text-red-700 hover:bg-red-50',
  ghost: 'text-slate-600 hover:bg-slate-100',
};

export function button(label, { variant = 'primary', onClick, type = 'button', ...rest } = {}) {
  return h(
    'button',
    {
      type,
      class: `rounded-lg px-4 py-2 text-sm font-medium transition disabled:opacity-40 disabled:cursor-not-allowed ${BUTTON_STYLES[variant]}`,
      onClick,
      ...rest,
    },
    label
  );
}

export function field(label, input, hint) {
  return h(
    'label',
    { class: 'block' },
    h('span', { class: 'block text-sm font-medium text-slate-700 mb-1' }, label),
    input,
    hint ? h('span', { class: 'block text-xs text-slate-500 mt-1' }, hint) : null
  );
}

export function input(props = {}) {
  return h('input', {
    class:
      'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-transparent',
    ...props,
  });
}

export function select(options, props = {}) {
  return h(
    'select',
    {
      class:
        'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900',
      ...props,
    },
    options.map((o) => h('option', { value: o.value, selected: o.selected }, o.label))
  );
}

export function checkbox(label, props = {}) {
  return h(
    'label',
    { class: 'flex items-center gap-2 text-sm text-slate-700 cursor-pointer' },
    h('input', { type: 'checkbox', class: 'w-4 h-4 rounded border-slate-300', ...props }),
    label
  );
}

export function badge(text, tone = 'neutral') {
  const tones = {
    neutral: 'bg-slate-100 text-slate-700',
    info: 'bg-blue-100 text-blue-800',
    warn: 'bg-amber-100 text-amber-800',
    ok: 'bg-emerald-100 text-emerald-800',
    danger: 'bg-red-100 text-red-800',
  };
  return h('span', { class: `inline-block px-2 py-0.5 rounded text-xs font-medium ${tones[tone]}` }, text);
}

// ---------- estados ----------
// Los tres estados de cualquier pantalla que pide datos. Antes cada archivo
// resolvía esto a su manera, o directamente no lo resolvía.

export function loading(text = 'Cargando…') {
  return h(
    'div',
    { class: 'flex items-center gap-3 text-sm text-slate-500 p-8 justify-center' },
    h('span', {
      class: 'w-4 h-4 border-2 border-slate-300 border-t-slate-600 rounded-full animate-spin',
    }),
    text
  );
}

export function empty(title, hint, action) {
  return h(
    'div',
    { class: 'text-center p-10' },
    h('p', { class: 'text-slate-900 font-medium' }, title),
    hint ? h('p', { class: 'text-sm text-slate-500 mt-1' }, hint) : null,
    action ? h('div', { class: 'mt-4' }, action) : null
  );
}

export function errorBox(message, onRetry) {
  return h(
    'div',
    { class: 'bg-red-50 border border-red-200 rounded-xl p-4 text-sm' },
    h('p', { class: 'text-red-800' }, message),
    onRetry ? h('div', { class: 'mt-3' }, button('Reintentar', { variant: 'secondary', onClick: onRetry })) : null
  );
}

// ---------- avisos y confirmaciones ----------

let toastTimer = null;

export function toast(message, kind = 'error') {
  const host = document.getElementById('toast-host');
  const tone = kind === 'error' ? 'bg-red-600' : kind === 'ok' ? 'bg-emerald-600' : 'bg-slate-900';
  render(
    host,
    h('div', { class: `px-4 py-3 rounded-lg text-white shadow-lg text-sm ${tone}`, role: 'status' }, message)
  );
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => clear(host), 4500);
}

/**
 * Confirmación para lo que no se puede deshacer de un clic. Devuelve una
 * promesa: `if (await confirm(...))`.
 */
export function confirm({ title, message, confirmLabel = 'Confirmar', variant = 'danger' }) {
  return new Promise((resolve) => {
    const close = (answer) => {
      overlay.remove();
      document.removeEventListener('keydown', onKey);
      resolve(answer);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') close(false);
    };

    const overlay = h(
      'div',
      {
        class: 'fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4',
        onClick: (e) => {
          if (e.target === overlay) close(false);
        },
      },
      h(
        'div',
        { class: 'bg-white rounded-xl max-w-sm w-full p-5', role: 'dialog', 'aria-modal': 'true' },
        h('h3', { class: 'font-semibold text-slate-900' }, title),
        message ? h('p', { class: 'text-sm text-slate-600 mt-2' }, message) : null,
        h(
          'div',
          { class: 'flex gap-2 mt-5' },
          button('Cancelar', { variant: 'secondary', onClick: () => close(false), class: 'flex-1 rounded-lg px-4 py-2 text-sm font-medium border border-slate-300 text-slate-700' }),
          button(confirmLabel, { variant, onClick: () => close(true) })
        )
      )
    );

    document.body.append(overlay);
    document.addEventListener('keydown', onKey);
    overlay.querySelector('button:last-child')?.focus();
  });
}

/** Deshabilita un botón mientras corre una acción, para no disparar dos veces. */
export async function withBusy(btn, fn) {
  const original = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'Un momento…';
  try {
    return await fn();
  } finally {
    btn.disabled = false;
    btn.textContent = original;
  }
}
