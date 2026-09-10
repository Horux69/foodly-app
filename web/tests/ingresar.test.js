// La pantalla de ingreso, sin sesión.
//
// Esta es la prueba que faltaba: el login estuvo roto desde `c697b9e` hasta
// `99e54ea` por un `[rail, topbar, barraInferior].forEach(render)` —forEach
// pasa (elemento, índice, array) y `render(el, ...children)` tomaba el resto
// como hijos, así que intentaba meter el rail dentro de sí mismo. Nadie lo
// vio porque esa rama solo corre con la sesión cerrada, y en desarrollo
// siempre había token en localStorage.

import { beforeEach, describe, expect, it } from 'vitest';
import { montarApp } from './montar-app.js';

describe('sin token en localStorage', () => {
  beforeEach(async () => {
    await montarApp();
  });

  it('aterriza en la pantalla de ingreso', () => {
    expect(window.location.hash).toBe('#/ingresar');
  });

  it('pinta el formulario de ingreso', () => {
    const vista = document.getElementById('vista');
    expect(vista.querySelector('form')).not.toBeNull();
    expect(vista.querySelector('input[type="email"]')).not.toBeNull();
    expect(vista.querySelector('input[type="password"]')).not.toBeNull();
    expect(vista.textContent).toContain('Bienvenido');
  });

  it('no pide nada a la API antes de que alguien escriba sus datos', async () => {
    const { fetch } = await montarApp();
    expect(fetch).not.toHaveBeenCalled();
  });

  // La regresión concreta: la estructura común se arma solo con sesión, y
  // armarla sin ella era lo que reventaba antes de llegar a pintar el
  // formulario.
  it('deja vacía la estructura de la aplicación y esconde el rail', () => {
    expect(document.getElementById('rail').childElementCount).toBe(0);
    expect(document.getElementById('topbar').childElementCount).toBe(0);
    expect(document.getElementById('barra-inferior').childElementCount).toBe(0);
    expect(document.getElementById('rail').classList.contains('hidden')).toBe(true);
  });
});

describe('con una sesión abierta', () => {
  const sesion = {
    user_id: '11111111-1111-4111-8111-111111111111',
    name: 'Ana Cajera',
    email: 'ana@demo.local',
    role: 'admin',
    permissions: ['orders.view'],
    branch_id: '22222222-2222-4222-8222-222222222222',
    branch_name: 'Centro',
    tenant_name: 'Restaurante Demo',
    currency: 'COP',
    channels: ['counter'],
    uses_tables: false,
    asks_tip: false,
    branches: [
      { id: '22222222-2222-4222-8222-222222222222', name: 'Centro', code: 'CEN' },
      { id: '33333333-3333-4333-8333-333333333333', name: 'Norte', code: 'NTE' },
    ],
  };

  it('arma la estructura y ofrece elegir sucursal cuando hay más de una', async () => {
    await montarApp({
      token: 'un-token',
      hash: '#/cocina',
      respuestas: { '/auth/me': sesion, '/kitchen/orders': { columns: ['new', 'kitchen', 'ready'], orders: [], dispatched: [] } },
    });

    const rail = document.getElementById('rail');
    expect(rail.textContent).toContain('Restaurante Demo');

    const selector = rail.querySelector('select[aria-label="Sucursal activa"]');
    expect(selector).not.toBeNull();
    expect([...selector.options].map((o) => o.textContent)).toEqual(['Centro', 'Norte']);
    expect(selector.value).toBe(sesion.branch_id);
  });

  it('manda la sucursal activa en las peticiones que dependen de ella', async () => {
    const { fetch } = await montarApp({
      token: 'un-token',
      hash: '#/cocina',
      respuestas: { '/auth/me': sesion, '/kitchen/orders': { columns: ['new', 'kitchen', 'ready'], orders: [], dispatched: [] } },
    });

    const tablero = fetch.mock.calls.map(([url]) => String(url)).find((url) => url.includes('/kitchen/orders'));
    expect(tablero).toContain(`branch_id=${sesion.branch_id}`);
  });
});
