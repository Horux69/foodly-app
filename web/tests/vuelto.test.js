// El vuelto al cobrar (F7.6).
//
// Hasta ahora el cajero hacía la resta de cabeza o en el teléfono. Lo que
// se protege aquí es sobre todo lo que el vuelto NO hace: no viaja en la
// petición ni se guarda en ninguna parte. No es un cobro —eso ya se
// registró entero— sino plata que sale del cajón por ese cobro;
// registrarlo lo contaría dos veces en el arqueo.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const PEDIDO = {
  id: 'o1',
  order_number: 'CEN-00042',
  channel: 'counter',
  table_code: null,
  created_at: new Date().toISOString(),
  status: { id: 's1', code: 'pendiente', name: 'Pendiente', category: 'new', color: null },
  subtotal: '18000.00',
  tax_total: '0.00',
  delivery_fee: '0.00',
  discount: '0.00',
  tip: '0.00',
  total: '18000.00',
  notes: null,
  is_editable: true,
  kitchen_has_it: false,
  is_server_assignable: true,
  courses: [],
  pending_courses: [],
  items: [
    {
      id: 'i1', menu_item_id: 'm1', name_snapshot: 'Hamburguesa', quantity: 1,
      unit_price: '18000.00', tax_amount: '0.00', line_total: '18000.00', notes: null,
      course: 1, fired_at: null, modifiers: [], components: [],
    },
  ],
  balance: {
    total: '18000.00', paid: '0.00', refunded: '0.00', net_paid: '0.00',
    pending: '18000.00', is_settled: false,
  },
  delivery: null,
  kitchen_tickets: [],
};

async function abrirPanel(permisos = ['orders.view', 'orders.create', 'payments.register']) {
  const montado = await montarApp({
    token: 't',
    hash: '#/pedidos',
    respuestas: {
      '/auth/me': sesion({ permissions: permisos }),
      '/menu': [],
      '/sales-sources': [],
      '/orders': { items: [PEDIDO], next_cursor: null },
      '/orders/o1': PEDIDO,
      '/orders/o1/payments': [],
      '/orders/o1/next-statuses': [],
      '/orders/o1/history': [],
      '/orders/o1/fiscal-document': { document: null },
    },
  });

  await reposar(2);
  document.querySelectorAll('#vista button').forEach((b) => {
    if (b.textContent === 'Pedidos del día') b.click();
  });
  await reposar();
  [...document.querySelectorAll('#vista button')].find((b) => b.textContent === 'CEN-00042').click();
  await reposar(6);
  return montado;
}

const panel = () => document.querySelector('[aria-label="Detalle del pedido"]');
const campo = (etiqueta) => panel().querySelector(`[aria-label="${etiqueta}"]`);
const escribir = (el, valor) => {
  el.value = valor;
  el.dispatchEvent(new Event('input'));
};

describe('vuelto al cobrar', () => {
  it('calcula lo que hay que devolver', async () => {
    await abrirPanel();

    escribir(campo('Con cuánto paga'), '20000');
    await reposar();

    expect(panel().textContent).toContain('Vuelto');
    expect(panel().textContent).toContain('2.000');
  });

  /** Pagar de menos no es un error: es un cobro parcial. */
  it('con menos de lo que cuesta dice cuánto falta', async () => {
    await abrirPanel();

    escribir(campo('Con cuánto paga'), '10000');
    await reposar();

    expect(panel().textContent).toContain('Faltan');
    expect(panel().textContent).toContain('8.000');
  });

  it('pagar justo lo dice sin inventar un vuelto', async () => {
    await abrirPanel();

    escribir(campo('Con cuánto paga'), '18000');
    await reposar();

    expect(panel().textContent).toContain('pagó justo');
  });

  /** Los billetes que alcanzan; ofrecer 2.000 para una cuenta de 18.000 es ruido. */
  it('ofrece como atajo el importe exacto y los billetes que cubren', async () => {
    await abrirPanel();

    // `money()` separa con espacio duro; se normaliza para comparar.
    const textos = [...panel().querySelectorAll('button')].map((b) =>
      b.textContent.trim().replace(/\u00a0/g, ' ')
    );
    expect(textos).toContain('$ 18.000');
    expect(textos).toContain('$ 20.000');
    expect(textos).not.toContain('$ 2.000');
  });

  it('el atajo llena el campo y calcula', async () => {
    await abrirPanel();

    [...panel().querySelectorAll('button')]
      .find((b) => b.textContent.trim().replace(/\u00a0/g, ' ') === '$ 50.000')
      .click();
    await reposar();

    expect(campo('Con cuánto paga').value).toBe('50000');
    expect(panel().textContent).toContain('32.000');
  });

  /**
   * Lo que importa: el vuelto no viaja. Si entrara en el cobro, el pedido
   * quedaría cobrado de más y el arqueo cuadraría con plata que no está.
   */
  it('el cobro manda solo lo que se cobra, no lo que entregó el cliente', async () => {
    const { fetch } = await abrirPanel();

    escribir(campo('Con cuánto paga'), '50000');
    await reposar();
    [...panel().querySelectorAll('button')].find((b) => b.textContent.trim() === 'Cobrar').click();
    await reposar(3);

    const cobro = fetch.mock.calls.find(
      ([url, o]) => o?.method === 'POST' && String(url).includes('/payments')
    );
    const cuerpo = JSON.parse(cobro[1].body);
    expect(cuerpo.amount).toBe(18000);
    expect(cuerpo).not.toHaveProperty('received');
    expect(cuerpo).not.toHaveProperty('change');
  });

  /** Con tarjeta no hay vuelto que dar, y el campo estorba. */
  it('con tarjeta no aparece', async () => {
    await abrirPanel();

    const metodo = campo('Método de pago');
    metodo.value = 'card';
    metodo.dispatchEvent(new Event('change'));
    await reposar();

    expect(campo('Con cuánto paga')).toBeNull();
  });
});
