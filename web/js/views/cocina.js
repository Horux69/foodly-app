// Tablero de cocina (KDS).
//
// Se mira desde lejos y con las manos ocupadas, así que el diseño prioriza
// otra cosa que el resto de la aplicación: número de pedido grande, espera
// visible de un vistazo y un botón por acción posible.
//
// Los pedidos se agrupan en columnas por categoría de estado, no por código:
// un restaurante puede llamar 'En preparación' a lo que otro llama 'En
// plancha', y ambos son 'kitchen'. Las acciones disponibles las declara el
// backend en `next_statuses`, según la máquina de estados configurada.

import { api } from '../api.js';
import { elapsed, minutesSince, time } from '../format.js';
import { icon } from '../icons.js';
import { badge, button, empty, errorBox, h, render, skeleton, toast } from '../ui.js';

const REFRESCO_MS = 15000;
const ATENTO_MINUTOS = 10;
const TARDE_MINUTOS = 15;

const COLUMNAS = [
  { categoria: 'new', titulo: 'Por preparar', tono: 'neutral' },
  { categoria: 'kitchen', titulo: 'En cocina', tono: 'warn' },
  { categoria: 'ready', titulo: 'Listos para entregar', tono: 'ok' },
];

export async function cocina(outlet) {
  const tablero = h('div');
  const marca = h('span', { class: 'flex items-center gap-1.5 text-xs text-stone-500' });

  render(
    outlet,
    h(
      'div',
      { class: 'space-y-4' },
      h(
        'div',
        { class: 'flex flex-wrap items-center justify-between gap-2' },
        h('h1', { class: 'text-xl font-semibold tracking-tight text-stone-900' }, 'Tablero de cocina'),
        marca
      ),
      tablero
    )
  );
  render(tablero, skeleton({ rows: 2 }));

  let vivo = true;

  async function refrescar() {
    if (!vivo) return;

    let pedidos;
    try {
      pedidos = await api.get('/kitchen/orders');
    } catch (error) {
      if (vivo) render(tablero, errorBox(error.message, refrescar));
      return;
    }
    if (!vivo) return;

    render(marca, icon('reloj', { size: 14 }), `Actualizado a las ${time(new Date().toISOString())}`);

    if (!pedidos.length) {
      render(
        tablero,
        h(
          'div',
          { class: 'superficie' },
          empty('Todo al día', 'Los pedidos nuevos aparecen aquí solos, sin recargar.', null, 'check')
        )
      );
      return;
    }

    render(
      tablero,
      h(
        'div',
        { class: 'grid grid-cols-1 md:grid-cols-3 gap-4 items-start' },
        COLUMNAS.map((columna) => {
          const suyos = pedidos.filter((p) => p.status.category === columna.categoria);
          return h(
            'section',
            { class: 'space-y-3' },
            h(
              'div',
              { class: 'flex items-center gap-2 px-1' },
              h('h2', { class: 'text-sm font-semibold text-stone-700' }, columna.titulo),
              badge(String(suyos.length), columna.tono)
            ),
            suyos.length
              ? suyos.map((pedido) => ticket(pedido, refrescar))
              : h(
                  'p',
                  { class: 'text-sm text-stone-400 px-1 py-6 text-center border border-dashed border-stone-200 rounded-xl' },
                  'Nada aquí'
                )
          );
        })
      )
    );
  }

  await refrescar();
  const temporizador = setInterval(refrescar, REFRESCO_MS);

  return {
    destroy() {
      vivo = false;
      clearInterval(temporizador);
    },
  };
}

/** La demora se señala con color y con el grosor del borde izquierdo, para
 *  que se distinga desde lejos y no dependa solo del color. */
function urgencia(minutos) {
  if (minutos >= TARDE_MINUTOS) return { clase: 'ticket-tarde', texto: 'text-red-700 font-semibold' };
  if (minutos >= ATENTO_MINUTOS) return { clase: 'ticket-atento', texto: 'text-amber-700 font-medium' };
  return { clase: 'ticket-fresco', texto: 'text-stone-500' };
}

function ticket(pedido, refrescar) {
  const espera = minutesSince(pedido.created_at);
  const nivel = urgencia(espera);

  return h(
    'article',
    { class: `superficie ticket ${nivel.clase} p-4 flex flex-col gap-3 aparece` },

    h(
      'div',
      { class: 'flex items-start justify-between gap-2' },
      h(
        'div',
        { class: 'min-w-0' },
        h('div', { class: 'text-2xl font-bold tracking-tight text-stone-900 tabular-nums' }, pedido.order_number),
        h(
          'div',
          { class: 'flex items-center gap-1.5 text-xs text-stone-500 mt-0.5' },
          icon(pedido.table_code ? 'mesa' : 'domicilio', { size: 14 }),
          pedido.table_code ? `Mesa ${pedido.table_code}` : canal(pedido.channel)
        )
      ),
      h(
        'div',
        { class: `flex items-center gap-1 text-sm shrink-0 ${nivel.texto}` },
        icon('reloj', { size: 15 }),
        elapsed(pedido.created_at)
      )
    ),

    h(
      'ul',
      { class: 'space-y-2' },
      pedido.items.map((item) =>
        h(
          'li',
          { class: 'flex gap-2.5' },
          // La cantidad va aparte y con peso: es lo primero que busca cocina.
          h(
            'span',
            { class: 'inline-flex items-center justify-center min-w-[26px] h-[26px] px-1.5 rounded-md bg-stone-100 text-stone-900 text-sm font-bold tabular-nums shrink-0' },
            item.quantity
          ),
          h(
            'div',
            { class: 'min-w-0' },
            h('span', { class: 'text-[15px] font-medium text-stone-900' }, item.name_snapshot),
            item.modifiers.length
              ? h('div', { class: 'text-xs text-stone-500' }, item.modifiers.join(' · '))
              : null,
            item.notes
              ? h(
                  'div',
                  { class: 'flex items-center gap-1 text-xs text-amber-800 bg-amber-50 rounded px-1.5 py-0.5 mt-1' },
                  icon('alerta', { size: 12 }),
                  item.notes
                )
              : null
          )
        )
      )
    ),

    h(
      'div',
      { class: 'flex flex-wrap gap-2 mt-auto pt-1' },
      pedido.next_statuses.map((estado) =>
        button(estado.name, {
          variant: estado.category === 'cancelled' ? 'danger' : 'primary',
          iconName: estado.category === 'cancelled' ? 'cerrar' : 'check',
          onClick: (event) => avanzar(event.currentTarget, pedido, estado, refrescar),
        })
      )
    )
  );
}

async function avanzar(boton, pedido, estado, refrescar) {
  boton.disabled = true;
  try {
    await api.post(`/orders/${pedido.id}/status`, { to_status_id: estado.id });
    await refrescar();
  } catch (error) {
    // Aquí aterrizan las reglas del backend: falta de permiso para esa
    // transición, o un pedido que no se puede completar sin estar pagado.
    toast(error.message);
    boton.disabled = false;
  }
}

const NOMBRE_CANAL = {
  counter: 'Mostrador',
  table: 'Mesa',
  delivery: 'Domicilio',
  whatsapp: 'WhatsApp',
  app: 'App',
};

export function canal(code) {
  return NOMBRE_CANAL[code] ?? code;
}
