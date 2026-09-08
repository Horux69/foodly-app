// Tablero de domicilios.
//
// El módulo 6 estaba entero en el backend y para el usuario no existía. Las
// columnas van por categoría de estado —`ready`, `in_transit`,
// `completed`—, nunca por código: un restaurante llama "En camino" a lo que
// otro llama "En moto", y ambos son `in_transit`.
//
// Un pedido es domicilio porque tiene entrega, no porque venga de cierto
// canal: por eso la lista se pide con `only_delivery`, que hace un JOIN
// contra `delivery_info`.
//
// El sellado de la hora de salida y de la de entrega no se hace aquí: lo
// hace el backend al cambiar de estado, por categoría y solo si estaban
// vacías.

import { api } from '../api.js';
import { elapsed, money, time } from '../format.js';
import { icon } from '../icons.js';
import { branchQuery, can } from '../session.js';
import {
  badge, button, empty, errorBox, h, input, pageHeader, render, select, skeleton, toast,
} from '../ui.js';
import { abrirPedido } from './pedido-detalle.js';

const REFRESCO_MS = 20000;

const COLUMNAS = [
  { categoria: 'ready', titulo: 'Listos para salir', tono: 'ok' },
  { categoria: 'in_transit', titulo: 'En camino', tono: 'info' },
  { categoria: 'completed', titulo: 'Entregados hoy', tono: 'neutral' },
];

