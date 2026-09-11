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
import { cajaGuardada, recordarCaja } from '../caja-elegida.js';
import { date, money, time } from '../format.js';
import { icon } from '../icons.js';
import { activeBranch, branchQuery, can } from '../session.js';
import {
  badge, button, empty, errorBox, field, h, input, pageHeader, render, section, select, skeleton, toast,
} from '../ui.js';
import { imprimirCorte } from './impresion.js';
import { metodoPago } from './pedido-detalle.js';

export async function caja(outlet) {
  const panel = h('div', { class: 'space-y-4' });
  const historial = h('div');
  const selectorCaja = h('div');

  // Las cajas configuradas para esta sede (F7.4). Vacío es el caso de casi
  // todos: un solo turno por sucursal, como siempre y sin preguntar nada.
  let cajas = [];
  let registerId = null;

  render(
    outlet,
    pageHeader('Caja', {
      // La sucursal viaja en la petición: sin ella la pantalla decía el
      // nombre de una sede y cuadraba la caja de otra —la del token—, que
      // con dos sedes es un descuadre garantizado.
      hint: `Turno de ${activeBranch()?.name ?? 'la sucursal'}. Cada cobro y cada reembolso queda en el turno que esté abierto.`,
    }),
    selectorCaja,
    panel,
    historial
  );
  render(panel, skeleton({ rows: 2 }));

  try {
    cajas = await api.get(`/branches/${activeBranch()?.id}/registers${branchQuery()}`);
  } catch {
    // Sin la lista, esta pantalla sigue sirviendo como si no hubiera cajas
    // configuradas: es mejor un turno único que ninguna pantalla de caja.
    cajas = [];
  }

  if (cajas.length) {
    const guardada = cajaGuardada();
    registerId = cajas.some((c) => c.id === guardada) ? guardada : cajas[0].id;
    pintarSelectorCaja();
  }

  function pintarSelectorCaja() {
    render(
      selectorCaja,
      section('Caja', {
        hint: 'Esta sede tiene más de un punto de cobro: cada uno cuadra su propio arqueo.',
        body: h(
          'div',
          { class: 'flex flex-wrap gap-1.5' },
          cajas.map((c) =>
            h(
              'button',
              {
                class: `px-3 py-1.5 rounded-full text-[13px] border transition ${
                  c.id === registerId
                    ? 'bg-stone-900 text-[--tinta-inversa] border-stone-900'
                    : 'bg-[--panel] text-stone-600 border-stone-300 hover:border-stone-900'
                }`,
                'aria-pressed': String(c.id === registerId),
                onClick: () => {
                  registerId = c.id;
                  recordarCaja(c.id);
                  pintarSelectorCaja();
                  cargar();
                },
              },
              c.name
            )
          )
        ),
      })
    );
  }

  async function cargar() {
    let actual;
    let movimientos = [];
    try {
      // El `register_id` va en la query, como el `branch_id`: dice a cuál
      // caja se refiere la lectura. Nulo cuando esta sede no usa cajas.
      actual = await api.get(`/cash/session${branchQuery({ register_id: registerId })}`);
      // Solo si hay turno: sin él la lista siempre está vacía y sería una
      // petición por nada cada vez que se entra a la pantalla.
      if (actual.session && can('cash.movements')) {
        movimientos = await api.get(`/cash/movements${branchQuery({ register_id: registerId })}`);
      }
    } catch (error) {
      return render(panel, errorBox(error.message, cargar));
    }

    render(panel, actual.session ? turnoAbierto(actual, movimientos, cargar, registerId) : sinTurno(cargar, registerId));
    if (can('cash.close')) await cargarHistorial();
  }

  async function cargarHistorial() {
    let turnos;
    try {
      turnos = await api.get(`/cash/sessions${branchQuery()}`);
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

function sinTurno(recargar, registerId) {
  if (!can('payments.register')) {
    return h('div', { class: 'seccion' }, empty('No hay un turno abierto', 'Lo abre quien va a cobrar.', null, 'dinero'));
  }

  const base = input({ type: 'number', min: '0', step: '0.01', value: '0', class: 'campo w-40 tabular-nums' });

  const abrir = button('Abrir turno', {
    iconName: 'dinero',
    onClick: async () => {
      abrir.disabled = true;
      try {
        // El `register_id` va en el cuerpo, no en la query: es el mismo
        // POST /cash/session de siempre con un dato más, no una ruta nueva.
        await api.post(`/cash/session${branchQuery()}`, {
          opening_float: Number(base.value || 0),
          register_id: registerId,
        });
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

function turnoAbierto({ session, totals }, movimientos, recargar, registerId) {
  const titulo = [session.register_name, session.number ? `N.º ${session.number}` : null].filter(Boolean).join(' · ');
  const bloques = [
    section(`Turno abierto${titulo ? ` · ${titulo}` : ''}`, {
      // El corte X no cierra nada: se imprime para revisar a mitad de
      // turno o para entregarle la caja a otro cajero. Solo para quien
      // puede cerrar, porque lleva el esperado: verlo antes de contar es
      // lo que ese permiso separa.
      actions: totals
        ? button('Corte X', {
            variant: 'secondary',
            iconName: 'archivar',
            onClick: () => imprimirCorte({ sesion: session, totales: totals, movimientos, tipo: 'X' }),
          })
        : null,
      body: h(
        'div',
        { class: 'flex flex-wrap items-center gap-x-6 gap-y-2 text-[13.5px]' },
        dato('Abrió', session.opened_by_name ?? 'alguien que ya no está'),
        dato('Desde', `${date(session.opened_at)} ${time(session.opened_at)}`),
        dato('Base', money(session.opening_float))
      ),
    }),
  ];

  if (can('cash.movements')) bloques.push(bloqueMovimientos(movimientos, recargar, registerId));

  if (totals) {
    bloques.push(cuadre(totals), formularioCierre(session, totals, recargar, movimientos));
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

/**
 * Entradas y salidas de efectivo del turno.
 *
 * Un cajón real recibe y entrega plata todo el día por fuera de las ventas:
 * la sangría, el pago al domiciliario, la compra de emergencia. Sin
 * registrarlas el arqueo declara un faltante que no lo es, y un control que
 * "siempre da mal" deja de usarse.
 *
 * El motivo es obligatorio porque esta lista existe para responder, al
 * cerrar, en qué se fue la plata. Quien la ve no ve el cuadre: son permisos
 * distintos a propósito.
 */
function bloqueMovimientos(movimientos, recargar, registerId) {
  const tipo = select(
    [
      { value: 'out', label: 'Sale del cajón' },
      { value: 'in', label: 'Entra al cajón' },
    ],
    { class: 'campo', 'aria-label': 'Tipo de movimiento' }
  );
  const importe = input({ type: 'number', min: '1', placeholder: '0', class: 'campo w-32 tabular-nums', 'aria-label': 'Importe' });
  const motivo = input({ placeholder: 'Pago del gas, sangría, domiciliario…', class: 'campo flex-1 min-w-[180px]', 'aria-label': 'Motivo' });
  const aviso = h('div');

  const registrar = button('Registrar', {
    onClick: async () => {
      render(aviso);
      registrar.disabled = true;
      try {
        await api.post(`/cash/movements${branchQuery()}`, {
          kind: tipo.value,
          amount: Number(importe.value || 0),
          reason: motivo.value.trim(),
          register_id: registerId,
        });
        toast('Movimiento registrado', 'ok');
        await recargar();
      } catch (error) {
        render(aviso, errorBox(error.message));
        registrar.disabled = false;
      }
    },
  });

  const signo = (m) => (m.kind === 'out' ? '−' : '+');

  return section('Movimientos del cajón', {
    hint: 'Plata que entra o sale sin ser una venta. Entra al arqueo como un movimiento más.',
    body: h(
      'div',
      { class: 'space-y-2' },
      h('div', { class: 'flex flex-wrap items-center gap-2' }, tipo, importe, motivo, registrar),
      aviso
    ),
    list: movimientos.length
      ? movimientos.map((m) =>
          h(
            'div',
            { class: 'fila' },
            h('span', { class: `text-[13.5px] tabular-nums font-medium ${m.kind === 'out' ? 'text-rose-700' : 'text-emerald-700'}` },
              `${signo(m)} ${money(m.amount)}`),
            h(
              'div',
              { class: 'flex-1 min-w-0' },
              h('div', { class: 'text-[13.5px] text-stone-900' }, m.reason),
              h('div', { class: 'text-[12px] text-stone-500' }, `${time(m.created_at)} · ${m.by_name ?? 'alguien que ya no está'}`)
            )
          )
        )
      : [h('p', { class: 'text-[13px] text-stone-500' }, 'Nada ha entrado ni salido del cajón en este turno.')],
  });
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

      Number(totals.cash_in) || Number(totals.cash_out)
        ? h(
            'div',
            { class: 'flex justify-between text-[13px] text-stone-500' },
            h('span', {}, `Entró al cajón ${money(totals.cash_in)}, salió ${money(totals.cash_out)}`),
            h('span', { class: 'tabular-nums' }, `neto ${money(Number(totals.cash_in) - Number(totals.cash_out))}`)
          )
        : null,
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
          h('span', { class: 'block text-[12px] font-normal text-stone-500' }, `Base ${money(totals.opening_float)}, el efectivo del turno y lo que entró o salió del cajón`)
        ),
        h('span', { class: 'text-xl font-bold tabular-nums' }, money(totals.expected_cash))
      )
    ),
  });
}

function formularioCierre(session, totals, recargar, movimientos = []) {
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
        render(
          resultado,
          resumenDiferencia(cerrado.totals),
          // El papel del cierre se ofrece, no se impone: en un mostrador
          // sin impresora, un diálogo de impresión al cerrar es un estorbo.
          h(
            'div',
            { class: 'mt-3 flex flex-wrap gap-2' },
            button('Imprimir cierre', {
              variant: 'secondary',
              iconName: 'archivar',
              onClick: () =>
                imprimirCorte({
                  sesion: cerrado.session,
                  totales: cerrado.totals,
                  movimientos,
                  tipo: 'Z',
                }),
            }),
            button('Listo', { onClick: recargar })
          )
        );
        toast('Turno cerrado', 'ok');
        // Se deja el resultado a la vista un momento antes de volver a la
        // pantalla de apertura: es la cifra que el cajero anota.
        // Antes volvía sola a los 2,5 s. Ahora espera: la cifra del cierre
        // es la que el cajero anota y hay un papel que imprimir, y
        // recargar debajo de la mano sería quitarle las dos cosas.
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
        `${date(session.opened_at)} · ${time(session.opened_at)} → ${time(session.closed_at)}`,
        // Solo cuando la sede tiene más de una caja: en las demás sería
        // repetir "sucursal" en cada fila del historial.
        session.register_name ? h('span', { class: 'text-stone-400 font-normal' }, ` · ${session.register_name}`) : null
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
