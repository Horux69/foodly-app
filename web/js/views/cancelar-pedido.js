// Anular un pedido, con motivo.
//
// Dos reglas del backend viven detrás de este diálogo, y las dos se explican
// aquí en vez de dejar que se descubran con un error:
//
//   * **Un pedido con plata encima no se anula.** Primero se reembolsa.
//     Cancelar dejaría un cobro sin venta que lo respalde: la caja cuadraría
//     de más y el cliente se quedaría sin su plata y sin su pedido.
//   * **Anular exige decir por qué.** Es lo único que convierte la anulación
//     en algo auditable; el reporte de cierre lista quién anuló y cuánto, y
//     sin motivo esa lista no responde la pregunta que se le hace.
//
// Lo usan el detalle del pedido, cocina y domicilios: es la misma decisión
// mirada desde tres sitios.

import { api } from '../api.js';
import { money } from '../format.js';
import { icon } from '../icons.js';
import { button, h, input, loading, montarDialogo, render, toast } from '../ui.js';

/**
 * @param {{id: string, order_number: string}} pedido
 * @param {{id: string, name: string}} estado el estado de categoría `cancelled` al que se va
 * @param {() => Promise<void>|void} alAnular se llama solo si se anuló
 */
export function abrirCancelacion(pedido, estado, alAnular) {
  const cuerpo = h('div', { class: 'p-5 space-y-4' });

  let desmontar;
  const cerrar = () => desmontar();

  const overlay = h(
    'div',
    {
      class: 'fixed inset-0 z-[60] bg-black/40 flex items-center justify-center p-4',
      onClick: (e) => e.target === overlay && cerrar(),
    },
    h(
      'div',
      {
        class: 'aparece bg-[--panel] rounded-[--r-g] max-w-sm w-full shadow-xl border border-[--linea]',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-label': 'Anular pedido',
      },
      cuerpo
    )
  );

  desmontar = montarDialogo(overlay, { alCerrar: cerrar });
  render(cuerpo, loading('Revisando los cobros…'));

  async function pintar() {
    // El saldo se pide aquí y no se recibe de fuera porque este diálogo lo
    // abren tres pantallas y solo una de ellas ya lo tiene a mano.
    let saldo;
    try {
      saldo = await api.get(`/orders/${pedido.id}/balance`);
    } catch (error) {
      return render(cuerpo, h('p', { class: 'text-[13.5px] text-red-700' }, error.message));
    }

    const cobrado = Number(saldo.net_paid);
    render(cuerpo, cobrado > 0 ? conPlataEncima(cobrado) : pedirMotivo());
  }

  const titulo = () =>
    h('h3', { class: 'text-[15px] font-semibold' }, `Anular ${pedido.order_number}`);

  function conPlataEncima(cobrado) {
    return [
      titulo(),
      h(
        'div',
        { class: 'flex gap-2.5 mt-2 rounded-[--r] border border-amber-300 bg-amber-50/60 p-3' },
        icon('alerta', { size: 18, class: 'text-amber-700 shrink-0 mt-px' }),
        h(
          'div',
          { class: 'text-[13px] text-stone-700' },
          h('p', { class: 'font-medium text-stone-900' }, `Este pedido tiene ${money(cobrado)} cobrados.`),
          h(
            'p',
            { class: 'mt-1' },
            'Reembólsalos antes de anularlo, desde el detalle del pedido. Anular sin devolver dejaría un cobro sin venta detrás: la caja cuadraría de más y el cliente se quedaría sin su plata y sin su pedido.'
          )
        )
      ),
      h('div', { class: 'flex justify-end mt-4' }, button('Entendido', { variant: 'secondary', onClick: cerrar })),
    ];
  }

  function pedirMotivo() {
    const motivo = input({
      placeholder: 'Por qué se anula',
      maxlength: '255',
      'aria-label': 'Motivo de la anulación',
    });

    const confirmar = button('Anular pedido', {
      variant: 'danger',
      onClick: async () => {
        if (motivo.value.trim() === '') {
          motivo.focus();
          return toast('Escribe el motivo de la anulación');
        }
        confirmar.disabled = true;
        try {
          await api.post(`/orders/${pedido.id}/status`, {
            to_status_id: estado.id,
            note: motivo.value.trim(),
          });
          cerrar();
          toast(`${pedido.order_number} anulado`, 'ok');
          await alAnular?.();
        } catch (error) {
          toast(error.message);
          confirmar.disabled = false;
        }
      },
    });

    // Enter en el campo confirma: anular es una acción de mostrador y suele
    // hacerse con prisa.
    motivo.addEventListener('keydown', (e) => e.key === 'Enter' && confirmar.click());

    setTimeout(() => motivo.focus(), 0);

    return [
      titulo(),
      h(
        'p',
        { class: 'text-[13px] text-stone-600 mt-1.5 leading-relaxed' },
        'El pedido pasa a ',
        h('b', {}, estado.name),
        ' y no se puede deshacer. El motivo queda en la bitácora y en el reporte de anulaciones.'
      ),
      h('div', { class: 'mt-4' }, motivo),
      h(
        'div',
        { class: 'flex justify-end gap-2 mt-5' },
        button('Volver', { variant: 'secondary', onClick: cerrar }),
        confirmar
      ),
    ];
  }

  pintar();
  return { cerrar };
}
