// El mapa del salón (F4.2).
//
// Lo que se protege aquí: que la pantalla no decida por su cuenta si una
// mesa está ocupada —llega resuelto del backend, por categoría del estado— y
// que tocar una mesa libre lleve a la venta con la mesa ya puesta, que es
// todo el punto de tener un plano.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const mesa = (code, extra = {}) => ({
  id: `t-${code}`,
  code,
  capacity: 4,
  is_active: true,
  pos_x: 0,
  pos_y: 0,
  shape: 'square',
  order_id: null,
  order_number: null,
  total: null,
  status_name: null,
  status_category: null,
  occupied_since: null,
  occupied_minutes: null,
  open_orders: 0,
  ...extra,
});

const OCUPADA = mesa('M2', {
  pos_x: 1,
  order_id: 'o1',
  order_number: 'CEN-00051',
  total: '48000.00',
  status_name: 'Abierto',
  status_category: 'new',
  occupied_since: new Date().toISOString(),
  occupied_minutes: 37,
  open_orders: 1,
});

const montarSalon = (mesas = [mesa('M1'), OCUPADA], permisos = ['orders.view', 'orders.create']) =>
  montarApp({
    token: 'un-token',
    hash: '#/salon',
    respuestas: {
      '/auth/me': sesion({ permissions: permisos, uses_tables: true, channels: ['table'] }),
      '/branches/22222222-2222-4222-8222-222222222222/tables/status': mesas,
      '/menu': [],
      '/orders': { items: [], next_cursor: null },
      '/orders/o1': {
        id: 'o1',
        order_number: 'CEN-00051',
        channel: 'table',
        table_code: 'M2',
        created_at: new Date().toISOString(),
        status: { id: 's1', code: 'abierto', name: 'Abierto', category: 'new', color: null },
        subtotal: '48000.00',
        tax_total: '0.00',
        delivery_fee: '0.00',
        discount: '0.00',
        tip: '0.00',
        total: '48000.00',
        notes: null,
        is_editable: true,
        kitchen_has_it: false,
        items: [],
        balance: { total: '48000.00', paid: '0.00', refunded: '0.00', net_paid: '0.00', pending: '48000.00', is_settled: false },
        delivery: null,
        kitchen_tickets: [],
      },
      '/orders/o1/payments': [],
      '/orders/o1/next-statuses': [],
      '/orders/o1/history': [],
      '/orders/o1/fiscal-document': { document: null },
    },
  });

const texto = () => document.getElementById('vista').textContent;
const mesaEn = (etiqueta) =>
  [...document.querySelectorAll('#vista button')].find((b) =>
    (b.getAttribute('aria-label') ?? '').startsWith(etiqueta)
  );

describe('mapa del salón', () => {
  it('dibuja las mesas con su estado', async () => {
    await montarSalon();

    expect(mesaEn('Mesa M1, libre')).toBeDefined();
    expect(mesaEn('Mesa M2, ocupada')).toBeDefined();
    expect(texto()).toContain('37 min');
    expect(texto()).toContain('48.000');
  });

  // La ocupación la decide el backend por categoría del estado. La pantalla
  // solo mira si hay pedido.
  it('una mesa libre muestra sus puestos, no una cuenta', async () => {
    await montarSalon([mesa('M1')]);

    expect(texto()).toContain('4 puestos');
    expect(texto()).not.toContain('min');
  });

  it('tocar una mesa libre abre la venta con la mesa puesta', async () => {
    await montarSalon();

    mesaEn('Mesa M1, libre').click();
    await reposar(4);

    expect(window.location.hash).toBe('#/pedidos?mesa=M1');
  });

  it('tocar una mesa ocupada abre su cuenta', async () => {
    await montarSalon();

    mesaEn('Mesa M2, ocupada').click();
    await reposar(4);

    expect(document.querySelector('[aria-label="Detalle del pedido"]')).not.toBeNull();
  });

  // Dos cuentas en la misma mesa se dicen: esconder una sería perderla.
  it('avisa cuando la mesa tiene más de una cuenta', async () => {
    await montarSalon([{ ...OCUPADA, open_orders: 2 }]);

    expect(texto()).toContain('2 cuentas');
  });

  it('sin permiso para administrar no se pueden acomodar', async () => {
    await montarSalon([mesa('M1')], ['orders.view']);

    expect([...document.querySelectorAll('#vista button')].some((b) => b.textContent.includes('Acomodar'))).toBe(false);
  });

  it('sin mesas dice dónde crearlas', async () => {
    await montarSalon([]);

    expect(texto()).toContain('Todavía no hay mesas');
  });
});
