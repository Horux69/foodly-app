// La pestaña de estaciones en la pantalla de menú (F8.1).
//
// Va en su propio archivo y no junto a las del tablero: el KDS deja un
// intervalo de refresco vivo, y montar otra pantalla después en el mismo
// jsdom lo deja pidiendo un endpoint que ya nadie responde.

import { describe, expect, it } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const texto = () => document.getElementById('vista').textContent;

describe('la pestaña de estaciones en el menú', () => {
  const CATALOGO = {
    branch_id: '22222222-2222-4222-8222-222222222222',
    categories: [
      { id: 'c1', name: 'Bebidas', sort_order: 0, is_active: true, station_id: null },
      { id: 'c2', name: 'Carnes', sort_order: 1, is_active: true, station_id: 'e2' },
    ],
    items: [],
  };

  const montarMenu = (estaciones) =>
    montarApp({
      token: 'un-token',
      hash: '#/menu',
      respuestas: {
        '/auth/me': sesion({ permissions: ['menu.view', 'menu.edit'] }),
        '/menu/catalog': CATALOGO,
        '/menu/modifier-groups': [],
        '/stations': estaciones,
      },
    });

  const abrirPestana = async () => {
    [...document.querySelectorAll('#vista button')].find((b) => b.textContent.trim() === 'Estaciones').click();
    await reposar(2);
  };

  it('se puede abrir aunque el restaurante no tenga ninguna', async () => {
    await montarMenu([]);
    await abrirPestana();

    expect(texto()).toContain('Sin estaciones, la comanda sale entera');
  });

  it('con estaciones, cada categoría dice a cuál manda', async () => {
    await montarMenu([
      { id: 'e1', name: 'Barra', sort_order: 0, is_active: true },
      { id: 'e2', name: 'Plancha', sort_order: 1, is_active: true },
    ]);
    await abrirPestana();

    const selector = document.querySelector('#vista [aria-label="Estación de Carnes"]');
    expect(selector.value).toBe('e2');
    // Lo que no se asignó sale en la comanda general: nada se pierde.
    expect(document.querySelector('#vista [aria-label="Estación de Bebidas"]').value).toBe('');
  });

  it('cambiar la estación de una categoría la manda al servidor', async () => {
    const { fetch } = await montarMenu([{ id: 'e1', name: 'Barra', sort_order: 0, is_active: true }]);
    await abrirPestana();

    const selector = document.querySelector('#vista [aria-label="Estación de Bebidas"]');
    selector.value = 'e1';
    selector.dispatchEvent(new Event('change'));
    await reposar(3);

    const put = fetch.mock.calls.filter(([, o]) => o?.method === 'PUT').at(-1);
    expect(String(put[0])).toBe('/api/v1/menu/categories/c1/station');
    expect(JSON.parse(put[1].body)).toEqual({ station_id: 'e1' });
  });
});
