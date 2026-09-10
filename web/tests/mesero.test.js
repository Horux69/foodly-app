// El mesero a cargo de la cuenta (F4.4).
//
// De este nombre depende el reparto de la propina, que es plata de alguien.
// Lo que se protege aquí: que la pantalla no invente la ventana en la que
// todavía se puede cambiar —la decide el backend (`is_server_assignable`)—,
// que la lista de a quién se puede asignar venga del servidor y no de un
// rol escrito a mano, y que el salón diga de quién es cada mesa.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const YO = '11111111-1111-4111-8111-111111111111';
const SUCURSAL = '22222222-2222-4222-8222-222222222222';

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
  is_server_assignable: true,
  server_id: 'u-ana',
  server_name: 'Ana',
  items: [
    {
      id: 'i1',
      menu_item_id: 'm1',
      name_snapshot: 'Plato',
      quantity: 1,
      unit_price: '20000.00',
      tax_amount: '0.00',
      line_total: '20000.00',
      notes: null,
      modifiers: [],
      components: [],
    },
  ],
  balance: {
    total: '20000.00',
    paid: '0.00',
    refunded: '0.00',
    net_paid: '0.00',
    pending: '20000.00',
    is_settled: false,
  },
  delivery: null,
  kitchen_tickets: [],
};

const EQUIPO = [
  { id: 'u-ana', name: 'Ana' },
  { id: 'u-luis', name: 'Luis' },
];

const mesa = (code, extra = {}) => ({
  id: `t-${code}`,
  code,
  capacity: 4,
  is_active: true,
  pos_x: 0,
  pos_y: 0,
  shape: 'square',
  order_id: null,
  order_number: null,
  total: null,
  status_name: null,
  status_category: null,
  server_id: null,
  server_name: null,
  occupied_since: null,
  occupied_minutes: null,
  open_orders: 0,
  ...extra,
});

const panel = () => document.querySelector('[aria-label="Detalle del pedido"]');
const dialogo = (etiqueta) => document.querySelector(`[aria-label="${etiqueta}"]`);
const boton = (etiqueta, raiz) =>
  [...(raiz ?? panel()).querySelectorAll('button')].find((b) => b.textContent.trim() === etiqueta);

const llamada = (fetch, metodo) => {
  const hecha = fetch.mock.calls.filter(([, o]) => o?.method === metodo).at(-1);
  return hecha ? { url: String(hecha[0]), cuerpo: JSON.parse(hecha[1].body ?? 'null') } : null;
};

