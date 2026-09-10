// Reportes del dueño.
//
// Una sola serie por gráfico: todo lo que se mide aquí es una magnitud
// (dinero o conteo), no identidades. Por eso va un solo tono, sin leyenda que
// repita el título, los valores en tinta y no en el color del dato, y una
// tabla para lo que el ojo no deba estimar.

import { api, query } from '../api.js';
import { date as fecha, isoDate, money, number, time } from '../format.js';
import { branches, me } from '../session.js';
import { badge, button, card, errorBox, h, pageHeader, render, skeleton, tabs, toast } from '../ui.js';
import { canal } from './cocina.js';
import { metodoPago } from './pedido-detalle.js';

const SERIE = '#2a78d6'; // validado para contraste sobre superficie blanca
const RANGOS = [
  { etiqueta: '7 días', dias: 6 },
  { etiqueta: '30 días', dias: 29 },
  { etiqueta: '90 días', dias: 89 },
];

export async function reportes(outlet) {
  const desde = h('input', { type: 'date', class: 'rounded-lg border border-stone-300 px-2 py-1.5 text-sm' });
  const hasta = h('input', { type: 'date', class: 'rounded-lg border border-stone-300 px-2 py-1.5 text-sm' });
  const sucursal = h('select', { class: 'rounded-lg border border-stone-300 px-2 py-1.5 text-sm' });
  const contenido = h('div', { class: 'space-y-4' });
  const pestanas = h('div');
  // Dos maneras de mirar el mismo período: la venta (qué se vendió) y el
  // cierre (qué plata se movió). No son el mismo número y no deberían
  // parecerlo, así que van en vistas separadas.
  let vista = 'venta';

  function aplicarRango(dias) {
    const fin = new Date();
    const inicio = new Date();
    inicio.setDate(fin.getDate() - dias);
    desde.value = isoDate(inicio);
    hasta.value = isoDate(fin);
    cargar();
  }

  // Un dueño no lee "vendí 4 millones", lee "vendí 12% más que la semana
  // pasada". El período anterior lo calcula `Domain\PeriodComparison`: la
  // misma cantidad de días, terminando justo antes.
  const comparar = h('input', {
    type: 'checkbox',
    class: 'w-4 h-4 rounded border-stone-300',
    checked: true,
    onChange: () => cargar(),
  });

  const filtros = card(
    h(
      'div',
      { class: 'flex flex-wrap items-end gap-3' },
      h(
        'div',
        { class: 'flex gap-1' },
        RANGOS.map((r) => button(r.etiqueta, { variant: 'secondary', onClick: () => aplicarRango(r.dias) }))
      ),
      etiqueta('Desde', desde),
      etiqueta('Hasta', hasta),
      branches().length > 1 ? etiqueta('Sucursal', sucursal) : null,
      h(
        'label',
        { class: 'flex items-center gap-2 text-[13px] text-stone-600 pb-2' },
        comparar,
        'Comparar con el período anterior'
      ),
      button('Aplicar', { onClick: () => cargar() })
    )
  );

  /**
   * Descarga el reporte que se está mirando.
   *
   * Lo arma el servidor y no el navegador: las listas de ajustes en pantalla
   * están recortadas a 200 filas, así que un CSV hecho con lo que se ve
   * exportaría eso sin que nadie lo note.
   */
  function descargar(reporte) {
    return button('Descargar CSV', {
      variant: 'secondary',
      iconName: 'archivar',
      onClick: async (e) => {
        const boton = e.currentTarget;
        boton.disabled = true;
        try {
          await api.download(
            `/reports/export${query({
              report: reporte,
              from_date: desde.value,
              to_date: hasta.value,
              branch_id: sucursal.value,
            })}`,
            `${reporte}.csv`
          );
        } catch (error) {
          toast(error.message);
        } finally {
          boton.disabled = false;
        }
      },
    });
  }

  function pintarPestanas() {
    render(
      pestanas,
      tabs(
        [
          { key: 'venta', label: 'Venta' },
          { key: 'cierre', label: 'Cierre' },
        ],
        vista,
        (clave) => {
          vista = clave;
          pintarPestanas();
          cargar();
        }
      )
    );
  }

  render(
    outlet,
    pageHeader('Reportes', { hint: 'La venta cuenta pedidos completados; el cierre sigue la plata que se movió.' }),
    h('div', { class: 'space-y-4' }, filtros, pestanas, contenido)
  );

  // Aquí "ninguna" sí significa algo —toda la empresa—, así que este
  // selector no es el de la barra lateral y arranca en vacío a propósito.
  render(
    sucursal,
    h('option', { value: '' }, 'Todas las sucursales'),
    branches().map((b) => h('option', { value: b.id }, b.name))
  );

  async function cargar() {
    render(contenido, skeleton({ rows: 3 }));
    const qs = query({ from_date: desde.value, to_date: hasta.value, branch_id: sucursal.value });

    try {
      if (vista === 'venta') {
        const [ventas, productos, tiempos, horas, promesa, porOrigen] = await Promise.all([
          api.get(`/reports/sales${query({ from_date: desde.value, to_date: hasta.value, branch_id: sucursal.value, compare: comparar.checked ? 'true' : '' })}`),
          api.get(`/reports/top-products${qs}`),
          api.get(`/reports/prep-times${qs}`),
          api.get(`/reports/peak-hours${qs}`),
          api.get(`/reports/delivery-promise${qs}`),
          api.get(`/reports/sales-by-source${qs}`),
        ]);
        render(contenido, panel(ventas, productos, tiempos, horas, promesa, porOrigen, descargar));
      } else {
        // Por mesero solo donde hay mesas: en un mostrador diría lo mismo
        // que "por usuario" con otro título, que es ruido y no información.
        const [ingresos, porUsuario, porMesero, ajustes] = await Promise.all([
          api.get(`/reports/payment-methods${qs}`),
          api.get(`/reports/sales-by-user${qs}`),
          me()?.uses_tables ? api.get(`/reports/sales-by-server${qs}`) : [],
          api.get(`/reports/adjustments${qs}`),
        ]);
        render(contenido, panelCierre(ingresos, porUsuario, porMesero, ajustes, descargar));
      }
    } catch (error) {
      render(contenido, errorBox(error.message, cargar));
    }
  }

  pintarPestanas();
  // aplicarRango deja las fechas puestas y dispara la primera carga.
  aplicarRango(29);
}

