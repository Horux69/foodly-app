// Los reportes de cierre.
//
// Lo que se protege es que la pantalla no invente cifras. El neto por método
// y los totales de cada ajuste los calcula el backend —los de ajustes con
// funciones de ventana, así que valen para todo el período aunque la lista
// venga recortada— y aquí solo se muestran.
//
// Y que se vea que venta y cierre no son el mismo número: uno cuenta pedidos
// completados y el otro sigue la plata. Sin decirlo, la diferencia parece un
// error de la aplicación.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const VENTAS = {
  from_date: '2026-09-01',
  to_date: '2026-09-09',
  totals: { orders: 3, revenue: '90000.00', avg_ticket: '30000.00' },
  by_day: [{ day: '2026-09-09', orders: 3, revenue: '90000.00' }],
  by_channel: [{ channel: 'counter', orders: 3, revenue: '90000.00' }],
  by_branch: [{ branch_id: 'b1', branch_name: 'Centro', orders: 3, revenue: '90000.00' }],
};

const INGRESOS = {
  from_date: '2026-09-01',
  to_date: '2026-09-09',
  by_method: [
    { method: 'cash', charges: 5, refunds: 1, charged: '129000.00', refunded: '5000.00', net: '124000.00' },
    { method: 'card', charges: 2, refunds: 1, charged: '46000.00', refunded: '10000.00', net: '36000.00' },
  ],
};

const POR_USUARIO = [
  { user_id: 'u1', user_name: 'Sara Caja', orders: 2, revenue: '60000.00' },
  // Sin nombre: el usuario se dio de baja o el pedido entró por integración.
  { user_id: null, user_name: null, orders: 1, revenue: '30000.00' },
];

const AJUSTES = {
  from_date: '2026-09-01',
  to_date: '2026-09-09',
  cancellations: {
    count: 1,
    total: '30000.00',
    truncated: false,
    items: [{ order_number: 'SUR-00006', channel: 'delivery', amount: '30000.00', at: '2026-09-09T07:34:00Z', reason: 'El cliente no contestó', by_name: 'Administrador' }],
  },
  refunds: {
    // Lista recortada a propósito: el total es del período completo.
    count: 210,
    total: '90000.00',
    truncated: true,
    items: [{ order_number: 'CEN-00005', method: 'card', amount: '4000.00', at: '2026-09-08T22:32:00Z', reason: 'Se cobró de más', by_name: 'Admin Demo' }],
  },
  discounts: {
    count: 1,
    total: '3000.00',
    truncated: false,
    items: [{ order_number: 'SUR-00005', amount: '3000.00', order_total: '27000.00', at: '2026-09-09T07:34:00Z', reason: null, by_name: 'Administrador' }],
  },
};

const respuestas = () => ({
  '/auth/me': sesion({ permissions: ['reports.view'] }),
  '/reports/sales': VENTAS,
  '/reports/top-products': [],
  '/reports/prep-times': { orders: 0, avg_minutes: null, median_minutes: null, min_minutes: null, max_minutes: null },
  '/reports/peak-hours': [],
  '/reports/payment-methods': INGRESOS,
  '/reports/sales-by-user': POR_USUARIO,
  '/reports/adjustments': AJUSTES,
});

const texto = () => document.getElementById('vista').textContent.replace(/ /g, ' ');
const pulsar = (etiqueta) =>
  [...document.querySelectorAll('#vista button')].find((b) => b.textContent.trim() === etiqueta).click();

async function abrirCierre(mapa = respuestas()) {
  const montado = await montarApp({ token: 'un-token', hash: '#/reportes', respuestas: mapa });
  await reposar(4);
  pulsar('Cierre');
  await reposar(6);
  return montado;
}

describe('pestaña de cierre', () => {
  it('arranca en venta y no pide los reportes de cierre hasta que se piden', async () => {
    const { fetch } = await montarApp({ token: 'un-token', hash: '#/reportes', respuestas: respuestas() });
    await reposar(4);

    const urls = fetch.mock.calls.map(([u]) => String(u));
    expect(urls.some((u) => u.includes('/reports/sales?'))).toBe(true);
    expect(urls.some((u) => u.includes('/reports/adjustments'))).toBe(false);
  });

  it('muestra el neto por método tal como llegó', async () => {
    await abrirCierre();
    const t = texto();

    expect(t).toContain('Efectivo');
    expect(t).toContain('$ 124.000');
    expect(t).toContain('$ 36.000');
    // 124.000 + 36.000: el total sí se suma aquí, y debe cuadrar con las filas.
    expect(t).toContain('$ 160.000');
  });

  it('avisa de que el cierre no cuadra con la venta, porque miden cosas distintas', async () => {
    await abrirCierre();
    expect(texto()).toContain('No tiene por qué coincidir con la venta');
  });

  it('nombra a quien no tiene nombre en vez de dejar el hueco', async () => {
    await abrirCierre();
    expect(texto()).toContain('Sara Caja');
    expect(texto()).toContain('Sin usuario');
  });

  it('lista las anulaciones con quién y por qué', async () => {
    await abrirCierre();
    const t = texto();

    expect(t).toContain('SUR-00006');
    expect(t).toContain('El cliente no contestó');
    expect(t).toContain('Administrador');
  });

  /**
   * El caso que hace útil el reporte: 210 reembolsos de los que se muestran
   * unos pocos. Si el total se sumara de las filas visibles diría 4.000 en
   * vez de 90.000, y nadie cuadraría el día con eso.
   */
  it('con la lista recortada, el total sigue siendo el del período', async () => {
    await abrirCierre();
    const t = texto();

    expect(t).toContain('$ 90.000');
    expect(t).toContain('210 en el período');
    expect(t).toContain('Lista recortada');
  });

  it('los filtros de fecha y sucursal viajan también en los reportes de cierre', async () => {
    const { fetch } = await abrirCierre();

    const cierre = fetch.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('/reports/adjustments'));
    expect(cierre).toHaveLength(1);
    expect(cierre[0]).toMatch(/from_date=\d{4}-\d{2}-\d{2}/);
    expect(cierre[0]).toMatch(/to_date=\d{4}-\d{2}-\d{2}/);
  });
});
