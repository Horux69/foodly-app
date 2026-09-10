// Horarios de sucursal desde la web.
//
// Lo que se protege son las dos formas en que un horario mal puesto apaga las
// ventas sin dar ninguna señal: un canal que se quedó sin ninguna franja
// —caso típico, se configuran las horas del mostrador y se olvida el
// domicilio— y una franja nocturna, que cierra antes de la hora a la que
// abre y que no hay que "corregir".

import { describe, expect, it } from 'vitest';
import { montarApp, reposar, Respuesta } from './montar-app.js';
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

const franja = (cambios = {}) => ({
  id: 'h1',
  branch_id: SEDE,
  weekday: 0,
  weekday_name: 'Lunes',
  opens_at: '10:00',
  closes_at: '22:00',
  crosses_midnight: false,
  channel: null,
  is_active: true,
  ...cambios,
});

const YO = sesion({ permissions: ['settings.view', 'branches.manage'] });

async function montarHorarios(horarios, extra = {}) {
  const montado = await montarApp({
    token: 't',
    hash: '#/admin',
    respuestas: {
      '/auth/me': YO,
      '/settings': AJUSTES,
      '/branches': [{ id: SEDE, name: 'Centro', code: 'CEN', timezone: 'America/Bogota' }],
      '/branches/*': (url) =>
        url.includes('/schedules') ? horarios : [],
      // El editor de estados carga el flujo del restaurante.
      '/order-statuses': { statuses: [], transitions: [], problems: [], warnings: [], categories: [], permissions: [] },
      '/print-profiles': [],
      '/tax-rates': [],
      ...extra,
    },
  });

  boton('Horarios').click();
  await reposar();
  return montado;
}

function boton(texto, raiz = document) {
  return [...raiz.querySelectorAll('button')].find((b) => b.textContent.trim().startsWith(texto));
}

const campo = (etiqueta) =>
  [...document.querySelectorAll('label')]
    .find((l) => l.textContent.trim().startsWith(etiqueta))
    ?.querySelector('input, select');

const ultimo = (fetch, metodo) => {
  const llamada = fetch.mock.calls.filter(([, o]) => o?.method === metodo).at(-1);
  return llamada ? { url: String(llamada[0]), cuerpo: JSON.parse(llamada[1].body ?? 'null') } : null;
};

describe('horarios de sucursal', () => {
  it('sin franjas dice que la sede atiende siempre', async () => {
    await montarHorarios({ schedules: [], channels_without_windows: [] });

    expect(document.body.textContent).toContain('atiende a cualquier hora');
  });

  it('avisa del canal que se quedó sin franjas', async () => {
    await montarHorarios({
      schedules: [franja({ channel: 'counter' })],
      channels_without_windows: ['delivery'],
    });

    // Con horarios ya configurados ese canal queda cerrado siempre, y no da
    // ninguna señal hasta que alguien intenta vender.
    expect(document.body.textContent).toContain('Sin franjas para Domicilio');
    expect(document.body.textContent).toContain('no se puede pedir en ningún momento');
  });

  it('no avisa cuando todos los canales tienen cobertura', async () => {
    await montarHorarios({ schedules: [franja()], channels_without_windows: [] });

    expect(document.body.textContent).not.toContain('Sin franjas para');
    expect(document.body.textContent).toContain('Todos los canales');
  });

  it('marca la franja nocturna como del día siguiente', async () => {
    await montarHorarios({
      schedules: [franja({ weekday: 4, weekday_name: 'Viernes', opens_at: '20:00', closes_at: '02:00', crosses_midnight: true })],
      channels_without_windows: [],
    });

    // Cerrar antes de abrir no es un error de captura: es la franja que cruza
    // la medianoche, y decirlo evita que alguien la "corrija".
    expect(document.body.textContent).toContain('20:00 – 02:00');
    expect(document.body.textContent).toContain('del día siguiente');
  });

  it('agrega una franja con su día, sus horas y su canal', async () => {
    const { fetch } = await montarHorarios({ schedules: [], channels_without_windows: [] });

    campo('Día').value = '4';
    campo('Abre').value = '20:00';
    campo('Cierra').value = '02:00';
    campo('Canal').value = 'delivery';
    boton('Agregar franja').click();
    await reposar();

    const enviado = ultimo(fetch, 'POST');
    expect(enviado.url).toContain(`/branches/${SEDE}/schedules`);
    expect(enviado.cuerpo).toEqual({
      weekday: 4,
      opens_at: '20:00',
      closes_at: '02:00',
      channel: 'delivery',
    });
  });

  it('manda canal nulo cuando la franja vale para todos', async () => {
    const { fetch } = await montarHorarios({ schedules: [], channels_without_windows: [] });

    boton('Agregar franja').click();
    await reposar();

    expect(ultimo(fetch, 'POST').cuerpo.channel).toBeNull();
  });

  it('muestra el rechazo del backend sin dejar el botón muerto', async () => {
    // Listar y crear son la misma ruta con distinto método: la respuesta se
    // decide por el método.
    await montarHorarios({ schedules: [], channels_without_windows: [] }, {
      '/branches/*': (url, opciones) => {
        if (!url.includes('/schedules')) return [];
        if (opciones.method === 'POST') {
          return new Respuesta(422, { detail: 'La franja no puede abrir y cerrar a la misma hora' });
        }
        return { schedules: [], channels_without_windows: [] };
      },
    });

    const agregar = boton('Agregar franja');
    agregar.click();
    await reposar();

    expect(document.body.textContent).toContain('abrir y cerrar a la misma hora');
    // Y el botón sigue sirviendo para corregir y reintentar.
    expect(agregar.disabled).toBe(false);
  });

  it('deja apagar una franja sin borrarla', async () => {
    const { fetch } = await montarHorarios({ schedules: [franja()], channels_without_windows: [] });

    boton('Apagar').click();
    await reposar();

    const enviado = ultimo(fetch, 'PATCH');
    expect(enviado.url).toContain('/schedules/h1/active');
    expect(enviado.cuerpo).toEqual({ is_active: false });
  });

  it('quien solo puede ver no agrega ni apaga', async () => {
    await montarApp({
      token: 't',
      hash: '#/admin',
      respuestas: {
        '/auth/me': sesion({ permissions: ['settings.view'] }),
        '/settings': AJUSTES,
        '/branches': [{ id: SEDE, name: 'Centro', code: 'CEN', timezone: 'America/Bogota' }],
        '/branches/*': (url) => (url.includes('/schedules') ? { schedules: [franja()], channels_without_windows: [] } : []),
        '/order-statuses': { statuses: [], transitions: [], problems: [], warnings: [], categories: [], permissions: [] },
        '/tax-rates': [],
      },
    });
    boton('Horarios').click();
    await reposar();

    expect(boton('Agregar franja')).toBeUndefined();
    expect(boton('Apagar')).toBeUndefined();
  });
});