const etiqueta = (texto, control) =>
  h('label', { class: 'block' }, h('span', { class: 'block text-xs text-stone-600 mb-1' }, texto), control);

function panel(ventas, productos, tiempos, horas, promesa, porOrigen, descargar) {
  const t = ventas.totals;
  const minutos = (v) => (v === null || v === undefined ? 'sin datos' : `${Number(v).toFixed(0)} min`);

  return [
    // Un solo número protagonista por vista.
    card(
      h(
        'div',
        { class: 'flex items-start justify-between gap-3' },
        h('div', { class: 'text-sm text-stone-600' }, 'Ingresos del período'),
        descargar('sales')
      ),
      h(
        'div',
        { class: 'flex items-baseline gap-3 flex-wrap mt-1' },
        h('div', { class: 'text-5xl font-semibold text-stone-900' }, money(t.revenue)),
        variacion(ventas.previous?.change.revenue)
      ),
      h(
        'div',
        { class: 'text-sm text-stone-500 mt-2' },
        `${ventas.from_date} a ${ventas.to_date} · solo pedidos completados`,
        ventas.previous
          ? h(
              'span',
              {},
              ` · antes ${money(ventas.previous.totals.revenue)} (${ventas.previous.from_date} a ${ventas.previous.to_date})`
            )
          : null
      )
    ),

    h(
      'div',
      { class: 'grid grid-cols-1 sm:grid-cols-3 gap-4' },
      tarjetaDato('Pedidos vendidos', number(t.orders), null, ventas.previous?.change.orders),
      tarjetaDato('Ticket promedio', money(t.avg_ticket), null, ventas.previous?.change.avg_ticket),
      tarjetaDato(
        'Cocina, tiempo mediano',
        minutos(tiempos.median_minutes),
        tiempos.orders
          ? `promedio ${minutos(tiempos.avg_minutes)} · pico ${minutos(tiempos.max_minutes)}`
          : 'todavía sin pedidos medidos'
      )
    ),

    // Solo cuando hay más de un origen: con uno solo —o ninguno— sería
    // repetir el total de arriba con otro título. La comisión al lado, que
    // es lo que el reporte viene a responder.
    porOrigen.length > 1
      ? barras('Ventas por origen', porOrigen, {
          etiquetaDe: (r) => r.source_name ?? 'Venta propia',
          valorDe: (r) => Number(r.revenue),
          formato: money,
          detalleDe: (r) =>
            Number(r.commission)
              ? `comisión ${money(r.commission)} · neto ${money(r.net)}`
              : `${r.orders} pedido${r.orders === 1 ? '' : 's'}`,
        })
      : null,

    // Solo si hubo domicilios entregados: en un restaurante que no reparte
    // sería una tarjeta vacía en cada reporte.
    promesa.delivered
      ? h(
          'div',
          { class: 'grid grid-cols-1 sm:grid-cols-3 gap-4' },
          tarjetaDato(
            'Domicilios a tiempo',
            // Contra los que prometieron algo: sin zona no hay promesa, y
            // contarlos como incumplidos sería mentir al revés.
            promesa.promised
              ? `${Math.round((promesa.on_time / promesa.promised) * 100)}%`
              : 'sin promesa',
            promesa.promised
              ? `${promesa.on_time} de ${promesa.promised} prometidos`
              : `${promesa.delivered} entregados sin zona`
          ),
          tarjetaDato('Entrega, promedio', minutos(promesa.avg_minutes), 'desde que se toma el pedido'),
          tarjetaDato(
            'Cuando llega tarde',
            minutos(promesa.avg_delay_minutes),
            promesa.promised - promesa.on_time
              ? `${promesa.promised - promesa.on_time} pedido${promesa.promised - promesa.on_time === 1 ? '' : 's'} tarde`
              : 'ninguno se pasó'
          )
        )
      : null,

    h(
      'div',
      { class: 'grid grid-cols-1 lg:grid-cols-2 gap-4' },
      columnas('Ingresos por día', ventas.by_day, {
        etiquetaDe: (r) => r.day.slice(5),
        valorDe: (r) => Number(r.revenue),
        formato: money,
      }),
      columnas('Pedidos por hora', horas, {
        etiquetaDe: (r) => `${r.hour} h`,
        valorDe: (r) => r.orders,
        formato: (v) => `${v} pedido${v === 1 ? '' : 's'}`,
      })
    ),

    h(
      'div',
      { class: 'grid grid-cols-1 lg:grid-cols-2 gap-4' },
      barras('Productos más vendidos', productos, {
        etiquetaDe: (r) => r.name,
        valorDe: (r) => r.units,
        formato: (v) => `${v} u`,
        detalleDe: (r) => money(r.revenue),
      }),
      barras('Ingresos por canal', ventas.by_channel, {
        etiquetaDe: (r) => canal(r.channel),
        valorDe: (r) => Number(r.revenue),
        formato: money,
        detalleDe: (r) => `${r.orders} pedidos`,
      })
    ),

    ventas.by_branch.length > 1
      ? barras('Ingresos por sucursal', ventas.by_branch, {
          etiquetaDe: (r) => r.branch_name,
          valorDe: (r) => Number(r.revenue),
          formato: money,
          detalleDe: (r) => `${r.orders} pedidos`,
        })
      : null,

    tablaDeDatos(ventas.by_day),
  ];
}

