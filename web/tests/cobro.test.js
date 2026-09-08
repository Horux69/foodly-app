// El cobro y el reembolso, desde el panel de detalle del pedido.
//
// Lo que se protege es la aritmética que sale por el cable: que se cobre el
// monto que el cajero escribió y con el método que eligió, no el saldo
// completo con el primero de la lista. Antes la web mandaba
// `amount: saldo.pending` y ya, así que una cuenta pagada mitad en efectivo
// y mitad con tarjeta no se podía asentar.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const PEDIDO = {
  id: 'o1',
  order_number: 'CEN-00001',
  channel: 'counter',
  created_at: '2026-09-08T18:00:00Z',
  table_code: null,
  status: { id: 's1', code: 'pending', name: 'Pendiente', category: 'new', color: null },
  subtotal: '40000.00',
  tax_total: '0.00',
  delivery_fee: '0.00',
  discount: '0.00',
  tip: '0.00',
  total: '40000.00',
  notes: null,
  items: [{ id: 'i1', menu_item_id: 'm1', name_snapshot: 'Hamburguesa', quantity: 2, unit_price: '20000.00', tax_amount: '0.00', line_total: '40000.00', notes: null, modifiers: [] }],
  balance: { total: '40000.00', paid: '0.00', pending: '40000.00', is_settled: false },
  delivery: null,
};

const respuestas = () => ({
  '/auth/me': sesion({ permissions: ['orders.view', 'orders.create', 'payments.register'] }),
  '/menu': [],
  '/orders': { items: [PEDIDO], next_cursor: null },
  '/orders/o1': PEDIDO,
  '/orders/o1/payments': [],
  '/orders/o1/next-statuses': [],
  '/orders/o1/history': [],
});

const COBRO = {
  id: 'p1',
  method: 'card',
  status: 'paid',
  amount: '40000.00',
  external_reference: null,
  paid_at: '2026-09-08T18:05:00Z',
  refund_of_payment_id: null,
  note: null,
};

const REEMBOLSO = {
  id: 'p2',
  method: 'card',
  status: 'paid',
  amount: '15000.00',
  external_reference: null,
  paid_at: '2026-09-08T18:20:00Z',
  refund_of_payment_id: 'p1',
  note: 'Se cobró de más',
};

/** El pedido tal como queda tras cobrarlo entero y devolver una parte. */
const conMovimientos = (pagos, balance) => {
  const r = respuestas();
  r['/auth/me'] = sesion({ permissions: ['orders.view', 'orders.create', 'payments.register', 'payments.refund'] });
  r['/orders/o1'] = { ...PEDIDO, balance };
  r['/orders'] = { items: [{ ...PEDIDO, balance }], next_cursor: null };
  r['/orders/o1/payments'] = pagos;
  return r;
};

/** Abre la lista del día y despliega el panel del primer pedido. */
async function abrirPanel(mapa = respuestas()) {
  const montado = await montarApp({ token: 'un-token', hash: '#/pedidos', respuestas: mapa });
  document.querySelectorAll('#vista button').forEach((b) => {
    if (b.textContent === 'Pedidos del día') b.click();
  });
  await reposar();

  [...document.querySelectorAll('#vista button')].find((b) => b.textContent === 'CEN-00001').click();
  await reposar(6);
  return montado;
}

const panel = () => document.querySelector('[role="dialog"]');

/**
 * El texto del panel con los espacios duros vueltos normales: `money()` usa
 * `Intl.NumberFormat`, que separa el símbolo con U+00A0, y una aserción
 * escrita con un espacio corriente no coincidiría nunca.
 */
const textoDelPanel = () => panel().textContent.replace(/\u00a0/g, ' ');
const cuerpoDe = (llamada) => JSON.parse(llamada[1].body);

