// Comanda de cocina y ticket de cliente.
//
// Se protege lo que distingue a un documento del otro: la comanda no lleva
// precios —a la cocina el dinero no le sirve y le quita sitio a lo que sí— y
// el ticket no lleva las notas de preparación. Y que una comanda repetida
// salga marcada, que es lo que evita que se prepare el mismo plato dos veces.

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const PEDIDO = {
  id: 'o1',
  order_number: 'SUR-00004',
  channel: 'delivery',
  created_at: '2026-09-09T12:03:00Z',
  table_code: null,
  status: { id: 's1', code: 'pending', name: 'Pendiente', category: 'new', color: null },
  subtotal: '39000.00',
  tax_total: '0.00',
  delivery_fee: '5000.00',
  discount: '0.00',
  tip: '0.00',
  total: '44000.00',
  notes: 'Tocar el timbre dos veces',
  items: [
    {
      id: 'i1',
      menu_item_id: 'm1',
      name_snapshot: 'Pizza margarita',
      quantity: 1,
      unit_price: '30000.00',
      tax_amount: '0.00',
      line_total: '30000.00',
      notes: 'Sin albahaca',
      modifiers: [{ modifier_id: 'x', name_snapshot: 'Masa delgada', price_delta: '0.00' }],
    },
    { id: 'i2', menu_item_id: 'm2', name_snapshot: 'Gaseosa', quantity: 2, unit_price: '4500.00', tax_amount: '0.00', line_total: '9000.00', notes: null, modifiers: [] },
    {
      id: 'i3',
      menu_item_id: 'm3',
      name_snapshot: 'Combo del día',
      quantity: 1,
      unit_price: '28000.00',
      tax_amount: '0.00',
      line_total: '28000.00',
      notes: null,
      modifiers: [],
      // Ya multiplicados por la cantidad y congelados al vender.
      components: [
        { menu_item_id: 'm1', name_snapshot: 'Hamburguesa', quantity: 1 },
        { menu_item_id: 'm2', name_snapshot: 'Papas', quantity: 2 },
      ],
    },
  ],
  balance: { total: '44000.00', paid: '9000.00', refunded: '0.00', net_paid: '9000.00', pending: '35000.00', is_settled: false },
  delivery: { order_id: 'o1', address: 'Calle 1 #2-3', zone_id: null, zone_name: 'Norte', courier_id: null, courier_name: null, estimated_time: null, dispatched_at: null, delivered_at: null },
};

const PAGOS = [
  { id: 'p1', method: 'cash', status: 'paid', amount: '9000.00', external_reference: null, paid_at: '2026-09-09T12:10:00Z', refund_of_payment_id: null, note: null },
];

const respuestas = () => ({
  '/auth/me': sesion({
    permissions: ['orders.view', 'orders.create'],
    tenant_name: 'Pizza Rápida',
    branches: [{ id: '22222222-2222-4222-8222-222222222222', name: 'Sede Sur', code: 'SUR', address: 'Av. Siempre Viva 742', phone: '3001234567' }],
  }),
  '/menu': [],
  '/orders': { items: [PEDIDO], next_cursor: null },
  '/orders/o1': PEDIDO,
  '/orders/o1/payments': PAGOS,
  '/orders/o1/next-statuses': [],
  '/orders/o1/history': [],
});

const documento = () => document.getElementById('impresion').textContent.replace(/ /g, ' ');
const pulsarEnPanel = (etiqueta) =>
  [...document.querySelector('[role="dialog"]').querySelectorAll('button')]
    .find((b) => b.textContent.trim() === etiqueta)
    .click();

async function abrirPanel() {
  const montado = await montarApp({ token: 'un-token', hash: '#/pedidos', respuestas: respuestas() });
  document.querySelectorAll('#vista button').forEach((b) => {
    if (b.textContent === 'Pedidos del día') b.click();
  });
  await reposar();
  [...document.querySelectorAll('#vista button')].find((b) => b.textContent === 'SUR-00004').click();
  await reposar(6);
  return montado;
}

beforeEach(() => {
  vi.stubGlobal('print', vi.fn());
});

