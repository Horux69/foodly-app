// Reportes del dueño.
//
// Una sola serie por gráfico: todo lo que se mide aquí es una magnitud
// (dinero o conteo), no identidades. Por eso va un solo tono, sin leyenda que
// repita el título, los valores en tinta y no en el color del dato, y una
// tabla para lo que el ojo no deba estimar.

import { api, query } from '../api.js';
import { isoDate, money, number } from '../format.js';
import { can } from '../session.js';
import { button, card, errorBox, h, pageHeader, render, skeleton } from '../ui.js';
import { canal } from './cocina.js';

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

  function aplicarRango(dias) {
    const fin = new Date();
    const inicio = new Date();
    inicio.setDate(fin.getDate() - dias);
    desde.value = isoDate(inicio);
    hasta.value = isoDate(fin);
    cargar();
  }

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
      can('settings.view') ? etiqueta('Sucursal', sucursal) : null,
      button('Aplicar', { onClick: () => cargar() })
    )
  );

  render(outlet, pageHeader('Reportes', { hint: 'Solo cuenta lo que se completó y se pagó.' }), h('div', { class: 'space-y-4' }, filtros, contenido));

  if (can('settings.view')) {
    try {
      const sucursales = await api.get('/branches');
      render(
        sucursal,
        h('option', { value: '' }, 'Todas las sucursales'),
        sucursales.map((b) => h('option', { value: b.id }, b.name))
      );
    } catch {
      sucursal.remove();
    }
  }

  async function cargar() {
    render(contenido, skeleton({ rows: 3 }));
    const qs = query({ from_date: desde.value, to_date: hasta.value, branch_id: sucursal.value });

    let ventas, productos, tiempos, horas;
    try {
      [ventas, productos, tiempos, horas] = await Promise.all([
        api.get(`/reports/sales${qs}`),
        api.get(`/reports/top-products${qs}`),
        api.get(`/reports/prep-times${qs}`),
        api.get(`/reports/peak-hours${qs}`),
      ]);
    } catch (error) {
      render(contenido, errorBox(error.message, cargar));
      return;
    }

    render(contenido, panel(ventas, productos, tiempos, horas));
  }

  aplicarRango(29);
}

const etiqueta = (texto, control) =>
  h('label', { class: 'block' }, h('span', { class: 'block text-xs text-stone-600 mb-1' }, texto), control);

function panel(ventas, productos, tiempos, horas) {
  const t = ventas.totals;
  const minutos = (v) => (v === null || v === undefined ? 'sin datos' : `${Number(v).toFixed(0)} min`);

  return [
    // Un solo número protagonista por vista.
    card(
      h('div', { class: 'text-sm text-stone-600' }, 'Ingresos del período'),
      h('div', { class: 'text-5xl font-semibold text-stone-900 mt-1' }, money(t.revenue)),
      h(
        'div',
        { class: 'text-sm text-stone-500 mt-2' },
        `${ventas.from_date} a ${ventas.to_date} · solo pedidos completados`
      )
    ),

    h(
      'div',
      { class: 'grid grid-cols-1 sm:grid-cols-3 gap-4' },
      tarjetaDato('Pedidos vendidos', number(t.orders)),
      tarjetaDato('Ticket promedio', money(t.avg_ticket)),
      tarjetaDato(
        'Cocina, tiempo mediano',
        minutos(tiempos.median_minutes),
        tiempos.orders
          ? `promedio ${minutos(tiempos.avg_minutes)} · pico ${minutos(tiempos.max_minutes)}`
          : 'todavía sin pedidos medidos'
      )
    ),

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

const tarjetaDato = (titulo, valor, pie) =>
  card(
    h('div', { class: 'text-sm text-stone-600' }, titulo),
    h('div', { class: 'text-2xl font-semibold text-stone-900 mt-1' }, valor),
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
