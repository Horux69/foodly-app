// Dividir la cuenta.
//
// Lo que se protege es que el reparto no se calcule aquí. 65000 entre tres
// no da redondo, y si el navegador dividiera con `toFixed(2)` el pedido
// quedaría con un centavo pendiente para siempre. La pantalla pide las
// partes y cobra lo que le dieron.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const PEDIDO = {
  id: 'o1',
  order_number: 'SUR-00003',
  channel: 'delivery',
  created_at: '2026-09-09T12:00:00Z',
  table_code: null,
  status: { id: 's1', code: 'pending', name: 'Pendiente', category: 'new', color: null },
  subtotal: '60000.00',
  tax_total: '0.00',
  delivery_fee: '5000.00',
  discount: '0.00',
  tip: '0.00',
  total: '65000.00',
  notes: null,
  items: [
    { id: 'i1', menu_item_id: 'm1', name_snapshot: 'Pizza margarita', quantity: 1, unit_price: '30000.00', tax_amount: '0.00', line_total: '30000.00', notes: null, modifiers: [] },
    { id: 'i2', menu_item_id: 'm2', name_snapshot: 'Gaseosa', quantity: 2, unit_price: '15000.00', tax_amount: '0.00', line_total: '30000.00', notes: null, modifiers: [] },
  ],
  balance: { total: '65000.00', paid: '0.00', refunded: '0.00', net_paid: '0.00', pending: '65000.00', is_settled: false },
  delivery: null,
};

// El backend reparte; aquí solo se devuelve lo que devolvería él, con el
// centavo suelto incluido para que la pantalla no pueda "arreglarlo".
const REPARTOS = {
  1: ['65000.00'],
  2: ['32500.00', '32500.00'],
  3: ['21666.67', '21666.67', '21666.66'],
};

const respuestas = () => ({
  '/auth/me': sesion({ permissions: ['orders.view', 'orders.create', 'payments.register'] }),
  '/menu': [],
  '/orders': { items: [PEDIDO], next_cursor: null },
  '/orders/o1': PEDIDO,
  '/orders/o1/payments': [],
  '/orders/o1/next-statuses': [],
  '/orders/o1/history': [],
  '/orders/o1/balance': PEDIDO.balance,
  '/orders/o1/split': (url) => ({
    pending: '65000.00',
    parts: REPARTOS[Number(new URL(url, 'http://x').searchParams.get('parts'))] ?? REPARTOS[2],
    non_item_total: '5000.00',
  }),
});

const dialogo = () => [...document.querySelectorAll('[role="dialog"]')].pop();
const textoDialogo = () => dialogo().textContent.replace(/\u00a0/g, ' ');
const botones = (texto) => [...dialogo().querySelectorAll('button')].filter((b) => b.textContent.includes(texto));

/** Abre el panel del pedido y, dentro, el diálogo de dividir. */
async function abrirDivision(mapa = respuestas()) {
  const montado = await montarApp({ token: 'un-token', hash: '#/pedidos', respuestas: mapa });
  document.querySelectorAll('#vista button').forEach((b) => {
    if (b.textContent === 'Pedidos del día') b.click();
  });
  await reposar();
  [...document.querySelectorAll('#vista button')].find((b) => b.textContent === 'SUR-00003').click();
  await reposar(6);
  [...document.querySelectorAll('[role="dialog"] button')].find((b) => b.textContent.includes('Dividir')).click();
  await reposar(6);
  return montado;
}

const cuerpoDe = (llamada) => JSON.parse(llamada[1].body);
const cobros = (fetch) =>
  fetch.mock.calls.filter(([url, o]) => String(url).includes('/payments') && o?.method === 'POST');

describe('dividir entre personas', () => {
  it('muestra las partes que repartió el backend, con su centavo suelto', async () => {
    await abrirDivision();
    dialogo().querySelector('button[aria-label="Una persona más"]').click();
    await reposar(4);

    const t = textoDialogo();
    expect(t).toContain('Persona 3');
    // 21666.67 y 21666.66 se muestran redondeados, pero son tres partes
    // distintas venidas del servidor, no una división hecha aquí.
    expect(dialogo().textContent.match(/Persona \d/g)).toHaveLength(3);
  });

  it('cobra exactamente el importe de la parte, sin recalcularlo', async () => {
    const { fetch } = await abrirDivision();
    dialogo().querySelector('button[aria-label="Una persona más"]').click();
    await reposar(4);

    botones('Cobrar')[0].click();
    await reposar(4);

    const [cobro] = cobros(fetch);
    expect(cobro).toBeDefined();
    // La primera parte de 65000 entre tres: el centavo de más va aquí.
    expect(cuerpoDe(cobro).amount).toBe(21666.67);
  });

  it('tras cobrar una parte reparte entre los que faltan', async () => {
    const { fetch } = await abrirDivision();
    dialogo().querySelector('button[aria-label="Una persona más"]').click();
    await reposar(4);

    botones('Cobrar')[0].click();
    await reposar(6);

    // La última consulta de reparto pide dos partes, no tres.
    const consultas = fetch.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('/split'));
    expect(consultas.at(-1)).toContain('parts=2');
  });

  /**
   * Con 65000 entre tres, dividir en el navegador daría por casualidad el
   * mismo primer importe que el servidor, así que ese caso no distingue
   * nada. Aquí el servidor reparte a propósito de forma despareja: si la
   * pantalla recalculara, mandaría 21666.67 en vez de 20000.
   */
  it('cobra lo que dijo el servidor aunque el reparto no sea parejo', async () => {
    const desparejo = respuestas();
    desparejo['/orders/o1/split'] = () => ({
      pending: '65000.00',
      parts: ['20000.00', '20000.00', '25000.00'],
      non_item_total: '5000.00',
    });

    const { fetch } = await abrirDivision(desparejo);
    botones('Cobrar')[0].click();
    await reposar(4);

    expect(cuerpoDe(cobros(fetch)[0]).amount).toBe(20000);
  });

  it('cada cobro lleva su propia llave de idempotencia', async () => {
    const { fetch } = await abrirDivision();
    botones('Cobrar')[0].click();
    await reposar(4);
    botones('Cobrar')[0].click();
    await reposar(4);

    const llaves = cobros(fetch).map((c) => cuerpoDe(c).idempotency_key);
    expect(llaves).toHaveLength(2);
    expect(new Set(llaves).size).toBe(2);
  });
});

describe('dividir por productos', () => {
  async function enModoProductos() {
    const montado = await abrirDivision();
    botones('Por productos')[0].click();
    await reposar(3);
    return montado;
  }

  it('avisa de lo que no está en ninguna línea', async () => {
    await enModoProductos();
    // El envío no pertenece a ningún producto: marcando productos nunca se
    // llega al total, y callarlo dejaría un saldo que no baja a cero.
    expect(textoDialogo()).toContain('$ 5.000 de envío, descuento o propina');
  });

  it('cobra la suma de las líneas marcadas', async () => {
    const { fetch } = await enModoProductos();

    dialogo().querySelectorAll('input[type="checkbox"]')[1].click();
    await reposar(2);
    botones('Cobrar lo marcado')[0].click();
    await reposar(4);

    const [cobro] = cobros(fetch);
    expect(cuerpoDe(cobro).amount).toBe(30000);
  });

  it('sin nada marcado no deja cobrar', async () => {
    await enModoProductos();
    expect(botones('Cobrar lo marcado')[0].disabled).toBe(true);
  });
});