describe('comanda de cocina', () => {
  it('lleva lo que hay que preparar', async () => {
    await abrirPanel();
    pulsarEnPanel('Comanda');
    await reposar();

    const d = documento();
    expect(d).toContain('COMANDA');
    expect(d).toContain('SUR-00004');
    expect(d).toContain('1×');
    expect(d).toContain('Pizza margarita');
    expect(d).toContain('Masa delgada');
    expect(d).toContain('Sin albahaca');
    // La dirección va en la comanda de un domicilio: sale con el pedido.
    expect(d).toContain('Calle 1 #2-3');
  });

  // A la cocina no le sirve "Combo del día": le sirven los platos.
  it('desarma el combo en lo que hay que preparar', async () => {
    await abrirPanel();
    pulsarEnPanel('Comanda');
    await reposar();

    const d = documento();
    expect(d).toContain('Combo del día');
    expect(d).toContain('1× Hamburguesa · 2× Papas');
  });

  it('no lleva precios', async () => {
    await abrirPanel();
    pulsarEnPanel('Comanda');
    await reposar();

    const d = documento();
    expect(d).not.toContain('30.000');
    expect(d).not.toContain('TOTAL');
  });

  it('la primera impresión no va marcada como repetida', async () => {
    await abrirPanel();
    pulsarEnPanel('Comanda');
    await reposar();
    expect(documento()).not.toContain('REIMPRESIÓN');
  });
});

describe('ticket de cliente', () => {
  it('identifica al restaurante y a la sede', async () => {
    await abrirPanel();
    pulsarEnPanel('Ticket');
    await reposar();

    const d = documento();
    expect(d).toContain('Pizza Rápida');
    expect(d).toContain('Sede Sur');
    expect(d).toContain('Av. Siempre Viva 742');
  });

  it('lleva los importes que calculó el backend', async () => {
    await abrirPanel();
    pulsarEnPanel('Ticket');
    await reposar();

    const d = documento();
    expect(d).toContain('$ 39.000');
    expect(d).toContain('$ 5.000');
    expect(d).toContain('$ 44.000');
    // Lo cobrado y lo que falta, para que el cliente no tenga que preguntar.
    expect(d).toContain('Efectivo');
    expect(d).toContain('Falta por pagar');
  });

  it('no lleva las notas de preparación', async () => {
    await abrirPanel();
    pulsarEnPanel('Ticket');
    await reposar();
    expect(documento()).not.toContain('Sin albahaca');
  });
});

describe('reimpresión desde cocina', () => {
  // El KDS manda los modificadores como texto plano y el detalle como
  // objeto: la comanda tiene que entender los dos o se imprimiría "[object
  // Object]" delante del cocinero.
  const TABLERO = {
    columns: ['new', 'kitchen', 'ready'],
    dispatched: [],
    orders: [
      {
        id: 'o1',
        order_number: 'SUR-00004',
        channel: 'delivery',
        table_code: null,
        created_at: '2026-09-09T12:03:00Z',
        status: { id: 's1', code: 'preparing', name: 'Preparación', category: 'kitchen', color: null },
        items: [{ id: 'ki1', name_snapshot: 'Pizza margarita', quantity: 1, notes: 'Sin albahaca', modifiers: ['Masa delgada'] }],
        next_statuses: [],
      },
    ],
  };

  const enCocina = () =>
    montarApp({
      token: 'un-token',
      hash: '#/cocina',
      respuestas: { ...respuestas(), '/kitchen/orders': TABLERO },
    });

  it('sale marcada, para que no se prepare dos veces lo mismo', async () => {
    await enCocina();
    await reposar(4);

    [...document.querySelectorAll('#vista button')].find((b) => b.textContent.includes('Reimprimir')).click();
    await reposar();

    const d = documento();
    expect(d).toContain('REIMPRESIÓN');
    expect(d).toContain('puede estar ya preparado');
    expect(d).toContain('SUR-00004');
  });

  it('entiende los modificadores que manda el KDS', async () => {
    await enCocina();
    await reposar(4);

    [...document.querySelectorAll('#vista button')].find((b) => b.textContent.includes('Reimprimir')).click();
    await reposar();

    expect(documento()).toContain('Masa delgada');
    expect(documento()).not.toContain('[object');
  });
});

describe('el ciclo de impresión', () => {
  it('llama a print y marca el body mientras dura', async () => {
    await abrirPanel();
    pulsarEnPanel('Comanda');
    await reposar();

    expect(window.print).toHaveBeenCalledTimes(1);
    expect(document.body.classList.contains('imprimiendo')).toBe(true);
  });

  it('al terminar suelta el documento y devuelve la aplicación', async () => {
    await abrirPanel();
    pulsarEnPanel('Comanda');
    await reposar();

    window.dispatchEvent(new Event('afterprint'));
    await reposar();

    expect(document.body.classList.contains('imprimiendo')).toBe(false);
    // Sin esto, un Ctrl+P posterior sacaría el documento anterior.
    expect(document.getElementById('impresion').childElementCount).toBe(0);
  });
});
