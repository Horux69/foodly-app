// Estados de pedido y transiciones desde la web.
//
// Es la pantalla más peligrosa de la fase: un flujo mal editado no da error
// al guardarlo sino cuando alguien intenta vender. Lo que se prueba es que
// las dos formas de romperlo lleguen como un rechazo con motivo —sin estado
// inicial no se puede crear ningún pedido; un estado no final sin salida deja
// el pedido atascado— y que la pantalla no invente reglas por su cuenta: los
// avisos los calcula `Domain\StatusMachineRules` y aquí solo se muestran.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar, Respuesta } from './montar-app.js';
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

const estado = (id, name, category, extra = {}) => ({
  id,
  code: name.toLowerCase(),
  name,
  category,
  color: null,
  sort_order: 1,
  is_initial: false,
  is_final: false,
  ...extra,
});

const FLUJO = {
  statuses: [
    estado('s1', 'Pendiente', 'new', { is_initial: true, sort_order: 1 }),
    estado('s2', 'Listo', 'ready', { sort_order: 2 }),
    estado('s3', 'Entregado', 'completed', { is_final: true, sort_order: 3 }),
  ],
  transitions: [
    { from: 's1', to: 's2', permission: 'orders.advance_kitchen' },
    { from: 's2', to: 's3', permission: null },
  ],
  problems: [],
  warnings: [],
  categories: ['new', 'kitchen', 'ready', 'in_transit', 'completed', 'cancelled'],
  permissions: [
    { code: 'orders.advance_kitchen', description: 'Avanzar estados de cocina' },
    { code: 'orders.cancel', description: 'Anular pedidos' },
  ],
};

const YO = sesion({ permissions: ['settings.view', 'settings.edit'] });

async function montarEstados(flujo = FLUJO, extra = {}) {
  const montado = await montarApp({
    token: 't',
    hash: '#/admin',
    respuestas: {
      '/auth/me': YO,
      '/settings': AJUSTES,
      '/branches': [{ id: '22222222-2222-4222-8222-222222222222', name: 'Centro', code: 'CEN', timezone: 'America/Bogota' }],
      '/branches/*': { schedules: [], channels_without_windows: [] },
      '/tax-rates': [],
      '/order-statuses': flujo,
      // Guardar, mover el inicial y fijar las salidas cuelgan del id.
      '/order-statuses/*': {},
      ...extra,
    },
  });

  boton('Estados').click();
  await reposar();
  return montado;
}

function boton(texto, raiz = document) {
  return [...raiz.querySelectorAll('button')].find((b) => b.textContent.trim().startsWith(texto));
}

/** La tarjeta de un estado, por el valor de su campo Nombre. */
const tarjeta = (nombre) =>
  [...document.querySelectorAll('#vista .seccion')].find(
    (c) => [...c.querySelectorAll('input')].some((i) => i.value === nombre)
  );

const ultimo = (fetch, metodo) => {
  const llamada = fetch.mock.calls.filter(([, o]) => o?.method === metodo).at(-1);
  return llamada ? { url: String(llamada[0]), cuerpo: JSON.parse(llamada[1].body ?? 'null') } : null;
};

