// Dividir la cuenta entre varias personas.
//
// No cambia el pedido ni sus totales: cada persona paga un cobro parcial más
// contra el mismo saldo, de los que ya existen desde F2.1. Lo único que hace
// esta pantalla es proponer cuánto pone cada quien.
//
// El reparto en partes iguales lo calcula el backend (`Domain\BillSplit`) y
// no el navegador, por el centavo suelto: 65000 entre tres no da redondo, y
// dos divisiones con `toFixed(2)` dejarían el pedido con un centavo
// pendiente para siempre. Tras cada cobro se vuelve a pedir el reparto del
// saldo que quedó, así que las cuentas cierran solas.

import { api, uuid } from '../api.js';
import { aCentavos, desdeCentavos, money } from '../format.js';
import { icon } from '../icons.js';
import { me } from '../session.js';
import { button, h, loading, montarDialogo, render, select, toast } from '../ui.js';
import { metodoPago } from './pedido-detalle.js';

const MAX_PARTES = 50;

/**
 * @param {object} pedido el pedido tal como lo devuelve `GET /orders/{id}`
 * @param {() => Promise<void>} recargar avisa al panel de atrás de que se cobró
 */
export function abrirDivision(pedido, recargar) {
  const cuerpo = h('div', { class: 'p-5 space-y-4' });
  let modo = 'personas';
  let personas = 2;
  let cobrado = false;

  let desmontar;
  const cerrar = () => {
    desmontar();
    if (cobrado) recargar();
  };

  const overlay = h(
    'div',
    {
      class: 'fixed inset-0 z-[60] bg-black/40 flex items-end sm:items-center justify-center p-0 sm:p-4',
      onClick: (e) => e.target === overlay && cerrar(),
    },
    h(
      'div',
      {
        class: 'aparece bg-[--panel] rounded-t-2xl sm:rounded-[--r-g] w-full max-w-md max-h-[90vh] overflow-y-auto shadow-xl border border-[--linea]',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-label': 'Dividir la cuenta',
      },
      cuerpo
    )
  );

  desmontar = montarDialogo(overlay, { alCerrar: cerrar });

  /**
   * Cobra un importe y vuelve a dibujar con el saldo que quedó.
   *
   * `tras` corre entre el cobro y el repintado: es donde el reparto entre
   * personas descuenta al que acaba de pagar, para que lo que falta se
   * reparta entre los que quedan y no otra vez entre todos.
   */
  async function cobrar(centavos, metodo, boton, tras = () => {}) {
    if (centavos <= 0) return toast('No hay nada que cobrar');
    boton.disabled = true;
    try {
      await api.post(`/orders/${pedido.id}/payments`, {
        method: metodo,
        amount: Number(desdeCentavos(centavos)),
        idempotency_key: uuid(),
      });
      cobrado = true;
      toast(`Cobrados ${money(desdeCentavos(centavos))}`, 'ok');
      tras();
      await pintar();
    } catch (error) {
      toast(error.message);
      boton.disabled = false;
    }
  }

  async function pintar() {
    render(cuerpo, loading('Calculando el reparto…'));

    let saldo;
    let reparto;
    try {
      saldo = await api.get(`/orders/${pedido.id}/balance`);
      // Con el saldo en cero el backend rechaza repartir, y con razón: no
      // hay nada que dividir. Se pide solo si queda algo.
      reparto = Number(saldo.pending) > 0
        ? await api.get(`/orders/${pedido.id}/split?parts=${Math.min(personas, MAX_PARTES)}`)
        : null;
    } catch (error) {
      return render(cuerpo, h('p', { class: 'text-[13.5px] text-red-700' }, error.message));
    }

    if (reparto === null) {
      return render(
        cuerpo,
        encabezado(saldo, cerrar),
        h(
          'div',
          { class: 'text-center py-6' },
          h('div', { class: 'inline-flex text-emerald-600 mb-2' }, icon('check', { size: 28 })),
          h('p', { class: 'text-[14px] font-medium' }, 'La cuenta quedó saldada'),
          h('p', { class: 'text-[13px] text-stone-600 mt-1' }, 'No queda nada por repartir.')
        ),
        h('div', { class: 'flex justify-end' }, button('Cerrar', { variant: 'secondary', onClick: cerrar }))
      );
    }

    render(
      cuerpo,
      encabezado(saldo, cerrar),
      selectorModo(),
      modo === 'personas' ? bloquePersonas(reparto) : bloqueProductos(reparto),
      h('div', { class: 'flex justify-end pt-1' }, button('Cerrar', { variant: 'secondary', onClick: cerrar }))
    );
  }

  function selectorModo() {
    const opcion = (clave, etiqueta) =>
      h(
        'button',
        {
          class: `flex-1 py-1.5 text-[13px] font-medium rounded-[--r] transition ${
            modo === clave ? 'bg-[--panel] shadow-sm text-stone-900' : 'text-stone-600 hover:text-stone-800'
          }`,
          onClick: () => {
            modo = clave;
            pintar();
          },
        },
        etiqueta
      );

    return h('div', { class: 'flex gap-1 p-1 bg-stone-100 rounded-[--r-g]' }, opcion('personas', 'Entre personas'), opcion('productos', 'Por productos'));
  }

  // ---------- entre personas ----------

  function bloquePersonas(reparto) {
    const partes = reparto.parts;
    const metodo = selectorMetodo();

    const menos = paso('menos', 'Una persona menos', () => {
      if (personas > 1) {
        personas -= 1;
        pintar();
      }
    });
    const mas = paso('mas', 'Una persona más', () => {
      if (personas < MAX_PARTES) {
        personas += 1;
        pintar();
      }
    });

    const primera = aCentavos(partes[0]);
    const cobrarParte = button(`Cobrar ${money(partes[0])}`, {
      full: true,
      iconName: 'dinero',
      onClick: () =>
        cobrar(primera, metodo.value, cobrarParte, () => {
          personas = Math.max(1, personas - 1);
        }),
    });

    return h(
      'div',
      { class: 'space-y-3' },
      h(
        'div',
        { class: 'flex items-center justify-between' },
        h('span', { class: 'text-[13.5px] text-stone-600' }, 'Entre cuántas personas'),
        h('div', { class: 'flex items-center gap-2' }, menos, h('span', { class: 'w-8 text-center text-lg font-semibold tabular-nums' }, String(personas)), mas)
      ),

      h(
        'div',
        { class: 'rounded-[--r-g] border border-[--linea] divide-y divide-[--linea]' },
        partes.map((importe, i) =>
          h(
            'div',
            { class: 'flex justify-between items-center px-3 py-2 text-[13.5px]' },
            h('span', { class: 'text-stone-600' }, `Persona ${i + 1}`),
            h('span', { class: `tabular-nums ${i === 0 ? 'font-semibold' : ''}` }, money(importe))
          )
        )
      ),

      // Se cobra de a una parte y se vuelve a repartir lo que queda: así el
      // centavo de diferencia siempre cae dentro de la cuenta y nunca sobra
      // ni falta al final.
      h('p', { class: 'text-[12px] text-stone-600' }, 'Se cobra una parte a la vez. Lo que quede se vuelve a repartir entre los que faltan.'),
      h('div', { class: 'flex flex-wrap items-center gap-2' }, metodo, h('div', { class: 'flex-1 min-w-[140px]' }, cobrarParte))
    );
  }

  // ---------- por productos ----------

  function bloqueProductos(reparto) {
    const metodo = selectorMetodo();
    const seleccion = new Set();
    const resumen = h('div', { class: 'text-[13.5px]' });

    const cobrarMarcado = button('Cobrar lo marcado', {
      full: true,
      iconName: 'dinero',
      onClick: () => cobrar(sumaSeleccion(), metodo.value, cobrarMarcado),
    });

    // Suma en centavos enteros, no en float: es una suma de importes que ya
    // vienen calculados, no un total que se invente aquí.
    const sumaSeleccion = () =>
      pedido.items
        .filter((i) => seleccion.has(i.id))
        .reduce((total, i) => total + aCentavos(i.line_total), 0);

    function actualizar() {
      const suma = sumaSeleccion();
      cobrarMarcado.disabled = suma <= 0;
      render(
        resumen,
        h(
          'div',
          { class: 'flex justify-between items-baseline' },
          h('span', { class: 'text-stone-600' }, `${seleccion.size} producto${seleccion.size === 1 ? '' : 's'}`),
          h('span', { class: 'text-lg font-semibold tabular-nums' }, money(desdeCentavos(suma)))
        )
      );
    }

    const filas = pedido.items.map((item) =>
      h(
        'label',
        { class: 'flex items-center gap-2.5 px-3 py-2 text-[13.5px] cursor-pointer hover:bg-stone-50' },
        h('input', {
          type: 'checkbox',
          class: 'w-4 h-4 rounded border-stone-300 accent-stone-900',
          onChange: (e) => {
            if (e.target.checked) seleccion.add(item.id);
            else seleccion.delete(item.id);
            actualizar();
          },
        }),
        h(
          'span',
          { class: 'flex-1 min-w-0' },
          h('span', { class: 'font-medium' }, `${item.quantity}× `),
          item.name_snapshot
        ),
        h('span', { class: 'tabular-nums shrink-0' }, money(item.line_total))
      )
    );

    const bloque = h(
      'div',
      { class: 'space-y-3' },
      h('div', { class: 'rounded-[--r-g] border border-[--linea] divide-y divide-[--linea]' }, filas),

      // El envío, el descuento y la propina no están en ninguna línea, así
      // que marcando productos nunca se llega al total. Decirlo aquí evita
      // la sorpresa de un saldo que no baja a cero.
      Number(reparto.non_item_total)
        ? h(
            'p',
            { class: 'text-[12px] text-amber-800 bg-amber-50 border border-amber-200 rounded-[--r] px-2 py-1.5' },
            `Los productos no incluyen ${money(reparto.non_item_total)} de envío, descuento o propina: eso queda en el saldo para que alguien lo pague.`
          )
        : null,

      resumen,
      h('div', { class: 'flex flex-wrap items-center gap-2' }, metodo, h('div', { class: 'flex-1 min-w-[140px]' }, cobrarMarcado))
    );

    actualizar();
    return bloque;
  }

  // ---------- piezas compartidas ----------

  function encabezado(saldo, alCerrar) {
    return h(
      'div',
      { class: 'flex items-start justify-between gap-3' },
      h(
        'div',
        {},
        h('h3', { class: 'text-[15px] font-semibold' }, 'Dividir la cuenta'),
        h(
          'p',
          { class: 'text-[13px] text-stone-600 mt-0.5' },
          `${pedido.order_number} · faltan `,
          h('b', { class: 'text-stone-900 tabular-nums' }, money(saldo.pending))
        )
      ),
      h(
        'button',
        { class: 'text-stone-400 hover:text-stone-900 p-1 shrink-0', onClick: alCerrar, 'aria-label': 'Cerrar' },
        icon('cerrar', { size: 20 })
      )
    );
  }

  const selectorMetodo = () =>
    select(
      (me().payment_methods ?? []).map((m) => ({ value: m, label: metodoPago(m) })),
      { class: 'campo w-auto', 'aria-label': 'Método de pago' }
    );

  // Con nombre accesible: un botón que solo tiene un icono no se puede
  // nombrar ni desde un lector de pantalla ni desde una prueba.
  const paso = (ico, etiqueta, onClick) =>
    h(
      'button',
      {
        class: 'w-8 h-8 inline-flex items-center justify-center rounded-[--r] border border-stone-300 text-stone-600 hover:border-stone-900 active:scale-95 transition',
        'aria-label': etiqueta,
        onClick,
      },
      icon(ico, { size: 15 })
    );

  pintar();
  return { cerrar };
}
