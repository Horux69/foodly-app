// Zonas de reparto dibujadas en el mapa (F9.3).
//
// Lo que se protege: la forma sigue siendo configuración opcional (una zona
// sin dibujar funciona igual que siempre), guardar exige al menos tres
// puntos —lo mismo que valida el backend—, y borrar la forma no apaga la
// zona ni le cambia la tarifa. Leaflet se mockea con `window.L`: la pantalla
// nunca lo carga de verdad en la prueba, así que no hay red de por medio ni
// dependencia de que jsdom sepa dibujar un mapa.

import { afterEach, describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const SEDE = '22222222-2222-4222-8222-222222222222';

const AJUSTES = {
  tenant_id: 't1',
  name: 'Burger Demo',
  business_type: 'fast_food',
  currency: 'COP',
  channels: ['counter', 'delivery'],
  uses_tables: false,
  asks_tip: false,
};

const zona = (cambios = {}) => ({
  id: 'z1',
  branch_id: SEDE,
  name: 'Centro',
  fee: '5000.00',
  min_order: '0.00',
  est_minutes: 30,
  is_active: true,
  polygon: null,
  ...cambios,
});

const YO = sesion({ permissions: ['settings.view', 'branches.manage'] });

/** Fake mínimo de Leaflet: solo lo que la pantalla de verdad usa. */
function fakeLeaflet() {
  let clickCb = null;
  const mapaFake = {
    setView: () => mapaFake,
    on: (evento, cb) => {
      if (evento === 'click') clickCb = cb;
      return mapaFake;
    },
  };
  const capas = [];
  const L = {
    map: () => mapaFake,
    tileLayer: () => ({ addTo: () => {} }),
    polygon: (puntos) => {
      const capa = { puntos, addTo: () => capa, remove: () => {}, bindTooltip: () => capa };
      capas.push(capa);
      return capa;
    },
  };
  return { L, capas, click: (lat, lng) => clickCb?.({ latlng: { lat, lng } }) };
}

async function montarZonas(zonas, extra = {}) {
  const montado = await montarApp({
    token: 't',
    hash: '#/admin',
    respuestas: {
      '/auth/me': YO,
      '/settings': AJUSTES,
      '/branches': [{ id: SEDE, name: 'Centro', code: 'CEN', timezone: 'America/Bogota' }],
      '/branches/*': (url) => (url.includes('/delivery-zones') ? zonas : url.includes('/schedules') ? { schedules: [], channels_without_windows: [] } : []),
      '/print-profiles': [],
      '/fiscal/resolutions': [],
      '/tax-rates': [],
      '/sales-sources': [],
      '/order-statuses': { statuses: [], transitions: [], problems: [], warnings: [], categories: [], permissions: [] },
      ...extra,
    },
  });

  boton('Domicilios').click();
  await reposar();
  return montado;
}

const boton = (texto, raiz = document) =>
  [...raiz.querySelectorAll('button')].find((b) => b.textContent.trim() === texto);

const dialogo = () => [...document.querySelectorAll('[role="dialog"]')].pop();
const botonDialogo = (texto) => [...dialogo().querySelectorAll('button')].find((b) => b.textContent.trim() === texto);

const ultimoPatch = (fetch) => {
  const llamada = fetch.mock.calls.filter(([u, o]) => String(u).includes('/polygon') && o?.method === 'PATCH').at(-1);
  return llamada ? JSON.parse(llamada[1].body) : null;
};

afterEach(() => {
  delete window.L;
});

describe('zonas de reparto: forma en el mapa', () => {
  it('una zona sin forma no muestra la insignia, una con forma sí', async () => {
    await montarZonas([zona({ id: 'z1', name: 'Centro', polygon: null }), zona({ id: 'z2', name: 'Norte', polygon: [[4.7, -74.1], [4.71, -74.11], [4.72, -74.12]] })]);

    const filas = [...document.querySelectorAll('#vista .divide-y.divide-stone-100 > div')];
    const filaCentro = filas.find((f) => f.textContent.includes('Centro'));
    const filaNorte = filas.find((f) => f.textContent.includes('Norte'));
    expect(filaCentro.textContent).not.toContain('Con forma');
    expect(filaNorte.textContent).toContain('Con forma');
  });

  it('dibujar tres puntos y guardar manda el polígono al backend', async () => {
    const { fetch } = await montarZonas([zona()]);
    const { L, click } = fakeLeaflet();
    window.L = L;

    boton('Dibujar').click();
    await reposar(3);

    click(4.6, -74.08);
    click(4.61, -74.07);
    click(4.62, -74.09);

    botonDialogo('Guardar forma').click();
    await reposar(3);

    expect(ultimoPatch(fetch)).toEqual({ polygon: [[4.6, -74.08], [4.61, -74.07], [4.62, -74.09]] });
  });

  it('guardar con menos de tres puntos no manda nada', async () => {
    const { fetch } = await montarZonas([zona()]);
    window.L = fakeLeaflet().L;

    boton('Dibujar').click();
    await reposar(3);

    botonDialogo('Guardar forma').click();
    await reposar(3);

    expect(ultimoPatch(fetch)).toBeNull();
  });

  it('deshacer el último punto lo saca del polígono guardado', async () => {
    const { fetch } = await montarZonas([zona()]);
    const { L, click } = fakeLeaflet();
    window.L = L;

    boton('Dibujar').click();
    await reposar(3);

    click(4.6, -74.08);
    click(4.61, -74.07);
    click(4.62, -74.09);
    click(9.99, -74.5); // este se deshace

    botonDialogo('Deshacer último punto').click();
    botonDialogo('Guardar forma').click();
    await reposar(3);

    expect(ultimoPatch(fetch)).toEqual({ polygon: [[4.6, -74.08], [4.61, -74.07], [4.62, -74.09]] });
  });

  it('borrar la forma manda polygon null sin exigir puntos', async () => {
    const { fetch } = await montarZonas([zona({ polygon: [[4.6, -74.08], [4.61, -74.07], [4.62, -74.09]] })]);
    window.L = fakeLeaflet().L;

    boton('Dibujar').click();
    await reposar(3);

    botonDialogo('Borrar forma').click();
    await reposar(3);

    expect(ultimoPatch(fetch)).toEqual({ polygon: null });
  });

  it('el diálogo muestra las demás zonas dibujadas de referencia', async () => {
    const { L, capas } = fakeLeaflet();
    window.L = L;
    await montarZonas([
      zona({ id: 'z1', name: 'Centro', polygon: null }),
      zona({ id: 'z2', name: 'Norte', polygon: [[4.7, -74.1], [4.71, -74.11], [4.72, -74.12]] }),
    ]);

    boton('Dibujar').click();
    await reposar(3);

    // La única zona con forma, aparte de la que se está editando, es Norte.
    expect(capas.length).toBe(1);
    expect(capas[0].puntos).toEqual([[4.7, -74.1], [4.71, -74.11], [4.72, -74.12]]);
  });
});
