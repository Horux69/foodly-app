// Tiempos de la cuenta y marchar (F4.5).
//
// Lo que se protege: que al tomar el pedido cada línea lleve su tiempo —sin
// eso el backend manda todo junto a la cocina y los postres salen con las
// entradas—, que el detalle deje marchar solo lo que falta y que marchar
// saque la comanda de ese tiempo y no la del pedido entero: la cocina no
// necesita otra vez el papel de lo que ya preparó.

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const TIEMPOS = ['Entradas', 'Fuertes', 'Postres'];
const SUCURSAL = '22222222-2222-4222-8222-222222222222';

const MENU = [
  {
    id: 'c1',
    name: 'Carta',
    items: [
      { id: 'i1', name: 'Sopa', description: null, price: '12000.00', is_available: true, modifier_groups: [] },
      { id: 'i2', name: 'Torta', description: null, price: '8000.00', is_available: true, modifier_groups: [] },
    ],
  },
];

const linea = (id, nombre, curso, salida) => ({
  id,
  menu_item_id: `m-${id}`,
  name_snapshot: nombre,
  quantity: 1,
  unit_price: '12000.00',
  tax_amount: '0.00',
  line_total: '12000.00',
  notes: null,
  course: curso,
  fired_at: salida,
  modifiers: [],
  components: [],
});

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
  server_id: null,
  server_name: null,
  courses: TIEMPOS,
  pending_courses: [{ course: 3, name: 'Postres' }],
  items: [linea('l1', 'Sopa', 1, '2026-09-10T12:00:00Z'), linea('l2', 'Torta', 3, null)],
  kitchen_tickets: [
    {
      station_id: null,
      station_name: 'General',
      course: 1,
      course_name: 'Entradas',
      lines: [linea('l1', 'Sopa', 1, '2026-09-10T12:00:00Z')],
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
};

/** El pedido tal como vuelve tras marchar los postres. */
const MARCHADO = {
  ...PEDIDO,
  pending_courses: [],
  items: [linea('l1', 'Sopa', 1, '2026-09-10T12:00:00Z'), linea('l2', 'Torta', 3, '2026-09-10T12:40:00Z')],
  kitchen_tickets: [
    ...PEDIDO.kitchen_tickets,
    {
      station_id: null,
      station_name: 'General',
      course: 3,
      course_name: 'Postres',
      lines: [linea('l2', 'Torta', 3, '2026-09-10T12:40:00Z')],
    },
  ],
};

const boton = (etiqueta, raiz = document) =>
  [...raiz.querySelectorAll('button')].find((b) => b.textContent.trim() === etiqueta);

/** En la carta el botón lleva el nombre y el precio: basta con que empiece igual. */
const producto = (nombre) =>
  [...document.querySelectorAll('#vista button')].find((b) => b.textContent.trim().startsWith(nombre));

/**
 * La última llamada a una ruta. Filtrar solo por método no sirve aquí: al
 * crear el pedido el carrito se vacía y repinta, y ese repintado lanza un
 * `/orders/preview` con `items: []` que queda como el último POST. Se pide la
 * ruta exacta —sin query string— para no quedarse con el preview.
 */
const llamada = (fetch, metodo, ruta = null) => {
  const hecha = fetch.mock.calls
    .filter(([u, o]) => o?.method === metodo && (ruta === null || String(u).split('?')[0].endsWith(ruta)))
    .at(-1);
  return hecha ? { url: String(hecha[0]), cuerpo: JSON.parse(hecha[1].body ?? 'null') } : null;
};

beforeEach(() => {
  vi.stubGlobal('print', vi.fn());
});

describe('tomar el pedido por tiempos', () => {
  const montarVenta = (cursos = TIEMPOS) =>
    montarApp({
      token: 't',
      hash: '#/pedidos',
      respuestas: {
        '/auth/me': sesion({
          permissions: ['orders.create', 'orders.view'],
          uses_tables: true,
          channels: ['table'],
          courses: cursos,
        }),
        '/menu': MENU,
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

  it('manda cada línea con el tiempo en el que se agregó', async () => {
    const { fetch } = await montarVenta();
    await reposar(2);

    producto('Sopa').click();
    await reposar();
    // Se cambia de tiempo y se agrega el postre.
    boton('Postres').click();
    await reposar();
    producto('Torta').click();
    await reposar();

    boton('Crear pedido').click();
    await reposar(4);

    expect(llamada(fetch, 'POST', '/orders').cuerpo.items).toEqual([
      { menu_item_id: 'i1', quantity: 1, modifier_ids: [], course: 1 },
      { menu_item_id: 'i2', quantity: 1, modifier_ids: [], course: 3 },
    ]);
  });

  /**
   * El mismo producto en dos tiempos son dos líneas: salen a la cocina en
   * momentos distintos, así que sumarlas las mandaría juntas.
   */
  it('el mismo producto en dos tiempos no se junta en una línea', async () => {
    const { fetch } = await montarVenta();
    await reposar(2);

    producto('Sopa').click();
    await reposar();
    boton('Fuertes').click();
    await reposar();
    producto('Sopa').click();
    await reposar();
    boton('Crear pedido').click();
    await reposar(4);

    expect(llamada(fetch, 'POST', '/orders').cuerpo.items).toEqual([
      { menu_item_id: 'i1', quantity: 1, modifier_ids: [], course: 1 },
      { menu_item_id: 'i1', quantity: 1, modifier_ids: [], course: 2 },
    ]);
  });

  it('un restaurante sin tiempos no los menciona y manda el 1', async () => {
    const { fetch } = await montarVenta([]);
    await reposar(2);

    expect(boton('Entradas')).toBeUndefined();
    expect(document.getElementById('vista').textContent).not.toContain('Agregando a');

    producto('Sopa').click();
    await reposar();
    boton('Crear pedido').click();
    await reposar(4);

    expect(llamada(fetch, 'POST', '/orders').cuerpo.items[0].course).toBe(1);
  });
});

describe('marchar un tiempo desde el detalle', () => {
  async function abrirDetalle(pedido = PEDIDO) {
    let actual = pedido;
    const montado = await montarApp({
      token: 't',
      hash: '#/pedidos',
      respuestas: {
        '/auth/me': sesion({
          permissions: ['orders.view', 'orders.create', 'orders.edit'],
          uses_tables: true,
          channels: ['table'],
          courses: TIEMPOS,
        }),
        '/menu': [],
        '/servers': [],
        '/orders': { items: [pedido], next_cursor: null },
        // Tras marchar, el detalle se relee: la segunda respuesta ya trae
        // los postres marchados.
        '/orders/o1': () => actual,
        '/orders/o1/payments': [],
        '/orders/o1/next-statuses': [],
        '/orders/o1/history': [],
        '/orders/o1/fiscal-document': { document: null },
        '/orders/o1/fire': () => {
          actual = MARCHADO;
          return MARCHADO;
        },
        [`/branches/${SUCURSAL}/tables/status`]: [],
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

  it('agrupa las líneas por tiempo y dice cuál ya salió', async () => {
    await abrirDetalle();

    const texto = panel().textContent;
    expect(texto).toContain('Entradas');
    expect(texto).toContain('Postres');
    expect(texto).toContain('Marchado');
    expect(boton('Marchar', panel())).toBeDefined();
  });

  it('marchar manda el tiempo pendiente e imprime solo su comanda', async () => {
    const { fetch } = await abrirDetalle();

    boton('Marchar', panel()).click();
    await reposar(6);

    // Se busca la llamada al marchado y no "el último POST": la pantalla de
    // venta de la prueba anterior deja programada su previsualización, y
    // esa carrera haría fallar esto un día sí y otro no.
    const marchado = fetch.mock.calls.find(
      ([url, o]) => o?.method === 'POST' && String(url).endsWith('/orders/o1/fire')
    );
    expect(JSON.parse(marchado[1].body)).toEqual({ course: 3 });
    expect(window.print).toHaveBeenCalledTimes(1);
    // La comanda impresa es la de los postres, no la del pedido entero.
    const impreso = document.getElementById('impresion').textContent;
    expect(impreso).toContain('POSTRES');
    expect(impreso).toContain('Torta');
    expect(impreso).not.toContain('Sopa');
  });

  it('sin permiso para editar no se puede marchar', async () => {
    await montarApp({
      token: 't',
      hash: '#/pedidos',
      respuestas: {
        '/auth/me': sesion({
          permissions: ['orders.view', 'orders.create'],
          uses_tables: true,
          channels: ['table'],
          courses: TIEMPOS,
        }),
        '/menu': [],
        '/servers': [],
        '/orders': { items: [PEDIDO], next_cursor: null },
        '/orders/o1': PEDIDO,
        '/orders/o1/payments': [],
        '/orders/o1/next-statuses': [],
        '/orders/o1/history': [],
        '/orders/o1/fiscal-document': { document: null },
        [`/branches/${SUCURSAL}/tables/status`]: [],
      },
    });
    await reposar(2);
    document.querySelectorAll('#vista button').forEach((b) => {
      if (b.textContent === 'Pedidos del día') b.click();
    });
    await reposar();
    [...document.querySelectorAll('#vista button')].find((b) => b.textContent === 'CEN-00061').click();
    await reposar(6);

    expect(boton('Marchar', panel())).toBeUndefined();
    expect(panel().textContent).toContain('Sin marchar');
  });
});
