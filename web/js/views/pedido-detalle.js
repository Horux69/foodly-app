// Detalle de un pedido, en un panel lateral.
//
// Lo abren Pedidos, Cocina y Domicilios: es el mismo pedido mirado desde tres
// sitios, y tenerlo escrito tres veces sería la forma segura de que las tres
// versiones se separaran.
//
// Junta lo que hasta ahora nadie llamaba: los pagos ya registrados, los
// próximos estados posibles según la máquina de estados configurada, y la
// bitácora —quién movió el pedido y cuándo—, que se escribía desde el primer
// día y solo la leía el reporte de tiempos.

import { api } from '../api.js';
import { date, money, time } from '../format.js';
import { icon } from '../icons.js';
import {
  badge, button, empty, errorBox, h, loading, render, section, toast,
} from '../ui.js';
import { canal } from './cocina.js';

const METODOS = {
  cash: 'Efectivo',
  card: 'Tarjeta',
  transfer: 'Transferencia',
};

export const metodoPago = (code) => METODOS[code] ?? code;

/** Tono del badge según la categoría del estado, nunca según su código. */
const TONO_CATEGORIA = {
  new: 'neutral',
  kitchen: 'warn',
  ready: 'ok',
  in_transit: 'info',
  completed: 'ok',
  cancelled: 'danger',
};

export const tonoEstado = (categoria) => TONO_CATEGORIA[categoria] ?? 'neutral';

/**
 * Abre el panel del pedido.
 *
 * @param {string} orderId
 * @param {{alCambiar?: () => void}} opciones `alCambiar` avisa a la pantalla
 *   de atrás cuando el pedido se movió, para que se repinte.
 */
export function abrirPedido(orderId, { alCambiar } = {}) {
  const cuerpo = h('div', { class: 'p-4 space-y-4' });
  let cambio = false;

  const cerrar = () => {
    overlay.remove();
    document.removeEventListener('keydown', alTeclear);
    if (cambio) alCambiar?.();
  };
  const alTeclear = (e) => e.key === 'Escape' && cerrar();

  const overlay = h(
    'div',
    {
      class: 'fixed inset-0 z-50 bg-stone-900/30 flex justify-end',
      onClick: (e) => e.target === overlay && cerrar(),
    },
    h(
      'div',
      {
        class: 'aparece bg-[--lienzo] w-full max-w-lg h-full overflow-y-auto shadow-xl border-l border-[--linea]',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-label': 'Detalle del pedido',
      },
      cuerpo
    )
  );

  document.body.append(overlay);
  document.addEventListener('keydown', alTeclear);
  render(cuerpo, loading('Cargando pedido…'));

  async function cargar() {
    let pedido;
    let pagos;
    let siguientes;
    let bitacora;
    try {
      [pedido, pagos, siguientes, bitacora] = await Promise.all([
        api.get(`/orders/${orderId}`),
        api.get(`/orders/${orderId}/payments`),
        api.get(`/orders/${orderId}/next-statuses`),
        api.get(`/orders/${orderId}/history`),
      ]);
    } catch (error) {
      return render(cuerpo, errorBox(error.message, cargar));
    }

    render(
      cuerpo,
      encabezado(pedido, cerrar),
      pedido.delivery ? bloqueEntrega(pedido.delivery) : null,
      bloqueLineas(pedido),
      bloqueTotales(pedido),
      bloquePagos(pedido, pagos),
      bloqueAvance(pedido, siguientes, async () => {
        cambio = true;
        await cargar();
      }),
      bloqueBitacora(bitacora)
    );
  }

  cargar();
  return { cerrar };
}

function encabezado(pedido, cerrar) {
  return h(
    'div',
    { class: 'flex items-start justify-between gap-3' },
    h(
      'div',
      { class: 'min-w-0' },
      h(
        'div',
        { class: 'flex items-center gap-2 flex-wrap' },
        h('span', { class: 'text-xl font-semibold tabular-nums' }, pedido.order_number),
        pedido.status ? badge(pedido.status.name, tonoEstado(pedido.status.category)) : null
      ),
      h(
        'div',
        { class: 'text-[12.5px] text-stone-500 mt-1' },
        [
          canal(pedido.channel),
          pedido.table_code ? `Mesa ${pedido.table_code}` : null,
          `${date(pedido.created_at)} ${time(pedido.created_at)}`,
        ]
          .filter(Boolean)
          .join(' · ')
      )
    ),
    h(
      'button',
      { class: 'text-stone-400 hover:text-stone-900 p-1 shrink-0', onClick: cerrar, 'aria-label': 'Cerrar' },
      icon('cerrar', { size: 20 })
    )
  );
}

function bloqueEntrega(entrega) {
  return section('Entrega', {
    body: h(
      'div',
      { class: 'space-y-1 text-[13.5px]' },
      h('div', { class: 'font-medium text-stone-900' }, entrega.address),
      h(
        'div',
        { class: 'text-stone-500 text-[12.5px]' },
        [
          entrega.zone_name ? `Zona ${entrega.zone_name}` : 'Sin zona',
          entrega.courier_name ? `Repartidor ${entrega.courier_name}` : 'Sin repartidor',
          entrega.dispatched_at ? `Salió ${time(entrega.dispatched_at)}` : null,
          entrega.delivered_at ? `Entregado ${time(entrega.delivered_at)}` : null,
        ]
          .filter(Boolean)
          .join(' · ')
      )
    ),
  });
}

