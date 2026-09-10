// El documento fiscal de la venta (F12.1).
//
// Lo que se protege aquí es cuándo aparece y qué dice. Solo con el pedido
// saldado —el documento dice cuánto se cobró— y con el estado tal como lo
// reporta el backend: "sin transmitir" no es un error de la pantalla, es la
// contingencia, y llamarla "emitido" escondería que la autoridad todavía no
// lo tiene.

import { describe, expect, it, vi } from 'vitest';
import { montarApp, reposar, Respuesta } from './montar-app.js';
import { sesion } from './sesion.js';

const saldado = {
  total: '20000.00',
  paid: '20000.00',
  refunded: '0.00',
  net_paid: '20000.00',
  pending: '0.00',
  is_settled: true,
};

const PEDIDO = {
  id: 'o1',
  order_number: 'CEN-00044',
  channel: 'counter',
  created_at: new Date().toISOString(),
  table_code: null,
  status: { id: 's1', code: 'pagado', name: 'Pagado', category: 'completed', color: null },
  subtotal: '20000.00',
  tax_total: '0.00',
  delivery_fee: '0.00',
  discount: '0.00',
  tip: '0.00',
  total: '20000.00',
  notes: null,
  is_editable: false,
  kitchen_has_it: false,
  items: [{ id: 'i1', menu_item_id: 'm1', name_snapshot: 'Plato', quantity: 1, unit_price: '20000.00', tax_amount: '0.00', line_total: '20000.00', notes: null, modifiers: [], components: [] }],
  balance: saldado,
  delivery: null,
  kitchen_tickets: [],
};

const DOCUMENTO = {
  id: 'f1',
  full_number: 'POS1042',
  status: 'contingency',
  external_id: null,
  provider: 'local',
  total: '20000.00',
  issued_at: new Date().toISOString(),
  transmitted_at: null,
};

async function abrir(pedido = PEDIDO, documento = null, extra = {}) {
  const montado = await montarApp({
    token: 't',
    hash: '#/pedidos',
    respuestas: {
      '/auth/me': sesion({ permissions: ['orders.view', 'orders.create', 'payments.register'] }),
      '/menu': [],
      '/orders': { items: [pedido], next_cursor: null },
      [`/orders/${pedido.id}`]: pedido,
      [`/orders/${pedido.id}/payments`]: [],
      [`/orders/${pedido.id}/next-statuses`]: [],
      [`/orders/${pedido.id}/history`]: [],
      [`/orders/${pedido.id}/fiscal-document`]: { document: documento },
      ...extra,
    },
  });

  await reposar(2);
  document.querySelectorAll('#vista button').forEach((b) => {
    if (b.textContent === 'Pedidos del día') b.click();
  });
  await reposar();
  [...document.querySelectorAll('#vista button')].find((b) => b.textContent === pedido.order_number).click();
  await reposar(6);
  return montado;
}

const panel = () => document.querySelector('[aria-label="Detalle del pedido"]');
const boton = (etiqueta) =>
  [...panel().querySelectorAll('button')].find((b) => b.textContent.trim() === etiqueta);

describe('documento fiscal', () => {
  it('con el pedido saldado ofrece emitirlo', async () => {
    await abrir();
    expect(boton('Emitir documento')).toBeDefined();
  });

  // Emitirlo antes de cobrar es prometer una cifra que todavía puede cambiar.
  it('sin saldar no lo ofrece', async () => {
    await abrir({
      ...PEDIDO,
      balance: { ...saldado, paid: '0.00', net_paid: '0.00', pending: '20000.00', is_settled: false },
    });

    expect(boton('Emitir documento')).toBeUndefined();
  });

  it('emitido muestra el número y su estado real', async () => {
    await abrir(PEDIDO, DOCUMENTO);

    expect(panel().textContent).toContain('POS1042');
    // 'Sin transmitir' y no 'Emitido': la autoridad todavía no lo tiene.
    expect(panel().textContent).toContain('Sin transmitir');
    expect(boton('Emitir documento')).toBeUndefined();
  });

  it('aceptado se ve distinto y muestra el identificador de la autoridad', async () => {
    await abrir(PEDIDO, { ...DOCUMENTO, status: 'accepted', external_id: 'CUFE-abc123' });

    expect(panel().textContent).toContain('Aceptado');
    expect(panel().textContent).toContain('CUFE-abc123');
  });

  it('el rechazo del servidor se muestra tal cual', async () => {
    await abrir(PEDIDO, null, {
      '/orders/o1/fiscal-document': (url, opciones) =>
        opciones.method === 'POST'
          ? new Respuesta(422, { detail: 'No hay una resolucion de numeracion activa para esta sucursal.' })
          : { document: null },
    });

    boton('Emitir documento').click();
    await reposar(3);

    expect(document.body.textContent).toContain('resolucion de numeracion activa');
  });

  // El número autorizado es lo que hace del papel un comprobante.
  it('el ticket impreso lleva el número', async () => {
    vi.stubGlobal('print', vi.fn());
    await abrir(PEDIDO, DOCUMENTO);

    boton('Ticket').click();
    await reposar(3);

    expect(document.getElementById('impresion').textContent).toContain('POS1042');
  });
});
