// La aplicación se puede instalar y su esqueleto está entero en el service
// worker.
//
// Estas pruebas no montan nada: leen los archivos que se despliegan. Sin paso
// de compilación, la lista de precarga de `web/sw.js` está escrita a mano, y
// una vista nueva que no se agregue ahí desaparece justo cuando se corta el
// internet — que es el único momento en que a nadie le sirve descubrirlo.

import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const RAIZ = resolve(process.cwd(), 'web');
const leer = (ruta) => readFileSync(join(RAIZ, ruta), 'utf8');

function modulosEnDisco(dir = 'js') {
  return readdirSync(join(RAIZ, dir), { withFileTypes: true }).flatMap((entrada) =>
    entrada.isDirectory()
      ? modulosEnDisco(join(dir, entrada.name))
      : entrada.name.endsWith('.js')
        ? [`/${relative('.', join(dir, entrada.name)).split('\\').join('/')}`]
        : []
  );
}

function precarga() {
  const sw = leer('sw.js');
  const lista = sw.match(/const ESQUELETO = \[([\s\S]*?)\];/);
  if (!lista) throw new Error('web/sw.js ya no declara ESQUELETO como una lista literal');
  return [...lista[1].matchAll(/'([^']+)'/g)].map((m) => m[1]);
}

describe('service worker', () => {
  it('precarga todos los módulos de la aplicación', () => {
    const enDisco = modulosEnDisco();
    expect(enDisco.length).toBeGreaterThan(10);
    expect(precarga()).toEqual(expect.arrayContaining(enDisco));
  });

  it('no precarga archivos que no existen', () => {
    // `cache.addAll` es todo o nada: una sola ruta rota deja el service
    // worker sin instalar y la tableta sin caché, en silencio.
    const enDisco = new Set([...modulosEnDisco(), '/', '/app.css', '/manifest.webmanifest', '/iconos/app.svg']);
    for (const ruta of precarga()) expect(enDisco).toContain(ruta);
  });

  it('deja pasar a la red todo lo de la API salvo la sesión y la carta', () => {
    const sw = leer('sw.js');
    const enCache = sw.match(/const API_EN_CACHE = \[([\s\S]*?)\];/);
    expect([...enCache[1].matchAll(/'([^']+)'/g)].map((m) => m[1])).toEqual([
      '/api/v1/auth/me',
      '/api/v1/menu',
    ]);
  });
});

describe('manifiesto', () => {
  const manifiesto = JSON.parse(leer('manifest.webmanifest'));

  it('tiene lo que el navegador exige para ofrecer la instalación', () => {
    expect(manifiesto.name).toBeTruthy();
    expect(manifiesto.short_name).toBeTruthy();
    expect(manifiesto.start_url).toBe('/');
    expect(manifiesto.display).toBe('standalone');

    // Chrome pide un icono de 144 px o más; iOS, además, que sea PNG.
    const grandes = manifiesto.icons.filter((i) => i.sizes === 'any' || parseInt(i.sizes, 10) >= 144);
    expect(grandes.length).toBeGreaterThan(0);
    expect(manifiesto.icons.some((i) => i.type === 'image/png')).toBe(true);
  });

  it('apunta a iconos que existen', () => {
    for (const icono of manifiesto.icons) expect(() => leer(icono.src)).not.toThrow();
  });

  it('está enlazado desde el HTML que sirve el servidor', () => {
    const html = leer('index.html');
    expect(html).toContain('rel="manifest" href="/manifest.webmanifest"');
    expect(html).toContain('rel="apple-touch-icon"');
  });
});
