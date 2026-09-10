// La pantalla de caja.
//
// Lo que se protege aquí es de dónde salen las cifras y quién las ve. El
// cuadre lo calcula `Domain\CashSessionTotals` y la pantalla lo muestra: si
// el esperado se recalculara en el navegador, un reembolso registrado
// después del cierre daría dos números distintos según dónde se mirara.
//
// Y quien solo puede cobrar no debe ver el esperado antes de contar el
// cajón: contar sabiendo el resultado no es un arqueo.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const TURNO = {
  id: 'cs1',
  branch_id: '22222222-2222-4222-8222-222222222222',
  opening_float: '50000.00',
  counted_cash: null,
  note: null,
  opened_at: '2026-09-08T14:00:00Z',
  closed_at: null,
  opened_by_name: 'Sara Caja',
  closed_by_name: null,
  is_open: true,
};

const CUADRE = {
  opening_float: '50000.00',
  by_method: { card: '36000.00', cash: '72000.00' },
  charged: '112000.00',
  refunded: '4000.00',
  net_collected: '108000.00',
  expected_cash: '122000.00',
  counted_cash: null,
  difference: null,
};

const CIERRE = {
  session: { ...TURNO, counted_cash: '121500.00', closed_at: '2026-09-08T22:00:00Z', closed_by_name: 'Admin', is_open: false },
  totals: { ...CUADRE, counted_cash: '121500.00', difference: '-500.00' },
};

const texto = () => document.getElementById('vista').textContent.replace(/\u00a0/g, ' ');

const montar = (respuestas) => montarApp({ token: 'un-token', hash: '#/caja', respuestas });

const supervisor = (extra = {}) => ({
  '/auth/me': sesion({ permissions: ['payments.register', 'cash.close'] }),
  '/cash/session': { session: TURNO, totals: CUADRE },
  '/cash/sessions': [],
  ...extra,
});

describe('caja con turno abierto', () => {
  it('muestra quién abrió el turno y con cuánta base', async () => {
    await montar(supervisor());
    expect(texto()).toContain('Sara Caja');
    expect(texto()).toContain('$ 50.000');
  });

  it('muestra el cuadre que calculó el backend, sin recalcularlo', async () => {
    await montar(supervisor());
    const t = texto();

    expect(t).toContain('Efectivo');
    expect(t).toContain('$ 72.000');
    expect(t).toContain('Tarjeta');
    // El esperado es el que llegó: base + efectivo, pero no se suma aquí.
    expect(t).toContain('$ 122.000');
    // Y los reembolsos del turno se ven, no se esconden en el neto.
    expect(t).toContain('devuelto $ 4.000');
  });

  it('al cerrar manda lo contado y la nota, y dice cuánto falta', async () => {
    const { fetch } = await montar(supervisor({ '/cash/sessions/cs1/close': CIERRE }));

    const vista = document.getElementById('vista');
    vista.querySelector('input[type="number"]').value = '121500';
    vista.querySelector('input[placeholder^="Nota del cierre"]').value = 'Faltó un billete';
    [...vista.querySelectorAll('button')].find((b) => b.textContent.includes('Cerrar turno')).click();
    await reposar();

    const llamada = fetch.mock.calls.find(([url, o]) => String(url).includes('/close') && o?.method === 'POST');
    expect(llamada).toBeDefined();
    const cuerpo = JSON.parse(llamada[1].body);
    expect(cuerpo.counted_cash).toBe(121500);
    expect(cuerpo.note).toBe('Faltó un billete');

    // La diferencia se muestra tal como la calculó el dominio.
    expect(texto()).toContain('Faltan $ 500');
  });
});

describe('caja sin turno abierto', () => {
  it('ofrece abrirlo con una base', async () => {
    const { fetch } = await montar(supervisor({ '/cash/session': { session: null, totals: null } }));
    expect(texto()).toContain('Abrir turno');

    const vista = document.getElementById('vista');
    vista.querySelector('input[type="number"]').value = '40000';
    [...vista.querySelectorAll('button')].find((b) => b.textContent.includes('Abrir turno')).click();
    await reposar();

    const llamada = fetch.mock.calls.find(([url, o]) => String(url).endsWith('/cash/session') && o?.method === 'POST');
    expect(JSON.parse(llamada[1].body).opening_float).toBe(40000);
  });
});

describe('quien solo cobra', () => {
  const cajera = {
    '/auth/me': sesion({ permissions: ['payments.register'] }),
    // El backend le devuelve el turno sin cuadre: la decisión es suya.
    '/cash/session': { session: TURNO, totals: null },
  };

  it('ve que hay turno abierto pero no cuánto debería haber', async () => {
    await montar(cajera);
    const t = texto();

    expect(t).toContain('Sara Caja');
    expect(t).toContain('El cuadre lo ve quien cierra');
    expect(t).not.toContain('$ 122.000');
    expect(t).not.toContain('Cerrar turno');
  });

  it('no pide el historial de turnos', async () => {
    const { fetch } = await montar(cajera);
    const historial = fetch.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('/cash/sessions'));
    expect(historial).toEqual([]);
  });
});
