// El tablero de domicilios.
//
// Dos cosas que no deben aflojarse: que las columnas se agrupen por
// categoría de estado y no por su nombre —cada restaurante los bautiza
// distinto—, y que la pantalla exista solo si el restaurante reparte, por
// configuración y no por un condicional sobre el tenant.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const entrega = (address) => ({
  order_id: 'o1',
  address,
  zone_id: 'z1',
  zone_name: 'Norte',
  courier_id: null,
  courier_name: null,
  estimated_time: null,
  dispatched_at: null,
  delivered_at: null,
  promise_state: 'none',
  late_minutes: 0,
});

const pedido = (numero, categoria, address) => ({
  id: `id-${numero}`,
  order_number: numero,
  channel: 'delivery',
  created_at: new Date().toISOString(),
  table_code: null,
  status: { id: 's1', code: 'lo-que-sea', name: 'Nombre inventado', category: categoria, color: null },
  subtotal: '30000.00',
  tax_total: '0.00',
  delivery_fee: '4000.00',
  discount: '0.00',
  tip: '0.00',
  total: '34000.00',
  notes: null,
  items: [],
  balance: { total: '34000.00', paid: '0.00', pending: '34000.00', is_settled: false },
  delivery: entrega(address),
  next_statuses: [{ id: 's2', code: 'x', name: 'En camino', category: 'in_transit', color: null }],
});

/** El backend responde según el `status_category` que se le pida. */
const porColumna = (url) => {
  if (url.includes('status_category=ready')) return { items: [pedido('SUR-00001', 'ready', 'Calle 1 #1-1')], next_cursor: null };
  if (url.includes('status_category=in_transit')) return { items: [pedido('SUR-00002', 'in_transit', 'Calle 2 #2-2')], next_cursor: null };
  return { items: [], next_cursor: null };
};

const REPARTE = sesion({
  permissions: ['orders.view', 'delivery.assign'],
  channels: ['counter', 'delivery'],
});

describe('tablero de domicilios', () => {
  it('agrupa por categoría de estado, no por el nombre del estado', async () => {
    await montarApp({
      token: 'un-token',
      hash: '#/domicilios',
      respuestas: { '/auth/me': REPARTE, '/couriers': [{ id: 'u1', name: 'Luis Moto' }], '/orders': porColumna },
    });
    await reposar();

    const vista = document.getElementById('vista').textContent;
    expect(vista).toContain('Listos para salir');
    expect(vista).toContain('Entregados hoy');
    // El estado se llama "Nombre inventado" y aun así cae en su columna.
    expect(vista).toContain('Calle 1 #1-1');
    expect(vista).toContain('Calle 2 #2-2');
  });

  it('ofrece los repartidores que devuelve la API', async () => {
    await montarApp({
      token: 'un-token',
      hash: '#/domicilios',
      respuestas: { '/auth/me': REPARTE, '/couriers': [{ id: 'u1', name: 'Luis Moto' }], '/orders': porColumna },
    });
    await reposar();

    const opciones = [...document.querySelectorAll('#vista select option')].map((o) => o.textContent);
    expect(opciones).toContain('Luis Moto');
  });

  it('pide solo domicilios y con los próximos estados', async () => {
    const { fetch } = await montarApp({
      token: 'un-token',
      hash: '#/domicilios',
      respuestas: { '/auth/me': REPARTE, '/couriers': [], '/orders': porColumna },
    });
    await reposar();

    const llamadas = fetch.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('/orders'));
    expect(llamadas.length).toBe(3);
    for (const url of llamadas) {
      expect(url).toContain('only_delivery=true');
      expect(url).toContain('with_next_statuses=true');
    }
  });

  it('no existe si el restaurante no reparte', async () => {
    await montarApp({
      token: 'un-token',
      hash: '#/domicilios',
      respuestas: {
        '/auth/me': sesion({ permissions: ['orders.view', 'delivery.assign'], channels: ['counter'] }),
        '/kitchen/orders': { columns: ['new', 'kitchen', 'ready'], orders: [], dispatched: [] },
      },
    });
    await reposar();

    expect(document.getElementById('rail').textContent).not.toContain('Domicilios');
    // Y entrar por la URL tampoco la abre: rebota a la primera que sí puede.
    expect(window.location.hash).not.toBe('#/domicilios');
  });
});
