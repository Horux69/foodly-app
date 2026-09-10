// La promesa de entrega en el tablero (F9.5).
//
// En su propio archivo, como las estaciones y los tiempos: la última prueba
// de `domicilios.test.js` deja vivo el temporizador del tablero de cocina, y
// ese repinta `#vista` encima de lo que monte la siguiente.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const REPARTE = sesion({
  permissions: ['orders.view', 'delivery.assign'],
  channels: ['counter', 'delivery'],
});

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
  next_statuses: [],
});

/**
 * La promesa de entrega (F9.5).
 *
 * Un domicilio tarde que nadie vio es una reseña de una estrella, así que
 * el tablero tiene que gritarlo. Quién está tarde lo decide el backend
 * (`Domain\DeliveryPromise`): aquí solo se comprueba que se pinte, y que un
 * pedido sin promesa no se marque como incumplido.
 */
describe('promesa de entrega en el tablero', () => {
  const conPromesa = (numero, categoria, promesa, minutos = 0) => {
    const p = pedido(numero, categoria, 'Calle 9 #9-9');
    p.delivery = {
      ...p.delivery,
      estimated_time: new Date().toISOString(),
      promise_state: promesa,
      late_minutes: minutos,
    };
    return p;
  };

  const montarCon = (items) =>
    montarApp({
      token: 'un-token',
      hash: '#/domicilios',
      respuestas: {
        '/auth/me': REPARTE,
        '/orders': (url) =>
          url.includes('status_category=in_transit') ? { items, next_cursor: null } : { items: [], next_cursor: null },
        '/couriers': [],
      },
    });

  it('marca en rojo el que ya se pasó, con cuántos minutos', async () => {
    await montarCon([conPromesa('SUR-00010', 'in_transit', 'late', 12)]);
    await reposar(3);

    expect(document.getElementById('vista').textContent).toContain('Tarde 12 min');
    expect(document.querySelectorAll('#vista .bg-red-50').length).toBe(1);
  });

  it('avisa antes, cuando todavía se puede hacer algo', async () => {
    await montarCon([conPromesa('SUR-00011', 'in_transit', 'at_risk')]);
    await reposar(3);

    expect(document.getElementById('vista').textContent).toContain('Por incumplirse');
    expect(document.querySelectorAll('#vista .bg-amber-50').length).toBe(1);
  });

  it('el que va a tiempo no lleva ningún aviso', async () => {
    await montarCon([conPromesa('SUR-00012', 'in_transit', 'on_time')]);
    await reposar(3);

    const texto = document.getElementById('vista').textContent;
    expect(texto).not.toContain('Tarde');
    expect(texto).not.toContain('Por incumplirse');
  });

  /** Sin zona no hay promesa, y el que no promete nada no incumple. */
  it('un pedido sin promesa no se marca', async () => {
    await montarCon([pedido('SUR-00013', 'in_transit', 'Calle 8 #8-8')]);
    await reposar(3);

    const texto = document.getElementById('vista').textContent;
    expect(texto).not.toContain('Tarde');
    expect(document.querySelectorAll('#vista .bg-red-50').length).toBe(0);
  });
});
