// Un pedido tomado sin red no se pierde: se encola y sale solo.
//
// Lo que se prueba aquí no es el service worker (eso lo hace el navegador),
// sino la parte que sí puede romperse en silencio: que el pedido se guarde,
// que se reenvíe con **la misma** llave de idempotencia con que se tomó, y
// que dos pedidos distintos nunca compartan la suya. Sin eso, reenviar una
// cola es duplicar ventas o perderlas.

import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { montarApp, reposar, Respuesta, sinRed } from './montar-app.js';
import { sesion } from './sesion.js';

const MENU = [
  {
    id: 'c1',
    name: 'Pizzas',
    items: [{ id: 'i1', name: 'Margarita', price: '20000.00', is_available: true, modifier_groups: [] }],
  },
];

const PREVIEW = {
  subtotal: '20000.00',
  tax_total: '0.00',
  delivery_fee: '0.00',
  discount: '0.00',
  tip: '0.00',
  total: '20000.00',
};

const CREADO = { id: 'p1', order_number: 12, total: '20000.00' };

const YO = sesion({ permissions: ['orders.create', 'orders.view'] });

// Cada montaje deja una instancia nueva de cola.js con su oyente de `online`
// y su temporizador. Se apagan al terminar para que no se pisen entre
// pruebas: comparten el localStorage.
const vivas = [];

async function montar(respuestaDePedidos) {
  const { fetch } = await montarApp({
    token: 't',
    hash: '#/pedidos',
    respuestas: {
      '/auth/me': YO,
      '/menu': MENU,
      '/orders/preview': PREVIEW,
      '/orders': respuestaDePedidos,
    },
  });
  const cola = await import('../js/cola.js');
  vivas.push(cola);
  return { fetch, cola };
}

const boton = (texto) =>
  [...document.querySelectorAll('button')].find((b) => b.textContent.trim().startsWith(texto));

/** Un pedido de un producto, de principio a fin. */
async function tomarPedido() {
  boton('Margarita').click();
  await reposar();
  boton('Crear pedido').click();
  await reposar();
}

/** Las llaves de idempotencia de cada POST a /orders, en orden. */
const llavesEnviadas = (fetch) =>
  fetch.mock.calls
    .filter(([url, opciones]) => String(url).includes('/orders?') && opciones?.method === 'POST')
    .map(([, opciones]) => JSON.parse(opciones.body).idempotency_key);

beforeEach(() => localStorage.clear());

afterEach(() => {
  while (vivas.length) vivas.pop().detener();
  localStorage.clear();
});

describe('cola de pedidos sin red', () => {
  it('guarda el pedido que no salió y deja la pantalla lista para el siguiente', async () => {
    const { cola } = await montar(sinRed);

    await tomarPedido();

    const enCola = cola.pendientes();
    expect(enCola).toHaveLength(1);
    expect(enCola[0].descripcion).toContain('1 producto');
    expect(enCola[0].cuerpo.items).toEqual([{ menu_item_id: 'i1', quantity: 1, modifier_ids: [], course: 1 }]);

    // El carrito se vacía como si el pedido hubiera salido: quien está en el
    // mostrador tiene que poder atender al siguiente, no quedarse mirando el
    // pedido anterior sin saber si tocar de nuevo.
    expect(document.body.textContent).toContain('Toca un producto para empezar');
    expect(document.body.textContent).toContain('1 sin enviar');
  });

  it('reenvía con la misma llave con que se tomó', async () => {
    let hayRed = false;
    const { fetch, cola } = await montar(() => {
      if (!hayRed) return sinRed();
      return CREADO;
    });

    await tomarPedido();
    expect(cola.pendientes()).toHaveLength(1);

    hayRed = true;
    window.dispatchEvent(new Event('online'));
    await reposar();

    const llaves = llavesEnviadas(fetch);
    expect(llaves).toHaveLength(2);
    // La misma llave: para el backend el reenvío es el mismo intento, así que
    // si el primero sí había llegado devuelve aquel pedido en vez de crear
    // otro. Con llaves distintas, la venta se cobraría dos veces.
    expect(llaves[0]).toBe(llaves[1]);

    expect(cola.pendientes()).toEqual([]);
    expect(document.body.textContent).not.toContain('sin enviar');
  });

  it('no reusa la llave del pedido que quedó en cola', async () => {
    const { fetch } = await montar(sinRed);

    await tomarPedido();
    await tomarPedido();

    const llaves = llavesEnviadas(fetch);
    expect(llaves).toHaveLength(2);
    // Si la llave no se renovara al encolar, el backend vería el segundo
    // pedido como un reintento del primero y devolvería aquel: la segunda
    // venta se perdería sin que nadie viera un error.
    expect(llaves[0]).not.toBe(llaves[1]);
  });

  it('descarta y avisa lo que el servidor rechaza', async () => {
    let sale = false;
    const { cola } = await montar(() =>
      sale ? new Respuesta(400, { detail: 'La sucursal está cerrada' }) : sinRed()
    );

    await tomarPedido();
    sale = true;
    window.dispatchEvent(new Event('online'));
    await reposar();

    // Un pedido que nunca va a entrar no puede quedarse al frente tapando a
    // los que sí: sale de la cola, pero anotado, porque hay que volver a
    // tomarlo y nadie se entera solo.
    expect(cola.pendientes()).toEqual([]);
    expect(cola.rechazados()).toHaveLength(1);
    expect(cola.rechazados()[0].motivo).toBe('La sucursal está cerrada');
    expect(document.body.textContent).toContain('1 rechazados');
  });

  it('conserva el pedido cuando el que falla es el servidor', async () => {
    let sale = false;
    const { cola } = await montar(() => (sale ? new Respuesta(500, { detail: 'Error interno' }) : sinRed()));

    await tomarPedido();
    sale = true;
    window.dispatchEvent(new Event('online'));
    await reposar();

    // Un 500 puede ser de un minuto: descartarlo sería tirar una venta buena.
    expect(cola.pendientes()).toHaveLength(1);
    expect(cola.rechazados()).toEqual([]);
  });
});
