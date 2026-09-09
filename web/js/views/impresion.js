// Comanda de cocina y ticket de cliente.
//
// Se imprime con el navegador (`window.print()`) y no por ESC/POS a
// propósito: funciona con cualquier impresora que tenga driver, no ata el
// producto a un modelo y no exige un servicio local corriendo en el
// restaurante. ESC/POS queda para cuando un cliente lo pida.
//
// El documento se arma en `#impresion`, hijo directo de `<body>`, y la hoja
// de impresión esconde a todos sus hermanos. Se hace así y no abriendo otra
// ventana porque una ventana nueva hay que volver a cargarla entera y suele
// chocar con el bloqueador de emergentes justo cuando hay cola en la caja.
//
// El DOM se arma con `h()`, así que un producto llamado `<img onerror=...>`
// se imprime como ese texto en vez de ejecutarse.

import { date, money, time } from '../format.js';
import { activeBranch, me } from '../session.js';
import { h, render } from '../ui.js';
import { canal } from './cocina.js';
import { metodoPago } from './pedido-detalle.js';

/**
 * Manda un nodo a la impresora.
 *
 * La limpieza va por `afterprint`, y por el foco de la ventana como respaldo
 * —vuelve justo al cerrarse el diálogo— para los navegadores que no disparan
 * el primero al cancelar. Deliberadamente NO por temporizador: `print()` no
 * bloquea en todos lados, y un plazo ciego puede borrar el documento con el
 * diálogo todavía abierto, que es imprimir una hoja en blanco.
 *
 * Si aun así no se limpiara, la aplicación no se ve afectada: `#impresion`
 * está oculto fuera de `@media print`. Lo único que se arriesga es que un
 * Ctrl+P posterior saque el documento anterior, y para eso está el respaldo.
 */
function imprimir(nodo) {
  const host = document.getElementById('impresion');
  if (!host) return;

  render(host, nodo);
  document.body.classList.add('imprimiendo');

  let limpiado = false;
  const limpiar = () => {
    if (limpiado) return;
    limpiado = true;
    document.body.classList.remove('imprimiendo');
    render(host);
  };

  window.addEventListener('afterprint', limpiar, { once: true });
  window.print();
  // Después de print() para no atrapar el foco que el propio diálogo devuelve
  // al abrirse en algunos navegadores.
  window.addEventListener('focus', limpiar, { once: true });
}

/** Los modificadores llegan como texto desde el KDS y como objeto desde el detalle. */
const nombreModificador = (m) => (typeof m === 'string' ? m : m.name_snapshot);

const cabecera = (pedido, titulo) =>
  h(
    'div',
    { class: 'doc-cabeza' },
    h('div', { class: 'doc-titulo' }, titulo),
    h('div', { class: 'doc-numero' }, pedido.order_number),
    h(
      'div',
      {},
      [
        pedido.table_code ? `Mesa ${pedido.table_code}` : canal(pedido.channel),
        pedido.created_at ? `${date(pedido.created_at)} ${time(pedido.created_at)}` : null,
      ]
        .filter(Boolean)
        .join(' · ')
    )
  );

/**
 * Comanda de cocina: qué hay que preparar. Sin precios — a la cocina el
 * dinero no le sirve para nada y le quita sitio a lo que sí.
 */
export function imprimirComanda(pedido, { reimpresion = false } = {}) {
  imprimir(
    h(
      'div',
      { class: 'doc doc-comanda' },
      reimpresion
        ? h('div', { class: 'doc-reimpresion' }, 'REIMPRESIÓN — puede estar ya preparado')
        : null,
      cabecera(pedido, 'COMANDA'),

      h(
        'div',
        {},
        pedido.items.map((item) =>
          h(
            'div',
            {},
            h(
              'div',
              { class: 'doc-linea' },
              h('span', { class: 'doc-cantidad' }, `${item.quantity}×`),
              h('span', {}, item.name_snapshot)
            ),
            item.modifiers?.length
              ? h('div', { class: 'doc-detalle' }, item.modifiers.map(nombreModificador).join(' · '))
              : null,
            item.notes ? h('div', { class: 'doc-detalle' }, `NOTA: ${item.notes}`) : null
          )
        )
      ),

      pedido.notes ? h('div', { class: 'doc-pie' }, pedido.notes) : null,
      pedido.delivery ? h('div', { class: 'doc-pie' }, `Domicilio: ${pedido.delivery.address}`) : null
    )
  );
}

/**
 * Ticket del cliente: qué se llevó y qué pagó.
 *
 * Los importes salen del pedido tal como los calculó el backend. Aquí no se
 * suma nada: un ticket que dijera un total distinto al del pedido sería peor
 * que no imprimir ninguno.
 */
export function imprimirTicket(pedido, pagos = []) {
  const sede = activeBranch();
  const saldo = pedido.balance;

  const fila = (etiqueta, valor) =>
    h('div', { class: 'doc-fila' }, h('span', {}, etiqueta), h('span', {}, valor));

  imprimir(
    h(
      'div',
      { class: 'doc' },
      h(
        'div',
        { class: 'doc-cabeza' },
        h('div', { class: 'doc-titulo' }, me()?.tenant_name ?? ''),
        sede ? h('div', {}, sede.name) : null,
        sede?.address ? h('div', {}, sede.address) : null,
        sede?.phone ? h('div', {}, `Tel. ${sede.phone}`) : null,
        h('div', { class: 'doc-numero' }, pedido.order_number),
        h(
          'div',
          {},
          [
            pedido.table_code ? `Mesa ${pedido.table_code}` : canal(pedido.channel),
            `${date(pedido.created_at)} ${time(pedido.created_at)}`,
          ].join(' · ')
        )
      ),

      h(
        'div',
        {},
        pedido.items.map((item) =>
          h(
            'div',
            {},
            h(
              'div',
              { class: 'doc-fila' },
              h('span', {}, `${item.quantity}× ${item.name_snapshot}`),
              h('span', {}, money(item.line_total))
            ),
            item.modifiers?.length
              ? h('div', { class: 'doc-detalle' }, item.modifiers.map(nombreModificador).join(' · '))
              : null
          )
        )
      ),

      h(
        'div',
        { class: 'doc-total' },
        fila('Subtotal', money(pedido.subtotal)),
        Number(pedido.delivery_fee) ? fila('Domicilio', money(pedido.delivery_fee)) : null,
        Number(pedido.discount) ? fila('Descuento', `- ${money(pedido.discount)}`) : null,
        Number(pedido.tip) ? fila('Propina', money(pedido.tip)) : null,
        fila('TOTAL', money(pedido.total)),
        Number(pedido.tax_total) ? fila('Impuesto incluido', money(pedido.tax_total)) : null
      ),

      pagos.length
        ? h(
            'div',
            { class: 'doc-pie', style: 'text-align:left' },
            pagos.map((p) =>
              fila(
                p.refund_of_payment_id ? `Reembolso ${metodoPago(p.method)}` : metodoPago(p.method),
                `${p.refund_of_payment_id ? '- ' : ''}${money(p.amount)}`
              )
            ),
            saldo && !saldo.is_settled ? fila('Falta por pagar', money(saldo.pending)) : null
          )
        : null,

      h('div', { class: 'doc-pie' }, '¡Gracias por su compra!')
    )
  );
}
