// Caja: abrir el turno, y cerrarlo cuadrando el cajón.
//
// Un turno es un intervalo con una base de apertura y un conteo de cierre.
// Cada cobro y cada reembolso queda enganchado al turno abierto en su
// sucursal, y de ahí sale el arqueo: cuánto debería haber, cuánto hay y en
// cuánto se diferencian.
//
// La aritmética no está aquí. La hace `Domain\CashSessionTotals` y esta
// pantalla muestra lo que llega: si el esperado se recalculara en el
// navegador, un reembolso registrado después del cierre daría dos cifras
// distintas según dónde se mirara.
//
// Quien solo puede cobrar ve que hay turno abierto pero no el cuadre: ver el
// esperado antes de contar el cajón es justo lo que un arqueo no debe
// permitir. El backend decide eso, aquí solo se pinta lo que llegó.

import { api } from '../api.js';
import { date, money, time } from '../format.js';
import { icon } from '../icons.js';
import { activeBranch, can } from '../session.js';
import {
  badge, button, empty, errorBox, field, h, input, pageHeader, render, section, skeleton, toast,
} from '../ui.js';
import { metodoPago } from './pedido-detalle.js';

export async function caja(outlet) {
  const panel = h('div', { class: 'space-y-4' });
  const historial = h('div');

  render(
    outlet,
    pageHeader('Caja', {
      hint: `Turno de ${activeBranch()?.name ?? 'la sucursal'}. Cada cobro y cada reembolso queda en el turno que esté abierto.`,
    }),
    panel,
    historial
  );
  render(panel, skeleton({ rows: 2 }));

  async function cargar() {
    let actual;
    try {
      actual = await api.get('/cash/session');
    } catch (error) {
      return render(panel, errorBox(error.message, cargar));
    }

    render(panel, actual.session ? turnoAbierto(actual, cargar) : sinTurno(cargar));
    if (can('cash.close')) await cargarHistorial();
  }

  async function cargarHistorial() {
    let turnos;
    try {
      turnos = await api.get('/cash/sessions');
    } catch {
      return render(historial);
    }

    const cerrados = turnos.filter((t) => !t.session.is_open);
    render(
      historial,
      cerrados.length
        ? section('Turnos cerrados', { list: cerrados.map(filaHistorial) })
        : null
    );
  }

  await cargar();
}

// ---------- sin turno ----------

function sinTurno(recargar) {
  if (!can('payments.register')) {
    return h('div', { class: 'seccion' }, empty('No hay un turno abierto', 'Lo abre quien va a cobrar.', null, 'dinero'));
  }

  const base = input({ type: 'number', min: '0', step: '0.01', value: '0', class: 'campo w-40 tabular-nums' });

  const abrir = button('Abrir turno', {
    iconName: 'dinero',
    onClick: async () => {
      abrir.disabled = true;
      try {
        await api.post('/cash/session', { opening_float: Number(base.value || 0) });
        toast('Turno abierto', 'ok');
        await recargar();
      } catch (error) {
        toast(error.message);
        abrir.disabled = false;
      }
    },
  });

  return section('Abrir turno', {
    body: [
      h(
        'p',
        { class: 'text-[13.5px] text-stone-600 mb-3' },
        'La base es lo que queda en el cajón para dar vuelto. A partir de aquí, todo lo que se cobre entra en este turno.'
      ),
      h('div', { class: 'flex flex-wrap items-end gap-3' }, field('Base de caja', base), abrir),
    ],
  });
}

// ---------- turno abierto ----------

function turnoAbierto({ session, totals }, recargar) {
  const bloques = [
    section('Turno abierto', {
      body: h(
        'div',
        { class: 'flex flex-wrap items-center gap-x-6 gap-y-2 text-[13.5px]' },
        dato('Abrió', session.opened_by_name ?? 'alguien que ya no está'),
        dato('Desde', `${date(session.opened_at)} ${time(session.opened_at)}`),
        dato('Base', money(session.opening_float))
      ),
    }),
  ];

  if (totals) {
    bloques.push(cuadre(totals), formularioCierre(session, totals, recargar));
  } else {
    bloques.push(
      h(
        'div',
        { class: 'seccion' },
        empty(
          'El cuadre lo ve quien cierra',
          'Contar el cajón sabiendo de antemano cuánto debería haber no es un arqueo.',
          null,
          'dinero'
        )
      )
    );
  }

  return bloques;
}

