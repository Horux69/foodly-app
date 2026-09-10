// Cuadre del repartidor (F9.1).
//
// El repartidor vuelve con el efectivo de varios pedidos, y hasta ahora eso
// no se cuadraba contra nada. Lo que se protege aquí: que la cifra que se
// muestra venga del backend —la diferencia se calcula allá, no aquí—, que
// la sección exista solo para quien tiene el permiso del arqueo, y que
// cuadrar mande lo contado y nada más.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const CUADRES = {
  couriers: [
    {
      courier_id: 'u-luis',
      courier_name: 'Luis Moto',
      from_at: null,
      orders: 2,
      charged: '60000.00',
      refunded: '0.00',
      expected_cash: '60000.00',
    },
  ],
  history: [],
};

const DETALLE = {
  courier_id: 'u-luis',
  from_at: null,
  orders: [
    { order_number: 'SUR-00001', total: '34000.00', cash: '34000.00', delivered_at: null },
    { order_number: 'SUR-00002', total: '26000.00', cash: '26000.00', delivered_at: null },
  ],
  expected_cash: '60000.00',
};

const vacio = { items: [], next_cursor: null };

const montar = (permisos, respuestas = {}) =>
  montarApp({
    token: 't',
    hash: '#/domicilios',
    respuestas: {
      '/auth/me': sesion({ permissions: permisos, channels: ['counter', 'delivery'] }),
      '/orders': vacio,
      '/couriers': [{ id: 'u-luis', name: 'Luis Moto' }],
      '/couriers/settlements': CUADRES,
      '/couriers/u-luis/settlement': DETALLE,
      ...respuestas,
    },
  });

const boton = (etiqueta, raiz = document) =>
  [...raiz.querySelectorAll('button')].find((b) => b.textContent.trim() === etiqueta);
const dialogo = () => document.querySelector('[aria-label="Cuadre del repartidor"]');

describe('cuadre del repartidor', () => {
  it('muestra lo que cada uno debería traer', async () => {
    await montar(['orders.view', 'delivery.assign', 'cash.close']);
    await reposar(3);

    const texto = document.getElementById('vista').textContent;
    expect(texto).toContain('Efectivo en la calle');
    expect(texto).toContain('Luis Moto');
    expect(texto).toContain('60.000');
    expect(texto).toContain('2 pedidos');
  });

  /**
   * El mismo permiso que el arqueo, y por el mismo motivo: ver cuánto
   * debería haber antes de contarlo es lo que ese permiso separa.
   */
  it('sin el permiso del arqueo no se ve', async () => {
    await montar(['orders.view', 'delivery.assign']);
    await reposar(3);

    expect(document.getElementById('vista').textContent).not.toContain('Efectivo en la calle');
  });

  it('el diálogo lista sus pedidos y manda lo contado', async () => {
    const { fetch } = await montar(['orders.view', 'delivery.assign', 'cash.close'], {
      '/couriers/u-luis/settlement': (url, opciones) =>
        opciones?.method === 'POST'
          ? {
              id: 'c1',
              expected_cash: '60000.00',
              counted_cash: '58000.00',
              difference: '-2000.00',
              summary: 'Faltan 2000.00',
            }
          : DETALLE,
    });
    await reposar(3);

    boton('Cuadrar').click();
    await reposar(3);

    const d = dialogo();
    expect(d.textContent).toContain('SUR-00001');
    expect(d.textContent).toContain('Debería traer');

    const entregado = d.querySelector('[aria-label="Efectivo que entrega"]');
    entregado.value = '58000';
    entregado.dispatchEvent(new Event('input'));
    // La diferencia se adelanta mientras se teclea, pero la que vale es la
    // que responde el backend.
    expect(d.textContent).toContain('Faltan');

    boton('Registrar cuadre', d).click();
    await reposar(4);

    const hecha = fetch.mock.calls.filter(([, o]) => o?.method === 'POST').at(-1);
    expect(String(hecha[0])).toContain('/couriers/u-luis/settlement');
    expect(JSON.parse(hecha[1].body)).toEqual({ counted_cash: 58000, note: null });
    expect(document.body.textContent).toContain('Faltan 2000.00');
  });

  /** Sin nadie con efectivo pendiente la sección no ocupa sitio. */
  it('no aparece cuando nadie debe nada', async () => {
    await montar(['orders.view', 'delivery.assign', 'cash.close'], {
      '/couriers/settlements': { couriers: [], history: [] },
    });
    await reposar(3);

    expect(document.getElementById('vista').textContent).not.toContain('Efectivo en la calle');
  });
});
