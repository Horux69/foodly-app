// Grupos de modificadores desde la web.
//
// Es lo que antes solo se podía poblar entrando a la base: un restaurante que
// quisiera "término de la carne" necesitaba a alguien con acceso a Postgres.
//
// Lo que se protege aquí no es el CRUD, que es aburrido, sino las dos formas
// en que un grupo mal configurado se paga en el mostrador: un grupo
// obligatorio que pide un mínimo de 0 —que el backend rechaza— y uno
// obligatorio sin ninguna opción que ofrecer, que vuelve impedible cualquier
// producto que lo tenga.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar, Respuesta } from './montar-app.js';
import { sesion } from './sesion.js';

const CATALOGO = {
  branch_id: '22222222-2222-4222-8222-222222222222',
  categories: [{ id: 'c1', name: 'Hamburguesas', sort_order: 0, is_active: true }],
  items: [
    {
      id: 'i1',
      category_id: 'c1',
      name: 'Hamburguesa clásica',
      description: null,
      base_price: '18000.00',
      tax_rate_id: null,
      prep_minutes: null,
      is_available: true,
      is_archived: false,
      sort_order: 0,
      branch_override: null,
      modifier_group_ids: [],
    },
  ],
};

const grupo = (cambios = {}) => ({
  id: 'g1',
  name: 'Término de la carne',
  min_select: 1,
  max_select: 1,
  is_required: true,
  rule: 'Elige 1',
  used_by_items: 0,
  modifiers: [],
  ...cambios,
});

const CON_OPCION = grupo({
  modifiers: [{ id: 'm1', group_id: 'g1', name: 'Término medio', price_delta: '0.00', is_available: true }],
});

async function montarMenu(grupos = [], extra = {}) {
  return montarApp({
    token: 't',
    hash: '#/menu',
    respuestas: {
      '/auth/me': sesion({ permissions: ['menu.view', 'menu.edit'] }),
      '/menu/catalog': CATALOGO,
      '/menu/modifier-groups': grupos,
      ...extra,
    },
  });
}

const boton = (texto, raiz = document) =>
  [...raiz.querySelectorAll('button')].find((b) => b.textContent.trim().startsWith(texto));

const campo = (etiqueta, raiz = document) =>
  [...raiz.querySelectorAll('label')]
    .find((l) => l.textContent.trim().startsWith(etiqueta))
    ?.querySelector('input, select');

const abrirOpciones = async () => {
  boton('Opciones').click();
  await reposar();
};

/** El cuerpo de la última llamada con ese método. */
const ultimo = (fetch, metodo) => {
  const llamada = fetch.mock.calls.filter(([, o]) => o?.method === metodo).at(-1);
  return llamada ? { url: String(llamada[0]), cuerpo: JSON.parse(llamada[1].body ?? 'null') } : null;
};

describe('grupos de opciones', () => {
  it('vive en su propia pestaña, junto a los productos', async () => {
    await montarMenu();
    expect(boton('Productos')).toBeDefined();
    expect(boton('Opciones')).toBeDefined();

    await abrirOpciones();
    expect(document.body.textContent).toContain('Todavía no hay grupos de opciones');
  });

  it('marcar obligatorio sube el mínimo a 1', async () => {
    const { fetch } = await montarMenu();
    await abrirOpciones();

    campo('Nombre').value = 'Tamaño';
    // Obligatorio con mínimo 0 se contradice —ModifierValidation ya exige una
    // selección— y el backend lo rechaza. La casilla lo resuelve sola en vez
    // de dejar que el error salga después.
    expect(campo('Mínimo').value).toBe('0');
    campo('Obligatorio').click();
    expect(campo('Mínimo').value).toBe('1');

    boton('Crear grupo').click();
    await reposar();

    expect(ultimo(fetch, 'POST').cuerpo).toEqual({
      name: 'Tamaño',
      min_select: 1,
      max_select: 1,
      is_required: true,
    });
  });

  it('avisa cuando un grupo obligatorio no tiene nada que ofrecer', async () => {
    await montarMenu([grupo()]);
    await abrirOpciones();

    // Mientras siga así, ningún pedido con un producto de ese grupo se puede
    // crear, y el error saldría en el mostrador y no aquí.
    expect(document.body.textContent).toContain('no se pueden pedir');
  });

  it('no avisa cuando sí hay una opción que se ofrece', async () => {
    await montarMenu([CON_OPCION]);
    await abrirOpciones();

    expect(document.body.textContent).not.toContain('no se pueden pedir');
    // El nombre de la opción es un campo editable, así que se busca su valor
    // y no el texto de la página.
    const nombres = [...document.querySelectorAll('#vista input')].map((i) => i.value);
    expect(nombres).toContain('Término medio');
  });

  it('avisa igual si la única opción está marcada como no disponible', async () => {
    const agotada = grupo({
      modifiers: [{ id: 'm1', group_id: 'g1', name: 'Término medio', price_delta: '0.00', is_available: false }],
    });
    await montarMenu([agotada]);
    await abrirOpciones();

    expect(document.body.textContent).toContain('no se pueden pedir');
  });

  it('muestra el mensaje del backend cuando el grupo está en uso', async () => {
    await montarMenu([grupo({ used_by_items: 3 })], {
      '/menu/modifier-groups/g1': new Respuesta(422, {
        detail: 'El grupo lo usan 3 producto(s): quitaselo primero desde cada uno',
      }),
    });
    await abrirOpciones();

    expect(document.body.textContent).toContain('Lo usan 3 productos');
    boton('Borrar grupo').click();
    await reposar();

    expect(document.body.textContent).toContain('quitaselo primero');
  });
});

describe('asignar grupos a un producto', () => {
  it('manda los marcados, en el orden en que se ven', async () => {
    const segundo = grupo({ id: 'g2', name: 'Adiciones', min_select: 0, max_select: 3, is_required: false, rule: 'Opcional, hasta 3' });
    const { fetch } = await montarMenu([CON_OPCION, segundo], {
      '/menu/items/i1/modifier-groups': { item_id: 'i1', group_ids: ['g1', 'g2'] },
    });

    boton('Opciones ·').click();
    await reposar();

    const dialogo = document.querySelector('[role="dialog"]');
    expect(dialogo.textContent).toContain('Hamburguesa clásica');
    dialogo.querySelectorAll('input[type="checkbox"]').forEach((c) => (c.checked = true));

    boton('Guardar', dialogo).click();
    await reposar();

    const enviado = ultimo(fetch, 'PUT');
    expect(enviado.url).toContain('/menu/items/i1/modifier-groups');
    // El orden importa: es el orden en que se van a pedir, y el backend lo
    // guarda en `sort_order`.
    expect(enviado.cuerpo).toEqual({ group_ids: ['g1', 'g2'] });
  });

  it('no abre el diálogo si todavía no hay grupos', async () => {
    await montarMenu([]);

    boton('Opciones ·').click();
    await reposar();

    expect(document.querySelector('[role="dialog"]')).toBeNull();
    expect(document.body.textContent).toContain('Todavía no hay grupos de opciones');
  });
});
