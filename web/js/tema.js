// Tema claro u oscuro, por dispositivo.
//
// Igual que la estación de cocina o la caja elegida: es del aparato, no de la
// persona ni de la empresa. Que alguien prenda el oscuro en la tableta de la
// cocina no dice nada de lo que quiera ver el mostrador.
//
// Sin elección explícita se sigue `prefers-color-scheme` del sistema, para no
// forzar un tema a quien nunca tocó el interruptor. En cuanto alguien lo usa,
// esa elección manda por encima del sistema hasta que la cambie de nuevo.
//
// El atributo se fija antes de pintar nada (ver el script en `index.html`)
// para que no haya un parpadeo del tema equivocado al cargar.

const CLAVE = 'resto_tema';

function guardado() {
  try {
    return localStorage.getItem(CLAVE);
  } catch {
    return null;
  }
}

function guardar(tema) {
  try {
    localStorage.setItem(CLAVE, tema);
  } catch {
    // Sin almacenamiento, el tema vale para esta carga y ya.
  }
}

/** El tema con el que se pintó esta carga: lo que ya fijó el script anti-parpadeo. */
export function temaActual() {
  return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
}

function aplicar(tema) {
  document.documentElement.setAttribute('data-theme', tema);
}

/** Cambia el tema y lo recuerda en este dispositivo. */
export function alternarTema() {
  const siguiente = temaActual() === 'dark' ? 'light' : 'dark';
  aplicar(siguiente);
  guardar(siguiente);
  return siguiente;
}

// Si el sistema cambia de tema y nadie eligió uno a mano en este
// dispositivo, se sigue el sistema en caliente.
try {
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (evento) => {
    if (guardado() !== null) return;
    aplicar(evento.matches ? 'dark' : 'light');
  });
} catch {
  // matchMedia sin addEventListener (muy viejo) o sin permiso: el tema
  // sencillamente no sigue al sistema en caliente, y eso no rompe nada.
}
