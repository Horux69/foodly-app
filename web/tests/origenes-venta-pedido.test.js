// Tomar un pedido por un origen de venta (F9.4).
//
// En su propio archivo: montar la pantalla de venta y la de administración
// en el mismo deja vivo el temporizador de la primera y la corrida se
// queda colgada.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const ORIGENES = [
  { id: 's1', name: 'Rappi', commission_percent: 30, is_active: true },
  { id: 's2', name: 'Propio web', commission_percent: 0, is_active: true },
];

const boton = (texto) =>
  [...document.querySelectorAll('button')].find((b) => b.textContent.trim().startsWith(texto));

const llamada = (fetch, metodo) => {
  const hecha = fetch.mock.calls.filter(([, o]) => o?.method === metodo).at(-1);
  return hecha ? { url: String(hecha[0]), cuerpo: JSON.parse(hecha[1].body ?? 'null') } : null;
};

describe('tomar un pedido por un origen', () => {
  const MENU = [
    {
      id: 'c1',
      name: 'Carta',
      items: [{ id: 'i1', name: 'Combo', description: null, price: '50000.00', is_available: true, modifier_groups: [] }],
    },
  ];

  const montarVenta = (origenes) =>
    montarApp({
      token: 't',
      hash: '#/pedidos',
      respuestas: {
        '/auth/me': sesion({ permissions: ['orders.create', 'orders.view'] }),
        '/menu': MENU,
        '/sales-sources': origenes,
        '/orders/preview': {
          subtotal: '50000.00', tax_total: '0.00', delivery_fee: '0.00', discount: '0.00', tip: '0.00', total: '50000.00',
        },
        '/orders': { id: 'o1', order_number: 'CEN-00090', total: '50000.00' },
      },
    });

  it('manda de dónde vino la venta', async () => {
    const { fetch } = await montarVenta(ORIGENES);
    await reposar(2);

    const selector = document.querySelector('[aria-label="Origen de la venta"]');
    // El porcentaje se ve al elegir: es plata que no entra.
    expect([...selector.options].map((o) => o.text)).toEqual(['Venta propia', 'Rappi (30%)', 'Propio web']);

    selector.value = 's1';
    boton('Combo').click();
    await reposar(2);
    boton('Crear pedido').click();
    await reposar(4);

    expect(llamada(fetch, 'POST').cuerpo.sales_source_id).toBe('s1');
  });

  /** Sin orígenes configurados —el caso de casi todos— la pantalla ni los menciona. */
  it('sin orígenes no aparece el campo', async () => {
    const { fetch } = await montarVenta([]);
    await reposar(2);

    expect(document.querySelector('[aria-label="Origen de la venta"]')).toBeNull();

    boton('Combo').click();
    await reposar(2);
    boton('Crear pedido').click();
    await reposar(4);

    expect(llamada(fetch, 'POST').cuerpo).not.toHaveProperty('sales_source_id');
  });
});