// =========================================================
// Cierre: lo que se mira para cuadrar el día
// =========================================================

/**
 * A diferencia de la vista de venta, esto sigue la plata: se fecha por el
 * cobro y cuenta también lo cobrado sobre pedidos que aún no están
 * completados. Por eso su total no tiene por qué coincidir con el de venta —
 * miden cosas distintas y la pantalla lo dice, para que la diferencia no
 * parezca un error.
 */
function panelCierre(ingresos, porUsuario, porMesero, ajustes, descargar) {
  const metodos = ingresos.by_method;
  const neto = metodos.reduce((suma, m) => suma + Number(m.net), 0);
  const devuelto = metodos.reduce((suma, m) => suma + Number(m.refunded), 0);

  return [
    card(
      h(
        'div',
        { class: 'flex items-start justify-between gap-3' },
        h('div', { class: 'text-sm text-stone-600' }, 'Ingresos del período'),
        h('div', { class: 'flex flex-wrap gap-2' }, descargar('payment-methods'), descargar('adjustments'))
      ),
      h('div', { class: 'text-5xl font-semibold text-stone-900 mt-1' }, money(neto)),
      h(
        'div',
        { class: 'text-sm text-stone-500 mt-2' },
        `${ingresos.from_date} a ${ingresos.to_date} · lo cobrado menos lo devuelto, por fecha del cobro`
      ),
      h(
        'p',
        { class: 'text-xs text-stone-500 mt-2' },
        'No tiene por qué coincidir con la venta: aquí entra lo cobrado sobre pedidos todavía abiertos, y no entra lo vendido que aún no se ha cobrado.'
      )
    ),

    metodos.length
      ? card(
          h('h3', { class: 'font-semibold text-stone-900 mb-3' }, 'Ingresos por método de pago'),
          h(
            'table',
            { class: 'w-full text-sm' },
            h(
              'thead',
              { class: 'text-left text-stone-500 border-b border-stone-200' },
              h(
                'tr',
                {},
                h('th', { class: 'py-1' }, 'Método'),
                h('th', { class: 'py-1 text-right' }, 'Cobros'),
                h('th', { class: 'py-1 text-right' }, 'Cobrado'),
                h('th', { class: 'py-1 text-right' }, 'Devuelto'),
                h('th', { class: 'py-1 text-right' }, 'Neto')
              )
            ),
            h(
              'tbody',
              { class: 'tabular-nums' },
              metodos.map((m) =>
                h(
                  'tr',
                  { class: 'border-b border-stone-100' },
                  h('td', { class: 'py-1.5' }, metodoPago(m.method)),
                  h('td', { class: 'py-1.5 text-right' }, number(m.charges)),
                  h('td', { class: 'py-1.5 text-right' }, money(m.charged)),
                  h(
                    'td',
                    { class: `py-1.5 text-right ${Number(m.refunded) ? 'text-red-700' : 'text-stone-400'}` },
                    Number(m.refunded) ? `− ${money(m.refunded)}` : '—'
                  ),
                  h('td', { class: 'py-1.5 text-right font-semibold' }, money(m.net))
                )
              )
            ),
            h(
              'tfoot',
              {},
              h(
                'tr',
                { class: 'font-semibold tabular-nums' },
                h('td', { class: 'py-2' }, 'Total'),
                h('td', {}),
                h('td', {}),
                h('td', { class: 'py-2 text-right text-red-700' }, devuelto ? `− ${money(devuelto)}` : '—'),
                h('td', { class: 'py-2 text-right' }, money(neto))
              )
            )
          )
        )
      : sinDatos('Ingresos por método de pago'),

    barras('Ventas por usuario', porUsuario, {
      etiquetaDe: (r) => r.user_name ?? 'Sin usuario',
      valorDe: (r) => Number(r.revenue),
      formato: money,
      detalleDe: (r) => `${r.orders} pedido${r.orders === 1 ? '' : 's'}`,
    }),

    // La venta va sin la propina y la propina al lado: sumadas, quien
    // recibió más propina parecería haber vendido más.
    porMesero.length
      ? barras('Ventas por mesero', porMesero, {
          etiquetaDe: (r) => r.user_name ?? 'Sin mesero',
          valorDe: (r) => Number(r.revenue),
          formato: money,
          detalleDe: (r) =>
            `${r.orders} cuenta${r.orders === 1 ? '' : 's'} · propina ${money(r.tips)}`,
        })
      : null,

    h(
      'div',
      { class: 'grid grid-cols-1 lg:grid-cols-3 gap-4 items-start' },
      seccionAjuste('Anulaciones', ajustes.cancellations, 'Pedidos que no se vendieron.', (i) =>
        h('span', {}, i.order_number, h('span', { class: 'text-stone-400' }, ` · ${canal(i.channel)}`))
      ),
      seccionAjuste('Reembolsos', ajustes.refunds, 'Plata que volvió al cliente.', (i) =>
        h('span', {}, i.order_number, h('span', { class: 'text-stone-400' }, ` · ${metodoPago(i.method)}`))
      ),
      seccionAjuste('Descuentos', ajustes.discounts, 'Lo que se dejó de cobrar.', (i) =>
        h('span', {}, i.order_number, h('span', { class: 'text-stone-400' }, ` · de ${money(i.order_total)}`))
      )
    ),
  ];
}

