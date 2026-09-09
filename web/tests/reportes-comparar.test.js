// Comparar contra el período anterior, y bajar el reporte en CSV.
//
// Un dueño no lee "vendí 4 millones", lee "vendí 12% más que la semana
// pasada". Lo que se protege es que el porcentaje no lo invente la pantalla
// —lo calcula `Domain\PeriodComparison`— y sobre todo el caso que rompe la
// aritmética: cuando antes no había nada, no hay porcentaje que mostrar.

import { describe, expect, it, vi } from 'vitest';
import { montarApp, reposar } from './montar-app.js';
import { sesion } from './sesion.js';

const conPrevio = (previous) => ({
  from_date: '2026-09-01',
  to_date: '2026-09-09',
  totals: { orders: 12, revenue: '450000.00', avg_ticket: '37500.00' },
  by_day: [{ day: '2026-09-09', orders: 12, revenue: '450000.00' }],
  by_channel: [],
  by_branch: [],
  previous,
});

const PREVIO = {
  from_date: '2026-08-23',
  to_date: '2026-08-31',
  totals: { orders: 10, revenue: '400000.00', avg_ticket: '40000.00' },
  change: { orders: 20.0, revenue: 12.5, avg_ticket: -6.3 },
};

const respuestas = (ventas) => ({
  '/auth/me': sesion({ permissions: ['reports.view'] }),
  '/reports/sales': ventas,
  '/reports/top-products': [],
  '/reports/prep-times': { orders: 0, avg_minutes: null, median_minutes: null, min_minutes: null, max_minutes: null },
  '/reports/peak-hours': [],
});

const texto = () => document.getElementById('vista').textContent.replace(/ /g, ' ');
const boton = (texto) => [...document.querySelectorAll('#vista button')].find((b) => b.textContent.trim() === texto);

async function montarReportes(ventas = conPrevio(PREVIO)) {
  const montado = await montarApp({ token: 't', hash: '#/reportes', respuestas: respuestas(ventas) });
  await reposar(4);
  return montado;
}

describe('comparación con el período anterior', () => {
  it('la pide de entrada', async () => {
    const { fetch } = await montarReportes();

    const url = fetch.mock.calls.map(([u]) => String(u)).find((u) => u.includes('/reports/sales?'));
    expect(url).toContain('compare=true');
  });

  it('muestra el cambio de cada número y el período con que compara', async () => {
    await montarReportes();
    const t = texto();

    expect(t).toContain('+12.5 %'); // ingresos
    expect(t).toContain('+20.0 %'); // pedidos
    expect(t).toContain('-6.3 %'); // ticket promedio
    expect(t).toContain('antes $ 400.000');
    expect(t).toContain('2026-08-23 a 2026-08-31');
  });

  /**
   * El caso que rompe la aritmética: dividir por cero. La pantalla no puede
   * decir "subió un infinito por ciento", así que no dice nada.
   */
  it('sin base para comparar no muestra ningún porcentaje', async () => {
    await montarReportes(
      conPrevio({
        from_date: '2026-08-23',
        to_date: '2026-08-31',
        totals: { orders: 0, revenue: '0.00', avg_ticket: '0.00' },
        change: { orders: null, revenue: null, avg_ticket: null },
      })
    );

    expect(texto()).not.toMatch(/[+-]?\d+\.\d %/);
    expect(texto()).toContain('antes $ 0');
  });

  it('al apagar la casilla deja de pedirla', async () => {
    const { fetch } = await montarReportes();

    [...document.querySelectorAll('#vista input[type="checkbox"]')][0].click();
    await reposar(4);

    const ultima = fetch.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('/reports/sales?')).at(-1);
    expect(ultima).not.toContain('compare=true');
  });

  it('un período sin comparación no rompe la pantalla', async () => {
    await montarReportes(conPrevio(null));

    expect(texto()).toContain('$ 450.000');
    expect(texto()).not.toContain('antes');
  });
});

describe('descargar el reporte', () => {
  it('lo pide al servidor con el rango que se está mirando', async () => {
    const descargas = [];
    // jsdom no implementa los blob URL: se rellenan para poder espiarlos.
    URL.createObjectURL ??= () => '';
    URL.revokeObjectURL ??= () => {};
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:falso');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});

    const { fetch } = await montarReportes();
    // El blob y el Content-Disposition los pone el servidor; aquí basta con
    // que la petición salga bien formada.
    fetch.mockImplementation(async (url) => {
      descargas.push(String(url));
      return {
        ok: true,
        status: 200,
        headers: { get: () => 'attachment; filename="sales-2026-09-01-a-2026-09-09.csv"' },
        blob: async () => new Blob(['a;b']),
      };
    });

    boton('Descargar CSV').click();
    await reposar(4);

    expect(descargas[0]).toContain('/reports/export');
    expect(descargas[0]).toContain('report=sales');
    expect(descargas[0]).toContain('from_date=');
  });
});
