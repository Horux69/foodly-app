// El mostrador se opera con teclado.
//
// Con cola y las dos manos ocupadas, apuntar a un botón de 36 píxeles es lo
// que hace lenta la toma de pedidos. Y lo otro que se prueba aquí es el foco
// en los diálogos: sin ciclo de Tab, tabular desde el último botón se va a la
// aplicación de atrás, que para quien navega con teclado es quedarse sin
// diálogo sin haberlo cerrado.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const GRUPO = {
  id: 'g1',
  name: 'Término',
  min_select: 1,
  max_select: 1,
  is_required: true,
  rule: 'Elige 1',
  modifiers: [{ id: 'm1', name: 'Medio', price_delta: '0.00', is_available: true }],
};

const MENU = [
  {
    id: 'c1',
    name: 'Hamburguesas',
    items: [
      { id: 'i1', name: 'Hamburguesa clásica', description: null, price: '18000.00', is_available: true, modifier_groups: [] },
      { id: 'i2', name: 'Perro caliente', description: null, price: '9000.00', is_available: true, modifier_groups: [GRUPO] },
    ],
  },
];

const PREVIEW = { subtotal: '18000.00', tax_total: '0.00', delivery_fee: '0.00', discount: '0.00', tip: '0.00', total: '18000.00' };

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

const teclear = (key, opciones = {}) =>
  document.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...opciones }));

const buscador = () => document.querySelector('#vista input[type="search"]');
const lineas = () => [...document.querySelectorAll('#vista [role="group"]')];

describe('atajos en la pantalla de venta', () => {
  it('la barra lleva el foco al buscador', async () => {
    await montarVenta();
    expect(document.activeElement).not.toBe(buscador());

    teclear('/');
    expect(document.activeElement).toBe(buscador());
  });

  it('escribiendo en un campo, la barra se teclea como una barra', async () => {
    await montarVenta();
    const notas = document.querySelector('#vista textarea');
    notas.focus();

    const evento = new KeyboardEvent('keydown', { key: '/', bubbles: true, cancelable: true });
    document.dispatchEvent(evento);

    // No se lo queda la aplicación: el navegador escribe el carácter.
    expect(evento.defaultPrevented).toBe(false);
    expect(document.activeElement).toBe(notas);
  });

  it('Enter crea el pedido cuando hay algo en el carrito', async () => {
    const { fetch } = await montarVenta();

    teclear('Enter');
    expect(fetch.mock.calls.some(([url, o]) => String(url).includes('/orders?') && o?.method === 'POST')).toBe(false);

    boton('Hamburguesa clásica').click();
    await reposar();
    teclear('Enter');
    await reposar();

    expect(fetch.mock.calls.some(([url, o]) => String(url).includes('/orders?') && o?.method === 'POST')).toBe(true);
  });

  it('las flechas cambian la cantidad de la línea con foco', async () => {
    await montarVenta();
    boton('Hamburguesa clásica').click();
    await reposar();

    const linea = lineas()[0];
    linea.focus();
    linea.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true, cancelable: true }));
    await reposar();
    expect(lineas()[0].getAttribute('aria-label')).toContain('Hamburguesa clásica, 2');

    lineas()[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
    await reposar();
    expect(lineas()[0].getAttribute('aria-label')).toContain('Hamburguesa clásica, 1');
  });

  it('bajar de uno saca la línea del carrito', async () => {
    await montarVenta();
    boton('Hamburguesa clásica').click();
    await reposar();

    lineas()[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
    await reposar();

    expect(lineas()).toHaveLength(0);
    expect(document.getElementById('vista').textContent).toContain('Toca un producto para empezar');
  });
});

describe('foco en los diálogos', () => {
  it('el modal de modificadores se anuncia como diálogo y toma el foco', async () => {
    await montarVenta();
    boton('Perro caliente').click();
    await reposar();

    const dialogo = document.querySelector('[role="dialog"]');
    expect(dialogo).not.toBeNull();
    expect(dialogo.getAttribute('aria-modal')).toBe('true');
    expect(dialogo.contains(document.activeElement)).toBe(true);
  });

  it('Escape lo cierra', async () => {
    await montarVenta();
    boton('Perro caliente').click();
    await reposar();

    teclear('Escape');
    await reposar();

    expect(document.querySelector('[role="dialog"]')).toBeNull();
  });

  it('con el diálogo abierto, los atajos de la pantalla de atrás no responden', async () => {
    const { fetch } = await montarVenta();
    boton('Hamburguesa clásica').click();
    await reposar();
    boton('Perro caliente').click();
    await reposar();

    // Con carrito no vacío, Enter crearía el pedido si el diálogo no ganara.
    teclear('Enter');
    await reposar();

    expect(fetch.mock.calls.some(([url, o]) => String(url).includes('/orders?') && o?.method === 'POST')).toBe(false);
  });

  it('Tab no se escapa del diálogo', async () => {
    await montarVenta();
    boton('Perro caliente').click();
    await reposar();

    const dialogo = document.querySelector('[role="dialog"]');
    const dentro = [...dialogo.querySelectorAll('button, input')].filter((el) => !el.disabled);
    dentro[dentro.length - 1].focus();

    teclear('Tab');
    // Vuelve al primero en vez de irse a la aplicación de atrás.
    expect(document.activeElement).toBe(dentro[0]);

    dentro[0].focus();
    teclear('Tab', { shiftKey: true });
    expect(document.activeElement).toBe(dentro[dentro.length - 1]);
  });

  it('al cerrar, el foco vuelve de donde salió', async () => {
    await montarVenta();
    const producto = boton('Perro caliente');
    producto.focus();
    producto.click();
    await reposar();

    teclear('Escape');
    await reposar();

    expect(document.activeElement).toBe(producto);
  });
});