/**
 * Una lista de ajustes con su total.
 *
 * El total viene del backend calculado sobre todo el período, no sumando lo
 * que se ve: la lista puede venir recortada y un total que solo sume las
 * filas visibles no sirve para cuadrar.
 */
function seccionAjuste(titulo, grupo, descripcion, tituloDe) {
  return card(
    h(
      'div',
      { class: 'flex items-baseline justify-between gap-2 mb-1' },
      h('h3', { class: 'font-semibold text-stone-900' }, titulo),
      h('span', { class: 'text-lg font-semibold tabular-nums' }, money(grupo.total))
    ),
    h('p', { class: 'text-xs text-stone-500 mb-3' }, `${grupo.count} en el período · ${descripcion}`),

    grupo.items.length
      ? h(
          'div',
          { class: 'divide-y divide-stone-100 -mx-1' },
          grupo.items.map((i) =>
            h(
              'div',
              { class: 'py-2 px-1' },
              h(
                'div',
                { class: 'flex justify-between gap-2 text-sm' },
                tituloDe(i),
                h('span', { class: 'tabular-nums whitespace-nowrap' }, money(i.amount))
              ),
              h(
                'div',
                { class: 'text-xs text-stone-500 mt-0.5' },
                [i.by_name ?? 'sin usuario', i.at ? `${fecha(i.at)} ${time(i.at)}` : null].filter(Boolean).join(' · ')
              ),
              i.reason ? h('div', { class: 'text-xs text-stone-600 mt-0.5' }, i.reason) : null
            )
          )
        )
      : h('p', { class: 'text-sm text-stone-500' }, 'Nada en este período.'),

    grupo.truncated
      ? h('p', { class: 'text-xs text-amber-800 mt-3' }, badge('Lista recortada', 'warn'), ' El total sí es del período completo.')
      : null
  );
}