async function abrirDetalle(pedido = PEDIDO, permisos = ['orders.view', 'orders.create', 'orders.assign_server']) {
  const montado = await montarApp({
    token: 't',
    hash: '#/pedidos',
    respuestas: {
      '/auth/me': sesion({ permissions: permisos, uses_tables: true, channels: ['table'] }),
      '/menu': [],
      '/servers': EQUIPO,
      '/orders': { items: [pedido], next_cursor: null },
      '/orders/o1': pedido,
      '/orders/o1/payments': [],
      '/orders/o1/next-statuses': [],
      '/orders/o1/history': [],
      '/orders/o1/fiscal-document': { document: null },
      '/orders/o1/server': pedido,
      [`/branches/${SUCURSAL}/tables/status`]: [mesa('M1', { order_id: 'o1' })],
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

describe('mesero a cargo', () => {
  it('dice quién atiende la cuenta', async () => {
    await abrirDetalle();

    expect(panel().textContent).toContain('Mesero');
    expect(panel().textContent).toContain('Ana');
    expect(boton('Cambiar')).toBeDefined();
  });

  it('cambia el mesero con la lista que da el servidor', async () => {
    const { fetch } = await abrirDetalle();

    boton('Cambiar').click();
    await reposar(4);
    const d = dialogo('Mesero a cargo');
    expect(boton('Ana', d)).toBeDefined();

    boton('Luis', d).click();
    await reposar(4);

    expect(llamada(fetch, 'PUT')).toEqual({
      url: '/api/v1/orders/o1/server',
      cuerpo: { server_id: 'u-luis' },
    });
  });

  // Dejar la mesa sin mesero es una elección válida, no un campo olvidado.
  it('deja quitar el mesero', async () => {
    const { fetch } = await abrirDetalle();

    boton('Cambiar').click();
    await reposar(4);
    boton('Dejar sin mesero', dialogo('Mesero a cargo')).click();
    await reposar(4);

    expect(llamada(fetch, 'PUT').cuerpo).toEqual({ server_id: null });
  });

  /**
   * La ventana la decide el backend: con la cuenta cerrada el nombre se
   * sigue viendo —saber de quién fue la mesa es la mitad del valor— pero ya
   * no se cambia.
   */
  it('con la cuenta cerrada se ve pero no se cambia', async () => {
    await abrirDetalle({
      ...PEDIDO,
      is_server_assignable: false,
      is_editable: false,
      status: { id: 's4', code: 'servido', name: 'Servido', category: 'completed', color: null },
    });

    expect(panel().textContent).toContain('Ana');
    expect(boton('Cambiar')).toBeUndefined();
  });

  it('sin el permiso no se ofrece cambiarlo', async () => {
    await abrirDetalle(PEDIDO, ['orders.view', 'orders.create']);

    expect(panel().textContent).toContain('Ana');
    expect(boton('Cambiar')).toBeUndefined();
  });
});

describe('el salón dice de quién es cada mesa', () => {
  const montarSalon = (mesas) =>
    montarApp({
      token: 't',
      hash: '#/salon',
      respuestas: {
        '/auth/me': sesion({
          permissions: ['orders.view', 'orders.create'],
          uses_tables: true,
          channels: ['table'],
        }),
        [`/branches/${SUCURSAL}/tables/status`]: mesas,
        '/menu': [],
        '/orders': { items: [], next_cursor: null },
      },
    });

  const ocupada = (code, servidor, extra = {}) =>
    mesa(code, {
      order_id: `o-${code}`,
      order_number: `CEN-000${code}`,
      total: '30000.00',
      status_name: 'Abierto',
      status_category: 'new',
      occupied_since: new Date().toISOString(),
      occupied_minutes: 12,
      open_orders: 1,
      server_id: servidor?.id ?? null,
      server_name: servidor?.name ?? null,
      ...extra,
    });

  it('escribe el nombre del mesero en la mesa ocupada', async () => {
    const { destroy } = await montarSalon([ocupada('M1', { id: YO, name: 'Ana Cajera' })]);
    await reposar(2);

    expect(document.querySelector('#vista').textContent).toContain('Ana Cajera');
    destroy?.();
  });

  /**
   * "Mis mesas" atenúa las ajenas en vez de esconderlas: el salón es un
   * plano, y un plano con huecos deja de parecerse al salón.
   */
  it('«mis mesas» atenúa las de los demás sin quitarlas', async () => {
    await montarSalon([
      ocupada('M1', { id: YO, name: 'Ana Cajera' }),
      ocupada('M2', { id: 'u-luis', name: 'Luis' }, { pos_x: 1 }),
    ]);
    await reposar(2);

    const mesas = () => [...document.querySelectorAll('#vista .absolute')];
    expect(mesas()).toHaveLength(2);
    expect(mesas().filter((m) => m.className.includes('opacity-40'))).toHaveLength(0);

    [...document.querySelectorAll('button')].find((b) => b.textContent.includes('Mis mesas')).click();
    await reposar();

    const atenuadas = mesas().filter((m) => m.className.includes('opacity-40'));
    expect(mesas()).toHaveLength(2);
    expect(atenuadas).toHaveLength(1);
    expect(atenuadas[0].textContent).toContain('M2');
  });
});

/**
 * La tableta compartida del pasillo: quien tiene la sesión abierta no es
 * necesariamente quien atiende, así que la venta pregunta —y arranca en uno
 * mismo, que es el caso común y evita que la cuenta salga sin dueño.
 */
describe('tomar el pedido a nombre de un mesero', () => {
  const MENU = [
    {
      id: 'c1',
      name: 'Carta',
      items: [
        { id: 'i1', name: 'Plato', description: null, price: '20000.00', is_available: true, modifier_groups: [] },
      ],
    },
  ];

  const montarVenta = (permisos) =>
    montarApp({
      token: 't',
      hash: '#/pedidos',
      respuestas: {
        '/auth/me': sesion({
          permissions: permisos,
          uses_tables: true,
          channels: ['table'],
          user_id: 'u-ana',
          name: 'Ana',
        }),
        '/menu': MENU,
        '/servers': EQUIPO,
        '/orders/preview': {
          subtotal: '20000.00',
          tax_total: '0.00',
          delivery_fee: '0.00',
          discount: '0.00',
          tip: '0.00',
          total: '20000.00',
        },
        '/orders': { id: 'o9', order_number: 'CEN-00070', total: '20000.00' },
      },
    });

  const tocar = (texto) =>
    [...document.querySelectorAll('button')].find((b) => b.textContent.trim().startsWith(texto));

  it('arranca en quien tiene la sesión y manda su id', async () => {
    const { fetch } = await montarVenta(['orders.create', 'orders.view', 'orders.assign_server']);
    await reposar(2);

    const desplegable = document.querySelector('[aria-label="Mesero a cargo"]');
    expect(desplegable.value).toBe('u-ana');

    tocar('Plato').click();
    await reposar(2);
    desplegable.value = 'u-luis';
    tocar('Crear pedido').click();
    await reposar(4);

    expect(llamada(fetch, 'POST').cuerpo.server_id).toBe('u-luis');
  });

  it('sin el permiso no aparece el campo y el pedido no lo manda', async () => {
    const { fetch } = await montarVenta(['orders.create', 'orders.view']);
    await reposar(2);

    expect(document.querySelector('[aria-label="Mesero a cargo"]')).toBeNull();

    tocar('Plato').click();
    await reposar(2);
    tocar('Crear pedido').click();
    await reposar(4);

    expect(llamada(fetch, 'POST').cuerpo).not.toHaveProperty('server_id');
  });
});