function bloqueLineas(pedido) {
  return section('Productos', {
    list: pedido.items.map((i) =>
      h(
        'div',
        { class: 'fila items-start' },
        h(
          'span',
          { class: 'inline-flex items-center justify-center min-w-[24px] h-6 px-1.5 rounded-md bg-stone-100 text-[12.5px] font-semibold tabular-nums shrink-0' },
          i.quantity
        ),
        h(
          'div',
          { class: 'flex-1 min-w-0' },
          h('div', { class: 'text-[13.5px] text-stone-900' }, i.name_snapshot),
          i.modifiers.length
            ? h('div', { class: 'text-[12px] text-stone-500' }, i.modifiers.map((m) => m.name_snapshot).join(' · '))
            : null,
          i.notes ? h('div', { class: 'text-[12px] text-amber-800' }, i.notes) : null
        ),
        h('span', { class: 'text-[13.5px] tabular-nums' }, money(i.line_total))
      )
    ),
  });
}

function bloqueTotales(pedido) {
  const linea = (etiqueta, valor, fuerte) =>
    h(
      'div',
      { class: `flex justify-between ${fuerte ? 'pt-2 mt-1 border-t border-[--linea] font-semibold text-stone-900' : 'text-stone-500'}` },
      h('span', {}, etiqueta),
      h('span', { class: 'tabular-nums' }, valor)
    );

  return section('Totales', {
    body: h(
      'div',
      { class: 'text-[13.5px] space-y-1' },
      linea('Subtotal', money(pedido.subtotal)),
      Number(pedido.tax_total) ? linea('Impuesto', money(pedido.tax_total)) : null,
      Number(pedido.delivery_fee) ? linea('Domicilio', money(pedido.delivery_fee)) : null,
      Number(pedido.discount) ? linea('Descuento', `− ${money(pedido.discount)}`) : null,
      Number(pedido.tip) ? linea('Propina', money(pedido.tip)) : null,
      linea('Total', money(pedido.total), true),
      pedido.notes ? h('p', { class: 'text-[12.5px] text-stone-500 pt-2' }, pedido.notes) : null
    ),
  });
}

function bloquePagos(pedido, pagos) {
  const saldo = pedido.balance;
  return section('Pagos', {
    body: h(
      'div',
      { class: 'text-[13.5px] space-y-2' },
      pagos.length
        ? h(
            'div',
            { class: 'space-y-1' },
            pagos.map((p) =>
              h(
                'div',
                { class: 'flex justify-between items-center' },
                h(
                  'span',
                  { class: 'text-stone-600' },
                  metodoPago(p.method),
                  p.status !== 'paid' ? h('span', { class: 'ml-1.5' }, badge(p.status, 'warn')) : null
                ),
                h('span', { class: 'tabular-nums' }, money(p.amount))
              )
            )
          )
        : h('p', { class: 'text-stone-500' }, 'Sin cobros registrados.'),
      h(
        'div',
        { class: 'flex justify-between pt-2 border-t border-[--linea]' },
        h('span', { class: 'text-stone-600' }, saldo.is_settled ? 'Pagado' : 'Falta por cobrar'),
        saldo.is_settled
          ? badge('Saldado', 'ok', 'check')
          : h('span', { class: 'font-semibold text-amber-800 tabular-nums' }, money(saldo.pending))
      )
    ),
  });
}

/**
 * Los estados posibles los declara el backend según la máquina de estados
 * del restaurante; aquí no se decide ninguno ni se compara por código.
 */
function bloqueAvance(pedido, siguientes, recargar) {
  if (!siguientes.length) return null;

  return section('Mover el pedido', {
    body: h(
      'div',
      { class: 'flex flex-wrap gap-2' },
      siguientes.map((estado) =>
        button(estado.name, {
          variant: estado.category === 'cancelled' ? 'danger' : 'primary',
          iconName: estado.category === 'cancelled' ? 'cerrar' : 'check',
          onClick: async (event) => {
            event.currentTarget.disabled = true;
            try {
              await api.post(`/orders/${pedido.id}/status`, { to_status_id: estado.id });
              await recargar();
            } catch (error) {
              // Aquí aterrizan las reglas del backend: falta de permiso para
              // esa transición, o una que el tenant no configuró.
              toast(error.message);
              event.currentTarget.disabled = false;
            }
          },
        })
      )
    ),
  });
}

function bloqueBitacora(eventos) {
  return section('Bitácora', {
    list: eventos.length
      ? eventos.map((e) =>
          h(
            'div',
            { class: 'fila items-start' },
            h('span', { class: 'w-1.5 h-1.5 rounded-full bg-stone-300 mt-2 shrink-0' }),
            h(
              'div',
              { class: 'flex-1 min-w-0' },
              h(
                'div',
                { class: 'text-[13.5px] text-stone-900' },
                e.status.name,
                h('span', { class: 'text-stone-400 font-normal' }, ` · ${e.changed_by_name ?? 'el sistema'}`)
              ),
              e.note ? h('div', { class: 'text-[12px] text-stone-500' }, e.note) : null
            ),
            h('span', { class: 'text-[12px] text-stone-400 shrink-0' }, `${date(e.changed_at)} ${time(e.changed_at)}`)
          )
        )
      : [h('div', { class: 'p-4' }, empty('Sin movimientos', null, null, 'reloj'))],
  });
}
