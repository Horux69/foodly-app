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

import { api, uuid } from '../api.js';
import { billetesUtiles, date, falta, money, time, vuelto } from '../format.js';
import { icon } from '../icons.js';
import { branchQuery, can, me } from '../session.js';
import {
  badge, button, empty, errorBox, field, h, input, loading, montarDialogo, render, section, select,
  toast,
} from '../ui.js';
import { abrirCancelacion } from './cancelar-pedido.js';
import { canal } from './cocina.js';
import { imprimirComanda, imprimirTicket } from './impresion.js';
import { abrirDivision } from './dividir-cuenta.js';
import { abrirModificadores } from './modificadores-dialogo.js';

const METODOS = {
  cash: 'Efectivo',
  card: 'Tarjeta',
  transfer: 'Transferencia',
};

export const metodoPago = (code) => METODOS[code] ?? code;

/**
 * Lo que el restaurante sugiere de propina, sobre el subtotal.
 *
 * Vive aquí y no en cada pantalla porque lo usan dos —la fila de propina al
 * cobrar y la pre-cuenta que se lleva a la mesa— y decir dos cifras
 * distintas por la misma venta es una discusión con el cliente.
 */
export const propinaSugerida = (pedido) =>
  Math.floor((Number(pedido.subtotal) * (me()?.tip_percent ?? 10)) / 100);

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

  let desmontar;
  const cerrar = () => {
    desmontar();
    if (cambio) alCambiar?.();
  };

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

  desmontar = montarDialogo(overlay, { alCerrar: cerrar });
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
      // El documento fiscal se pide aparte y sin bloquear: un restaurante
      // que no lo use no debería ver un error por algo que no tiene.
      pedido.fiscal = (await api.get(`/orders/${orderId}/fiscal-document`).catch(() => null))?.document ?? null;
    } catch (error) {
      return render(cuerpo, errorBox(error.message, cargar));
    }

    const recargarTrasCambio = async () => {
      cambio = true;
      await cargar();
    };

    render(
      cuerpo,
      encabezado(pedido, cerrar),
      bloqueImpresion(pedido, pagos),
      pedido.delivery ? bloqueEntrega(pedido.delivery, pedido) : null,
      bloqueLineas(pedido, recargarTrasCambio),
      bloqueMesa(pedido, recargarTrasCambio),
      bloqueMesero(pedido, recargarTrasCambio),
      bloqueTotales(pedido, recargarTrasCambio),
      bloquePagos(pedido, pagos, recargarTrasCambio),
      bloqueFiscal(pedido, recargarTrasCambio),
      bloqueAvance(pedido, siguientes, recargarTrasCambio),
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

/**
 * Imprimir es una acción del pedido, no de la pantalla desde la que se abrió:
 * por eso vive en el panel, que se alcanza desde Pedidos, Cocina y
 * Domicilios.
 */
function bloqueImpresion(pedido, pagos) {
  return h(
    'div',
    { class: 'flex flex-wrap gap-2' },
    button('Comanda', {
      variant: 'secondary',
      iconName: 'cocina',
      onClick: () => imprimirComanda(pedido),
    }),
    button('Ticket', {
      variant: 'secondary',
      iconName: 'etiqueta',
      onClick: () => imprimirTicket(pedido, pagos),
    }),
    // La pre-cuenta se ofrece mientras quede saldo: con el pedido saldado
    // el documento que va a la mesa es el ticket, y dar a elegir entre los
    // dos ahí solo invita a entregar el que no es.
    pedido.balance?.is_settled
      ? null
      : button('Pre-cuenta', {
          variant: 'secondary',
          iconName: 'pedidos',
          onClick: () => imprimirTicket(pedido, pagos, { precuenta: true }),
        })
  );
}

function bloqueEntrega(entrega, pedido) {
  return section('Entrega', {
    // El enlace que se le manda al cliente. Se copia en vez de abrirse:
    // quien lo mira es él, por WhatsApp, y abrirlo aquí solo gastaría una
    // pestaña.
    actions: pedido.tracking_token
      ? button('Copiar enlace', {
          variant: 'secondary',
          onClick: async (e) => {
            const enlace = `${location.origin}/seguimiento.html?t=${pedido.tracking_token}`;
            try {
              await navigator.clipboard.writeText(enlace);
              toast('Enlace de seguimiento copiado', 'ok');
            } catch {
              // Sin permiso de portapapeles —o sin HTTPS— se muestra para
              // copiarlo a mano: peor sería no dar ninguna forma.
              e.currentTarget.replaceWith(
                h('input', { class: 'campo text-[12px]', value: enlace, readonly: '' })
              );
            }
          },
        })
      : null,
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

/**
 * Los productos del pedido, editables mientras siga en preparación (F4.0).
 *
 * Quién puede tocarlo lo dice el backend en `is_editable` —por categoría del
 * estado, no por su código— y el permiso `orders.edit`, que hasta ahora
 * estaba en el catálogo sin que nadie lo comprobara. Con la cocina ya
 * cocinando se avisa, pero no se impide: hay que poder quitar el plato que el
 * cliente canceló dos minutos después de pedirlo.
 */
function bloqueLineas(pedido, recargar) {
  const editable = pedido.is_editable && can('orders.edit');

  const cambiar = async (peticion, boton) => {
    boton.disabled = true;
    try {
      await peticion();
      await recargar();
    } catch (error) {
      toast(error.message);
      boton.disabled = false;
    }
  };

  const controles = (i) =>
    h(
      'div',
      { class: 'flex items-center gap-1 shrink-0' },
      button('−', {
        variant: 'subtle',
        title: `Quitar una unidad de ${i.name_snapshot}`,
        'aria-label': `Quitar una unidad de ${i.name_snapshot}`,
        disabled: i.quantity <= 1,
        onClick: (e) =>
          cambiar(
            () => api.patch(`/orders/${pedido.id}/items/${i.id}`, { quantity: i.quantity - 1 }),
            e.currentTarget
          ),
      }),
      button('+', {
        variant: 'subtle',
        title: `Agregar una unidad de ${i.name_snapshot}`,
        'aria-label': `Agregar una unidad de ${i.name_snapshot}`,
        onClick: (e) =>
          cambiar(
            () => api.patch(`/orders/${pedido.id}/items/${i.id}`, { quantity: i.quantity + 1 }),
            e.currentTarget
          ),
      }),
      button('Quitar', {
        variant: 'subtle',
        'aria-label': `Quitar ${i.name_snapshot} del pedido`,
        onClick: (e) => cambiar(() => api.delete(`/orders/${pedido.id}/items/${i.id}`), e.currentTarget),
      })
    );

  return section('Productos', {
    actions: editable
      ? button('Agregar', { variant: 'secondary', onClick: () => abrirAgregar(pedido, recargar) })
      : null,
    list: [
      editable && pedido.kitchen_has_it
        ? h(
            'div',
            { class: 'text-[12px] text-amber-800 bg-amber-50 rounded px-2 py-1.5' },
            'La cocina ya tiene este pedido: lo que cambies puede estar preparándose.'
          )
        : null,
      ...conTiempos(pedido, recargar, (i) =>
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
            i.components?.length
              ? h(
                  'div',
                  { class: 'text-[12px] text-stone-500' },
                  i.components.map((c) => `${c.quantity}× ${c.name_snapshot}`).join(' · ')
                )
              : null,
            i.modifiers.length
              ? h('div', { class: 'text-[12px] text-stone-500' }, i.modifiers.map((m) => m.name_snapshot).join(' · '))
              : null,
            i.notes ? h('div', { class: 'text-[12px] text-amber-800' }, i.notes) : null
          ),
          editable ? controles(i) : null,
          h('span', { class: 'text-[13.5px] tabular-nums' }, money(i.line_total))
        )
      ),
    ].filter(Boolean),
  });
}

/**
 * Las líneas agrupadas por tiempo, con la cabecera de cada uno.
 *
 * Sin tiempos configurados devuelve las líneas tal cual: es el caso de casi
 * todos los restaurantes y no tiene por qué costarles una etiqueta de más.
 * Qué se puede marchar lo decide el backend —`pending_courses` sale de
 * `Domain\KitchenTickets`—, aquí solo se pinta.
 */
function conTiempos(pedido, recargar, linea) {
  const tiempos = pedido.courses ?? [];
  if (!tiempos.length) return pedido.items.map(linea);

  const puedeMarchar = can('orders.edit') && pedido.is_server_assignable !== false;
  const numeros = [...new Set(pedido.items.map((i) => i.course ?? 1))].sort((a, b) => a - b);

  return numeros.flatMap((numero) => {
    const suyas = pedido.items.filter((i) => (i.course ?? 1) === numero);
    const salida = suyas.find((i) => i.fired_at)?.fired_at ?? null;
    const pendiente = suyas.some((i) => !i.fired_at);

    return [
      h(
        'div',
        { class: 'flex items-center justify-between gap-2 pt-1' },
        h(
          'span',
          { class: 'text-[11.5px] font-semibold uppercase tracking-wide text-stone-500' },
          tiempos[numero - 1] ?? `Tiempo ${numero}`
        ),
        pendiente
          ? puedeMarchar
            ? button('Marchar', {
                variant: 'secondary',
                onClick: (e) => marchar(pedido, numero, recargar, e.currentTarget),
              })
            : h('span', { class: 'text-[11px] text-amber-700' }, 'Sin marchar')
          : h('span', { class: 'text-[11px] text-stone-400' }, `Marchado ${time(salida)}`)
      ),
      ...suyas.map(linea),
    ];
  });
}

/**
 * Marchar un tiempo: lo manda a la cocina y saca su comanda.
 *
 * La comanda se imprime aquí y no en el backend porque se imprime con el
 * navegador (ver `impresion.js`), y sale sola: marchar sin que la comanda
 * salga dejaría el pedido "mandado" para el sistema y sin papel en la
 * cocina, que es la peor de las dos mentiras.
 */
async function marchar(pedido, numero, recargar, boton) {
  boton.disabled = true;
  try {
    await api.post(`/orders/${pedido.id}/fire`, { course: numero });
    // Se relee para imprimir: la respuesta del marchado no trae las
    // comandas repartidas por estación, y la comanda tiene que decir lo
    // mismo que el tablero.
    const fresco = await api.get(`/orders/${pedido.id}`);
    imprimirComanda(fresco, { curso: numero });
    toast('Marchado a la cocina', 'ok');
    await recargar();
  } catch (error) {
    toast(error.message);
    boton.disabled = false;
  }
}

/**
 * Elegir qué agregarle a una cuenta abierta.
 *
 * Se pide `/menu` —el de vender, con el precio efectivo de la sucursal— y no
 * el catálogo de administración: lo que se agrega entra al precio de hoy, que
 * es el mismo que cobraría el mostrador.
 */
function abrirAgregar(pedido, recargar) {
  const lista = h('div', { class: 'p-4 space-y-4' });
  let desmontar;
  const cerrar = () => desmontar();

  const agregar = async (item, modifierIds) => {
    try {
      await api.post(`/orders/${pedido.id}/items`, {
        items: [{ menu_item_id: item.id, quantity: 1, modifier_ids: modifierIds }],
      });
      cerrar();
      toast(`${item.name} agregado`, 'ok');
      await recargar();
    } catch (error) {
      toast(error.message);
    }
  };

  const overlay = h(
    'div',
    {
      class: 'fixed inset-0 z-[60] bg-stone-900/30 flex items-center justify-center p-4',
      onClick: (e) => e.target === overlay && cerrar(),
    },
    h(
      'div',
      {
        class: 'aparece bg-white rounded-[--r-g] max-w-md w-full shadow-xl border border-[--linea] max-h-[80vh] overflow-y-auto',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-label': 'Agregar productos al pedido',
      },
      lista
    )
  );

  desmontar = montarDialogo(overlay, { alCerrar: cerrar });
  render(lista, loading('Cargando la carta…'));

  api
    .get(`/menu${branchQuery()}`)
    .then((categorias) => {
      const productos = categorias.flatMap((c) => c.items.filter((i) => i.is_available));
      render(
        lista,
        h('h3', { class: 'text-[15px] font-semibold' }, `Agregar a ${pedido.order_number}`),
        productos.length
          ? h(
              'div',
              { class: 'divide-y divide-stone-100' },
              productos.map((item) =>
                h(
                  'button',
                  {
                    class: 'w-full text-left py-2.5 flex items-center gap-3 hover:bg-stone-50',
                    onClick: () =>
                      item.modifier_groups.length
                        ? abrirModificadores(item, (seleccion) => agregar(item, seleccion))
                        : agregar(item, []),
                  },
                  h(
                    'span',
                    { class: 'flex-1 min-w-0' },
                    h('span', { class: 'text-sm text-stone-900' }, item.name),
                    item.components?.length
                      ? h(
                          'span',
                          { class: 'block text-[11px] text-stone-500' },
                          item.components.map((c) => `${c.quantity}× ${c.name}`).join(' · ')
                        )
                      : null
                  ),
                  h('span', { class: 'text-sm tabular-nums text-amber-800' }, money(item.price))
                )
              )
            )
          : empty('No hay nada disponible', 'Todos los productos están agotados en esta sucursal.'),
        h('div', { class: 'flex justify-end pt-2' }, button('Cerrar', { variant: 'secondary', onClick: cerrar }))
      );
    })
    .catch((error) => render(lista, errorBox(error.message)));
}

/**
 * La mesa de la cuenta: moverla o unirle otra.
 *
 * Las dos operaciones mueven plata de sitio sin cobrarla, que es donde más
 * fácil es que desaparezca, así que las dos quedan en la bitácora. Unir pide
 * elegir de qué mesa viene la otra cuenta, no un número de pedido: quien
 * atiende piensa en mesas.
 */
function bloqueMesa(pedido, recargar) {
  if (!me().uses_tables || !pedido.is_editable || !can('orders.edit')) return null;

  return section('Mesa', {
    body: h(
      'div',
      { class: 'flex flex-wrap items-center gap-2 text-[13.5px]' },
      h('span', { class: 'text-stone-500' }, pedido.table_code ? `Mesa ${pedido.table_code}` : 'Sin mesa'),
      button('Mover', { variant: 'secondary', onClick: () => abrirMover(pedido, recargar) }),
      button('Unir otra cuenta', { variant: 'secondary', onClick: () => abrirUnir(pedido, recargar) })
    ),
  });
}

/**
 * Quién atiende la cuenta.
 *
 * Se ve aunque no se pueda cambiar: saber de quién es la mesa es la mitad
 * del valor, y esconderlo a quien no tiene el permiso dejaría al mesero sin
 * poder comprobar que su venta quedó a su nombre. Quien decide si todavía se
 * puede cambiar es el backend (`is_server_assignable`), no esta pantalla.
 */
function bloqueMesero(pedido, recargar) {
  if (!me().uses_tables) return null;

  const puede = can('orders.assign_server') && pedido.is_server_assignable;
  if (!puede && !pedido.server_name) return null;

  return section('Mesero', {
    body: h(
      'div',
      { class: 'flex flex-wrap items-center gap-2 text-[13.5px]' },
      h(
        'span',
        { class: pedido.server_name ? 'font-medium' : 'text-stone-500' },
        pedido.server_name ?? 'Sin mesero a cargo'
      ),
      puede
        ? button(pedido.server_name ? 'Cambiar' : 'Asignar', {
            variant: 'secondary',
            onClick: () => abrirMeseros(pedido, recargar),
          })
        : null
    ),
  });
}

/** Elegir entre quienes pueden tomar pedidos: la lista la arma el backend. */
function abrirMeseros(pedido, recargar) {
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
        class: 'aparece bg-white rounded-[--r-g] max-w-sm w-full shadow-xl border border-[--linea] max-h-[80vh] overflow-y-auto',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-label': 'Mesero a cargo',
      },
      cuerpo
    )
  );

  desmontar = montarDialogo(overlay, { alCerrar: cerrar });
  render(cuerpo, loading('Cargando el equipo…'));

  const asignar = async (serverId) => {
    try {
      await api.put(`/orders/${pedido.id}/server`, { server_id: serverId });
      cerrar();
      toast('Mesero actualizado', 'ok');
      await recargar();
    } catch (error) {
      toast(error.message);
    }
  };

  api
    .get('/servers')
    .then((gente) => {
      render(
        cuerpo,
        h('h3', { class: 'text-[15px] font-semibold' }, `Mesero de ${pedido.order_number}`),
        h('p', { class: 'text-[13px] text-stone-500' }, 'Queda en la bitácora y en el reporte por mesero.'),
        gente.length
          ? h(
              'div',
              { class: 'grid gap-2' },
              gente.map((persona) =>
                button(persona.name, {
                  variant: persona.id === pedido.server_id ? 'primary' : 'secondary',
                  onClick: () => asignar(persona.id),
                })
              )
            )
          : empty(
              'Nadie puede atender mesas',
              'Ningún rol tiene el permiso de crear pedidos.',
              null,
              'clientes'
            ),
        // Dejarla sin mesero es una elección válida —una mesa que atiende
        // quien pase— y no un campo que se olvidó.
        pedido.server_id
          ? button('Dejar sin mesero', { variant: 'secondary', onClick: () => asignar(null) })
          : null,
        h('div', { class: 'flex justify-end' }, button('Cancelar', { variant: 'secondary', onClick: cerrar }))
      );
    })
    .catch((error) => render(cuerpo, errorBox(error.message)));
}

