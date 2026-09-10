// Varias cajas por sucursal (F7.4).
//
// Con una sola caja —o ninguna configurada, el caso de casi todos— nada
// cambia: la pantalla no pregunta nada y el `register_id` que manda es
// `null`. Lo que se protege aquí es lo nuevo: con dos cajas hay que elegir
// en cuál se está, la elección se recuerda por dispositivo, y esa misma
// elección sirve tanto para abrir/cerrar el turno en Caja como para cobrar
// desde el detalle del pedido, sin volver a preguntar.

import { beforeEach, describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const SUCURSAL = '22222222-2222-4222-8222-222222222222';

const CAJAS = [
  { id: 'r1', name: 'Mostrador', is_active: true },
  { id: 'r2', name: 'Barra', is_active: true },
];

const turno = (registerId, registerName, numero) => ({
  id: `cs-${registerId ?? 'sede'}`,
  branch_id: SUCURSAL,
  register_id: registerId,
  register_name: registerName,
  number: numero,
  opening_float: '50000.00',
  counted_cash: null,
  note: null,
  opened_at: '2026-09-10T14:00:00Z',
  closed_at: null,
  opened_by_name: 'Sara Caja',
  closed_by_name: null,
  is_open: true,
});

const boton = (etiqueta, raiz = document) =>
  [...raiz.querySelectorAll('button')].find((b) => b.textContent.trim() === etiqueta);
const texto = () => document.getElementById('vista').textContent.replace(/ /g, ' ');

beforeEach(() => {
  try {
    localStorage.clear();
  } catch {
    // nada que limpiar
  }
});

describe('la pantalla de caja con dos cajas', () => {
  const montar = (respuestas = {}) =>
    montarApp({
      token: 't',
      hash: '#/caja',
      respuestas: {
        '/auth/me': sesion({ permissions: ['payments.register', 'cash.close', 'cash.movements'] }),
        [`/branches/${SUCURSAL}/registers`]: CAJAS,
        '/cash/session': (url) =>
          url.includes('register_id=r2')
            ? { session: turno('r2', 'Barra', 2), totals: null }
            : { session: turno('r1', 'Mostrador', 1), totals: null },
        '/cash/movements': [],
        '/cash/sessions': [],
        ...respuestas,
      },
    });

  it('muestra un selector y arranca en la primera caja', async () => {
    await montar();
    await reposar(3);

    expect(boton('Mostrador')).toBeDefined();
    expect(boton('Barra')).toBeDefined();
    expect(texto()).toContain('Mostrador');
    expect(texto()).toContain('N.º 1');
  });

  it('cambiar de caja pide el turno de la otra y lo recuerda', async () => {
    const { fetch } = await montar();
    await reposar(3);

    boton('Barra').click();
    await reposar(8);

    expect(texto()).toContain('N.º 2');
    const ultima = fetch.mock.calls.filter(([u]) => String(u).includes('/cash/session?')).at(-1);
    expect(String(ultima[0])).toContain('register_id=r2');

    // Se recuerda por dispositivo, con la misma llave que usa el detalle
    // del pedido para cobrar en la misma caja sin volver a preguntar.
    expect(localStorage.getItem(`resto_caja_elegida:${SUCURSAL}`)).toBe('r2');
  });

  it('abrir turno manda el register_id de la caja elegida', async () => {
    const { fetch } = await montar({
      '/cash/session': { session: null, totals: null },
    });
    await reposar(3);

    boton('Barra').click();
    await reposar(2);
    document.querySelector('#vista input[type="number"]').value = '30000';
    boton('Abrir turno').click();
    await reposar(3);

    const abierto = fetch.mock.calls.find(([u, o]) => String(u).includes('/cash/session') && o?.method === 'POST');
    expect(JSON.parse(abierto[1].body)).toEqual({ opening_float: 30000, register_id: 'r2' });
  });

  it('un movimiento del cajón se registra en la caja elegida', async () => {
    const { fetch } = await montar();
    await reposar(3);

    // Se elige Barra antes de registrar el movimiento: por defecto arranca
    // en la primera caja de la lista, así que sin este paso el movimiento
    // entraría al Mostrador.
    boton('Barra').click();
    await reposar(3);

    document.querySelector('[aria-label="Importe"]').value = '5000';
    document.querySelector('[aria-label="Motivo"]').value = 'Cambio';
    boton('Registrar').click();
    await reposar(3);

    const post = fetch.mock.calls.find(([u, o]) => String(u).includes('/cash/movements') && o?.method === 'POST');
    expect(JSON.parse(post[1].body).register_id).toBe('r2');
  });
});

describe('sin cajas configuradas', () => {
  it('no muestra ningún selector', async () => {
    await montarApp({
      token: 't',
      hash: '#/caja',
      respuestas: {
        '/auth/me': sesion({ permissions: ['payments.register', 'cash.close', 'cash.movements'] }),
        [`/branches/${SUCURSAL}/registers`]: [],
        '/cash/session': { session: turno(null, null, 1), totals: null },
        '/cash/movements': [],
        '/cash/sessions': [],
      },
    });
    await reposar(3);

    expect(texto()).not.toContain('Mostrador');
    expect(texto()).toContain('N.º 1');
  });
});
