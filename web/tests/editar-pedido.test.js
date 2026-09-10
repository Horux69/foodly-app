// Modificar un pedido abierto desde el detalle (F4.0).
//
// Lo que se protege aquí no es el CRUD: es que la pantalla obedezca al
// backend en vez de decidir por su cuenta. Quién puede editar lo dice
// `is_editable` —que sale de la categoría del estado, no de su código— y el
// permiso `orders.edit`. Una pantalla que lo dedujera sola volvería a
// separarse del servidor en cuanto un restaurante renombrara sus estados.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const linea = (id, nombre, cantidad, total) => ({
  id,
  menu_item_id: `m-${id}`,
  name_snapshot: nombre,
  quantity: cantidad,
  unit_price: '20000.00',
  tax_amount: '0.00',
  line_total: total,
  notes: null,
  modifiers: [],
  components: [],
});

const PEDIDO = {
  id: 'o1',
  order_number: 'SUR-00010',
  channel: 'counter',
  created_at: new Date().toISOString(),
  table_code: null,
  status: { id: 's1', code: 'recibido', name: 'Recibido', category: 'new', color: null },
  subtotal: '40000.00',
  tax_total: '0.00',
  delivery_fee: '0.00',
  discount: '0.00',
  tip: '0.00',
  total: '40000.00',
  notes: null,
  is_editable: true,
  kitchen_has_it: false,
  items: [linea('i1', 'Hamburguesa', 2, '40000.00')],
  balance: { total: '40000.00', paid: '0.00', refunded: '0.00', net_paid: '0.00', pending: '40000.00', is_settled: false },
  delivery: null,
};

const MENU = [
  {
    id: 'c1',
    name: 'Carta',
    items: [
      { id: 'm2', name: 'Gaseosa', description: null, price: '5000.00', is_available: true, modifier_groups: [], components: [] },
      {
        id: 'm3',
        name: 'Pizza',
        description: null,
        price: '30000.00',
        is_available: true,
        components: [],
        modifier_groups: [
          {
            id: 'g1',
            name: 'Tamaño',
            min_select: 1,
            max_select: 1,
            is_required: true,
            rule: 'Elige 1',
            modifiers: [{ id: 'mod1', name: 'Grande', price_delta: '0.00', is_available: true }],
          },
        ],
      },
      { id: 'm4', name: 'Agotada', description: null, price: '1000.00', is_available: false, modifier_groups: [], components: [] },
    ],
  },
];

