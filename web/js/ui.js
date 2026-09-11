// Construcción de interfaz.
//
// `h` reemplaza a las plantillas con innerHTML. La diferencia no es de
// estilo: el texto entra por `textContent`, así que un producto que se llame
// `<img onerror="...">` se ve como ese texto en vez de ejecutarse.

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

// ---------- estructura de página ----------

/** Encabezado de pantalla. Vive en la página, no dentro de una tarjeta. */
export function pageHeader(titulo, { hint, actions } = {}) {
  return h(
    'div',
    { class: 'flex flex-wrap items-end justify-between gap-3 mb-5' },
    h(
      'div',
      { class: 'min-w-0' },
      h('h1', { class: 'text-[19px] font-semibold' }, titulo),
      hint ? h('p', { class: 'text-[13px] text-stone-600 mt-0.5' }, hint) : null
    ),
    actions ? h('div', { class: 'flex flex-wrap gap-2' }, actions) : null
  );
}

/**
 * Bloque de contenido con encabezado y línea. Sustituye a la tarjeta suelta:
 * si todo es una tarjeta, ninguna jerarquiza.
 */
export function section(titulo, { actions, body, list, hint } = {}) {
  return h(
    'section',
    { class: 'seccion' },
    titulo
      ? h(
          'header',
          {},
          h(
            'div',
            { class: 'min-w-0' },
            h('h2', {}, titulo),
            hint ? h('p', { class: 'text-[12px] text-stone-600 normal-case font-normal mt-0.5' }, hint) : null
          ),
          actions ? h('div', { class: 'flex gap-2 shrink-0' }, actions) : null
        )
      : null,
    body ? h('div', { class: 'cuerpo' }, body) : null,
    list ? h('div', { class: 'lista' }, list) : null
  );
}

/** Fila de lista: alineada, densa y con divisor, no una tarjeta más. */
export function row(...children) {
  return h('div', { class: 'fila' }, children);
}

/** Bloque suelto, para lo que de verdad flota (un ticket de cocina). */
export function card(...children) {
  return h('div', { class: 'seccion p-4' }, children);
}

export function titledCard(titulo, ...children) {
  return section(titulo, { body: children });
}

// ---------- controles ----------

const VARIANTES = {
  primary: 'boton boton-principal',
  secondary: 'boton boton-secundario',
  danger: 'boton boton-peligro',
  subtle: 'boton boton-sutil',
};

export function button(label, { variant = 'primary', onClick, type = 'button', iconName, full, big, ...rest } = {}) {
  return h(
    'button',
    {
      type,
      class: `${VARIANTES[variant]} ${big ? 'boton-grande' : ''} ${full ? 'w-full' : ''}`,
      onClick,
      ...rest,
    },
    iconName ? icon(iconName, { size: 16 }) : null,
    label ? h('span', {}, label) : null
  );
}

