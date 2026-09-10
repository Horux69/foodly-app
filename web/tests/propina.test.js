// La propina se decide al cobrar (F7.3).
//
// Antes viajaba en el cuerpo del pedido y no se podía tocar después: había
// que adivinarla antes de que el cliente pagara. Lo que se protege aquí es
// que se sugiera el porcentaje del restaurante, que se pueda quitar de un
// toque —es voluntaria— y que el saldo por cobrar la incluya, para que el
// cajero cobre una sola vez.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const PEDIDO = {
  id: 'o1',
  order_number: 'CEN-00007',
  channel: 'table',
  created_at: new Date().toISOString(),
  table_code: 'M4',
  status: { id: 's1', code: 'abierta', name: 'Abierta', category: 'new', color: null },
  subtotal: '50000.00',
  tax_total: '0.00',
  delivery_fee: '0.00',
  discount: '0.00',
  tip: '0.00',
  total: '50000.00',
  notes: null,
  is_editable: true,
  kitchen_has_it: false,
  items: [{ id: 'i1', menu_item_id: 'm1', name_snapshot: 'Plato', quantity: 1, unit_price: '50000.00', tax_amount: '0.00', line_total: '50000.00', notes: null, modifiers: [], components: [] }],
  balance: { total: '50000.00', paid: '0.00', refunded: '0.00', net_paid: '0.00', pending: '50000.00', is_settled: false },
  delivery: null,
};

async function abrir(pedido = PEDIDO, extraSesion = {}) {
  const montado = await montarApp({
    token: 't',
    hash: '#/pedidos',
    respuestas: {
      '/auth/me': sesion({
        permissions: ['orders.view', 'orders.create', 'payments.register'],
        asks_tip: true,
        tip_percent: 10,
        uses_tables: true,
        channels: ['table'],
        ...extraSesion,
      }),
      '/menu': [],
      '/orders': { items: [pedido], next_cursor: null },
      [`/orders/${pedido.id}`]: pedido,
      [`/orders/${pedido.id}/payments`]: [],
      [`/orders/${pedido.id}/next-statuses`]: [],
      [`/orders/${pedido.id}/history`]: [],
      [`/orders/${pedido.id}/tip`]: pedido,
    },
  });

  await reposar(2);
  document.querySelectorAll('#vista button').forEach((b) => {
    if (b.textContent === 'Pedidos del día') b.click();
  });
  await reposar();
  [...document.querySelectorAll('#vista button')].find((b) => b.textContent === pedido.order_number).click();
  await reposar(6);
  return montado;
}

const panel = () => document.querySelector('[aria-label="Detalle del pedido"]');
const boton = (etiqueta) =>
  [...panel().querySelectorAll('button')].find(
    (b) => (b.getAttribute('aria-label') ?? b.textContent).trim() === etiqueta
  );

const ultimoPut = (fetch) => {
  const llamada = fetch.mock.calls.filter(([, o]) => o?.method === 'PUT').at(-1);
  return llamada ? { url: String(llamada[0]), cuerpo: JSON.parse(llamada[1].body ?? 'null') } : null;
};

describe('propina al cobrar', () => {
  it('sugiere el porcentaje que configuró el restaurante', async () => {
    await abrir();
    // 10% de 50.000
    expect(boton('Propina sugerida').textContent).toContain('5.000');
  });

  it('al aceptarla manda el importe, no el porcentaje', async () => {
    const { fetch } = await abrir();

    boton('Propina sugerida').click();
    await reposar(3);

    expect(ultimoPut(fetch)).toEqual({ url: '/api/v1/orders/o1/tip', cuerpo: { amount: 5000 } });
  });

  it('se puede poner otra distinta', async () => {
    const { fetch } = await abrir();

    panel().querySelector('[aria-label="Otra propina"]').value = '7500';
    boton('Poner otra propina').click();
    await reposar(3);

    expect(ultimoPut(fetch).cuerpo).toEqual({ amount: 7500 });
  });

  // Es voluntaria: quitarla es un toque, no una negociación.
  it('la puesta se puede quitar', async () => {
    const conPropina = {
      ...PEDIDO,
      tip: '5000.00',
      total: '55000.00',
      balance: { ...PEDIDO.balance, total: '55000.00', pending: '55000.00' },
    };
    const { fetch } = await abrir(conPropina);

    boton('Sin propina').click();
    await reposar(3);

    expect(ultimoPut(fetch).cuerpo).toEqual({ amount: 0 });
  });

  it('un restaurante que no la recibe no la ofrece', async () => {
    await abrir(PEDIDO, { asks_tip: false });

    expect(boton('Propina sugerida')).toBeUndefined();
    expect(panel().querySelector('[aria-label="Otra propina"]')).toBeNull();
  });
});
