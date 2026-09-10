// La página de seguimiento del cliente (F9.2).
//
// Es la única página que ve alguien sin sesión, y va aparte de la
// aplicación: no comparte sus módulos —quien la abre sería mandado a la
// pantalla de ingreso— y se explica sola. Por eso la prueba tampoco monta la
// aplicación: lee el archivo que se despliega y ejecuta su script.
//
// Lo que se protege: que pinte el paso donde va el pedido, que un pedido
// anulado no se lea como entregado, y que el nombre de un producto se
// escriba como texto y no como HTML —es texto que viene de la base—.

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const HTML = readFileSync(resolve(process.cwd(), 'web/seguimiento.html'), 'utf8');

const PEDIDO = {
  order_number: 'NTE-00016',
  placed_at: '2026-09-10T12:00:00Z',
  currency: 'COP',
  status: 'En camino',
  steps: ['Recibido', 'En preparación', 'Listo', 'En camino', 'Entregado'],
  step: 3,
  cancelled: false,
  is_delivery: true,
  address: 'Calle 45 #12-34',
  promised_at: '2026-09-10T12:40:00Z',
  dispatched_at: '2026-09-10T12:20:00Z',
  delivered_at: null,
  courier_name: 'Luis',
  items: [{ quantity: 2, name: 'Hamburguesa doble carne' }],
  total: '52000.00',
  restaurant: { name: 'Burger Demo', branch: 'Sede Norte', phone: '3001234567' },
};

/**
 * Monta la página: su cuerpo, y su script ejecutado a mano.
 *
 * El script es un módulo sin `import`, así que se puede evaluar tal cual.
 * `setInterval` se anula: la página se refresca sola cada 30 s y un
 * temporizador vivo deja la corrida colgada.
 */
async function montarSeguimiento(respuesta, token = 'a'.repeat(32)) {
  const cuerpo = HTML.match(/<body[^>]*>([\s\S]*)<\/body>/i)[1];
  document.body.innerHTML = cuerpo.replace(/<script[\s\S]*?<\/script>/gi, '');

  history.replaceState({}, '', token ? `/seguimiento.html?t=${token}` : '/seguimiento.html');
  vi.stubGlobal('setInterval', () => 0);
  vi.stubGlobal('fetch', vi.fn(async () => respuesta));

  const script = HTML.match(/<script type="module">([\s\S]*?)<\/script>/)[1];
  // eslint-disable-next-line no-new-func
  new Function(script)();
  await new Promise((r) => setTimeout(r, 0));
  await new Promise((r) => setTimeout(r, 0));
}

const ok = (datos) => ({ ok: true, json: async () => datos });

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('seguimiento del cliente', () => {
  it('pinta el pedido con el paso en el que va', async () => {
    await montarSeguimiento(ok(PEDIDO));

    const texto = document.body.textContent;
    expect(texto).toContain('Burger Demo');
    expect(texto).toContain('NTE-00016');
    expect(texto).toContain('En camino');
    expect(texto).toContain('Luis lo lleva');
    expect(texto).toContain('2× Hamburguesa doble carne');

    // Los pasos cumplidos van marcados; el actual, además, resaltado.
    const pasos = [...document.querySelectorAll('.paso')];
    expect(pasos).toHaveLength(5);
    expect(pasos.filter((p) => p.classList.contains('hecho'))).toHaveLength(4);
    expect(pasos.filter((p) => p.classList.contains('actual'))[0].textContent).toContain('En camino');
  });

  /** Un pedido anulado no puede leerse como uno que va en camino. */
  it('un pedido anulado lo dice y no muestra pasos', async () => {
    await montarSeguimiento(ok({ ...PEDIDO, cancelled: true, step: null }));

    expect(document.body.textContent).toContain('fue anulado');
    expect(document.querySelectorAll('.paso')).toHaveLength(0);
  });

  it('un enlace que no existe no deja al cliente mirando una pantalla vacía', async () => {
    await montarSeguimiento({ ok: false, json: async () => ({ detail: 'No encontramos ese pedido' }) });

    expect(document.body.textContent).toContain('No encontramos ese pedido');
  });

  it('sin token no pide nada', async () => {
    await montarSeguimiento(ok(PEDIDO), '');

    expect(fetch).not.toHaveBeenCalled();
    expect(document.body.textContent).toContain('enlace está incompleto');
  });

  /**
   * El nombre del producto sale de la base: si se interpolara en HTML,
   * bastaría un producto llamado `<img onerror=…>` para ejecutarlo en el
   * teléfono del cliente.
   */
  it('el nombre de un producto se escribe como texto, no como HTML', async () => {
    await montarSeguimiento(
      ok({ ...PEDIDO, items: [{ quantity: 1, name: '<img src=x onerror=alert(1)>' }] })
    );

    expect(document.querySelectorAll('img')).toHaveLength(0);
    expect(document.body.textContent).toContain('<img src=x onerror=alert(1)>');
  });

  it('pide el pedido por su token', async () => {
    await montarSeguimiento(ok(PEDIDO), 'b'.repeat(32));

    expect(String(fetch.mock.calls[0][0])).toBe(`/api/v1/public/orders/${'b'.repeat(32)}`);
  });
});