export function field(label, input, hint) {
  return h(
    'label',
    { class: 'block' },
    h('span', { class: 'block text-[12.5px] font-medium text-stone-600 mb-1' }, label),
    input,
    hint ? h('span', { class: 'block text-[12px] text-stone-600 mt-1' }, hint) : null
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

export function checkbox(label, props = {}) {
  return h(
    'label',
    { class: 'flex items-center gap-2 text-[13.5px] text-stone-700 cursor-pointer py-1' },
    h('input', { type: 'checkbox', class: 'w-[15px] h-[15px] rounded-[3px] border-stone-300 accent-stone-900', ...props }),
    label
  );
}

const TONOS = {
  neutral: 'bg-stone-100 text-stone-600',
  info: 'bg-sky-50 text-sky-700',
  warn: 'bg-amber-50 text-amber-800',
  ok: 'bg-emerald-50 text-emerald-700',
  danger: 'bg-red-50 text-red-700',
};

export function badge(text, tone = 'neutral', iconName) {
  return h(
    'span',
    { class: `inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11.5px] font-medium ${TONOS[tone]}` },
    iconName ? icon(iconName, { size: 11 }) : null,
    text
  );
}

/** Pestañas de texto subrayado: menos ruido que un grupo de píldoras. */
export function tabs(items, activa, alCambiar) {
  return h(
    'div',
    { class: 'flex gap-5 border-b border-[--linea] mb-5 overflow-x-auto' },
    items.map((item) =>
      h(
        'button',
        {
          class: `relative pb-2.5 text-[13.5px] whitespace-nowrap transition ${
            item.key === activa ? 'text-stone-900 font-semibold' : 'text-stone-600 hover:text-stone-800'
          }`,
          onClick: () => alCambiar(item.key),
        },
        item.label,
        item.key === activa ? h('span', { class: 'absolute -bottom-px inset-x-0 h-[2px] bg-stone-900' }) : null
      )
    )
  );
}

// ---------- estados ----------

export function skeleton({ rows = 3 } = {}) {
  return h(
    'div',
    { class: 'seccion' },
    h(
      'div',
      { class: 'lista' },
      Array.from({ length: rows }, () =>
        h(
          'div',
          { class: 'fila' },
          h('div', { class: 'flex-1 space-y-2' }, h('div', { class: 'esqueleto h-3.5 w-1/3' }), h('div', { class: 'esqueleto h-3 w-1/2' })),
          h('div', { class: 'esqueleto h-7 w-20' })
        )
      )
    )
  );
}

export function loading(text = 'Cargando…') {
  return h(
    'div',
    { class: 'flex items-center gap-2.5 text-[13px] text-stone-600 p-8 justify-center' },
    h('span', { class: 'w-3.5 h-3.5 border-2 border-stone-200 border-t-stone-500 rounded-full animate-spin' }),
    text
  );
}

/** Un vacío debe decir qué hacer, no solo que no hay nada. */
export function empty(title, hint, action, iconName = 'vacio') {
  return h(
    'div',
    { class: 'text-center px-6 py-12' },
    h('div', { class: 'inline-flex text-stone-300 mb-3' }, icon(iconName, { size: 28 })),
    h('p', { class: 'text-[14px] font-medium text-stone-800' }, title),
    hint ? h('p', { class: 'text-[13px] text-stone-600 mt-1 max-w-sm mx-auto' }, hint) : null,
    action ? h('div', { class: 'mt-4 flex justify-center' }, action) : null
  );
}

export function errorBox(message, onRetry) {
  return h(
    'div',
    { class: 'flex items-start gap-2.5 border border-red-200 bg-red-50/60 rounded-[--r-g] p-3.5' },
    icon('alerta', { size: 17, class: 'text-red-600 mt-px' }),
    h(
      'div',
      { class: 'flex-1 min-w-0' },
      h('p', { class: 'text-[13.5px] text-red-800' }, message),
      onRetry ? h('div', { class: 'mt-2.5' }, button('Reintentar', { variant: 'secondary', onClick: onRetry })) : null
    )
  );
}

// ---------- avisos ----------

let toastTimer = null;

export function toast(message, kind = 'error') {
  const host = document.getElementById('toast-host');
  const [fondo, ico] = {
    error: ['bg-red-700', 'alerta'],
    ok: ['bg-stone-900', 'check'],
    info: ['bg-stone-900', 'alerta'],
    // Ni error ni éxito: quedó pendiente y alguien tiene que saberlo.
    warn: ['bg-amber-700', 'alerta'],
  }[kind];

  render(
    host,
    h(
      'div',
      { class: `aparece flex items-center gap-2 px-3.5 py-2.5 rounded-[--r] text-[--tinta-inversa] shadow-lg text-[13px] max-w-sm ${fondo}`, role: 'status' },
      icon(ico, { size: 16 }),
      h('span', {}, message)
    )
  );

  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => clear(host), 4500);
}

// ---------- diálogos ----------

const ENFOCABLES = 'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])';

/**
 * Monta un diálogo modal y le da el comportamiento de teclado que se espera
 * de uno: Escape cierra, Tab no se escapa, el foco entra al abrir y vuelve a
 * donde estaba al cerrar.
 *
 * Cada diálogo arma su propio marcado —son muy distintos entre sí— y este
 * helper solo aporta la conducta, que sí es la misma en todos. Sin el ciclo
 * de Tab, tabular desde el último botón se va a la aplicación de atrás, que
 * para quien navega con teclado o lector de pantalla es quedarse sin diálogo
 * sin haberlo cerrado.
 *
 * Devuelve la función que lo desmonta: el `cerrar` de cada diálogo la llama.
 */
export function montarDialogo(overlay, { alCerrar, foco } = {}) {
  const previo = document.activeElement;

  const enfocables = () =>
    [...overlay.querySelectorAll(ENFOCABLES)].filter((el) => !el.disabled && el.getAttribute('aria-hidden') !== 'true');

  const onKey = (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      alCerrar?.();
      return;
    }
    if (event.key !== 'Tab') return;

    const lista = enfocables();
    if (lista.length === 0) return;
    const primero = lista[0];
    const ultimo = lista[lista.length - 1];

    // El foco puede estar fuera del diálogo (recién abierto, o el navegador
    // lo movió): en ese caso Tab entra en vez de salir.
    if (!overlay.contains(document.activeElement)) {
      event.preventDefault();
      primero.focus();
      return;
    }
    if (event.shiftKey && document.activeElement === primero) {
      event.preventDefault();
      ultimo.focus();
    } else if (!event.shiftKey && document.activeElement === ultimo) {
      event.preventDefault();
      primero.focus();
    }
  };

  // En captura para ganarle a los atajos de la pantalla de atrás: con un
  // diálogo abierto, la tecla es del diálogo.
  document.addEventListener('keydown', onKey, true);
  document.body.append(overlay);
  (foco ?? enfocables()[0])?.focus();

  return function desmontar() {
    document.removeEventListener('keydown', onKey, true);
    overlay.remove();
    previo?.focus?.();
  };
}

export function confirm({ title, message, confirmLabel = 'Confirmar', variant = 'danger' }) {
  return new Promise((resolve) => {
    let desmontar;
    const close = (answer) => {
      desmontar();
      resolve(answer);
    };
    const confirmar = button(confirmLabel, { variant, onClick: () => close(true) });

    const overlay = h(
      'div',
      {
        class: 'fixed inset-0 z-50 bg-black/30 flex items-center justify-center p-4',
        onClick: (e) => e.target === overlay && close(false),
      },
      h(
        'div',
        { class: 'aparece bg-[--panel] rounded-[--r-g] max-w-sm w-full p-5 shadow-xl border border-[--linea]', role: 'dialog', 'aria-modal': 'true' },
        h('h3', { class: 'text-[15px] font-semibold' }, title),
        message ? h('p', { class: 'text-[13.5px] text-stone-600 mt-1.5 leading-relaxed' }, message) : null,
        h(
          'div',
          { class: 'flex justify-end gap-2 mt-5' },
          button('Cancelar', { variant: 'secondary', onClick: () => close(false) }),
          confirmar
        )
      )
    );

    desmontar = montarDialogo(overlay, { alCerrar: () => close(false), foco: confirmar });
  });
}
