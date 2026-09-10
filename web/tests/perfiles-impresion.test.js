// Perfiles de impresión (F8.2).
//
// El ancho estaba fijo en el CSS y las copias eran siempre una. Lo que se
// protege aquí es que imprimir no dependa de que la configuración esté
// disponible: con el cliente enfrente, un ticket con el ancho por defecto es
// mejor que ninguno.

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { montarApp, reposar, Respuesta } from './montar-app.js';
import { sesion } from './sesion.js';

const PEDIDO = {
  id: 'o1',
  order_number: 'CEN-00033',
  channel: 'counter',
  table_code: null,
  created_at: new Date().toISOString(),
  status: { id: 's1', code: 'x', name: 'Recibido', category: 'kitchen', color: null },
  items: [
    { id: 'l1', name_snapshot: 'Plato', quantity: 1, notes: null, modifiers: [], components: [], station_id: null, station_name: null },
  ],
  kitchen_tickets: [],
  next_statuses: [],
};

const perfiles = (comanda) => [
  { document: 'comanda', ...comanda },
  { document: 'ticket', width_mm: 80, content_width_mm: 72, copies: 1 },
  { document: 'precuenta', width_mm: 80, content_width_mm: 72, copies: 1 },
];

const montarCocina = (respuestaPerfiles) =>
  montarApp({
    token: 'un-token',
    hash: '#/cocina',
    respuestas: {
      '/auth/me': sesion({ permissions: ['orders.view'] }),
      '/kitchen/orders': { columns: ['kitchen'], orders: [PEDIDO], dispatched: [], stations: [] },
      '/print-profiles': respuestaPerfiles,
    },
  });

const reimprimir = async () => {
  [...document.querySelectorAll('#vista button')].find((b) => b.textContent.trim() === 'Reimprimir').click();
  await reposar(4);
};

const impresion = () => document.getElementById('impresion');

beforeEach(() => {
  vi.stubGlobal('print', vi.fn());
});

describe('perfiles de impresión', () => {
  // La primera impresión de la sesión sale con los valores por defecto en
  // vez de esperar a la red; la siguiente ya usa los del restaurante.
  it('la segunda impresión ya usa el ancho configurado', async () => {
    await montarCocina(perfiles({ width_mm: 58, content_width_mm: 48, copies: 1 }));

    await reimprimir();
    await reposar(3);
    await reimprimir();

    expect(impresion().style.getPropertyValue('--doc-ancho')).toBe('48mm');
  });

  it('las copias salen como hojas separadas', async () => {
    await montarCocina(perfiles({ width_mm: 80, content_width_mm: 72, copies: 3 }));

    await reimprimir();
    await reposar(3);
    await reimprimir();

    expect(impresion().querySelectorAll('.doc').length).toBe(3);
  });

  // Imprimir no puede depender de una configuración opcional.
  it('si los perfiles fallan se imprime igual, con lo de siempre', async () => {
    await montarCocina(() => new Respuesta(500, { detail: 'boom' }));

    await reimprimir();
    await reposar(3);
    await reimprimir();

    expect(impresion().querySelectorAll('.doc').length).toBe(1);
    expect(impresion().textContent).toContain('Plato');
  });
});