/** Elegir mesa entre las del salón, no escribir un código de memoria. */
function abrirMesas({ titulo, ayuda, etiqueta, filtrar, alElegir, alTerminar }) {
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
        class: 'aparece bg-white rounded-[--r-g] max-w-sm w-full shadow-xl border border-[--linea] max-h-[80vh] overflow-y-auto',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-label': etiqueta,
      },
      cuerpo
    )
  );

  desmontar = montarDialogo(overlay, { alCerrar: cerrar });
  render(cuerpo, loading('Cargando el salón…'));

  api
    .get(`/branches/${me().branch_id}/tables/status`)
    .then((mesas) => {
      const candidatas = mesas.filter((m) => m.is_active && filtrar(m));
      render(
        cuerpo,
        h('h3', { class: 'text-[15px] font-semibold' }, titulo),
        h('p', { class: 'text-[13px] text-stone-500' }, ayuda),
        candidatas.length
          ? h(
              'div',
              { class: 'grid grid-cols-3 gap-2' },
              candidatas.map((mesa) =>
                button(mesa.code, {
                  variant: 'secondary',
                  onClick: async () => {
                    try {
                      await alElegir(mesa);
                      cerrar();
                      await alTerminar();
                    } catch (error) {
                      toast(error.message);
                    }
                  },
                })
              )
            )
          : empty('No hay mesas para esto', 'Revisa el salón.', null, 'mesa'),
        h('div', { class: 'flex justify-end' }, button('Cancelar', { variant: 'secondary', onClick: cerrar }))
      );
    })
    .catch((error) => render(cuerpo, errorBox(error.message)));
}

