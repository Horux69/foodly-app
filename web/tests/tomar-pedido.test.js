// Tomar un pedido con un grupo de modificadores obligatorio.
//
// Es el recorrido más usado del sistema y el que menos se prueba, porque en
// desarrollo el menú de ejemplo no tiene modificadores. Lo que se protege es
// que el mostrador no pueda armar un pedido que el backend va a rechazar: las
// reglas de selección viven en `Domain\ModifierValidation` y la pantalla no
// las repite, pero sí tiene que impedir llegar al final sin cumplirlas —
// enterarse al confirmar, con el carrito lleno, es la peor forma.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const TERMINO = {
  id: 'g1',
  name: 'Término de la carne',
  min_select: 1,
  max_select: 1,
  is_required: true,
  modifiers: [
    { id: 'm1', name: 'Término medio', price_delta: '0.00', is_available: true },
    { id: 'm2', name: 'Bien asada', price_delta: '0.00', is_available: true },
  ],
};

const ADICIONES = {
  id: 'g2',
  name: 'Adiciones',
  min_select: 0,
  max_select: 2,
  is_required: false,
  modifiers: [
    { id: 'm3', name: 'Tocineta', price_delta: '3000.00', is_available: true },
    { id: 'm4', name: 'Queso extra', price_delta: '2500.00', is_available: true },
    { id: 'm5', name: 'Cebolla caramelizada', price_delta: '1500.00', is_available: true },
    { id: 'm6', name: 'Huevo', price_delta: '2000.00', is_available: false },
  ],
};

const MENU = [
  {
    id: 'c1',
    name: 'Hamburguesas',
    items: [
      {
        id: 'i1',
        name: 'Hamburguesa clásica',
        description: null,
        price: '18000.00',
        is_available: true,
        modifier_groups: [TERMINO, ADICIONES],
      },
      { id: 'i2', name: 'Gaseosa', description: null, price: '4000.00', is_available: true, modifier_groups: [] },
    ],
  },
];

const PREVIEW = {
  subtotal: '18000.00',
  tax_total: '0.00',
  delivery_fee: '0.00',
  discount: '0.00',
  tip: '0.00',
  total: '18000.00',
};

async function montarVenta() {
  return montarApp({
    token: 't',
    hash: '#/pedidos',
    respuestas: {
      '/auth/me': sesion({ permissions: ['orders.create', 'orders.view'] }),
      '/menu': MENU,
      '/orders/preview': PREVIEW,
      '/orders': { id: 'o1', order_number: 7, total: '18000.00' },
    },
  });
}

const boton = (texto, raiz = document) =>
  [...raiz.querySelectorAll('button')].find((b) => b.textContent.trim().startsWith(texto));

const modal = () => document.querySelector('.fixed.inset-0');

/** La casilla de una opción, por su nombre. */
const opcion = (nombre) =>
  [...modal().querySelectorAll('label')]
    .find((l) => l.textContent.trim().startsWith(nombre))
    ?.querySelector('input');

async function abrirProducto(nombre) {
  boton(nombre).click();
  await reposar();
}

describe('producto con modificadores', () => {
  it('pregunta antes de agregarlo al carrito', async () => {
    await montarVenta();
    await abrirProducto('Hamburguesa clásica');

    expect(modal()).not.toBeNull();
    expect(modal().textContent).toContain('Término de la carne');
    expect(modal().textContent).toContain('Obligatorio');
    // Todavía no está en el carrito.
    expect(document.getElementById('vista').textContent).toContain('Toca un producto para empezar');
  });

  it('un producto sin opciones va derecho al carrito', async () => {
    await montarVenta();
    await abrirProducto('Gaseosa');

    expect(modal()).toBeNull();
    expect(document.getElementById('vista').textContent).not.toContain('Toca un producto para empezar');
  });

  /**
   * La regla que importa: sin elegir del grupo obligatorio el backend
   * rechazaría el pedido entero, y enterarse ahí —con el carrito lleno y
   * alguien esperando— es lo que hay que evitar.
   */
  it('no deja agregar sin cumplir el grupo obligatorio', async () => {
    await montarVenta();
    await abrirProducto('Hamburguesa clásica');

    expect(boton('Agregar', modal()).disabled).toBe(true);
    expect(modal().textContent).toContain('Término de la carne');
  });

  it('al elegir se puede agregar', async () => {
    await montarVenta();
    await abrirProducto('Hamburguesa clásica');

    opcion('Término medio').click();
    await reposar();

    expect(boton('Agregar', modal()).disabled).toBe(false);
  });

  it('manda las opciones elegidas con el pedido', async () => {
    const { fetch } = await montarVenta();
    await abrirProducto('Hamburguesa clásica');

    opcion('Bien asada').click();
    opcion('Tocineta').click();
    await reposar();
    boton('Agregar', modal()).click();
    await reposar();

    boton('Crear pedido').click();
    await reposar();

    const creado = fetch.mock.calls.find(([url, o]) => String(url).includes('/orders?') && o?.method === 'POST');
    expect(JSON.parse(creado[1].body).items).toEqual([
      { menu_item_id: 'i1', quantity: 1, modifier_ids: ['m2', 'm3'], course: 1 },
    ]);
  });

  it('no deja elegir una opción agotada', async () => {
    await montarVenta();
    await abrirProducto('Hamburguesa clásica');

    expect(opcion('Huevo').disabled).toBe(true);
  });

  /** Con máximo 2, la tercera no se puede marcar: el backend la rechazaría. */
  it('respeta el máximo de un grupo de varias', async () => {
    await montarVenta();
    await abrirProducto('Hamburguesa clásica');

    opcion('Tocineta').click();
    opcion('Queso extra').click();
    await reposar();

    // Las dos elegidas siguen desmarcables, pero no se puede sumar una tercera
    // que sí está disponible.
    expect(opcion('Tocineta').disabled).toBe(false);
    expect(opcion('Cebolla caramelizada').disabled).toBe(true);
  });
});
