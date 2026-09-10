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
  badge, button, empty, errorBox, h, input, montarDialogo, pageHeader, render, section, select,
  skeleton, toast,
} from '../ui.js';
import { abrirCancelacion } from './cancelar-pedido.js';
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

  // El cuadre de los repartidores vive en esta pantalla y no en la de caja:
  // es de quien despacha, y se hace cuando el repartidor vuelve.
  const cuadre = h('div');

  render(
    outlet,
    pageHeader('Domicilios', {
      hint: 'Las columnas son categorías de estado, no nombres: funcionan igual aunque tu restaurante los llame de otra forma.',
      actions: marca,
    }),
    cuadre,
    tablero
  );
  const cargarCuadre = () => pintarCuadre(cuadre);
  cargarCuadre();
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

/**
 * Cuánto debe traer cada repartidor.
 *
 * Bajo `cash.close`, el mismo permiso del arqueo: es el mismo control sobre
 * otra caja. Quien no lo tiene ni ve la sección — y no por esconder, sino
 * porque ver cuánto debería haber antes de contarlo es justo lo que ese
 * permiso separa.
 */
async function pintarCuadre(host) {
  if (!can('cash.close')) return;

  let datos;
  try {
    datos = await api.get(`/couriers/settlements${branchQuery()}`);
  } catch {
    // El cuadre es un añadido: si falla, el tablero sigue sirviendo.
    return render(host);
  }

  const pendientes = datos.couriers.filter((c) => Number(c.expected_cash) !== 0 || c.orders > 0);
  if (!pendientes.length) return render(host);

  render(
    host,
    section('Efectivo en la calle', {
      hint: 'Lo que cada repartidor debería traer, según los cobros en efectivo de sus pedidos.',
      list: pendientes.map((c) =>
        h(
          'div',
          { class: 'fila items-center' },
          h(
            'div',
            { class: 'flex-1 min-w-0' },
            h('div', { class: 'text-[13.5px] text-stone-900' }, c.courier_name ?? 'Sin nombre'),
            h(
              'div',
              { class: 'text-[12px] text-stone-500' },
              `${c.orders} pedido${c.orders === 1 ? '' : 's'}${
                c.from_at ? ` · desde el último cuadre` : ''
              }`
            )
          ),
          h('span', { class: 'text-[13.5px] tabular-nums font-medium' }, money(c.expected_cash)),
          button('Cuadrar', { variant: 'secondary', onClick: () => abrirCuadre(c, host) })
        )
      ),
    })
  );
}