const abrirMover = (pedido, recargar) =>
  abrirMesas({
    titulo: `Mover ${pedido.order_number}`,
    ayuda: 'La cuenta se pasa a la mesa que elijas y queda en la bitácora.',
    etiqueta: 'Mover de mesa',
    filtrar: (mesa) => mesa.code !== pedido.table_code,
    alElegir: (mesa) => api.put(`/orders/${pedido.id}/table`, { table_code: mesa.code }),
    alTerminar: async () => {
      toast('Cuenta movida', 'ok');
      await recargar();
    },
  });

const abrirUnir = (pedido, recargar) =>
  abrirMesas({
    titulo: `Unir otra cuenta a ${pedido.order_number}`,
    ayuda: 'Las líneas pasan a esta cuenta y la otra se cierra. Si ya tiene cobros, primero se reembolsan.',
    etiqueta: 'Unir cuenta',
    // Solo las que tienen cuenta abierta, y no la propia.
    filtrar: (mesa) => mesa.order_id !== null && mesa.order_id !== pedido.id,
    alElegir: (mesa) => api.post(`/orders/${pedido.id}/merge`, { source_order_id: mesa.order_id }),
    alTerminar: async () => {
      toast('Cuentas unidas', 'ok');
      await recargar();
    },
  });

/**
 * El documento fiscal de la venta.
 *
 * Solo aparece con el pedido saldado: el documento dice cuánto se cobró, y
 * emitirlo antes es prometer una cifra que todavía puede cambiar. Si el
 * restaurante no tiene resolución configurada, el botón lo dice al pulsarlo
 * en vez de esconderse — esconderlo dejaría a alguien buscando por qué no
 * puede facturar.
 */
