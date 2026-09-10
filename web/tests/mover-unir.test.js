// Mover de mesa y unir cuentas (F4.3).
//
// Las dos operaciones mueven plata de sitio sin cobrarla, que es donde más
// fácil es que desaparezca. Lo que se protege aquí es que se elija mesa —no
// un número de pedido, que quien atiende no conoce— y que unir solo ofrezca
// mesas que de verdad tienen una cuenta abierta.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const PEDIDO = {
  id: 'o1',
  order_number: 'CEN-00061',
  channel: 'table',
  table_code: 'M1',
  created_at: new Date().toISOString(),
  status: { id: 's1', code: 'abierto', name: 'Abierto', category: 'new', color: null },
  subtotal: '20000.00',
  tax_total: '0.00',
  delivery_fee: '0.00',
  discount: '0.00',
  tip: '0.00',
  total: '20000.00',
  notes: null,
  is_editable: true,
  kitchen_has_it: false,
  items: [{ id: 'i1', menu_item_id: 'm1', name_snapshot: 'Plato', quantity: 1, unit_price: '20000.00', tax_amount: '0.00', line_total: '20000.00', notes: null, modifiers: [], components: [] }],
  balance: { total: '20000.00', paid: '0.00', refunded: '0.00', net_paid: '0.00', pending: '20000.00', is_settled: false },
  delivery: null,
  kitchen_tickets: [],
};

const mesa = (code, orderId = null) => ({
  id: `t-${code}`,
  code,
  capacity: 4,
  is_active: true,
  pos_x: 0,
  pos_y: 0,
  shape: 'square',
  order_id: orderId,
  order_number: orderId ? 'CEN-00062' : null,
  total: orderId ? '15000.00' : null,
  status_name: null,
  status_category: null,
  occupied_since: null,
  occupied_minutes: null,
  open_orders: orderId ? 1 : 0,
});

const MESAS = [mesa('M1', 'o1'), mesa('M2'), mesa('M3', 'o2')];

async function abrir(permisos = ['orders.view', 'orders.create', 'orders.edit']) {
  const montado = await montarApp({
    token: 't',
    hash: '#/pedidos',
    respuestas: {
      '/auth/me': sesion({ permissions: permisos, uses_tables: true, channels: ['table'] }),
      '/menu': [],
      '/orders': { items: [PEDIDO], next_cursor: null },
      '/orders/o1': PEDIDO,
      '/orders/o1/payments': [],
      '/orders/o1/next-statuses': [],
      '/orders/o1/history': [],
      '/orders/o1/fiscal-document': { document: null },
      '/orders/o1/table': PEDIDO,
      '/orders/o1/merge': PEDIDO,
      '/branches/22222222-2222-4222-8222-222222222222/tables/status': MESAS,
    },
  });

  await reposar(2);
  document.querySelectorAll('#vista button').forEach((b) => {
    if (b.textContent === 'Pedidos del día') b.click();
  });
  await reposar();
  [...document.querySelectorAll('#vista button')].find((b) => b.textContent === 'CEN-00061').click();
  await reposar(6);
  return montado;
}

const panel = () => document.querySelector('[aria-label="Detalle del pedido"]');
const boton = (etiqueta, raiz = panel()) =>
  [...raiz.querySelectorAll('button')].find((b) => b.textContent.trim() === etiqueta);
const dialogo = (etiqueta) => document.querySelector(`[aria-label="${etiqueta}"]`);

const llamada = (fetch, metodo) => {
  const hecha = fetch.mock.calls.filter(([, o]) => o?.method === metodo).at(-1);
  return hecha ? { url: String(hecha[0]), cuerpo: JSON.parse(hecha[1].body ?? 'null') } : null;
};

describe('mover de mesa y unir cuentas', () => {
  it('ofrece las dos con la mesa actual a la vista', async () => {
    await abrir();

    expect(panel().textContent).toContain('Mesa M1');
    expect(boton('Mover')).toBeDefined();
    expect(boton('Unir otra cuenta')).toBeDefined();
  });

  it('mover ofrece las otras mesas, no la propia', async () => {
    await abrir();

    boton('Mover').click();
    await reposar(4);

    const d = dialogo('Mover de mesa');
    expect(boton('M2', d)).toBeDefined();
    expect(boton('M3', d)).toBeDefined();
    expect(boton('M1', d)).toBeUndefined();
  });

  it('mover manda el código de la mesa', async () => {
    const { fetch } = await abrir();

    boton('Mover').click();
    await reposar(4);
    boton('M2', dialogo('Mover de mesa')).click();
    await reposar(4);

    expect(llamada(fetch, 'PUT')).toEqual({
      url: '/api/v1/orders/o1/table',
      cuerpo: { table_code: 'M2' },
    });
  });

  // Unir con una mesa vacía no significa nada: no hay cuenta que traer.
  it('unir solo ofrece mesas con cuenta abierta', async () => {
    await abrir();

    boton('Unir otra cuenta').click();
    await reposar(4);

    const d = dialogo('Unir cuenta');
    expect(boton('M3', d)).toBeDefined();
    expect(boton('M2', d)).toBeUndefined();
    expect(boton('M1', d)).toBeUndefined();
  });

  it('unir manda el pedido de la otra mesa', async () => {
    const { fetch } = await abrir();

    boton('Unir otra cuenta').click();
    await reposar(4);
    boton('M3', dialogo('Unir cuenta')).click();
    await reposar(4);

    expect(llamada(fetch, 'POST')).toEqual({
      url: '/api/v1/orders/o1/merge',
      cuerpo: { source_order_id: 'o2' },
    });
  });

  it('sin permiso para editar no aparece nada de esto', async () => {
    await abrir(['orders.view', 'orders.create']);

    expect(boton('Mover')).toBeUndefined();
    expect(boton('Unir otra cuenta')).toBeUndefined();
  });
});
