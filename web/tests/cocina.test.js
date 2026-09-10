// El tablero de cocina.
//
// Tres cosas que no deben aflojarse: que las columnas las decida la
// configuración del restaurante y no esta pantalla, que un pedido nuevo se
// anuncie —el tablero se repinta cada quince segundos y en una cocina nadie
// está mirando—, y que marcar una línea como preparada sobreviva al
// repintado, que ocurre cada quince segundos.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const pedido = (numero, categoria, items = [{ id: `${numero}-1`, name_snapshot: 'Pizza', quantity: 1, notes: null, modifiers: [] }]) => ({
  id: `id-${numero}`,
  order_number: numero,
  channel: 'delivery',
  table_code: null,
  created_at: new Date().toISOString(),
  status: { id: 's1', code: 'x', name: 'Nombre inventado', category: categoria, color: null },
  items,
  next_statuses: [],
});

const tablero = ({ columns = ['new', 'kitchen', 'ready'], orders = [], dispatched = [] } = {}) => ({
  columns,
  orders,
  dispatched,
});

const montarCocina = (respuestaKds, extra = {}) =>
  montarApp({
    token: 'un-token',
    hash: '#/cocina',
    respuestas: {
      '/auth/me': sesion({ permissions: ['orders.view', 'orders.advance_kitchen'] }),
      '/kitchen/orders': respuestaKds,
      ...extra,
    },
  });

const texto = () => document.getElementById('vista').textContent;

/**
 * Monta el tablero con el reloj bajo control, para poder provocar el
 * refresco de los quince segundos sin esperarlos.
 *
 * `shouldAdvanceTime` deja que el tiempo real siga corriendo por debajo: sin
 * eso, los `await` de la propia carga nunca se resolverían. Y los
 * temporizadores falsos se instalan **antes** de montar, porque un intervalo
 * creado con el reloj de verdad no lo controla el falso.
 */
async function montarConReloj(respuestaKds) {
  vi.useFakeTimers({ shouldAdvanceTime: true });
  const montado = await montarCocina(respuestaKds);
  await reposar(3);
  return montado;
}

/** Provoca el siguiente refresco automático del tablero. */
async function pasarUnRefresco() {
  await vi.advanceTimersByTimeAsync(16000);
  await reposar(3);
}

beforeEach(() => {
  localStorage.clear();
});

afterEach(() => {
  // Volver al reloj real corta los tableros que quedaron vivos de una prueba
  // anterior: con el falso auto-avanzando seguirían refrescando y tocando el
  // título, que es global a todo el documento.
  vi.useRealTimers();
  document.title = '';
});

describe('las columnas las decide el restaurante', () => {
  it('un restaurante sin domicilios no ve la columna de en camino', async () => {
    await montarCocina(tablero({ orders: [pedido('A-1', 'new')] }));
    await reposar(3);

    expect(texto()).toContain('Por preparar');
    expect(texto()).not.toContain('En camino');
  });

  it('uno que reparte sí la ve', async () => {
    await montarCocina(
      tablero({ columns: ['new', 'kitchen', 'ready', 'in_transit'], orders: [pedido('A-1', 'in_transit')] })
    );
    await reposar(3);

    expect(texto()).toContain('En camino');
    // Y el pedido cae en ella aunque su estado se llame de otra forma.
    expect(texto()).toContain('A-1');
  });
});

describe('lo despachado hace poco', () => {
  it('se puede desplegar para recuperar lo que salió por error', async () => {
    await montarCocina(tablero({ dispatched: [pedido('A-9', 'completed')] }));
    await reposar(3);

    expect(texto()).toContain('Despachados hace poco (1)');
    expect(texto()).toContain('A-9');
  });

  it('no ocupa sitio si no hay nada', async () => {
    await montarCocina(tablero({ orders: [pedido('A-1', 'new')] }));
    await reposar(3);
    expect(texto()).not.toContain('Despachados');
  });
});