function bloqueFiscal(pedido, recargar) {
  if (!pedido.balance.is_settled && !pedido.fiscal) return null;
  if (!can('payments.register') && !pedido.fiscal) return null;

  const emitir = button('Emitir documento', {
    onClick: async () => {
      emitir.disabled = true;
      try {
        await api.post(`/orders/${pedido.id}/fiscal-document`, {});
        toast('Documento emitido', 'ok');
        await recargar();
      } catch (error) {
        toast(error.message);
        emitir.disabled = false;
      }
    },
  });

  if (!pedido.fiscal) {
    return section('Documento', { body: h('div', { class: 'flex items-center gap-2' }, emitir) });
  }

  const doc = pedido.fiscal;
  const tono = doc.status === 'accepted' ? 'ok' : doc.status === 'rejected' ? 'danger' : 'warn';
  const etiqueta = {
    accepted: 'Aceptado',
    contingency: 'Sin transmitir',
    rejected: 'Rechazado',
  }[doc.status] ?? doc.status;

  return section('Documento', {
    body: h(
      'div',
      { class: 'flex flex-wrap items-center gap-x-4 gap-y-1 text-[13.5px]' },
      h('span', { class: 'font-semibold tabular-nums' }, doc.full_number),
      badge(etiqueta, tono),
      doc.external_id ? h('span', { class: 'text-[12px] text-stone-500 break-all' }, doc.external_id) : null
    ),
  });
}

