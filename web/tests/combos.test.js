// Combos: un producto que lleva otros dentro, a precio de paquete.
//
// Lo que se protege aquí es lo que se ve en la pantalla del menú: que un
// combo se distinga de un producto suelto sin abrir nada, que el diálogo
// mande la lista entera —y no un parche por componente—, y que vaciarla sea
// la forma de devolverlo a producto suelto. La aritmética y los ciclos los
// decide `Domain\ComboRules`, no el navegador.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const producto = (id, name, cambios = {}) => ({
  id,
  category_id: 'c1',
  name,
  description: null,
  base_price: '10000.00',
  tax_rate_id: null,
  prep_minutes: null,
  is_available: true,
  is_archived: false,
  sort_order: 0,
  branch_override: null,
  modifier_group_ids: [],
  components: [],
  ...cambios,
});

const catalogo = (items) => ({
  branch_id: '22222222-2222-4222-8222-222222222222',
  categories: [{ id: 'c1', name: 'Carta', sort_order: 0, is_active: true }],
  items,
});

const HAMBURGUESA = producto('i1', 'Hamburguesa');
const PAPAS = producto('i2', 'Papas');
const COMBO = producto('i3', 'Combo del día', {
  base_price: '28000.00',
  components: [
    { item_id: 'i1', name: 'Hamburguesa', quantity: 1 },
    { item_id: 'i2', name: 'Papas', quantity: 2 },
  ],
});

async function montarMenu(items) {
  return montarApp({
    token: 't',
    hash: '#/menu',
    respuestas: {
      '/auth/me': sesion({ permissions: ['menu.view', 'menu.edit'] }),
      '/menu/catalog': catalogo(items),
      '/menu/modifier-groups': [],
      '/stations': [],
    },
  });
}

const boton = (texto, raiz = document) =>
  [...raiz.querySelectorAll('button')].find((b) => b.textContent.trim().startsWith(texto));

const dialogo = () => document.querySelector('[role="dialog"]');

const casilla = (nombre) =>
  [...dialogo().querySelectorAll('label')]
    .find((l) => l.textContent.trim().startsWith(nombre))
    ?.querySelector('input[type="checkbox"]');

const cantidadDe = (nombre) => dialogo().querySelector(`input[aria-label="Cantidad de ${nombre}"]`);

const ultimoPut = (fetch) => {
  const llamada = fetch.mock.calls.filter(([, o]) => o?.method === 'PUT').at(-1);
  return llamada ? { url: String(llamada[0]), cuerpo: JSON.parse(llamada[1].body ?? 'null') } : null;
};

describe('combos en el menú', () => {
  it('un combo se distingue en la lista y dice qué lleva', async () => {
    await montarMenu([HAMBURGUESA, PAPAS, COMBO]);

    expect(document.body.textContent).toContain('Combo');
    expect(document.body.textContent).toContain('Lleva: 1× Hamburguesa, 2× Papas');
  });

  it('el diálogo llega con lo que el combo ya lleva y sus cantidades', async () => {
    await montarMenu([HAMBURGUESA, PAPAS, COMBO]);

    boton('Combo · 2').click();
    await reposar();

    expect(casilla('Hamburguesa').checked).toBe(true);
    expect(casilla('Papas').checked).toBe(true);
    expect(cantidadDe('Papas').value).toBe('2');
    // Un combo no se lleva a sí mismo: no aparece entre los candidatos.
    expect(casilla('Combo del día')).toBeUndefined();
  });

  it('guarda la composición entera, no un parche por componente', async () => {
    const { fetch } = await montarMenu([HAMBURGUESA, PAPAS, COMBO]);

    boton('Combo · 2').click();
    await reposar();

    casilla('Papas').checked = false;
    cantidadDe('Hamburguesa').value = '3';
    boton('Guardar', dialogo()).click();
    await reposar();

    expect(ultimoPut(fetch)).toEqual({
      url: '/api/v1/menu/items/i3/components',
      cuerpo: { components: [{ item_id: 'i1', quantity: 3 }] },
    });
  });

  it('desmarcar todo lo devuelve a producto suelto', async () => {
    const { fetch } = await montarMenu([HAMBURGUESA, PAPAS, COMBO]);

    boton('Combo · 2').click();
    await reposar();

    casilla('Hamburguesa').checked = false;
    casilla('Papas').checked = false;
    boton('Guardar', dialogo()).click();
    await reposar();

    expect(ultimoPut(fetch).cuerpo).toEqual({ components: [] });
  });

  it('sin otro producto que ofrecer no abre el diálogo', async () => {
    await montarMenu([producto('i9', 'Único')]);

    boton('Combo').click();
    await reposar();

    expect(dialogo()).toBeNull();
    expect(document.body.textContent).toContain('al menos otro producto');
  });
});
