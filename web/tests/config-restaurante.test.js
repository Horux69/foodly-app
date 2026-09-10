// Los datos del restaurante se editan desde la web.
//
// Lo que se prueba con más cuidado es el cambio de moneda: no reconvierte
// nada, así que casi siempre es un dedazo. La API lo rechaza sin una
// confirmación explícita (`Domain\TenantProfile`), y esta pantalla es la que
// tiene que pedirla — un `confirm` que no se traduzca en el campo del cuerpo
// dejaría al usuario mirando un error que ya respondió.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const AJUSTES = {
  tenant_id: 't1',
  name: 'Burger Demo',
  business_type: 'fast_food',
  currency: 'COP',
  channels: ['counter'],
  uses_tables: false,
  asks_tip: false,
};

const YO = sesion({ permissions: ['settings.view', 'settings.edit'] });

async function montarAdmin(respuestaDeSettings = AJUSTES) {
  const { fetch } = await montarApp({
    token: 't',
    hash: '#/admin',
    respuestas: {
      '/auth/me': YO,
      '/settings': respuestaDeSettings,
      '/branches': [],
      // La pantalla carga las franjas de la sede activa desde que existe la
      // sección de Horarios.
      '/branches/*': { schedules: [], channels_without_windows: [] },
      // El editor de estados carga el flujo del restaurante.
      '/order-statuses': { statuses: [], transitions: [], problems: [], warnings: [], categories: [], permissions: [] },
      '/print-profiles': [],
      '/fiscal/resolutions': [],
      '/tax-rates': [],
      '/fiscal/resolutions': [],
    },
  });
  return { fetch };
}

const boton = (texto) =>
  [...document.querySelectorAll('button')].find((b) => b.textContent.trim().startsWith(texto));

const campo = (etiqueta) =>
  [...document.querySelectorAll('label')]
    .find((l) => l.textContent.trim().startsWith(etiqueta))
    ?.querySelector('input, select');

/** El cuerpo del último PATCH a /settings. */
const guardado = (fetch) => {
  const llamada = fetch.mock.calls.filter(([, o]) => o?.method === 'PATCH').at(-1);
  return llamada ? JSON.parse(llamada[1].body) : null;
};

describe('datos del restaurante', () => {
  it('muestra el nombre, el modelo y la moneda como campos editables', async () => {
    await montarAdmin();

    expect(campo('Nombre').value).toBe('Burger Demo');
    expect(campo('Modelo de negocio').value).toBe('fast_food');
    expect(campo('Moneda').value).toBe('COP');
  });

  it('guarda el nombre sin preguntar nada', async () => {
    const { fetch } = await montarAdmin();

    campo('Nombre').value = 'Burger Demo Centro';
    boton('Guardar cambios').click();
    await reposar();

    const cuerpo = guardado(fetch);
    expect(cuerpo.name).toBe('Burger Demo Centro');
    // Sin cambio de moneda no hay nada que confirmar, y mandar el visto bueno
    // igual convertiría la salvaguarda en un campo decorativo.
    expect(cuerpo.confirm_currency_change).toBe(false);
  });

  it('pide confirmar el cambio de moneda y lo dice en el cuerpo', async () => {
    const { fetch } = await montarAdmin();

    campo('Moneda').value = 'usd';
    boton('Guardar cambios').click();
    await reposar();

    // Primero el diálogo: todavía no se guardó nada.
    expect(guardado(fetch)).toBeNull();
    expect(document.body.textContent).toContain('no se reconvierten');

    boton('Cambiar la moneda').click();
    await reposar();

    const cuerpo = guardado(fetch);
    expect(cuerpo.currency).toBe('USD');
    expect(cuerpo.confirm_currency_change).toBe(true);
  });

  it('no guarda nada si se cancela el cambio de moneda', async () => {
    const { fetch } = await montarAdmin();

    campo('Moneda').value = 'USD';
    boton('Guardar cambios').click();
    await reposar();

    boton('Cancelar').click();
    await reposar();

    expect(guardado(fetch)).toBeNull();
  });

  it('deja los campos bloqueados a quien solo puede ver', async () => {
    await montarApp({
      token: 't',
      hash: '#/admin',
      respuestas: {
        '/auth/me': sesion({ permissions: ['settings.view'] }),
        '/settings': AJUSTES,
        '/branches': [],
        '/branches/*': { schedules: [], channels_without_windows: [] },
        '/order-statuses': { statuses: [], transitions: [], problems: [], warnings: [], categories: [], permissions: [] },
        '/tax-rates': [],
        '/fiscal/resolutions': [],
      },
    });

    expect(campo('Nombre').disabled).toBe(true);
    expect(campo('Moneda').disabled).toBe(true);
    expect(boton('Guardar cambios')).toBeUndefined();
  });
});