function cuadre(totals) {
  const metodos = Object.entries(totals.by_method);

  return section('Movimientos del turno', {
    body: h(
      'div',
      { class: 'space-y-3 text-[13.5px]' },
      metodos.length
        ? h(
            'div',
            { class: 'space-y-1' },
            metodos.map(([metodo, importe]) =>
              h(
                'div',
                { class: 'flex justify-between' },
                h('span', { class: 'text-stone-600' }, metodoPago(metodo)),
                h('span', { class: 'tabular-nums' }, money(importe))
              )
            )
          )
        : h('p', { class: 'text-stone-500' }, 'Todavía no se ha cobrado nada en este turno.'),

      Number(totals.refunded)
        ? h(
            'div',
            { class: 'flex justify-between text-stone-500 pt-2 border-t border-[--linea]' },
            h('span', {}, `Cobrado ${money(totals.charged)}, devuelto ${money(totals.refunded)}`),
            h('span', { class: 'tabular-nums' }, `neto ${money(totals.net_collected)}`)
          )
        : null,

      h(
        'div',
        { class: 'flex justify-between items-baseline pt-2.5 border-t border-[--linea]' },
        h(
          'span',
          { class: 'font-medium text-stone-700' },
          'Debería haber en el cajón',
          h('span', { class: 'block text-[12px] font-normal text-stone-500' }, `Base ${money(totals.opening_float)} más el efectivo del turno`)
        ),
        h('span', { class: 'text-xl font-bold tabular-nums' }, money(totals.expected_cash))
      )
    ),
  });
}

function formularioCierre(session, totals, recargar) {
  const contado = input({ type: 'number', min: '0', step: '0.01', placeholder: '0', class: 'campo w-40 tabular-nums' });
  const nota = input({ placeholder: 'Nota del cierre (opcional)', maxlength: '255' });
  const resultado = h('div');

  const cerrar = button('Cerrar turno', {
    variant: 'danger',
    iconName: 'check',
    onClick: async () => {
      if (contado.value.trim() === '') return toast('Escribe cuánto efectivo contaste');
      cerrar.disabled = true;
      try {
        const cerrado = await api.post(`/cash/sessions/${session.id}/close`, {
          counted_cash: Number(contado.value),
          note: nota.value.trim() || null,
        });
        render(resultado, resumenDiferencia(cerrado.totals));
        toast('Turno cerrado', 'ok');
        // Se deja el resultado a la vista un momento antes de volver a la
        // pantalla de apertura: es la cifra que el cajero anota.
        setTimeout(recargar, 2500);
      } catch (error) {
        toast(error.message);
        cerrar.disabled = false;
      }
    },
  });

  return section('Cerrar turno', {
    body: [
      h(
        'p',
        { class: 'text-[13.5px] text-stone-600 mb-3' },
        'Cuenta el efectivo del cajón, incluida la base, y escribe el total.'
      ),
      h(
        'div',
        { class: 'flex flex-wrap items-end gap-3' },
        field('Efectivo contado', contado),
        h('div', { class: 'flex-1 min-w-[200px]' }, field('Nota', nota)),
        cerrar
      ),
      resultado,
    ],
  });
}

/** La cifra que importa al cerrar: cuánto sobra o falta. */
function resumenDiferencia(totals) {
  const diferencia = Number(totals.difference);
  const cuadra = diferencia === 0;
  const sobra = diferencia > 0;

  return h(
    'div',
    {
      class: `mt-4 rounded-[--r-g] border p-4 ${
        cuadra ? 'border-emerald-200 bg-emerald-50/60' : 'border-amber-300 bg-amber-50/60'
      }`,
    },
    h(
      'div',
      { class: 'flex items-center gap-2' },
      icon(cuadra ? 'check' : 'alerta', { size: 18, class: cuadra ? 'text-emerald-700' : 'text-amber-700' }),
      h(
        'span',
        { class: 'font-semibold text-[15px]' },
        cuadra ? 'El cajón cuadra' : sobra ? `Sobran ${money(diferencia)}` : `Faltan ${money(Math.abs(diferencia))}`
      )
    ),
    h(
      'p',
      { class: 'text-[13px] text-stone-600 mt-1' },
      `Esperado ${money(totals.expected_cash)} · contado ${money(totals.counted_cash)}`
    )
  );
}

// ---------- historial ----------

function filaHistorial({ session, totals }) {
  const diferencia = Number(totals.difference);

  return h(
    'div',
    { class: 'fila' },
    h(
      'div',
      { class: 'flex-1 min-w-0' },
      h(
        'div',
        { class: 'text-[13.5px] font-medium text-stone-900' },
        `${date(session.opened_at)} · ${time(session.opened_at)} → ${time(session.closed_at)}`
      ),
      h(
        'div',
        { class: 'text-[12.5px] text-stone-500' },
        [
          `Abrió ${session.opened_by_name ?? '—'}`,
          `cerró ${session.closed_by_name ?? '—'}`,
          `neto ${money(totals.net_collected)}`,
        ].join(' · ')
      ),
      session.note ? h('div', { class: 'text-[12px] text-stone-500 mt-0.5' }, session.note) : null
    ),
    diferencia === 0
      ? badge('Cuadró', 'ok', 'check')
      : badge(diferencia > 0 ? `Sobró ${money(diferencia)}` : `Faltó ${money(Math.abs(diferencia))}`, 'warn')
  );
}

const dato = (etiqueta, valor) =>
  h(
    'div',
    {},
    h('div', { class: 'text-[11.5px] uppercase tracking-wide text-stone-400' }, etiqueta),
    h('div', { class: 'text-[14px] font-medium text-stone-900' }, valor)
  );
