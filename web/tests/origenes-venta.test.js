// Orígenes de venta y su comisión (F9.4).
//
// Va aparte del canal (mostrador, mesa, domicilio), que dice por dónde se
// vendió. Este dice de quién vino la venta: sin él, un pedido de Rappi se
// reporta como uno propio y el dueño cree que vendió 50.000 cuando le
// entraron 35.000.
//
// Se prueba en su propio archivo, como las estaciones: la pantalla de venta
// y la de administración en el mismo dejan vivo un temporizador.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const ORIGENES = [
  { id: 's1', name: 'Rappi', commission_percent: 30, is_active: true },
  { id: 's2', name: 'Propio web', commission_percent: 0, is_active: true },
];

const AJUSTES = {
  tenant_id: 't1',
  name: 'Burger Demo',
  business_type: 'fast_food',
  currency: 'COP',
  channels: ['counter'],
  uses_tables: false,
  asks_tip: false,
  tip_percent: 10,
  courses: [],
};

const boton = (texto) =>
  [...document.querySelectorAll('button')].find((b) => b.textContent.trim().startsWith(texto));

const llamada = (fetch, metodo) => {
  const hecha = fetch.mock.calls.filter(([, o]) => o?.method === metodo).at(-1);
  return hecha ? { url: String(hecha[0]), cuerpo: JSON.parse(hecha[1].body ?? 'null') } : null;
};

describe('administrar los orígenes', () => {
  const montarAdmin = (origenes = ORIGENES, permisos = ['settings.view', 'settings.edit']) =>
    montarApp({
      token: 't',
      hash: '#/admin',
      respuestas: {
        '/auth/me': sesion({ permissions: permisos }),
        '/settings': AJUSTES,
        '/branches': [],
        '/branches/*': { schedules: [], channels_without_windows: [] },
        '/order-statuses': {
          statuses: [], transitions: [], problems: [], warnings: [], categories: [], permissions: [],
        },
        '/tax-rates': [],
        '/sales-sources': origenes,
        '/sales-sources/*': {},
        '/print-profiles': [],
        '/fiscal/resolutions': [],
      },
    });

  it('lista los orígenes con su comisión', async () => {
    await montarAdmin();
    boton('Orígenes').click();
    await reposar(2);

    const texto = document.getElementById('vista').textContent;
    expect(texto).toContain('% de comisión');
    const nombres = [...document.querySelectorAll('#vista input')].map((i) => i.value);
    expect(nombres).toContain('Rappi');
  });

  it('crea uno nuevo con su porcentaje', async () => {
    const { fetch } = await montarAdmin();
    boton('Orígenes').click();
    await reposar(2);

    const campos = [...document.querySelectorAll('#vista input')];
    // Los dos últimos son los del formulario de alta.
    campos.at(-2).value = 'DiDi Food';
    campos.at(-1).value = '25';
    boton('Agregar origen').click();
    await reposar(2);

    expect(llamada(fetch, 'POST')).toEqual({
      url: '/api/v1/sales-sources',
      cuerpo: { name: 'DiDi Food', commission_percent: 25 },
    });
  });

  it('quien solo puede ver no edita', async () => {
    await montarAdmin(ORIGENES, ['settings.view']);
    boton('Orígenes').click();
    await reposar(2);

    expect(boton('Agregar origen')).toBeUndefined();
    expect(boton('Apagar')).toBeUndefined();
    expect(document.querySelector('#vista input').disabled).toBe(true);
  });
});