function bloqueTotales(pedido, recargar) {
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
    actions:
      pedido.is_editable && can('orders.discount')
        ? button(Number(pedido.discount) ? 'Cambiar descuento' : 'Descuento', {
            variant: 'secondary',
            onClick: () => abrirDescuento(pedido, recargar),
          })
        : null,
  });
}

/**
 * Aplicar o quitar el descuento de una cuenta abierta.
 *
 * El motivo sale del catálogo del restaurante y es obligatorio: es lo que
 * hace que el reporte de ajustes pueda responder en qué se fue la plata. El
 * tope por rol lo impone `Domain\DiscountRules`; aquí solo se muestra su
 * mensaje cuando lo rechaza.
 */
function abrirDescuento(pedido, recargar) {
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
        class: 'aparece bg-white rounded-[--r-g] max-w-sm w-full shadow-xl border border-[--linea]',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-label': 'Descuento del pedido',
      },
      cuerpo
    )
  );

  desmontar = montarDialogo(overlay, { alCerrar: cerrar });
  render(cuerpo, loading('Cargando motivos…'));

  api
    .get('/discount-reasons')
    .then((motivos) => {
      const importe = input({
        type: 'number',
        min: '0',
        value: Number(pedido.discount) || '',
        class: 'campo w-full tabular-nums',
        'aria-label': 'Importe del descuento',
      });
      const motivo = select(
        [{ value: '', label: 'Elige el motivo' }, ...motivos.map((m) => ({ value: m.id, label: m.name }))],
        { class: 'campo w-full', 'aria-label': 'Motivo del descuento' }
      );
      const aviso = h('div');

      const guardar = button('Aplicar', {
        onClick: async () => {
          render(aviso);
          guardar.disabled = true;
          try {
            await api.put(`/orders/${pedido.id}/discount`, {
              amount: Number(importe.value || 0),
              reason_id: motivo.value || null,
            });
            cerrar();
            toast('Descuento aplicado', 'ok');
            await recargar();
          } catch (error) {
            render(aviso, errorBox(error.message));
            guardar.disabled = false;
          }
        },
      });

      render(
        cuerpo,
        h('h3', { class: 'text-[15px] font-semibold' }, `Descuento de ${pedido.order_number}`),
        h(
          'p',
          { class: 'text-[13px] text-stone-500' },
          `Sobre un subtotal de ${money(pedido.subtotal)}. Queda en la bitácora con tu nombre.`
        ),
        field('Importe', importe),
        field('Motivo', motivo),
        aviso,
        h(
          'div',
          { class: 'flex justify-between gap-2 pt-1' },
          Number(pedido.discount)
            ? button('Quitarlo', {
                variant: 'subtle',
                onClick: async () => {
                  try {
                    await api.put(`/orders/${pedido.id}/discount`, { amount: 0, reason_id: null });
                    cerrar();
                    await recargar();
                  } catch (error) {
                    render(aviso, errorBox(error.message));
                  }
                },
              })
            : h('span'),
          h('div', { class: 'flex gap-2' }, button('Cancelar', { variant: 'secondary', onClick: cerrar }), guardar)
        )
      );
    })
    .catch((error) => render(cuerpo, errorBox(error.message)));
}