export async function domicilios(outlet) {
  const tablero = h('div');
  const marca = h('span', { class: 'flex items-center gap-1.5 text-xs text-stone-500' });
  let vivo = true;
  let repartidores = [];

  render(
    outlet,
    pageHeader('Domicilios', {
      hint: 'Las columnas son categorías de estado, no nombres: funcionan igual aunque tu restaurante los llame de otra forma.',
      actions: marca,
    }),
    tablero
  );
  render(tablero, skeleton({ rows: 2 }));

  if (can('delivery.assign')) {
    try {
      repartidores = await api.get('/couriers');
    } catch {
      // Sin lista de repartidores el tablero sigue sirviendo para mirar.
    }
  }

  async function refrescar() {
    if (!vivo) return;

    let paginas;
    try {
      paginas = await Promise.all(
        COLUMNAS.map((c) =>
          api.get(
            `/orders${branchQuery({
              only_delivery: 'true',
              with_next_statuses: 'true',
              status_category: c.categoria,
              limit: 50,
            })}`
          )
        )
      );
    } catch (error) {
      if (vivo) render(tablero, errorBox(error.message, refrescar));
      return;
    }
    if (!vivo) return;

    render(marca, icon('reloj', { size: 14 }), `Actualizado a las ${time(new Date().toISOString())}`);

    const total = paginas.reduce((suma, p) => suma + p.items.length, 0);
    if (!total) {
      return render(
        tablero,
        h(
          'div',
          { class: 'seccion' },
          empty(
            'Nada en reparto',
            'Los pedidos con dirección aparecen aquí en cuanto la cocina los deja listos.',
            null,
            'domicilio'
          )
        )
      );
    }

    render(
      tablero,
      h(
        'div',
        { class: 'grid grid-cols-1 md:grid-cols-3 gap-4 items-start' },
        COLUMNAS.map((columna, i) =>
          h(
            'section',
            { class: 'space-y-3' },
            h(
              'div',
              { class: 'flex items-center gap-2 px-1' },
              h('h2', { class: 'text-sm font-semibold text-stone-700' }, columna.titulo),
              badge(String(paginas[i].items.length), columna.tono)
            ),
            paginas[i].items.length
              ? paginas[i].items.map((pedido) => tarjeta(pedido, repartidores, refrescar))
              : h(
                  'p',
                  { class: 'text-sm text-stone-400 px-1 py-6 text-center border border-dashed border-stone-200 rounded-xl' },
                  'Nada aquí'
                )
          )
        )
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

function tarjeta(pedido, repartidores, refrescar) {
  const entrega = pedido.delivery;
  const asigna = can('delivery.assign');

  const selectorRepartidor = asigna && repartidores.length
    ? select(
        [
          { value: '', label: 'Sin repartidor' },
          ...repartidores.map((r) => ({ value: r.id, label: r.name, selected: r.id === entrega.courier_id })),
        ],
        {
          class: 'campo h-8 py-0 text-[12.5px]',
          'aria-label': 'Repartidor',
          onChange: async (event) => {
            const courierId = event.target.value;
            if (!courierId) return;
            event.target.disabled = true;
            try {
              await api.put(`/orders/${pedido.id}/delivery/courier`, { courier_id: courierId });
              toast('Repartidor asignado', 'ok');
              await refrescar();
            } catch (error) {
              toast(error.message);
              event.target.disabled = false;
            }
          },
        }
      )
    : null;

  const minutos = input({
    type: 'number',
    min: '1',
    max: '600',
    placeholder: 'min',
    class: 'campo h-8 py-0 w-20 text-[12.5px]',
    'aria-label': 'Minutos estimados',
  });

  const ponerEta = button('ETA', {
    variant: 'secondary',
    onClick: async () => {
      const valor = Number(minutos.value);
      if (!valor) return toast('Escribe los minutos');
      ponerEta.disabled = true;
      try {
        await api.put(`/orders/${pedido.id}/delivery/eta`, { minutes: valor });
        toast('Hora estimada actualizada', 'ok');
        await refrescar();
      } catch (error) {
        toast(error.message);
        ponerEta.disabled = false;
      }
    },
  });

  return h(
    'article',
    { class: 'seccion p-4 space-y-3' },

    h(
      'div',
      { class: 'flex items-start justify-between gap-2' },
      h(
        'div',
        { class: 'min-w-0' },
        h(
          'button',
          {
            class: 'text-lg font-bold tracking-tight text-stone-900 tabular-nums hover:underline',
            title: 'Ver el detalle',
            onClick: () => abrirPedido(pedido.id, { alCambiar: refrescar }),
          },
          pedido.order_number
        ),
        h('div', { class: 'text-[13px] text-stone-700 mt-0.5' }, entrega.address),
        h(
          'div',
          { class: 'text-[12px] text-stone-500' },
          [
            entrega.zone_name ?? 'Sin zona',
            money(pedido.total),
            pedido.balance.is_settled ? 'Pagado' : `Cobrar ${money(pedido.balance.pending)}`,
          ].join(' · ')
        )
      ),
      h(
        'div',
        { class: 'text-right shrink-0 text-[12px] text-stone-500' },
        h('div', { class: 'flex items-center gap-1 justify-end' }, icon('reloj', { size: 13 }), elapsed(pedido.created_at)),
        entrega.estimated_time
          ? h('div', { class: 'text-stone-400' }, `Promete ${time(entrega.estimated_time)}`)
          : null,
        entrega.dispatched_at ? h('div', { class: 'text-stone-400' }, `Salió ${time(entrega.dispatched_at)}`) : null,
        entrega.delivered_at ? h('div', { class: 'text-stone-400' }, `Entregó ${time(entrega.delivered_at)}`) : null
      )
    ),

    asigna
      ? h(
          'div',
          { class: 'flex flex-wrap items-center gap-2' },
          selectorRepartidor ?? h('span', { class: 'text-[12px] text-stone-400' }, 'Nadie con permiso de entrega'),
          minutos,
          ponerEta
        )
      : entrega.courier_name
        ? h('div', { class: 'text-[12.5px] text-stone-600' }, `Lleva ${entrega.courier_name}`)
        : null,

    // Las transiciones posibles las declara el backend según la máquina de
    // estados del restaurante; aquí no se decide ninguna.
    pedido.next_statuses?.length
      ? h(
          'div',
          { class: 'flex flex-wrap gap-2' },
          pedido.next_statuses.map((estado) =>
            button(estado.name, {
              variant: estado.category === 'cancelled' ? 'danger' : 'primary',
              onClick: async (event) => {
                event.currentTarget.disabled = true;
                try {
                  await api.post(`/orders/${pedido.id}/status`, { to_status_id: estado.id });
                  await refrescar();
                } catch (error) {
                  toast(error.message);
                  event.currentTarget.disabled = false;
                }
              },
            })
          )
        )
      : null
  );
}
