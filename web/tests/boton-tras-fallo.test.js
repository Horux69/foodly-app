// Un botón que falla vuelve a servir.
//
// Todas las acciones que llaman a la API deshabilitan su botón mientras van y
// lo vuelven a habilitar si la llamada falla. Ese segundo paso estaba roto en
// cinco pantallas: `event.currentTarget` queda en null en cuanto termina el
// despacho del evento —comprobado en Chromium, no solo en jsdom—, así que el
// `catch` reventaba antes de rehabilitarlo. Se veía el mensaje de error y el
// botón quedaba muerto: había que salir de la pantalla y volver.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar, Respuesta } from './montar-app.js';
import { sesion } from './sesion.js';

const PEDIDO = {
  id: 'o1',
  order_number: 'CEN-00001',
  channel: 'counter',
  created_at: '2026-09-08T18:00:00Z',
  table_code: null,
  status: { id: 's1', code: 'pending', name: 'Pendiente', category: 'new', color: null },
  subtotal: '40000.00',
  tax_total: '0.00',
  delivery_fee: '0.00',
  discount: '0.00',
  tip: '0.00',
  total: '40000.00',
  notes: null,
  items: [],
  balance: { total: '40000.00', paid: '0.00', pending: '40000.00', is_settled: false },
  delivery: null,
};

const EN_COCINA = { id: 's2', code: 'cooking', name: 'En preparación', category: 'kitchen', color: null };

async function abrirPanel() {
  await montarApp({
    token: 't',
    hash: '#/pedidos',
    respuestas: {
      '/auth/me': sesion({ permissions: ['orders.view', 'orders.create'] }),
      '/menu': [],
      '/orders': { items: [PEDIDO], next_cursor: null },
      '/orders/o1': PEDIDO,
      '/orders/o1/payments': [],
      '/orders/o1/next-statuses': [EN_COCINA],
      '/orders/o1/history': [],
      // Lo que el backend responde cuando la transición no procede.
      '/orders/o1/status': new Respuesta(422, { detail: 'No se puede avanzar: el pedido no está saldado' }),
    },
  });

  [...document.querySelectorAll('#vista button')].find((b) => b.textContent === 'Pedidos del día').click();
  await reposar();
  [...document.querySelectorAll('#vista button')].find((b) => b.textContent === 'CEN-00001').click();
  await reposar(6);
}

describe('avanzar un pedido que el backend rechaza', () => {
  it('muestra el error y deja el botón utilizable para reintentar', async () => {
    await abrirPanel();

    const panel = document.querySelector('[role="dialog"]');
    const avanzar = [...panel.querySelectorAll('button')].find((b) => b.textContent.includes('En preparación'));
    expect(avanzar).toBeDefined();

    avanzar.click();
    await reposar();

    expect(document.body.textContent).toContain('No se puede avanzar');
    expect(avanzar.disabled).toBe(false);
  });
});