/**
 * Cuánto cambió contra el período anterior.
 *
 * `null` no es 0%: es que antes no había nada con qué comparar, y decir
 * "subió un infinito por ciento" no es una lectura. Lo decide
 * `Domain\PeriodComparison`, aquí solo se pinta.
 */
function variacion(cambio) {
  if (cambio === null || cambio === undefined) return null;

  const sube = cambio > 0;
  const tono = cambio === 0 ? 'bg-stone-100 text-stone-600' : sube ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700';
  const signo = sube ? '+' : '';
  return h(
    'span',
    { class: `text-[13px] font-medium px-2 py-0.5 rounded-full ${tono}` },
    `${signo}${cambio.toFixed(1)} %`
  );
}

const tarjetaDato = (titulo, valor, pie, cambio) =>
  card(
    h('div', { class: 'text-sm text-stone-600' }, titulo),
    h(
      'div',
      { class: 'flex items-baseline gap-2 flex-wrap mt-1' },
      h('div', { class: 'text-2xl font-semibold text-stone-900' }, valor),
      variacion(cambio)
    ),
    pie ? h('div', { class: 'text-xs text-stone-500 mt-1' }, pie) : null
  );

function columnas(titulo, filas, { etiquetaDe, valorDe, formato }) {
  if (!filas.length) return sinDatos(titulo);
  const maximo = Math.max(...filas.map(valorDe));

  return card(
    h('h3', { class: 'font-semibold text-stone-900' }, titulo),
    h('p', { class: 'text-xs text-stone-500 mb-3 tabular-nums' }, `Máximo ${formato(maximo)}`),
    h(
      'div',
      { class: 'h-40 flex items-stretch gap-1 border-b border-stone-200' },
      filas.map((fila) => {
        const valor = valorDe(fila);
        const alto = maximo > 0 ? Math.max((valor / maximo) * 100, 2) : 2;
        return h(
          'div',
          { class: 'flex-1 flex flex-col items-center gap-1 min-w-0', title: `${etiquetaDe(fila)}: ${formato(valor)}` },
          h(
            'div',
            { class: 'w-full flex-1 flex items-end' },
            // Extremo redondeado arriba, recto en la base de la que crece.
            h('div', {
              class: 'w-full max-w-[24px] mx-auto rounded-t',
              style: `height:${alto}%; background:${SERIE}`,
            })
          ),
          h('div', { class: 'text-[10px] text-stone-500 truncate w-full text-center' }, etiquetaDe(fila))
        );
      })
    )
  );
}