/**
 * Cobrar: cuánto y con qué.
 *
 * Antes la web solo sabía cobrar el saldo completo con un método —mandaba
 * `amount: saldo.pending` y ya—, así que una cuenta que se paga mitad en
 * efectivo y mitad con tarjeta no se podía asentar. El backend aceptaba
 * pagos parciales desde el principio: `Domain\PaymentBalance` suma lo
 * cobrado y resta contra el total, sin exigir que un pago lo cubra entero.
 *
 * El monto arranca en lo que falta, que es el caso común; cambiarlo es lo
 * que lo vuelve un cobro parcial.
 */
function formularioCobro(pedido, recargar) {
  const saldo = pedido.balance;
  const metodos = me().payment_methods ?? [];
  if (saldo.is_settled || !can('payments.register') || !metodos.length) return null;

  // Una llave por formulario pintado: si la respuesta se pierde y el cajero
  // vuelve a tocar, el backend reconoce la llave y devuelve el cobro que ya
  // registró en vez de cobrar dos veces.
  const llave = uuid();

  const monto = input({
    type: 'number',
    min: '0',
    step: '0.01',
    value: saldo.pending,
    class: 'campo w-32 tabular-nums',
    'aria-label': 'Monto a cobrar',
    oninput: () => pintarVuelto(),
  });

  const metodo = select(
    metodos.map((m) => ({ value: m, label: metodoPago(m) })),
    {
      class: 'campo w-auto',
      'aria-label': 'Método de pago',
      onChange: () => pintarVuelto(),
    }
  );

  // Con cuánto paga el cliente. Solo en efectivo: con tarjeta no hay vuelto
  // que dar, y un campo que nunca se usa estorba en la pantalla que más
  // prisa tiene.
  const recibido = input({
    type: 'number',
    min: '0',
    step: '0.01',
    placeholder: 'Con cuánto paga',
    class: 'campo w-36 tabular-nums',
    'aria-label': 'Con cuánto paga',
    oninput: () => pintarVuelto(),
  });

  const bloqueVuelto = h('div', { class: 'space-y-2' });

  /**
   * El vuelto, en grande.
   *
   * No se guarda en ninguna parte: no es un cobro —eso ya se registró
   * entero— sino plata que sale del cajón por ese cobro. Registrarlo lo
   * contaría dos veces en el arqueo, y como cobro es justo lo que
   * `Domain\ChargeRules` impide.
   */
  function pintarVuelto() {
    if (metodo.value !== 'cash') return render(bloqueVuelto);

    const aCobrar = Number(monto.value || 0);
    const entregado = Number(recibido.value || 0);
    const devolver = vuelto(entregado, aCobrar);
    const pendiente = falta(entregado, aCobrar);

    render(
      bloqueVuelto,
      h(
        'div',
        { class: 'flex flex-wrap items-center gap-2' },
        recibido,
        // Atajos con los billetes que alcanzan: en el mostrador se teclea
        // menos y se equivoca menos.
        billetesUtiles(aCobrar).map((billete) =>
          button(money(billete), {
            variant: 'subtle',
            onClick: () => {
              recibido.value = String(billete);
              pintarVuelto();
            },
          })
        )
      ),
      entregado
        ? h(
            'div',
            {
              class: `text-[15px] font-semibold ${pendiente ? 'text-amber-700' : 'text-stone-900'}`,
              'aria-live': 'polite',
            },
            devolver
              ? `Vuelto ${money(devolver)}`
              : pendiente
                ? `Faltan ${money(pendiente)} para cubrir este cobro`
                : 'Sin vuelto: pagó justo'
          )
        : null
    );
  }

  const cobrar = button('Cobrar', {
    iconName: 'dinero',
    onClick: async () => {
      const importe = Number(monto.value);
      if (!(importe > 0)) return toast('El monto debe ser mayor que cero');
      // El vuelto no viaja: lo que se cobra es lo que se cobra.

      cobrar.disabled = true;
      try {
        await api.post(`/orders/${pedido.id}/payments`, {
          method: metodo.value,
          amount: importe,
          idempotency_key: llave,
        });
        toast('Cobro registrado', 'ok');
        await recargar();
      } catch (error) {
        toast(error.message);
        cobrar.disabled = false;
      }
    },
  });

  // El efectivo suele venir preseleccionado: se pinta al montar para que
  // los atajos de billetes estén ahí sin tocar nada.
  pintarVuelto();

  return h(
    'div',
    { class: 'space-y-2 pt-3 mt-1 border-t border-[--linea]' },
    filaPropina(pedido, recargar),
    h(
    'div',
    { class: 'flex flex-wrap items-center gap-2' },
    monto,
    metodo,
    cobrar,
    // Repartir la cuenta es cobrar varias veces contra el mismo saldo, así
    // que su sitio natural es junto al cobro y no en otra pantalla.
    button('Dividir', {
      variant: 'secondary',
      iconName: 'clientes',
      onClick: () => abrirDivision(pedido, recargar),
    })
    ),
    bloqueVuelto
  );
}

