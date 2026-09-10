// Entradas y salidas de efectivo del cajón (F7.1).
//
// Lo que se protege aquí son los dos permisos. Registrar un movimiento pide
// `cash.movements` y ver el cuadre pide `cash.close`: en un restaurante que
// separe los roles, quien saca la plata para el gas no ve de paso cuánto
// debería haber en el cajón. Si la pantalla juntara las dos cosas, daría ese
// dato sin que nadie lo decidiera.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar, Respuesta } from './montar-app.js';
import { sesion } from './sesion.js';

const TURNO = {
  id: 'cs1',
  branch_id: '22222222-2222-4222-8222-222222222222',
  opening_float: '100000.00',
  counted_cash: null,
  note: null,
  opened_at: '2026-09-10T14:00:00Z',
  closed_at: null,
  opened_by_name: 'Sara Caja',
  closed_by_name: null,
  is_open: true,
};

const CUADRE = {
  opening_float: '100000.00',
  by_method: { cash: '50000.00' },
  charged: '50000.00',
  refunded: '0.00',
  net_collected: '50000.00',
  cash_in: '0.00',
  cash_out: '30000.00',
  expected_cash: '120000.00',
  counted_cash: null,
  difference: null,
};

const MOVIMIENTOS = [
  { id: 'm1', kind: 'out', reason: 'Pago del gas', amount: '30000.00', created_at: '2026-09-10T15:00:00Z', by_name: 'Sara Caja' },
];

const montar = (permisos, extra = {}) =>
  montarApp({
    token: 'un-token',
    hash: '#/caja',
    respuestas: {
      '/auth/me': sesion({ permissions: permisos }),
      '/cash/session': { session: TURNO, totals: permisos.includes('cash.close') ? CUADRE : null },
      '/cash/sessions': [],
      '/cash/movements': MOVIMIENTOS,
      ...extra,
    },
  });

const texto = () => document.getElementById('vista').textContent.replace(/ /g, ' ');
const boton = (etiqueta) =>
  [...document.querySelectorAll('#vista button')].find((b) => b.textContent.trim() === etiqueta);
const campo = (etiqueta) => document.querySelector(`#vista [aria-label="${etiqueta}"]`);

const ultimoPost = (fetch) => {
  const llamada = fetch.mock.calls.filter(([, o]) => o?.method === 'POST').at(-1);
  return llamada ? { url: String(llamada[0]), cuerpo: JSON.parse(llamada[1].body ?? 'null') } : null;
};

describe('movimientos del cajón', () => {
  it('lista lo que salió, con su motivo y quién lo hizo', async () => {
    await montar(['payments.register', 'cash.movements']);

    expect(texto()).toContain('Pago del gas');
    expect(texto()).toContain('− $ 30.000');
    expect(texto()).toContain('Sara Caja');
  });

  it('registra una salida con su motivo', async () => {
    const { fetch } = await montar(['payments.register', 'cash.movements']);

    campo('Importe').value = '25000';
    campo('Motivo').value = 'Sangría al banco';
    boton('Registrar').click();
    await reposar(3);

    const post = ultimoPost(fetch);
    expect(post.cuerpo).toEqual({ kind: 'out', amount: 25000, reason: 'Sangría al banco', register_id: null });
    // Sobre la sucursal activa, no sobre la del token: con dos sedes, cuadrar
    // el cajón de la otra es un descuadre garantizado.
    expect(post.url).toBe('/api/v1/cash/movements?branch_id=22222222-2222-4222-8222-222222222222');
  });

  // El motivo obligatorio lo impone el dominio; la pantalla muestra su
  // mensaje en vez de inventar uno propio.
  it('muestra el rechazo del servidor sin reescribirlo', async () => {
    await montar(['payments.register', 'cash.movements'], {
      '/cash/movements': (url, opciones) =>
        opciones.method === 'POST'
          ? new Respuesta(422, { detail: 'No se pueden sacar 900.000,00: en el cajon hay 120.000,00' })
          : MOVIMIENTOS,
    });

    campo('Importe').value = '900000';
    campo('Motivo').value = 'Sangría';
    boton('Registrar').click();
    await reposar(3);

    expect(texto()).toContain('en el cajon hay 120.000,00');
  });

  // Quien registra movimientos no ve el esperado: son permisos distintos.
  it('sin cash.close no muestra el cuadre', async () => {
    await montar(['payments.register', 'cash.movements']);

    expect(texto()).toContain('Movimientos del cajón');
    expect(texto()).not.toContain('Debería haber');
    expect(texto()).toContain('El cuadre lo ve quien cierra');
  });

  it('sin cash.movements no ofrece registrar nada', async () => {
    await montar(['payments.register', 'cash.close']);

    expect(texto()).not.toContain('Movimientos del cajón');
    expect(boton('Registrar')).toBeUndefined();
  });

  // El cuadre suma lo que salió, y lo dice: si no, la diferencia entre lo
  // cobrado y lo esperado parece un error de la aplicación.
  it('el cuadre reporta lo que entró y lo que salió', async () => {
    await montar(['payments.register', 'cash.close', 'cash.movements']);

    expect(texto()).toContain('Entró al cajón $ 0, salió $ 30.000');
    expect(texto()).toContain('$ 120.000');
  });
});