/** El diálogo del cuadre: sus pedidos, lo que entrega y la diferencia. */
function abrirCuadre(courier, host) {
  const cuerpo = h('div', { class: 'p-5 space-y-4' });
  let desmontar;
  const cerrar = () => desmontar();

  const overlay = h(
    'div',
    {
      class: 'fixed inset-0 z-[60] bg-stone-900/30 flex items-center justify-center p-4',
      onClick: (e) => e.target === overlay && cerrar(),
    },
    h(
      'div',
      {
        class: 'aparece bg-white rounded-[--r-g] max-w-md w-full shadow-xl border border-[--linea] max-h-[85vh] overflow-y-auto',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-label': 'Cuadre del repartidor',
      },
      cuerpo
    )
  );

  desmontar = montarDialogo(overlay, { alCerrar: cerrar });
  render(cuerpo, h('p', { class: 'text-sm text-stone-500' }, 'Cargando sus pedidos…'));

  api
    .get(`/couriers/${courier.courier_id}/settlement${branchQuery()}`)
    .then((detalle) => {
      const entregado = input({
        type: 'number',
        min: '0',
        step: '1',
        class: 'campo tabular-nums',
        'aria-label': 'Efectivo que entrega',
      });
      const diferencia = h('p', { class: 'text-[13px] text-stone-500' });
      const nota = input({ placeholder: 'Nota (opcional)', maxlength: '255' });

      // La diferencia se muestra mientras se teclea, pero la que vale es la
      // que calcula el backend: aquí es una ayuda, no la fuente.
      const repintarDiferencia = () => {
        const valor = Number(entregado.value || 0) - Number(detalle.expected_cash);
        render(
          diferencia,
          entregado.value === ''
            ? 'Escribe cuánto entregó.'
            : valor === 0
              ? 'Cuadra exacto.'
              : valor > 0
                ? `Sobran ${money(valor)}.`
                : `Faltan ${money(-valor)}.`
        );
      };
      entregado.addEventListener('input', repintarDiferencia);
      repintarDiferencia();

      const guardar = button('Registrar cuadre', {
        onClick: async () => {
          guardar.disabled = true;
          try {
            const hecho = await api.post(`/couriers/${courier.courier_id}/settlement${branchQuery()}`, {
              counted_cash: Number(entregado.value || 0),
              note: nota.value.trim() || null,
            });
            cerrar();
            toast(hecho.summary, hecho.difference === '0.00' ? 'ok' : 'warn');
            await pintarCuadre(host);
          } catch (error) {
            toast(error.message);
            guardar.disabled = false;
          }
        },
      });

      render(
        cuerpo,
        h('h3', { class: 'text-[15px] font-semibold' }, `Cuadre de ${courier.courier_name ?? 'el repartidor'}`),
        detalle.orders.length
          ? h(
              'div',
              { class: 'space-y-1 max-h-56 overflow-y-auto' },
              detalle.orders.map((p) =>
                h(
                  'div',
                  { class: 'flex items-center justify-between text-[13px]' },
                  h('span', {}, p.order_number),
                  h('span', { class: 'tabular-nums' }, money(p.cash))
                )
              )
            )
          : h('p', { class: 'text-[13px] text-stone-500' }, 'Sin pedidos en efectivo pendientes.'),
        h(
          'div',
          { class: 'flex items-center justify-between text-[14px] font-medium border-t border-[--linea] pt-2' },
          h('span', {}, 'Debería traer'),
          h('span', { class: 'tabular-nums' }, money(detalle.expected_cash))
        ),
        entregado,
        diferencia,
        nota,
        h(
          'div',
          { class: 'flex justify-end gap-2' },
          button('Cancelar', { variant: 'secondary', onClick: cerrar }),
          guardar
        )
      );
    })
    .catch((error) => render(cuerpo, errorBox(error.message)));
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

  // Cómo va la promesa lo decide el backend (`Domain\DeliveryPromise`): el
  // tablero solo lo pinta. En rojo lo que ya se incumplió y en ámbar lo que
  // está por incumplirse, que es cuando todavía se puede hacer algo.
  const TONO_PROMESA = {
    late: 'border-red-300 bg-red-50',
    at_risk: 'border-amber-300 bg-amber-50',
  };
  const aviso = {
    // Recién pasada la hora todavía no hay minutos que decir, y un
    // "Tarde 0 min" no lo lee nadie.
    late: entrega.late_minutes ? `Tarde ${entrega.late_minutes} min` : 'Tarde',
    at_risk: 'Por incumplirse',
  }[entrega.promise_state];

  return h(
    'article',
    { class: `seccion p-4 space-y-3 ${TONO_PROMESA[entrega.promise_state] ?? ''}`.trim() },

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
          ? h(
              'div',
              { class: entrega.promise_state === 'late' ? 'text-red-700 font-medium' : 'text-stone-400' },
              `Promete ${time(entrega.estimated_time)}`
            )
          : null,
        aviso
          ? h(
              'div',
              {
                class: `font-semibold ${entrega.promise_state === 'late' ? 'text-red-700' : 'text-amber-700'}`,
              },
              aviso
            )
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
                if (estado.category === 'cancelled') {
                  return abrirCancelacion(pedido, estado, refrescar);
                }

                // `currentTarget` se guarda antes del primer `await`: el navegador lo deja
                // en null en cuanto termina el despacho del evento, y sin esto el `catch`
                // no podria volver a habilitar el boton.
                const boton = event.currentTarget;
                boton.disabled = true;
                try {
                  await api.post(`/orders/${pedido.id}/status`, { to_status_id: estado.id });
                  await refrescar();
                } catch (error) {
                  toast(error.message);
                  boton.disabled = false;
                }
              },
            })
          )
        )
      : null
  );
}