/**
 * La propina, que se decide al pagar y no al pedir.
 *
 * Se ofrece el porcentaje que el restaurante configuró y se puede quitar de
 * un toque: en Colombia es voluntaria, y una propina que cuesta discutir es
 * la forma más rápida de perder un cliente. Al fijarla sube el total, así
 * que el saldo por cobrar la incluye — el cajero cobra una sola vez.
 */
function filaPropina(pedido, recargar) {
  const contexto = me();
  if (!contexto.asks_tip) return null;

  const sugerida = propinaSugerida(pedido);
  const puesta = Number(pedido.tip);

  const fijar = async (cents, boton) => {
    boton.disabled = true;
    try {
      await api.put(`/orders/${pedido.id}/tip`, { amount: cents });
      await recargar();
    } catch (error) {
      toast(error.message);
      boton.disabled = false;
    }
  };

  const otra = input({
    type: 'number',
    min: '0',
    placeholder: 'Otra',
    class: 'campo w-24 tabular-nums',
    'aria-label': 'Otra propina',
  });

  return h(
    'div',
    { class: 'flex flex-wrap items-center gap-2 text-[13px]' },
    h('span', { class: 'text-stone-500' }, puesta ? `Propina ${money(puesta)}` : 'Propina'),
    sugerida && puesta !== sugerida
      ? button(`${contexto.tip_percent ?? 10}% · ${money(sugerida)}`, {
          variant: 'secondary',
          'aria-label': 'Propina sugerida',
          onClick: (e) => fijar(sugerida, e.currentTarget),
        })
      : null,
    otra,
    button('Poner', {
      variant: 'secondary',
      'aria-label': 'Poner otra propina',
      onClick: (e) => fijar(Number(otra.value || 0), e.currentTarget),
    }),
    puesta
      ? button('Sin propina', {
          variant: 'subtle',
          onClick: (e) => fijar(0, e.currentTarget),
        })
      : null
  );
}

/**
 * El libro de caja del pedido: lo que entró y lo que salió.
 *
 * Un reembolso no borra ni edita el cobro: es una fila que apunta a él. Por
 * eso aquí se ven las dos, y el cobro revertido queda tachado en vez de
 * desaparecer — la caja necesita saber que ese cobro existió.
 */
function bloquePagos(pedido, pagos, recargar) {
  const saldo = pedido.balance;
  const cobros = pagos.filter((p) => !p.refund_of_payment_id);
  const devoluciones = pagos.filter((p) => p.refund_of_payment_id);

  const devueltoDe = (cobroId) =>
    devoluciones
      .filter((r) => r.refund_of_payment_id === cobroId && r.status === 'paid')
      .reduce((suma, r) => suma + Number(r.amount), 0);

  return section('Pagos', {
    body: h(
      'div',
      { class: 'text-[13.5px] space-y-2' },
      cobros.length
        ? h('div', { class: 'space-y-1' }, cobros.map((p) => filaCobro(pedido, p, devueltoDe(p.id), recargar)))
        : h('p', { class: 'text-stone-500' }, 'Sin cobros registrados.'),

      devoluciones.length
        ? h(
            'div',
            { class: 'space-y-1 pt-2 border-t border-[--linea]' },
            devoluciones.map((r) =>
              h(
                'div',
                { class: 'flex justify-between items-start gap-2' },
                h(
                  'div',
                  { class: 'min-w-0' },
                  h('span', { class: 'text-stone-600' }, `Reembolso · ${metodoPago(r.method)}`),
                  r.note ? h('div', { class: 'text-[12px] text-stone-500' }, r.note) : null
                ),
                h('span', { class: 'tabular-nums text-red-700 shrink-0' }, `− ${money(r.amount)}`)
              )
            )
          )
        : null,

      h(
        'div',
        { class: 'flex justify-between pt-2 border-t border-[--linea]' },
        h('span', { class: 'text-stone-600' }, saldo.is_settled ? 'Pagado' : 'Falta por cobrar'),
        saldo.is_settled
          ? badge('Saldado', 'ok', 'check')
          : h('span', { class: 'font-semibold text-amber-800 tabular-nums' }, money(saldo.pending))
      ),
      formularioCobro(pedido, recargar)
    ),
  });
}

