// Estaciones de preparación (F8.1).
//
// Lo que se protege aquí es que la pantalla no reparta por su cuenta. Quién
// va a qué estación lo decide `Domain\StationRouting` y llega repartido en
// `kitchen_tickets`: si la comanda impresa y el tablero agruparan cada uno
// por su lado, tarde o temprano dirían cosas distintas.

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const linea = (id, nombre, estacion, nombreEstacion) => ({
  id,
  category_id: 'c1',
  name_snapshot: nombre,
  quantity: 1,
  notes: null,
  modifiers: [],
  components: [],
  station_id: estacion,
  station_name: nombreEstacion,
});

const PEDIDO = {
  id: 'o1',
  order_number: 'CEN-00021',
  channel: 'counter',
  table_code: null,
  created_at: new Date().toISOString(),
  status: { id: 's1', code: 'x', name: 'Recibido', category: 'kitchen', color: null },
  items: [linea('l1', 'Cerveza', 'e1', 'Barra'), linea('l2', 'Churrasco', 'e2', 'Plancha')],
  kitchen_tickets: [
    { station_id: 'e1', station_name: 'Barra', lines: [linea('l1', 'Cerveza', 'e1', 'Barra')] },
    { station_id: 'e2', station_name: 'Plancha', lines: [linea('l2', 'Churrasco', 'e2', 'Plancha')] },
  ],
  next_statuses: [],
};

const tablero = (extra = {}) => ({
  columns: ['new', 'kitchen', 'ready'],
  orders: [PEDIDO],
  dispatched: [],
  stations: [
    { id: 'e1', name: 'Barra', sort_order: 0, is_active: true },
    { id: 'e2', name: 'Plancha', sort_order: 1, is_active: true },
  ],
  ...extra,
});

const montarCocina = (respuestaKds = tablero()) =>
  montarApp({
    token: 'un-token',
    hash: '#/cocina',
    respuestas: {
      '/auth/me': sesion({ permissions: ['orders.view'] }),
      '/kitchen/orders': respuestaKds,
    },
  });

const texto = () => document.getElementById('vista').textContent;
const chip = (nombre) =>
  [...document.querySelectorAll('#vista button')].find((b) => b.textContent.trim() === nombre);

beforeEach(() => {
  localStorage.clear();
});

describe('estaciones en el tablero de cocina', () => {
  it('ofrece un filtro por estación, más "Todo"', async () => {
    await montarCocina();

    expect(chip('Todo')).toBeDefined();
    expect(chip('Barra')).toBeDefined();
    expect(chip('Plancha')).toBeDefined();
  });

  it('un restaurante sin estaciones no ve ningún filtro', async () => {
    await montarCocina(tablero({ stations: [] }));

    expect(chip('Todo')).toBeUndefined();
    expect(texto()).toContain('Cerveza');
    expect(texto()).toContain('Churrasco');
  });

  it('al elegir una estación solo quedan sus líneas', async () => {
    await montarCocina();

    chip('Barra').click();
    await reposar(4);

    expect(texto()).toContain('Cerveza');
    expect(texto()).not.toContain('Churrasco');
  });

  // La tableta de la barra es siempre la barra: se recuerda por dispositivo,
  // como el interruptor del aviso, para no tener que elegir cada turno.
  it('la estación elegida se recuerda en el dispositivo', async () => {
    await montarCocina();

    chip('Plancha').click();
    await reposar(4);

    expect(localStorage.getItem('resto_estacion_cocina')).toBe('e2');
    expect(chip('Plancha').getAttribute('aria-pressed')).toBe('true');

    chip('Todo').click();
    await reposar(4);
    expect(localStorage.getItem('resto_estacion_cocina')).toBeNull();
  });

  // Una estación borrada mientras la pantalla está abierta dejaría el
  // tablero vacío para siempre si el filtro no se soltara solo.
  it('si la estación deja de existir se vuelve a Todo', async () => {
    let borrada = false;
    await montarCocina(() =>
      borrada
        ? tablero({ stations: [{ id: 'e2', name: 'Plancha', sort_order: 0, is_active: true }] })
        : tablero()
    );

    chip('Barra').click();
    await reposar(4);
    expect(texto()).not.toContain('Churrasco');

    // Alguien borra la Barra desde Administración; el tablero se refresca.
    borrada = true;
    chip('Barra').click();
    await reposar(4);

    expect(chip('Todo').getAttribute('aria-pressed')).toBe('true');
    expect(texto()).toContain('Churrasco');
    expect(texto()).toContain('Cerveza');
  });
});

describe('la comanda se parte por estación', () => {
  beforeEach(() => {
    vi.stubGlobal('print', vi.fn());
  });

  it('imprime una hoja por estación, con su nombre', async () => {
    await montarCocina();

    // Desde la tarjeta del tablero, que es donde se reimprime en cocina.
    [...document.querySelectorAll('#vista button')]
      .find((b) => b.textContent.trim() === 'Reimprimir')
      .click();
    await reposar(3);

    const documento = document.getElementById('impresion');
    expect(documento.querySelectorAll('.doc').length).toBe(2);
    expect(documento.textContent).toContain('BARRA');
    expect(documento.textContent).toContain('PLANCHA');
  });
});