describe('un combo en el tablero', () => {
  it('llega con los platos que lo componen, no solo con su nombre', async () => {
    await montarCocina(
      tablero({
        orders: [
          pedido('A-1', 'kitchen', [
            {
              id: 'li-1',
              name_snapshot: 'Combo del día',
              quantity: 2,
              notes: null,
              modifiers: [],
              // Vienen ya multiplicados por la cantidad: dos combos son dos
              // hamburguesas y cuatro papas.
              components: [
                { name_snapshot: 'Hamburguesa', quantity: 2 },
                { name_snapshot: 'Papas', quantity: 4 },
              ],
            },
          ]),
        ],
      })
    );

    expect(texto()).toContain('Combo del día');
    expect(texto()).toContain('2× Hamburguesa · 4× Papas');
  });
});

describe('marcar una línea como preparada', () => {
  const conDosLineas = tablero({
    orders: [
      pedido('A-1', 'kitchen', [
        { id: 'li-1', name_snapshot: 'Pizza', quantity: 1, notes: null, modifiers: [] },
        { id: 'li-2', name_snapshot: 'Gaseosa', quantity: 2, notes: null, modifiers: [] },
      ]),
    ],
  });

  const lineas = () => [...document.querySelectorAll('#vista li button')];

  it('marca solo la que se tocó', async () => {
    await montarCocina(conDosLineas);
    await reposar(3);

    lineas()[0].click();
    await reposar();

    expect(lineas()[0].getAttribute('aria-pressed')).toBe('true');
    expect(lineas()[1].getAttribute('aria-pressed')).toBe('false');
  });

  /**
   * El tablero se repinta cada quince segundos: una marca que no sobreviviera
   * al repintado no serviría de nada.
   */
  it('la marca sobrevive al refresco', async () => {
    await montarConReloj(conDosLineas);
    lineas()[0].click();
    await reposar();

    await pasarUnRefresco();

    expect(lineas()[0].getAttribute('aria-pressed')).toBe('true');
  });

  it('se olvida cuando el pedido deja el tablero', async () => {
    let vuelta = 0;
    await montarConReloj(() => (vuelta++ === 0 ? conDosLineas : tablero()));
    lineas()[0].click();
    await reposar();
    expect(JSON.parse(localStorage.getItem('resto_lineas_listas'))).toEqual(['li-1']);

    await pasarUnRefresco();

    // Si no se podaran, el almacenamiento crecería con ids de ayer.
    expect(JSON.parse(localStorage.getItem('resto_lineas_listas'))).toEqual([]);
  });
});

describe('aviso de pedido nuevo', () => {
  const original = document.title;

  it('no anuncia como nuevos los que ya estaban al abrir', async () => {
    await montarCocina(tablero({ orders: [pedido('A-1', 'new'), pedido('A-2', 'new')] }));
    await reposar(3);
    expect(document.title).toBe(original);
  });

  it('cuenta en el título los que entran después', async () => {
    let vuelta = 0;
    await montarConReloj(() =>
      vuelta++ === 0
        ? tablero({ orders: [pedido('A-1', 'new')] })
        : tablero({ orders: [pedido('A-1', 'new'), pedido('A-2', 'new'), pedido('A-3', 'new')] })
    );

    await pasarUnRefresco();

    expect(document.title).toContain('(2)');
  });

  it('el interruptor se recuerda en el dispositivo', async () => {
    await montarCocina(tablero());
    await reposar(3);

    const boton = [...document.querySelectorAll('#vista button')].find((b) => /Avisa al entrar|Sin aviso/.test(b.textContent));
    expect(boton.textContent).toContain('Avisa al entrar');

    boton.click();
    await reposar();

    expect(boton.textContent).toContain('Sin aviso');
    expect(localStorage.getItem('resto_aviso_cocina')).toBe('no');
  });

  /** Volver a la pestaña es enterarse: el contador ya cumplió su función. */
  it('el contador se limpia al volver a la pestaña', async () => {
    let vuelta = 0;
    await montarConReloj(() =>
      vuelta++ === 0 ? tablero({ orders: [] }) : tablero({ orders: [pedido('A-2', 'new')] })
    );

    await pasarUnRefresco();
    expect(document.title).toMatch(/^\(\d+\)/);

    window.dispatchEvent(new Event('focus'));
    expect(document.title).toBe(original);
  });
});

