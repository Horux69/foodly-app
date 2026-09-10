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

import { api } from '../api.js';
import { date, money, time } from '../format.js';
import { activeBranch, activeBranchId, branchQuery, me } from '../session.js';
import { h, render } from '../ui.js';
import { canal } from './cocina.js';
import { metodoPago, propinaSugerida } from './pedido-detalle.js';

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
function imprimirDocumento(nodo, documento = 'ticket', { copias = null } = {}) {
  const host = document.getElementById('impresion');
  if (!host) return;

  const perfil = perfilDe(documento);
  // El ancho útil del rollo: 72 mm en una térmica de 80, 48 en una de 58.
  host.style.setProperty('--doc-ancho', `${perfil.content_width_mm}mm`);

  const hojas = [nodo];
  // Las copias son hojas idénticas, no una repetición dentro de la misma:
  // `@media print` ya corta entre `.doc` hermanos.
  const veces = copias ?? perfil.copies;
  for (let i = 1; i < veces; i += 1) {
    hojas.push(...[nodo].flat().map((n) => n.cloneNode(true)));
  }

  render(host, hojas);
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

/**
 * Cómo imprime esta sucursal: ancho del papel y copias por documento.
 *
 * Se lee una vez y se guarda en memoria. Si la petición falla se usan los
 * valores por defecto —80 mm y una copia, lo de siempre— porque imprimir no
 * puede depender de que una configuración opcional esté disponible: con el
 * cliente enfrente, un ticket con el ancho equivocado es mejor que ninguno.
 */
const PERFIL_POR_DEFECTO = { width_mm: 80, content_width_mm: 72, copies: 1 };

// La caché lleva de qué sucursal es: cada sede tiene su impresora, y al
// cambiar de sede hay que volver a preguntar.
let cache = { sucursal: null, perfiles: null };

export function cargarPerfilesDeImpresion() {
  const sucursal = activeBranchId();
  return api
    .get(`/print-profiles${branchQuery()}`)
    .then((lista) => {
      cache = { sucursal, perfiles: Object.fromEntries(lista.map((p) => [p.document, p])) };
    })
    .catch(() => {
      cache = { sucursal, perfiles: {} };
    });
}

function perfilDe(documento) {
  if (cache.perfiles === null || cache.sucursal !== activeBranchId()) {
    // Primera impresión con esta sucursal: se pide para la siguiente y esta
    // sale con los valores por defecto en vez de esperar a la red con el
    // cliente enfrente.
    cache = { sucursal: activeBranchId(), perfiles: {} };
    cargarPerfilesDeImpresion();
  }
  return cache.perfiles[documento] ?? PERFIL_POR_DEFECTO;
}

/** Los modificadores llegan como texto desde el KDS y como objeto desde el detalle. */
const nombreModificador = (m) => (typeof m === 'string' ? m : m.name_snapshot);

/**
 * Lo que lleva un combo, ya multiplicado por las veces que se pidió.
 *
 * Sale del pedido y no de la carta: `order_item_components` guarda la
 * composición del momento de la venta, así que una comanda reimpresa dice lo
 * que se preparó y no lo que el combo llevaría hoy.
 */
const componentes = (item) =>
  (item.components ?? []).map((c) => `${c.quantity}× ${c.name_snapshot}`).join(' · ');

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
/**
 * Las comandas del pedido, una por estación.
 *
 * Quien las reparte es `Domain\StationRouting` y llegan hechas en
 * `kitchen_tickets`: la comanda impresa y el tablero de cocina tienen que
 * decir lo mismo, y agrupar aquí sería una segunda fuente de verdad. Un
 * pedido sin estaciones trae una sola, sin nombre.
 */
const comandasDe = (pedido) =>
  pedido.kitchen_tickets?.length
    ? pedido.kitchen_tickets
    : [{ station_id: null, station_name: null, course: 1, course_name: null, lines: pedido.items }];

/**
 * @param {object} pedido
 * @param {{reimpresion?: boolean, curso?: number}} opciones `curso` imprime
 *   solo ese tiempo, que es lo que se manda al marcharlo: la cocina no
 *   necesita otra vez el papel de las entradas que ya preparó.
 */
export function imprimirComanda(pedido, { reimpresion = false, curso = null } = {}) {
  const grupos = comandasDe(pedido).filter((g) => curso === null || (g.course ?? 1) === curso);
  if (!grupos.length) return;

  // Una hoja por estación: la barra no necesita saber qué lleva la plancha,
  // y con una sola hoja alguien termina recortándola con tijeras.
  imprimirDocumento(
    grupos.map((grupo) =>
    h(
      'div',
      { class: 'doc doc-comanda' },
      reimpresion
        ? h('div', { class: 'doc-reimpresion' }, 'REIMPRESIÓN — puede estar ya preparado')
        : null,
      cabecera(pedido, 'COMANDA'),

      grupos.length > 1 && grupo.station_name
        ? h('div', { class: 'doc-estacion' }, grupo.station_name.toUpperCase())
        : null,

      // El tiempo, cuando el restaurante los usa: una comanda de postres
      // que no dice que es de postres se prepara con lo demás.
      grupo.course_name ? h('div', { class: 'doc-estacion' }, grupo.course_name.toUpperCase()) : null,

      h(
        'div',
        {},
        grupo.lines.map((item) =>
          h(
            'div',
            {},
            h(
              'div',
              { class: 'doc-linea' },
              h('span', { class: 'doc-cantidad' }, `${item.quantity}×`),
              h('span', {}, item.name_snapshot)
            ),
            item.components?.length ? h('div', { class: 'doc-detalle' }, componentes(item)) : null,
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
    ),
    'comanda'
  );
}

/**
 * Ticket del cliente: qué se llevó y qué pagó.
 *
 * Los importes salen del pedido tal como los calculó el backend. Aquí no se
 * suma nada: un ticket que dijera un total distinto al del pedido sería peor
 * que no imprimir ninguno.
 *
 * Con `precuenta` sale el mismo documento marcado como lo que es: el papel
 * que se lleva a la mesa antes de cobrar. Es el mismo y no otro a propósito
 * —dos documentos con la misma cuenta terminan diciendo cifras distintas—,
 * y no cierra ni cambia nada: hasta ahora la única forma de mostrarle el
 * total a la mesa era cobrar.
 *
 * @param {{precuenta?: boolean}} opciones
 */
export function imprimirTicket(pedido, pagos = [], { precuenta = false } = {}) {
  const sede = activeBranch();
  const saldo = pedido.balance;
  // Lo que el restaurante sugiere, y que es voluntario: decirlo en el papel
  // es la forma de que el cliente lo decida antes de que el cajero
  // pregunte. Solo si todavía no hay propina puesta.
  const sugerida = precuenta && me()?.asks_tip && !Number(pedido.tip) ? propinaSugerida(pedido) : 0;

  const fila = (etiqueta, valor) =>
    h('div', { class: 'doc-fila' }, h('span', {}, etiqueta), h('span', {}, valor));

  imprimirDocumento(
    h(
      'div',
      { class: 'doc' },
      // Marcada en grande: un papel con el total que no diga que no es la
      // factura se entrega como si lo fuera.
      precuenta ? h('div', { class: 'doc-reimpresion' }, 'PRE-CUENTA — NO ES FACTURA DE VENTA') : null,

      h(
        'div',
        { class: 'doc-cabeza' },
        h('div', { class: 'doc-titulo' }, me()?.tenant_name ?? ''),
        sede ? h('div', {}, sede.name) : null,
        sede?.address ? h('div', {}, sede.address) : null,
        sede?.phone ? h('div', {}, `Tel. ${sede.phone}`) : null,
        h('div', { class: 'doc-numero' }, pedido.order_number),
        // El número autorizado, cuando el restaurante emite documento: es lo
        // que hace del papel un comprobante y no un recibo cualquiera.
        // El número autorizado nunca va en una pre-cuenta: con él encima,
        // el papel se lee como el comprobante que todavía no es.
        pedido.fiscal && !precuenta
          ? h('div', { class: 'doc-fiscal' }, pedido.fiscal.full_number)
          : null,
        pedido.fiscal?.external_id && !precuenta
          ? h('div', { class: 'doc-detalle' }, pedido.fiscal.external_id)
          : null,
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
            item.components?.length ? h('div', { class: 'doc-detalle' }, componentes(item)) : null,
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
        Number(pedido.tax_total) ? fila('Impuesto incluido', money(pedido.tax_total)) : null,
        sugerida ? fila('Propina sugerida (voluntaria)', money(sugerida)) : null,
        sugerida ? fila('Total con propina', money(Number(pedido.total) + sugerida)) : null
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

      h(
        'div',
        { class: 'doc-pie' },
        precuenta ? 'Este documento no es un comprobante de pago.' : '¡Gracias por su compra!'
      )
    ),
    'ticket',
    // Mismo papel que el ticket —el ancho del rollo es del rollo— pero una
    // sola hoja: la segunda copia del ticket es para archivar la venta, y
    // una pre-cuenta no es una venta.
    { copias: precuenta ? 1 : null }
  );
}

/**
 * El corte de caja, en papel (F7.5).
 *
 * Dos usos con el mismo documento: el **corte X** a mitad de turno, que no
 * cierra nada y sirve para revisar o para entregar el turno, y el **corte
 * Z**, que es el cierre. Es el mismo papel a propósito —quien lo archiva
 * compara dos cortes del mismo formato— con la diferencia dicha en grande,
 * porque un X que se confunda con un cierre hace contar dos veces.
 *
 * Los importes salen del backend tal como los calculó `CashSessionTotals`.
 * Aquí no se suma nada: un corte que dijera un esperado distinto al de la
 * pantalla no serviría para cuadrar.
 */
export function imprimirCorte({ sesion, totales, movimientos = [], tipo = 'X' }) {
  const esCierre = tipo === 'Z';
  const sede = activeBranch();

  const fila = (etiqueta, valor) =>
    h('div', { class: 'doc-fila' }, h('span', {}, etiqueta), h('span', {}, valor));

  imprimirDocumento(
    h(
      'div',
      { class: 'doc' },
      h(
        'div',
        { class: 'doc-cabeza' },
        h('div', { class: 'doc-titulo' }, esCierre ? 'CIERRE DE CAJA' : 'CORTE X'),
        h('div', { class: 'doc-numero' }, `Turno ${sesion.number ?? ''}`),
        // Solo si la sede tiene más de una caja: el nombre dice a cuál de
        // los dos cajones corresponde este corte.
        sesion.register_name ? h('div', {}, sesion.register_name) : null,
        h('div', {}, me()?.tenant_name ?? ''),
        sede ? h('div', {}, sede.name) : null,
        h('div', {}, `Abrió ${date(sesion.opened_at)} ${time(sesion.opened_at)} · ${sesion.opened_by_name ?? ''}`),
        sesion.closed_at
          ? h('div', {}, `Cerró ${date(sesion.closed_at)} ${time(sesion.closed_at)} · ${sesion.closed_by_name ?? ''}`)
          : h('div', {}, `Impreso ${date(new Date().toISOString())} ${time(new Date().toISOString())}`)
      ),

      // Lo cobrado por método: la tarjeta no pasa por el cajón, pero al
      // cuadrar el día hay que verla igual.
      h(
        'div',
        {},
        Object.entries(totales.by_method ?? {}).map(([metodo, importe]) =>
          fila(metodoPago(metodo), money(importe))
        )
      ),

      h(
        'div',
        { class: 'doc-total' },
        fila('Base', money(totales.opening_float)),
        fila('Cobrado', money(totales.charged)),
        Number(totales.refunded) ? fila('Reembolsado', `- ${money(totales.refunded)}`) : null,
        Number(totales.cash_in) ? fila('Entradas al cajón', money(totales.cash_in)) : null,
        Number(totales.cash_out) ? fila('Salidas del cajón', `- ${money(totales.cash_out)}`) : null,
        fila('EFECTIVO ESPERADO', money(totales.expected_cash)),
        totales.counted_cash === null ? null : fila('Contado', money(totales.counted_cash)),
        totales.difference === null ? null : fila('Diferencia', money(totales.difference))
      ),

      // En qué se fue la plata: es la pregunta que el arqueo le hace a la
      // lista de movimientos.
      movimientos.length
        ? h(
            'div',
            { class: 'doc-pie', style: 'text-align:left' },
            movimientos.map((m) =>
              fila(`${m.kind === 'in' ? '+' : '−'} ${m.reason}`, money(m.amount))
            )
          )
        : null,

      h(
        'div',
        { class: 'doc-pie' },
        esCierre ? 'Turno cerrado.' : 'Este corte NO cierra el turno.'
      )
    ),
    'ticket',
    // Una sola hoja: el corte se archiva, no se entrega al cliente.
    { copias: 1 }
  );
}
