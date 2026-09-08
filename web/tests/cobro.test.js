// El cobro, desde el panel de detalle del pedido.
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

/** Abre la lista del día y despliega el panel del primer pedido. */
async function abrirPanel() {
  const montado = await montarApp({ token: 'un-token', hash: '#/pedidos', respuestas: respuestas() });
  document.querySelectorAll('#vista button').forEach((b) => {
    if (b.textContent === 'Pedidos del día') b.click();
  });
  await reposar();

  [...document.querySelectorAll('#vista button')].find((b) => b.textContent === 'CEN-00001').click();
  await reposar(6);
  return montado;
}

const panel = () => document.querySelector('[role="dialog"]');
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
    await montarApp({ token: 'un-token', hash: '#/pedidos', respuestas: sinCaja });
    document.querySelectorAll('#vista button').forEach((b) => {
      if (b.textContent === 'Pedidos del día') b.click();
    });
    await reposar();
    [...document.querySelectorAll('#vista button')].find((b) => b.textContent === 'CEN-00001').click();
    await reposar(6);

    expect([...panel().querySelectorAll('button')].some((b) => b.textContent.includes('Cobrar'))).toBe(false);
  });
});