describe('cobro desde el detalle del pedido', () => {
  it('arranca ofreciendo cobrar lo que falta', async () => {
    await abrirPanel();
    expect(panel().querySelector('input[type="number"]').value).toBe('40000.00');
  });

  it('ofrece los métodos que declara el backend, no una lista escrita a mano', async () => {
    await abrirPanel();
    const opciones = [...panel().querySelectorAll('select option')].map((o) => o.textContent);
    expect(opciones).toEqual(['Efectivo', 'Tarjeta', 'Transferencia']);
  });

  it('cobra el monto escrito con el método elegido', async () => {
    const { fetch } = await abrirPanel();

    panel().querySelector('input[type="number"]').value = '15000';
    panel().querySelector('select').value = 'card';
    [...panel().querySelectorAll('button')].find((b) => b.textContent.includes('Cobrar')).click();
    await reposar();

    const cobro = fetch.mock.calls.find(([url, opciones]) => String(url).includes('/payments') && opciones?.method === 'POST');
    expect(cobro).toBeDefined();
    expect(cuerpoDe(cobro).amount).toBe(15000);
    expect(cuerpoDe(cobro).method).toBe('card');
    // Y con llave, para que un reintento no cobre dos veces.
    expect(cuerpoDe(cobro).idempotency_key).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('no ofrece cobrar sin el permiso de caja', async () => {
    const sinCaja = respuestas();
    sinCaja['/auth/me'] = sesion({ permissions: ['orders.view', 'orders.create'] });
    await abrirPanel(sinCaja);

    expect([...panel().querySelectorAll('button')].some((b) => b.textContent.includes('Cobrar'))).toBe(false);
  });
});

describe('reembolsos', () => {
  const SALDADO = { total: '40000.00', paid: '40000.00', refunded: '0.00', net_paid: '40000.00', pending: '0.00', is_settled: true };
  const DEVUELTO = { total: '40000.00', paid: '40000.00', refunded: '15000.00', net_paid: '25000.00', pending: '15000.00', is_settled: false };

  it('ofrece reembolsar un cobro confirmado', async () => {
    await abrirPanel(conMovimientos([COBRO], SALDADO));
    expect([...panel().querySelectorAll('button')].some((b) => b.textContent === 'Reembolsar')).toBe(true);
  });

  it('no lo ofrece sin el permiso payments.refund', async () => {
    const mapa = conMovimientos([COBRO], SALDADO);
    mapa['/auth/me'] = sesion({ permissions: ['orders.view', 'orders.create', 'payments.register'] });
    await abrirPanel(mapa);

    expect([...panel().querySelectorAll('button')].some((b) => b.textContent === 'Reembolsar')).toBe(false);
  });

  it('muestra el cobro y su devolución, sin borrar ninguno', async () => {
    await abrirPanel(conMovimientos([COBRO, REEMBOLSO], DEVUELTO));
    const texto = textoDelPanel();

    // El cobro sigue ahí, con lo devuelto anotado…
    expect(texto).toContain('devuelto $ 15.000');
    // …y la devolución aparece aparte, en negativo y con su motivo.
    expect(texto).toContain('Reembolso · Tarjeta');
    expect(texto).toContain('Se cobró de más');
    expect(texto).toContain('− $ 15.000');
    // El saldo vuelve a estar pendiente.
    expect(texto).toContain('Falta por cobrar');
  });

  it('un cobro ya revertido del todo no se puede volver a reembolsar', async () => {
    const revertido = { ...COBRO, status: 'refunded' };
    const completo = { ...REEMBOLSO, amount: '40000.00', note: null };
    await abrirPanel(conMovimientos([revertido, completo], { ...DEVUELTO, refunded: '40000.00', net_paid: '0.00', pending: '40000.00' }));

    expect(textoDelPanel()).toContain('Reembolsado');
    expect([...panel().querySelectorAll('button')].some((b) => b.textContent === 'Reembolsar')).toBe(false);
  });

  it('manda el monto y el motivo que se escribieron', async () => {
    const { fetch } = await abrirPanel(conMovimientos([COBRO], SALDADO));

    [...panel().querySelectorAll('button')].find((b) => b.textContent === 'Reembolsar').click();
    await reposar();

    const dialogo = [...document.querySelectorAll('[role="dialog"]')].pop();
    dialogo.querySelector('input[type="number"]').value = '5000';
    dialogo.querySelector('input[placeholder="Por qué se devuelve"]').value = 'Plato frío';
    [...dialogo.querySelectorAll('button')].find((b) => b.textContent === 'Reembolsar').click();
    await reposar();

    const llamada = fetch.mock.calls.find(([url, o]) => String(url).includes('/refund') && o?.method === 'POST');
    expect(llamada).toBeDefined();
    expect(cuerpoDe(llamada).amount).toBe(5000);
    expect(cuerpoDe(llamada).note).toBe('Plato frío');
    expect(String(llamada[0])).toContain('/orders/o1/payments/p1/refund');
  });
});