describe('editor de estados', () => {
  it('muestra cada estado con su categoría y marca cuál es el inicial', async () => {
    await montarEstados();

    const pendiente = tarjeta('Pendiente');
    expect(pendiente.querySelector('select').value).toBe('new');
    expect(pendiente.textContent).toContain('Inicial');
    // El que ya es inicial no ofrece volver a serlo.
    expect(boton('Hacer inicial', pendiente)).toBeUndefined();
    expect(boton('Hacer inicial', tarjeta('Listo'))).toBeDefined();
  });

  it('lista las salidas de cada estado con su permiso', async () => {
    await montarEstados();

    const pendiente = tarjeta('Pendiente');
    const casillas = [...pendiente.querySelectorAll('input[type="checkbox"]')];
    // La primera casilla es "Final"; las demás son los destinos posibles.
    const [aListo, aEntregado] = casillas.slice(1);
    expect(aListo.checked).toBe(true);
    expect(aEntregado.checked).toBe(false);

    const permisos = [...pendiente.querySelectorAll('select')].slice(1);
    expect(permisos[0].value).toBe('orders.advance_kitchen');
  });

  it('guarda el estado y sus salidas en dos llamadas separadas', async () => {
    const { fetch } = await montarEstados();

    const pendiente = tarjeta('Pendiente');
    pendiente.querySelectorAll('input')[0].value = 'Recibido';
    // Se marca también la salida a Entregado.
    [...pendiente.querySelectorAll('input[type="checkbox"]')][2].checked = true;
    boton('Guardar', pendiente).click();
    await reposar();

    expect(ultimo(fetch, 'PATCH').cuerpo.name).toBe('Recibido');
    // Aparte, porque la comprobación de las salidas mira el flujo entero:
    // juntas, no se sabría cuál de las dos cosas se rechazó.
    const salidas = ultimo(fetch, 'PUT');
    expect(salidas.url).toContain('/order-statuses/s1/transitions');
    expect(salidas.cuerpo.transitions).toEqual([
      { to_status_id: 's2', required_permission: 'orders.advance_kitchen' },
      { to_status_id: 's3', required_permission: null },
    ]);
  });

  it('muestra el rechazo de dejar un estado sin salida', async () => {
    const { fetch } = await montarEstados(FLUJO, {
      '/order-statuses/*': (url, opciones) =>
        opciones.method === 'PUT'
          ? new Respuesta(422, {
              detail: "El estado 'pending' no tendria ninguna salida: un pedido que llegue ahi se queda atascado.",
            })
          : {},
    });

    const pendiente = tarjeta('Pendiente');
    [...pendiente.querySelectorAll('input[type="checkbox"]')].slice(1).forEach((c) => (c.checked = false));
    const guardar = boton('Guardar', pendiente);
    guardar.click();
    await reposar();

    expect(document.body.textContent).toContain('se queda atascado');
    expect(guardar.disabled).toBe(false);
    expect(ultimo(fetch, 'PUT')).not.toBeNull();
  });

  it('muestra los avisos que calcula el dominio, sin inventarlos', async () => {
    await montarEstados({
      ...FLUJO,
      problems: [],
      warnings: [
        'No hay ningun estado de categoria "anulado": no se va a poder anular un pedido.',
        "A 'huerfano' no se llega desde el estado inicial: ningun pedido va a pasar por ahi.",
      ],
    });

    expect(document.body.textContent).toContain('no se va a poder anular');
    expect(document.body.textContent).toContain("A 'huerfano' no se llega");
  });

  it('no muestra avisos cuando el flujo está sano', async () => {
    await montarEstados();

    expect(document.body.textContent).not.toContain('no se va a poder anular');
    expect(document.body.textContent).not.toContain('El flujo está roto');
  });

  /**
   * Una configuración rota se sigue pudiendo editar —es la única forma de
   * arreglarla— así que hay que verla, y en rojo: no es lo mismo que un aviso.
   */
  it('muestra en rojo los problemas que impiden operar, y deja editar igual', async () => {
    await montarEstados({
      ...FLUJO,
      problems: ["El estado 'ready' no tiene ninguna salida: un pedido que llegue ahi se queda atascado."],
    });

    expect(document.body.textContent).toContain('El flujo está roto');
    expect(document.body.textContent).toContain("'ready' no tiene ninguna salida");
    expect(boton('Guardar', tarjeta('Pendiente'))).toBeDefined();
  });

  it('mueve el estado inicial', async () => {
    const { fetch } = await montarEstados();

    boton('Hacer inicial', tarjeta('Listo')).click();
    await reposar();

    expect(ultimo(fetch, 'PUT').url).toContain('/order-statuses/s2/initial');
  });

  it('dice que un estado final no usa sus salidas', async () => {
    await montarEstados();

    expect(tarjeta('Entregado').textContent).toContain('de aquí no se sale');
    expect(tarjeta('Pendiente').textContent).not.toContain('de aquí no se sale');
  });

  it('quien solo puede ver no edita nada', async () => {
    await montarApp({
      token: 't',
      hash: '#/admin',
      respuestas: {
        '/auth/me': sesion({ permissions: ['settings.view'] }),
        '/settings': AJUSTES,
        '/branches': [{ id: '22222222-2222-4222-8222-222222222222', name: 'Centro', code: 'CEN', timezone: 'America/Bogota' }],
        '/branches/*': { schedules: [], channels_without_windows: [] },
        '/tax-rates': [],
        '/order-statuses': FLUJO,
        '/order-statuses/*': {},
      },
    });
    boton('Estados').click();
    await reposar();

    expect(boton('Guardar')).toBeUndefined();
    expect(boton('Crear estado')).toBeUndefined();
    expect(boton('Hacer inicial')).toBeUndefined();
    expect(tarjeta('Pendiente').querySelector('input').disabled).toBe(true);
  });
});
