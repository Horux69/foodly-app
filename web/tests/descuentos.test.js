// Descuento con motivo y permiso (F7.2).
//
// El descuento era una casilla numérica libre en la pantalla de venta: se
// mandaba con `orders.create` y sin decir por qué. Lo que se protege aquí es
// que el campo dependa de `orders.discount` y que el motivo viaje siempre —
// quien decide si cabe sigue siendo `Domain\DiscountRules`.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar, Respuesta } from './montar-app.js';
import { sesion } from './sesion.js';

const MOTIVOS = [
  { id: 'r1', name: 'Cortesía', is_active: true, sort_order: 0 },
  { id: 'r2', name: 'Reclamo del cliente', is_active: true, sort_order: 1 },
];

const MENU = [
  {
    id: 'c1',
    name: 'Carta',
    items: [
      { id: 'm1', name: 'Plato', description: null, price: '100000.00', is_available: true, modifier_groups: [], components: [] },
    ],
  },
];

const montarVenta = (permisos, extra = {}) =>
  montarApp({
    token: 'un-token',
    hash: '#/pedidos',
    respuestas: {
      '/auth/me': sesion({ permissions: permisos }),
      '/menu': MENU,
      '/discount-reasons': MOTIVOS,
      '/orders/preview': {
        subtotal: '100000.00',
        tax_total: '0.00',
        delivery_fee: '0.00',
        discount: '0.00',
        tip: '0.00',
        total: '100000.00',
      },
      '/orders': { id: 'o9', order_number: 'CEN-00009' },
      ...extra,
    },
  });

const campo = (etiqueta) =>
  [...document.querySelectorAll('#vista label')]
    .find((l) => l.textContent.trim().startsWith(etiqueta))
    ?.querySelector('input, select');

const boton = (texto) =>
  [...document.querySelectorAll('#vista button')].find((b) => b.textContent.trim().startsWith(texto));

const cuerpoDelPost = (fetch, ruta) => {
  const llamada = fetch.mock.calls.filter(([u, o]) => o?.method === 'POST' && String(u).includes(ruta)).at(-1);
  return llamada ? JSON.parse(llamada[1].body) : null;
};

describe('descuento en la pantalla de venta', () => {
  it('sin el permiso no hay campo de descuento', async () => {
    await montarVenta(['orders.create', 'orders.view']);

    expect(campo('Descuento')).toBeUndefined();
    expect(campo('Motivo')).toBeUndefined();
  });

  it('con el permiso aparece el campo y el motivo del catálogo', async () => {
    await montarVenta(['orders.create', 'orders.view', 'orders.discount']);
    await reposar(3);

    expect(campo('Descuento')).toBeDefined();
    const motivo = campo('Motivo');
    expect([...motivo.options].map((o) => o.textContent)).toEqual(['Motivo…', 'Cortesía', 'Reclamo del cliente']);
  });

  it('el motivo elegido viaja con el pedido', async () => {
    const { fetch } = await montarVenta(['orders.create', 'orders.view', 'orders.discount']);
    await reposar(3);

    boton('Plato').click();
    await reposar(3);
    campo('Descuento').value = '20000';
    campo('Motivo').value = 'r2';
    boton('Crear pedido').click();
    await reposar(4);

    const cuerpo = cuerpoDelPost(fetch, '/orders');
    expect(cuerpo.discount).toBe(20000);
    expect(cuerpo.discount_reason_id).toBe('r2');
  });

  // El rechazo lo escribe el dominio —con el tope del rol dentro— y la
  // pantalla lo muestra tal cual en vez de inventarse un mensaje.
  it('muestra el rechazo del tope sin reescribirlo', async () => {
    await montarVenta(['orders.create', 'orders.view', 'orders.discount'], {
      '/orders': (url, opciones) =>
        opciones.method === 'POST'
          ? new Respuesta(422, { detail: 'Tu rol puede descontar hasta el 10% (10000.00). Este descuento necesita autorizacion.' })
          : { items: [], next_cursor: null },
    });
    await reposar(3);

    boton('Plato').click();
    await reposar(3);
    campo('Descuento').value = '90000';
    campo('Motivo').value = 'r1';
    boton('Crear pedido').click();
    await reposar(4);

    expect(document.body.textContent).toContain('necesita autorizacion');
  });
});
