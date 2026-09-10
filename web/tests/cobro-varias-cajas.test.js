// Cobrar cuando la sucursal tiene más de una caja abierta (F7.4).
//
// Va en su propio archivo: montar la pantalla de caja deja vivo un fetch en
// curso de `/branches/.../registers` que, al resolverse durante la prueba
// siguiente, contamina el DOM que esa prueba ya reemplazó.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const boton = (etiqueta, raiz = document) =>
  [...raiz.querySelectorAll('button')].find((b) => b.textContent.trim() === etiqueta);

describe('cobrar cuando hay dos cajas abiertas', () => {
  const PEDIDO = {
    id: 'o1',
    order_number: 'CEN-00050',
    channel: 'counter',
    table_code: null,
    created_at: new Date().toISOString(),
    status: { id: 's1', code: 'pendiente', name: 'Pendiente', category: 'new', color: null },
    subtotal: '18000.00',
    tax_total: '0.00',
    delivery_fee: '0.00',
    discount: '0.00',
    tip: '0.00',
    total: '18000.00',
    notes: null,
    is_editable: true,
    kitchen_has_it: false,
    is_server_assignable: true,
    courses: [],
    pending_courses: [],
    items: [
      {
        id: 'i1', menu_item_id: 'm1', name_snapshot: 'Hamburguesa', quantity: 1,
        unit_price: '18000.00', tax_amount: '0.00', line_total: '18000.00', notes: null,
        course: 1, fired_at: null, modifiers: [], components: [],
      },
    ],
    balance: {
      total: '18000.00', paid: '0.00', refunded: '0.00', net_paid: '0.00',
      pending: '18000.00', is_settled: false,
    },
    delivery: null,
    kitchen_tickets: [],
  };

  async function abrirPanel() {
    const montado = await montarApp({
      token: 't',
      hash: '#/pedidos',
      respuestas: {
        '/auth/me': sesion({ permissions: ['orders.view', 'orders.create', 'payments.register'] }),
        '/menu': [],
        '/sales-sources': [],
        '/cash/registers/open': [
          { register_id: 'r1', register_name: 'Mostrador' },
          { register_id: 'r2', register_name: 'Barra' },
        ],
        '/orders': { items: [PEDIDO], next_cursor: null },
        '/orders/o1': PEDIDO,
        '/orders/o1/payments': [],
        '/orders/o1/next-statuses': [],
        '/orders/o1/history': [],
        '/orders/o1/fiscal-document': { document: null },
      },
    });
    await reposar(2);
    document.querySelectorAll('#vista button').forEach((b) => {
      if (b.textContent === 'Pedidos del día') b.click();
    });
    await reposar();
    [...document.querySelectorAll('#vista button')].find((b) => b.textContent === 'CEN-00050').click();
    await reposar(6);
    return montado;
  }

  const panel = () => document.querySelector('[aria-label="Detalle del pedido"]');

  it('ofrece elegir la caja antes de cobrar', async () => {
    await abrirPanel();
    await reposar(2);

    expect(panel().textContent).toContain('Caja:');
    expect(boton('Mostrador', panel())).toBeDefined();
    expect(boton('Barra', panel())).toBeDefined();
  });

  it('cobrar manda la caja elegida', async () => {
    const { fetch } = await abrirPanel();
    await reposar(2);

    boton('Barra', panel()).click();
    await reposar();
    boton('Cobrar', panel()).click();
    await reposar(3);

    const post = fetch.mock.calls.find(([u, o]) => String(u).includes('/payments') && o?.method === 'POST');
    expect(JSON.parse(post[1].body).register_id).toBe('r2');
  });
});