// `orders.create` va en la lista porque sin él la pantalla de pedidos no es
// accesible y el enrutador manda a la primera permitida — que sería cocina.
async function abrirDetalle(pedido = PEDIDO, permisos = ['orders.view', 'orders.create', 'orders.edit']) {
  const montado = await montarApp({
    token: 't',
    hash: '#/pedidos',
    respuestas: {
      '/auth/me': sesion({ permissions: permisos }),
      '/orders': { items: [pedido], next_cursor: null },
      [`/orders/${pedido.id}`]: pedido,
      [`/orders/${pedido.id}/payments`]: [],
      [`/orders/${pedido.id}/next-statuses`]: [],
      [`/orders/${pedido.id}/history`]: [],
      '/menu': MENU,
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
const boton = (etiqueta, raiz = document) =>
  [...raiz.querySelectorAll('button')].find((b) => (b.getAttribute('aria-label') ?? b.textContent).trim() === etiqueta);

const llamada = (fetch, metodo) => {
  const hecha = fetch.mock.calls.filter(([, o]) => o?.method === metodo).at(-1);
  return hecha ? { url: String(hecha[0]), cuerpo: JSON.parse(hecha[1].body ?? 'null') } : null;
};

describe('editar un pedido abierto', () => {
  it('ofrece los controles cuando el backend dice que se puede', async () => {
    await abrirDetalle();

    expect(boton('Agregar una unidad de Hamburguesa', panel())).toBeDefined();
    expect(boton('Quitar Hamburguesa del pedido', panel())).toBeDefined();
    expect(boton('Agregar', panel())).toBeDefined();
  });

  // La categoría del estado la decide el restaurante; la pantalla solo
  // obedece la respuesta.
  it('no los ofrece si el pedido ya no es editable', async () => {
    await abrirDetalle({ ...PEDIDO, is_editable: false, status: { ...PEDIDO.status, category: 'completed' } });

    expect(boton('Agregar una unidad de Hamburguesa', panel())).toBeUndefined();
    expect(boton('Agregar', panel())).toBeUndefined();
  });

  it('tampoco sin el permiso orders.edit', async () => {
    await abrirDetalle(PEDIDO, ['orders.view', 'orders.create']);

    expect(boton('Agregar una unidad de Hamburguesa', panel())).toBeUndefined();
  });

  it('subir la cantidad manda la nueva, no un incremento', async () => {
    const { fetch } = await abrirDetalle();

    boton('Agregar una unidad de Hamburguesa', panel()).click();
    await reposar(3);

    expect(llamada(fetch, 'PATCH')).toEqual({
      url: '/api/v1/orders/o1/items/i1',
      cuerpo: { quantity: 3 },
    });
  });

  // Bajar de 1 sería 0, que el backend rechaza: para eso está Quitar.
  it('no deja bajar de una unidad', async () => {
    await abrirDetalle({ ...PEDIDO, items: [linea('i1', 'Hamburguesa', 1, '20000.00')] });

    expect(boton('Quitar una unidad de Hamburguesa', panel()).disabled).toBe(true);
  });

  it('quitar una línea la borra por su id', async () => {
    const { fetch } = await abrirDetalle();

    boton('Quitar Hamburguesa del pedido', panel()).click();
    await reposar(3);

    expect(llamada(fetch, 'DELETE').url).toBe('/api/v1/orders/o1/items/i1');
  });

  it('avisa cuando la cocina ya lo tiene, sin impedir el cambio', async () => {
    await abrirDetalle({ ...PEDIDO, kitchen_has_it: true, status: { ...PEDIDO.status, category: 'kitchen' } });

    expect(panel().textContent).toContain('La cocina ya tiene este pedido');
    expect(boton('Quitar Hamburguesa del pedido', panel())).toBeDefined();
  });

  it('agregar un producto sin opciones lo manda directo', async () => {
    const { fetch } = await abrirDetalle();

    boton('Agregar', panel()).click();
    await reposar(4);

    const dialogo = document.querySelector('[aria-label="Agregar productos al pedido"]');
    expect(dialogo.textContent).toContain('Gaseosa');
    // Lo agotado no se ofrece: el backend lo rechazaría al agregarlo.
    expect(dialogo.textContent).not.toContain('Agotada');

    [...dialogo.querySelectorAll('button')].find((b) => b.textContent.includes('Gaseosa')).click();
    await reposar(3);

    expect(llamada(fetch, 'POST')).toEqual({
      url: '/api/v1/orders/o1/items',
      cuerpo: { items: [{ menu_item_id: 'm2', quantity: 1, modifier_ids: [] }] },
    });
  });

  // El mismo diálogo que la pantalla de venta: un grupo obligatorio se
  // pregunta también aquí, y no se puede agregar sin cumplirlo.
  it('un producto con grupo obligatorio pregunta antes de agregar', async () => {
    const { fetch } = await abrirDetalle();

    boton('Agregar', panel()).click();
    await reposar(4);

    const lista = document.querySelector('[aria-label="Agregar productos al pedido"]');
    [...lista.querySelectorAll('button')].find((b) => b.textContent.includes('Pizza')).click();
    await reposar(3);

    expect(llamada(fetch, 'POST')).toBeNull();
    expect(document.body.textContent).toContain('Falta elegir: Tamaño');
  });
});