// =========================================================
// Avanzar un pedido desde cocina
// =========================================================
//
// Es la mitad de lo que hace la pantalla y no estaba probada. Los botones no
// los inventa el tablero: son los que declara la máquina de estados del
// restaurante, así que un tenant sin camino de vuelta no tiene ninguno — y
// eso es correcto.

const EN_COCINA = { id: 's2', code: 'preparing', name: 'En preparación', category: 'kitchen', color: null };
const LISTO = { id: 's3', code: 'ready', name: 'Listo', category: 'ready', color: null };
const ANULADO = { id: 's9', code: 'cancelled', name: 'Cancelado', category: 'cancelled', color: null };

const conSalidas = (numero, categoria, salidas) => ({ ...pedido(numero, categoria), next_statuses: salidas });

const textoDeLosBotones = () =>
  [...document.querySelectorAll('#vista button')].map((b) => b.textContent.trim());

describe('avanzar el estado desde cocina', () => {
  it('ofrece solo los estados que declara la máquina del restaurante', async () => {
    await montarCocina(tablero({ orders: [conSalidas('A-1', 'new', [EN_COCINA])] }));
    await reposar(3);

    const botones = textoDeLosBotones();
    expect(botones).toContain('En preparación');
    // 'Listo' existe como estado pero no como salida de este: no aparece.
    expect(botones).not.toContain('Listo');
  });

  it('no ofrece ninguno si el restaurante no configuró salida', async () => {
    await montarCocina(tablero({ orders: [conSalidas('A-1', 'ready', [])] }));
    await reposar(3);

    // Queda el de reimprimir y los de marcar líneas, pero ninguno de estado.
    const botones = textoDeLosBotones();
    expect(botones).toContain('Reimprimir');
    for (const estado of ['En preparación', 'Listo', 'Cancelado']) {
      expect(botones).not.toContain(estado);
    }
  });

  it('manda el estado elegido y repinta el tablero', async () => {
    let avanzado = false;
    const { fetch } = await montarCocina(
      () => tablero({ orders: [avanzado ? conSalidas('A-1', 'kitchen', [LISTO]) : conSalidas('A-1', 'new', [EN_COCINA])] }),
      {
        '/orders/id-A-1/status': () => {
          avanzado = true;
          return {};
        },
      }
    );
    await reposar(3);

    [...document.querySelectorAll('#vista button')].find((b) => b.textContent.trim() === 'En preparación').click();
    await reposar(4);

    const llamada = fetch.mock.calls.find(([url, o]) => String(url).includes('/status') && o?.method === 'POST');
    expect(JSON.parse(llamada[1].body)).toEqual({ to_status_id: 's2' });
    // Y el tablero ya muestra la salida siguiente, no la anterior.
    expect(texto()).toContain('Listo');
  });

  /**
   * Anular no avanza: abre el diálogo que pide el motivo, porque sin él el
   * reporte de anulaciones no responde la pregunta que se le hace.
   */
  it('anular pide el motivo en vez de mandar el cambio', async () => {
    const { fetch } = await montarCocina(tablero({ orders: [conSalidas('A-1', 'new', [ANULADO])] }), {
      '/orders/id-A-1/balance': { total: '0.00', paid: '0.00', pending: '0.00', is_settled: true },
    });
    await reposar(3);

    [...document.querySelectorAll('#vista button')].find((b) => b.textContent.trim() === 'Cancelado').click();
    await reposar(4);

    expect(fetch.mock.calls.some(([, o]) => o?.method === 'POST')).toBe(false);
    expect(document.body.textContent).toMatch(/motivo/i);
  });
});
