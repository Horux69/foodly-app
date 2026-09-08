// Tablero de cocina (KDS).
//
// Pensado para mirarse de lejos y tocarse con las manos ocupadas: tarjetas
// grandes, la espera bien visible y un solo botón por acción posible.
// Las acciones disponibles las declara el backend en `next_statuses`, según
// la máquina de estados que configuró el restaurante.

import { api } from '../api.js';
import { elapsed, minutesSince, time } from '../format.js';
import { badge, button, empty, errorBox, h, loading, render, toast } from '../ui.js';

const REFRESCO_MS = 15000;
const DEMORA_MINUTOS = 15;

const TONO_POR_CATEGORIA = { new: 'neutral', kitchen: 'warn', ready: 'ok' };

export async function cocina(outlet) {
  const tablero = h('div', { class: 'grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3' });
  const actualizado = h('span', { class: 'text-xs text-slate-500' });

  render(
    outlet,
    h(
      'div',
      { class: 'space-y-4' },
      h(
        'div',
        { class: 'flex items-center justify-between' },
        h('h1', { class: 'text-lg font-semibold text-slate-900' }, 'Tablero de cocina'),
        actualizado
      ),
      tablero
    )
  );

  render(tablero, loading('Cargando pedidos…'));

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

    actualizado.textContent = `Actualizado a las ${time(new Date().toISOString())}`;

    if (!pedidos.length) {
      render(
        tablero,
        h('div', { class: 'col-span-full' }, empty('No hay pedidos en curso', 'Los pedidos nuevos aparecen aquí solos.'))
      );
      return;
    }

    render(tablero, pedidos.map((pedido) => tarjeta(pedido, refrescar)));
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

function tarjeta(pedido, refrescar) {
  const espera = minutesSince(pedido.created_at);
  const demorado = espera >= DEMORA_MINUTOS;

  return h(
    'article',
    {
      class: `bg-white rounded-xl border p-4 flex flex-col gap-3 ${
        demorado ? 'border-red-300 ring-1 ring-red-100' : 'border-slate-200'
      }`,
    },
    h(
      'div',
      { class: 'flex items-start justify-between gap-2' },
      h(
        'div',
        {},
        h('div', { class: 'font-semibold text-slate-900 text-lg' }, pedido.order_number),
        h(
          'div',
          { class: 'text-xs text-slate-500' },
          canal(pedido.channel),
          pedido.table_code ? ` · mesa ${pedido.table_code}` : ''
        )
      ),
      h(
        'div',
        { class: 'text-right shrink-0' },
        badge(pedido.status.name, TONO_POR_CATEGORIA[pedido.status.category] ?? 'neutral'),
        h(
          'div',
          { class: `text-xs mt-1 ${demorado ? 'text-red-600 font-semibold' : 'text-slate-500'}` },
          elapsed(pedido.created_at)
        )
      )
    ),

    h(
      'ul',
      { class: 'space-y-1.5' },
      pedido.items.map((item) =>
        h(
          'li',
          {},
          h(
            'span',
            { class: 'font-medium text-slate-900' },
            `${item.quantity}× `,
            item.name_snapshot
          ),
          item.modifiers.length
            ? h('div', { class: 'text-xs text-slate-500 pl-5' }, item.modifiers.join(', '))
            : null,
          item.notes ? h('div', { class: 'text-xs text-amber-700 pl-5 font-medium' }, item.notes) : null
        )
      )
    ),

    h(
      'div',
      { class: 'flex flex-wrap gap-2 mt-auto pt-1' },
      pedido.next_statuses.map((estado) =>
        button(estado.name, {
          variant: estado.category === 'cancelled' ? 'danger' : 'primary',
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
