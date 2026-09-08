// Arranca la SPA dentro de jsdom, tal como la sirve el servidor.
//
// El esqueleto sale de `web/index.html` de verdad y no de una copia escrita
// aquí: `app.js` busca sus elementos por id al importarse, así que una
// prueba con un esqueleto inventado seguiría pasando después de renombrar
// un id en el HTML real — justo el tipo de rotura que esto debe atrapar.

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { vi } from 'vitest';

// Desde la raíz del proyecto: dentro de jsdom `import.meta.url` no es una
// URL de archivo, y vitest corre con la raíz en vitest.config.js.
const INDEX = resolve(process.cwd(), 'web/index.html');

function esqueleto() {
  const html = readFileSync(INDEX, 'utf8');
  const cuerpo = html.match(/<body[^>]*>([\s\S]*)<\/body>/i);
  if (!cuerpo) throw new Error('web/index.html no tiene <body>');
  // Los <script> se quitan: insertados con innerHTML no se ejecutan igual, y
  // la prueba importa app.js a mano para poder preparar el terreno antes.
  return cuerpo[1].replace(/<script[\s\S]*?<\/script>/gi, '');
}

/**
 * Deja la página en su estado inicial y monta la aplicación.
 *
 * @param {{token?: string, hash?: string, respuestas?: Record<string, unknown>}} opciones
 *   `respuestas` mapea una ruta de la API a lo que debe devolver. La clave
 *   puede terminar en `/*` para cubrir las rutas con id ('/customers/*'), y
 *   el valor puede ser una función que recibe la URL completa, para cuando
 *   la respuesta depende de la query ('/orders?status_category=ready').
 */
export async function montarApp({ token = null, hash = '', respuestas = {} } = {}) {
  localStorage.clear();
  if (token) localStorage.setItem('resto_token', token);

  window.location.hash = hash;
  document.body.innerHTML = esqueleto();

  const fetchFalso = vi.fn(async (url) => {
    const completa = String(url).replace('/api/v1', '');
    const ruta = completa.split('?')[0];

    // El exacto gana; entre los patrones `/*`, el más largo, para que
    // '/customers/*' no se coma una ruta más específica declarada aparte.
    const clave =
      ruta in respuestas
        ? ruta
        : Object.keys(respuestas)
            .filter((k) => k.endsWith('/*') && ruta.startsWith(k.slice(0, -1)))
            .sort((a, b) => b.length - a.length)[0];
    if (clave === undefined) {
      throw new Error(`La prueba no esperaba una llamada a ${completa}`);
    }

    const valor = respuestas[clave];
    return {
      ok: true,
      status: 200,
      json: async () => (typeof valor === 'function' ? valor(completa) : valor),
    };
  });
  vi.stubGlobal('fetch', fetchFalso);

  vi.resetModules();
  await import('../js/app.js');
  await reposar();

  return { fetch: fetchFalso };
}

/** Espera a que se vacíen microtareas y el hashchange que dispara el enrutador. */
export async function reposar(vueltas = 4) {
  for (let i = 0; i < vueltas; i += 1) {
    await new Promise((resolve) => setTimeout(resolve, 0));
  }
}
