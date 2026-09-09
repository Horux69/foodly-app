// Anular un pedido.
//
// Las dos reglas se comprueban desde la pantalla porque su valor está en que
// se expliquen antes, no en que el backend las rechace después: un botón que
// falla con un toast enseña menos que uno que dice por qué no.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const CANCELADO = { id: 's9', code: 'cancelled', name: 'Cancelado', category: 'cancelled', color: null };
const PREPARANDO = { id: 's3', code: 'preparing', name: 'Preparación', category: 'kitchen', color: null };

const PEDIDO = {
  id: 'o1',
  order_number: 'SUR-00008',
  channel: 'delivery',
  created_at: '2026-09-09T12:00:00Z',
  table_code: null,
  status: { id: 's1', code: 'pending', name: 'Pendiente', category: 'new', color: null },
  subtotal: '30000.00',
  tax_total: '0.00',
  delivery_fee: '0.00',
  discount: '0.00',
  tip: '0.00',
  total: '30000.00',
  notes: null,
  items: [],
  balance: { total: '30000.00', paid: '0.00', refunded: '0.00', net_paid: '0.00', pending: '30000.00', is_settled: false },
  delivery: null,
};

const SIN_COBROS = { total: '30000.00', paid: '0.00', refunded: '0.00', net_paid: '0.00', pending: '30000.00', is_settled: false };
const CON_COBROS = { total: '30000.00', paid: '12000.00', refunded: '0.00', net_paid: '12000.00', pending: '18000.00', is_settled: false };
// Cobrado y devuelto entero: la regla mira el neto, así que este sí se anula.
const DEVUELTO = { total: '30000.00', paid: '12000.00', refunded: '12000.00', net_paid: '0.00', pending: '30000.00', is_settled: false };

const respuestas = (saldo = SIN_COBROS) => ({
  '/auth/me': sesion({ permissions: ['orders.view', 'orders.create', 'orders.cancel'] }),
  '/menu': [],
  '/orders': { items: [PEDIDO], next_cursor: null },
  '/orders/o1': PEDIDO,
  '/orders/o1/payments': [],
  '/orders/o1/next-statuses': [PREPARANDO, CANCELADO],
  '/orders/o1/history': [],
  '/orders/o1/balance': saldo,
  '/orders/o1/status': PEDIDO,
});

const dialogo = () => [...document.querySelectorAll('[role="dialog"]')].pop();
const texto = () => dialogo().textContent.replace(/ /g, ' ');
const boton = (etiqueta) => [...dialogo().querySelectorAll('button')].find((b) => b.textContent.trim() === etiqueta);

/** Abre el panel del pedido y pulsa el estado de categoría `cancelled`. */
async function abrirAnulacion(saldo) {
  const montado = await montarApp({ token: 'un-token', hash: '#/pedidos', respuestas: respuestas(saldo) });
  document.querySelectorAll('#vista button').forEach((b) => {
    if (b.textContent === 'Pedidos del día') b.click();
  });
  await reposar();
  [...document.querySelectorAll('#vista button')].find((b) => b.textContent === 'SUR-00008').click();
  await reposar(6);
  [...document.querySelectorAll('[role="dialog"] button')].find((b) => b.textContent.includes('Cancelado')).click();
  await reposar(6);
  return montado;
}

const cambiosDeEstado = (fetch) =>
  fetch.mock.calls.filter(([url, o]) => String(url).endsWith('/status') && o?.method === 'POST');

describe('un pedido con plata encima', () => {
  it('no se puede anular, y dice cuánto hay que devolver', async () => {
    await abrirAnulacion(CON_COBROS);
    const t = texto();

    expect(t).toContain('$ 12.000 cobrados');
    expect(t).toContain('Reembólsalos antes de anularlo');
    // Ni siquiera se ofrece: no hay campo de motivo ni botón de anular.
    expect(boton('Anular pedido')).toBeUndefined();
    expect(dialogo().querySelector('input')).toBeNull();
  });

  it('no manda nada al backend', async () => {
    const { fetch } = await abrirAnulacion(CON_COBROS);
    expect(cambiosDeEstado(fetch)).toHaveLength(0);
  });
});

describe('un pedido sin plata encima', () => {
  it('pide el motivo antes de anular', async () => {
    await abrirAnulacion();
    expect(dialogo().querySelector('input')).not.toBeNull();
    expect(texto()).toContain('no se puede deshacer');
  });

  it('sin motivo no anula', async () => {
    const { fetch } = await abrirAnulacion();
    boton('Anular pedido').click();
    await reposar();

    expect(cambiosDeEstado(fetch)).toHaveLength(0);
    expect(document.getElementById('toast-host').textContent).toContain('motivo');
  });

  it('con motivo manda el estado y la nota', async () => {
    const { fetch } = await abrirAnulacion();
    dialogo().querySelector('input').value = 'Se cayó el pedido';
    boton('Anular pedido').click();
    await reposar(4);

    const [llamada] = cambiosDeEstado(fetch);
    expect(llamada).toBeDefined();
    const cuerpo = JSON.parse(llamada[1].body);
    expect(cuerpo.to_status_id).toBe('s9');
    expect(cuerpo.note).toBe('Se cayó el pedido');
  });

  /** La regla mira el neto: devolver todo es el camino que ella misma obliga. */
  it('un pedido cobrado y reembolsado entero sí se anula', async () => {
    await abrirAnulacion(DEVUELTO);
    expect(boton('Anular pedido')).toBeDefined();
    expect(texto()).not.toContain('Reembólsalos');
  });
});

describe('los demás estados', () => {
  it('no pasan por el diálogo de anulación', async () => {
    const { fetch } = await montarApp({ token: 'un-token', hash: '#/pedidos', respuestas: respuestas() });
    document.querySelectorAll('#vista button').forEach((b) => {
      if (b.textContent === 'Pedidos del día') b.click();
    });
    await reposar();
    [...document.querySelectorAll('#vista button')].find((b) => b.textContent === 'SUR-00008').click();
    await reposar(6);

    [...document.querySelectorAll('[role="dialog"] button')].find((b) => b.textContent.includes('Preparación')).click();
    await reposar(4);

    // Avanza directo, sin pedir motivo.
    const [llamada] = cambiosDeEstado(fetch);
    expect(JSON.parse(llamada[1].body).to_status_id).toBe('s3');
    expect(JSON.parse(llamada[1].body).note).toBeUndefined();
  });
});
