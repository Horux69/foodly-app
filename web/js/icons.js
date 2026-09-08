// Iconos.
//
// SVG dibujado a mano en vez de una librería: no hay paso de compilación, y
// traer una fuente de iconos por CDN sumaría una dependencia externa y una
// petición bloqueante para algo que se resuelve con trazos.
//
// Todos comparten caja de 24, trazo de 1.75 y `currentColor`, así que heredan
// el color y el tamaño del texto que los acompaña y nunca desentonan.

const TRAZOS = {
  pedidos: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9.5 8.5h5M9.5 12.5h5"/>',
  cocina: '<path d="M12 3c3 4 5 6 5 9a5 5 0 0 1-10 0c0-2 1-3 2-4 0 1.5 1 2 1.5 2 .5-2-1-4 1.5-7z"/>',
  menu: '<path d="M5 4h13v16H5z"/><path d="M9 4v16"/><path d="M12 9h4M12 13h4"/>',
  reportes: '<path d="M3 20h18"/><path d="M6 20v-7M12 20V5M18 20v-4"/>',
  admin: '<path d="M4 8h9M17 8h3M4 16h3M11 16h9"/><circle cx="15" cy="8" r="2"/><circle cx="9" cy="16" r="2"/>',
  buscar: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/>',
  mas: '<path d="M12 5v14M5 12h14"/>',
  menos: '<path d="M5 12h14"/>',
  check: '<path d="M20 6L9 17l-5-5"/>',
  cerrar: '<path d="M18 6L6 18M6 6l12 12"/>',
  reloj: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  alerta: '<path d="M12 4l8.5 15h-17z"/><path d="M12 10v4M12 16.5h.01"/>',
  archivar: '<path d="M3 6h18v4H3z"/><path d="M5 10v9h14v-9"/><path d="M10 14h4"/>',
  usuario: '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20c0-3.6 3.4-5.5 7.5-5.5s7.5 1.9 7.5 5.5"/>',
  salir: '<path d="M14 7V5H5v14h9v-2"/><path d="M19 12H10"/><path d="M16 9l3 3-3 3"/>',
  sucursal: '<path d="M4 10h16v10H4z"/><path d="M3 10l2-5h14l2 5"/><path d="M10 20v-5h4v5"/>',
  domicilio:
    '<path d="M3 7h11v9H3z"/><path d="M14 10h3.5l3.5 3.5V16H14z"/><circle cx="7.5" cy="18" r="2"/><circle cx="17.5" cy="18" r="2"/>',
  dinero: '<path d="M3 7h18v10H3z"/><circle cx="12" cy="12" r="2.5"/>',
  mesa: '<path d="M3 8h18"/><path d="M6 8l-1 11M18 8l1 11"/><path d="M4 5h16v3H4z"/>',
  vacio: '<path d="M4 13h4l2 3h4l2-3h4"/><path d="M6 5h12l3 8v6H3v-6z"/>',
  impuesto: '<path d="M6 3h12v18H6z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
  clientes: '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.4 3.1-5 7-5s7 1.6 7 5"/><path d="M17 8.5a3 3 0 0 1 0 5M18 20c0-2-.6-3.4-1.6-4.4"/>',
  etiqueta: '<path d="M4 4h8l8 8-8 8-8-8z"/><circle cx="9" cy="9" r="1.5"/>',
};

/**
 * icon('cocina', { size: 20, class: 'text-amber-600' })
 * Decorativo por defecto: los lectores de pantalla lo ignoran y leen el texto
 * que lo acompaña. Con `label` pasa a anunciarse.
 */
export function icon(nombre, { size = 20, class: clase = '', label } = {}) {
  const trazo = TRAZOS[nombre];
  if (!trazo) return document.createTextNode('');

  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('width', size);
  svg.setAttribute('height', size);
  svg.setAttribute('fill', 'none');
  svg.setAttribute('stroke', 'currentColor');
  svg.setAttribute('stroke-width', '1.75');
  svg.setAttribute('stroke-linecap', 'round');
  svg.setAttribute('stroke-linejoin', 'round');
  svg.setAttribute('class', `shrink-0 ${clase}`);

  if (label) {
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', label);
  } else {
    svg.setAttribute('aria-hidden', 'true');
  }

  // innerHTML con constantes propias del módulo, nunca con datos de la base.
  svg.innerHTML = trazo;
  return svg;
}