function barras(titulo, filas, { etiquetaDe, valorDe, formato, detalleDe }) {
  if (!filas.length) return sinDatos(titulo);
  const maximo = Math.max(...filas.map(valorDe));

  return card(
    h('h3', { class: 'font-semibold text-stone-900 mb-3' }, titulo),
    h(
      'div',
      { class: 'space-y-3' },
      filas.map((fila) => {
        const valor = valorDe(fila);
        const ancho = maximo > 0 ? Math.max((valor / maximo) * 100, 1) : 1;
        return h(
          'div',
          { class: 'space-y-1' },
          h(
            'div',
            { class: 'flex justify-between gap-2 text-sm' },
            h('span', { class: 'text-stone-900 truncate' }, etiquetaDe(fila)),
            h(
              'span',
              { class: 'text-stone-600 tabular-nums whitespace-nowrap' },
              formato(valor),
              detalleDe ? h('span', { class: 'text-stone-400' }, ` ${detalleDe(fila)}`) : null
            )
          ),
          h(
            'div',
            { class: 'h-2' },
            h('div', { class: 'h-full rounded-r', style: `width:${ancho}%; background:${SERIE}` })
          )
        );
      })
    )
  );
}

const sinDatos = (titulo) =>
  card(
    h('h3', { class: 'font-semibold text-stone-900 mb-2' }, titulo),
    h('p', { class: 'text-sm text-stone-500' }, 'Sin datos en este período.')
  );

function tablaDeDatos(porDia) {
  return h(
    'details',
    { class: 'bg-white rounded-xl border border-stone-200 p-4' },
    h('summary', { class: 'cursor-pointer font-semibold text-stone-900' }, 'Ver los datos en tabla'),
    h(
      'table',
      { class: 'w-full text-sm mt-3' },
      h(
        'thead',
        { class: 'text-left text-stone-500 border-b border-stone-200' },
        h(
          'tr',
          {},
          h('th', { class: 'py-1' }, 'Día'),
          h('th', { class: 'py-1 text-right' }, 'Pedidos'),
          h('th', { class: 'py-1 text-right' }, 'Ingresos')
        )
      ),
      h(
        'tbody',
        { class: 'tabular-nums' },
        porDia.length
          ? porDia.map((r) =>
              h(
                'tr',
                { class: 'border-b border-stone-100' },
                h('td', { class: 'py-1' }, r.day),
                h('td', { class: 'py-1 text-right' }, number(r.orders)),
                h('td', { class: 'py-1 text-right' }, money(r.revenue))
              )
            )
          : h('tr', {}, h('td', { colspan: '3', class: 'py-3 text-stone-500' }, 'Sin ventas en el período.'))
      )
    )
  );
}