function filaCobro(pedido, cobro, devuelto, recargar) {
  const revertido = cobro.status === 'refunded';
  const puedeDevolver = can('payments.refund') && cobro.status === 'paid' && devuelto < Number(cobro.amount);

  return h(
    'div',
    { class: 'flex justify-between items-center gap-2' },
    h(
      'span',
      { class: `text-stone-600 ${revertido ? 'line-through text-stone-400' : ''}` },
      metodoPago(cobro.method),
      revertido ? h('span', { class: 'ml-1.5' }, badge('Reembolsado', 'danger')) : null,
      !revertido && devuelto ? h('span', { class: 'ml-1.5 text-[12px] text-stone-500' }, `(devuelto ${money(devuelto)})`) : null
    ),
    h(
      'span',
      { class: 'flex items-center gap-2 shrink-0' },
      h('span', { class: `tabular-nums ${revertido ? 'line-through text-stone-400' : ''}` }, money(cobro.amount)),
      puedeDevolver
        ? button('Reembolsar', { variant: 'subtle', onClick: () => pedirReembolso(pedido, cobro, devuelto, recargar) })
        : null
    )
  );
}

/**
 * Un reembolso sin motivo no es historia, es un número suelto: por eso el
 * motivo se pide aquí y viaja a `payments.note`.
 */
function pedirReembolso(pedido, cobro, devuelto, recargar) {
  const restante = (Number(cobro.amount) - devuelto).toFixed(2);

  const monto = input({
    type: 'number',
    min: '0',
    step: '0.01',
    value: restante,
    class: 'campo tabular-nums',
    'aria-label': 'Monto a reembolsar',
  });
  const motivo = input({ placeholder: 'Por qué se devuelve', maxlength: '255', 'aria-label': 'Motivo' });

  let desmontar;
  const cerrar = () => desmontar();

  const confirmar = button('Reembolsar', {
    variant: 'danger',
    onClick: async () => {
      confirmar.disabled = true;
      try {
        await api.post(`/orders/${pedido.id}/payments/${cobro.id}/refund`, {
          amount: Number(monto.value),
          note: motivo.value.trim() || null,
        });
        cerrar();
        toast('Reembolso registrado', 'ok');
        await recargar();
      } catch (error) {
        toast(error.message);
        confirmar.disabled = false;
      }
    },
  });

  const overlay = h(
    'div',
    {
      class: 'fixed inset-0 z-[60] bg-stone-900/30 flex items-center justify-center p-4',
      onClick: (e) => e.target === overlay && cerrar(),
    },
    h(
      'div',
      { class: 'aparece bg-white rounded-[--r-g] max-w-sm w-full p-5 shadow-xl border border-[--linea]', role: 'dialog', 'aria-modal': 'true' },
      h('h3', { class: 'text-[15px] font-semibold' }, `Reembolsar ${metodoPago(cobro.method)}`),
      h(
        'p',
        { class: 'text-[13px] text-stone-600 mt-1.5 leading-relaxed' },
        `De este cobro de ${money(cobro.amount)} quedan ${money(restante)} por devolver. Se devuelve por el mismo medio y el cobro no se borra: queda registrado con su reembolso.`
      ),
      h('div', { class: 'space-y-2 mt-4' }, monto, motivo),
      h('div', { class: 'flex justify-end gap-2 mt-5' }, button('Cancelar', { variant: 'secondary', onClick: cerrar }), confirmar)
    )
  );

  desmontar = montarDialogo(overlay, { alCerrar: cerrar });
  monto.focus();
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
            // Anular pide motivo y avisa si hay plata encima: por eso pasa
            // por su propio diálogo en vez de avanzar directo.
            if (estado.category === 'cancelled') {
              return abrirCancelacion(pedido, estado, recargar);
            }

            // `currentTarget` se guarda antes del primer `await`: el navegador lo deja
            // en null en cuanto termina el despacho del evento, y sin esto el `catch`
            // no podria volver a habilitar el boton.
            const boton = event.currentTarget;
            boton.disabled = true;
            try {
              await api.post(`/orders/${pedido.id}/status`, { to_status_id: estado.id });
              await recargar();
            } catch (error) {
              // Aquí aterrizan las reglas del backend: falta de permiso para
              // esa transición, o una que el tenant no configuró.
              toast(error.message);
              boton.disabled = false;
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
