// Configurar los tiempos de la cuenta (F4.5).
//
// Va en su propio archivo, como las estaciones: montar la pantalla de venta
// y la de administración en el mismo deja vivo el temporizador de la
// primera y la corrida se queda colgada.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

/**
 * Los tiempos se configuran desde la web, como todo lo demás: un
 * restaurante que necesite un cuarto tiempo no debería esperar a que
 * alguien toque una tabla.
 */
describe('configurar los tiempos', () => {
  const AJUSTES = {
    tenant_id: 't1',
    name: 'Mesa Demo',
    business_type: 'table_service',
    currency: 'COP',
    channels: ['table'],
    uses_tables: true,
    asks_tip: true,
    tip_percent: 10,
    courses: ['Entradas', 'Fuertes'],
  };

  const montarAdmin = () =>
    montarApp({
      token: 't',
      hash: '#/admin',
      respuestas: {
        '/auth/me': sesion({ permissions: ['settings.view', 'settings.edit'], uses_tables: true }),
        '/settings': AJUSTES,
        '/branches': [],
        '/branches/*': { schedules: [], channels_without_windows: [] },
        '/order-statuses': {
          statuses: [],
          transitions: [],
          problems: [],
          warnings: [],
          categories: [],
          permissions: [],
        },
        '/print-profiles': [],
        '/fiscal/resolutions': [],
        '/tax-rates': [],
      },
    });

  const guardado = (fetch) => {
    const hecha = fetch.mock.calls.filter(([, o]) => o?.method === 'PATCH').at(-1);
    return hecha ? JSON.parse(hecha[1].body) : null;
  };

  const empieza = (texto) =>
    [...document.querySelectorAll('button')].find((b) => b.textContent.trim().startsWith(texto));

  it('muestra los tiempos configurados y guarda uno nuevo', async () => {
    const { fetch } = await montarAdmin();
    await reposar(2);

    const campos = [...document.querySelectorAll('[aria-label^="Nombre del tiempo"]')];
    expect(campos.map((c) => c.value)).toEqual(['Entradas', 'Fuertes']);

    empieza('Agregar tiempo').click();
    await reposar();
    const nuevo = [...document.querySelectorAll('[aria-label^="Nombre del tiempo"]')].at(-1);
    nuevo.value = 'Postres';
    nuevo.dispatchEvent(new Event('input'));

    empieza('Guardar cambios').click();
    await reposar(2);

    expect(guardado(fetch).courses).toEqual(['Entradas', 'Fuertes', 'Postres']);
  });

  /** Un tiempo vacío no se puede ofrecer, y el backend rechazaría el guardado entero. */
  it('descarta los tiempos sin nombre', async () => {
    const { fetch } = await montarAdmin();
    await reposar(2);

    empieza('Agregar tiempo').click();
    await reposar();
    empieza('Guardar cambios').click();
    await reposar(2);

    expect(guardado(fetch).courses).toEqual(['Entradas', 'Fuertes']);
  });
});
