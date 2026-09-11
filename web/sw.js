// Service worker: que la tableta del mostrador siga sirviendo con internet
// intermitente.
//
// Estrategia: **red primero, caché como respaldo**. Es deliberado y no es la
// receta habitual de PWA (caché primero, que es más rápida). El proyecto no
// tiene paso de compilación: `web/js/app.js` se llama igual antes y después
// de cada cambio, así que una caché que gane siempre serviría código viejo
// hasta que alguien acierte a invalidarla. Con la red primero, quien tiene
// conexión ve siempre lo último —el servidor ya manda `Cache-Control:
// no-cache`, así que lo que no cambió se resuelve con un 304 barato— y la
// caché solo aparece cuando la red no está.
//
// Por eso `VERSION` no hay que subirla en cada despliegue: la caché nunca es
// la fuente de verdad estando en línea. Se sube solo para tirar la anterior.
const VERSION = 'v1';
const CACHE_APP = `app-${VERSION}`;
const CACHE_DATOS = `datos-${VERSION}`;

// La red no siempre falla: a veces se queda colgada, que para quien está
// frente a la pantalla es peor. Pasado este tiempo se responde con la caché
// y la petición sigue su curso para refrescarla.
const ESPERA_RED_MS = 3500;

/**
 * El esqueleto de la aplicación. Se guarda al instalar para que una tableta
 * recién instalada ya funcione sin red, sin haber tenido que pasar antes por
 * cada pantalla.
 *
 * La lista está escrita a mano porque no hay empaquetador que la derive. Que
 * no se quede corta al agregar una vista lo vigila `web/tests/pwa.test.js`,
 * que la compara contra los archivos que hay en disco.
 */
const ESQUELETO = [
  '/',
  '/app.css',
  '/manifest.webmanifest',
  '/iconos/app.svg',
  '/js/api.js',
  '/js/app.js',
  '/js/caja-elegida.js',
  '/js/cola.js',
  '/js/format.js',
  '/js/icons.js',
  '/js/router.js',
  '/js/session.js',
  '/js/tema.js',
  '/js/ui.js',
  '/js/views/admin.js',
  '/js/views/caja.js',
  '/js/views/cancelar-pedido.js',
  '/js/views/clientes.js',
  '/js/views/cocina.js',
  '/js/views/cuenta.js',
  '/js/views/dividir-cuenta.js',
  '/js/views/domicilios.js',
  '/js/views/impresion.js',
  '/js/views/ingresar.js',
  '/js/views/menu.js',
  '/js/views/modificadores-dialogo.js',
  '/js/views/pedido-detalle.js',
  '/js/views/pedidos.js',
  '/js/views/reportes.js',
  '/js/views/salon.js',
];

/**
 * Lo único de la API que se guarda. `/auth/me` para poder arrancar sin red
 * —sin él la aplicación se cree sin sesión y manda a una pantalla de ingreso
 * que sin red no puede ingresar— y `/menu` para poder seguir tomando
 * pedidos, que es de lo que se trata todo esto.
 *
 * Nada más: un tablero de cocina o un arqueo servidos de una caché de hace
 * horas serían peores que un mensaje de error honesto.
 */
const API_EN_CACHE = ['/api/v1/auth/me', '/api/v1/menu'];

// Tailwind llega por CDN y no se puede precargar: si el CDN no responde, un
// `addAll` con esta URL dentro dejaría el service worker sin instalar y sin
// nada en caché. Se guarda sobre la marcha, la primera vez que sí responde.
const CDN_ESTILOS = 'https://cdn.tailwindcss.com';

self.addEventListener('install', (evento) => {
  evento.waitUntil(
    caches.open(CACHE_APP).then((cache) => cache.addAll(ESQUELETO)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (evento) => {
  evento.waitUntil(
    caches
      .keys()
      .then((nombres) =>
        Promise.all(nombres.filter((n) => n !== CACHE_APP && n !== CACHE_DATOS).map((n) => caches.delete(n)))
      )
      .then(() => self.clients.claim())
  );
});

/**
 * Al cerrar sesión se tira lo guardado de la API.
 *
 * En una tableta compartida el turno siguiente entra con otro usuario, y
 * `/auth/me` del anterior le daría sus permisos y su sucursal mientras no
 * haya red. El esqueleto no se toca: es el mismo para todo el mundo.
 */
self.addEventListener('message', (evento) => {
  if (evento.data?.tipo === 'olvidar') {
    evento.waitUntil(caches.delete(CACHE_DATOS));
  }
});

self.addEventListener('fetch', (evento) => {
  const peticion = evento.request;
  if (peticion.method !== 'GET') return;

  const url = new URL(peticion.url);

  if (url.origin === self.location.origin && url.pathname.startsWith('/api/')) {
    if (API_EN_CACHE.some((ruta) => url.pathname === ruta)) {
      evento.respondWith(redPrimero(peticion, CACHE_DATOS));
    }
    return; // el resto de la API va derecho a la red
  }

  if (peticion.url.startsWith(CDN_ESTILOS)) {
    evento.respondWith(cacheMientrasRefresca(peticion));
    return;
  }

  if (url.origin !== self.location.origin) return;

  evento.respondWith(redPrimero(peticion, CACHE_APP));
});

/**
 * Red primero, con tope de espera; si falla o tarda, lo guardado.
 *
 * Una navegación (recargar la pestaña, abrir la aplicación instalada) cae en
 * `/index.html`, que en la caché está bajo `/`: sin ese respaldo, abrir la
 * aplicación sin red daría el dinosaurio del navegador y no la pantalla.
 */
async function redPrimero(peticion, nombreCache) {
  const cache = await caches.open(nombreCache);

  const desdeRed = fetch(peticion)
    .then((respuesta) => {
      // Solo se guarda lo que salió bien: un 500 en caché sería un error
      // que sobrevive a que el servidor se arregle. Las opacas tampoco, que
      // no se pueden leer.
      if (respuesta.ok && respuesta.type === 'basic') cache.put(peticion, respuesta.clone());
      return respuesta;
    })
    .catch(() => null);

  const respuesta = await Promise.race([desdeRed, esperar(ESPERA_RED_MS)]);
  if (respuesta) return respuesta;

  const guardada = (await cache.match(peticion)) ?? (await respaldoDeNavegacion(peticion, cache));
  if (guardada) return guardada;

  // Ni red ni caché: se espera a la red aunque haya pasado el tope, porque
  // un error inventado aquí sería indistinguible de uno del servidor.
  return (await desdeRed) ?? Response.error();
}

/** Cualquier navegación se resuelve con el esqueleto: el enrutado es por hash. */
function respaldoDeNavegacion(peticion, cache) {
  if (peticion.mode !== 'navigate') return Promise.resolve(null);
  return cache.match('/');
}

/** Lo guardado ya mismo y de paso se refresca para la próxima. */
async function cacheMientrasRefresca(peticion) {
  const cache = await caches.open(CACHE_APP);
  const guardada = await cache.match(peticion);

  const desdeRed = fetch(peticion)
    .then((respuesta) => {
      if (respuesta.ok || respuesta.type === 'opaque') cache.put(peticion, respuesta.clone());
      return respuesta;
    })
    .catch(() => null);

  return guardada ?? (await desdeRed) ?? Response.error();
}

const esperar = (ms) => new Promise((resolve) => setTimeout(() => resolve(null), ms));
