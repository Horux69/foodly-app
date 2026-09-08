// Arranque de la aplicación: sesión, rutas y la cáscara común.

import { icon } from './icons.js';
import * as router from './router.js';
import * as session from './session.js';
import { h, render, toast } from './ui.js';
import { admin } from './views/admin.js';
import { cocina } from './views/cocina.js';
import { ingresar } from './views/ingresar.js';
import { menu } from './views/menu.js';
import { pedidos } from './views/pedidos.js';
import { reportes } from './views/reportes.js';

// El orden importa: define qué pantalla ve primero cada quien según sus
// permisos. Un cocinero no debería aterrizar en la caja.
const RUTAS = [
  { path: 'pedidos', label: 'Pedidos', icon: 'pedidos', permission: 'orders.create', view: pedidos },
  { path: 'cocina', label: 'Cocina', icon: 'cocina', permission: 'orders.view', view: cocina },
  { path: 'menu', label: 'Menú', icon: 'menu', permission: 'menu.edit', view: menu },
  { path: 'reportes', label: 'Reportes', icon: 'reportes', permission: 'reports.view', view: reportes },
  { path: 'admin', label: 'Administración', icon: 'admin', permission: 'settings.view', view: admin },
  { path: 'ingresar', public: true, view: ingresar },
];

const cabecera = document.getElementById('cabecera');
const outlet = document.getElementById('vista');
const barraInferior = document.getElementById('barra-inferior');

function enlaceEscritorio(ruta, activa) {
  return h(
    'a',
    {
      href: `#/${ruta.path}`,
      'aria-current': activa ? 'page' : null,
      class: `flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium whitespace-nowrap transition ${
        activa ? 'bg-stone-900 text-white' : 'text-stone-600 hover:bg-stone-100 hover:text-stone-900'
      }`,
    },
    icon(ruta.icon, { size: 18 }),
    ruta.label
  );
}

// En móvil la navegación baja al pulgar. Solo icono y etiqueta corta, con el
// activo marcado por color y por una barra: nunca por color solo.
function enlaceMovil(ruta, activa) {
  return h(
    'a',
    {
      href: `#/${ruta.path}`,
      'aria-current': activa ? 'page' : null,
      class: `flex-1 flex flex-col items-center justify-center gap-0.5 py-2 min-h-[56px] relative ${
        activa ? 'text-amber-700' : 'text-stone-500'
      }`,
    },
    activa ? h('span', { class: 'absolute top-0 h-0.5 w-8 rounded-full bg-amber-700' }) : null,
    icon(ruta.icon, { size: 20 }),
    h('span', { class: 'text-[11px] font-medium' }, ruta.label)
  );
}

function pintarCabecera(rutaActiva) {
  const usuario = session.me();

  if (!usuario) {
    render(cabecera);
    render(barraInferior);
    return;
  }

  const rutas = router.menuRoutes();

  render(
    cabecera,
    h(
      'header',
      { class: 'bg-white border-b border-stone-200 sticky top-0 z-30' },
      h(
        'div',
        { class: 'max-w-7xl mx-auto px-4 h-14 flex items-center justify-between gap-4' },
        h(
          'div',
          { class: 'flex items-center gap-5 min-w-0' },
          h(
            'span',
            { class: 'flex items-center gap-2 font-semibold text-stone-900 truncate' },
            h(
              'span',
              { class: 'inline-flex items-center justify-center w-7 h-7 rounded-lg bg-amber-700 text-white shrink-0' },
              icon('cocina', { size: 16 })
            ),
            h('span', { class: 'truncate' }, usuario.tenant_name)
          ),
          h(
            'nav',
            { class: 'hidden md:flex gap-1', 'aria-label': 'Secciones' },
            rutas.map((r) => enlaceEscritorio(r, r.path === rutaActiva?.path))
          )
        ),
        h(
          'div',
          { class: 'flex items-center gap-3 min-w-0' },
          h(
            'span',
            { class: 'hidden sm:flex items-center gap-2 text-sm text-stone-500 min-w-0' },
            icon('usuario', { size: 16 }),
            h('span', { class: 'truncate' }, `${usuario.name} · ${usuario.branch_name ?? 'sin sucursal'}`)
          ),
          h(
            'button',
            {
              class: 'flex items-center gap-1.5 text-sm text-stone-500 hover:text-stone-900 font-medium',
              title: 'Cerrar sesión',
              onClick: () => {
                session.forget();
                router.go('ingresar', { replace: true });
                pintarCabecera(null);
              },
            },
            icon('salir', { size: 18 }),
            h('span', { class: 'hidden sm:inline' }, 'Salir')
          )
        )
      )
    )
  );

  render(
    barraInferior,
    h(
      'nav',
      {
        class: 'md:hidden fixed bottom-0 inset-x-0 z-30 bg-white border-t border-stone-200 flex',
        'aria-label': 'Secciones',
      },
      rutas.map((r) => enlaceMovil(r, r.path === rutaActiva?.path))
    )
  );
}

async function arrancar() {
  router.define(RUTAS);

  try {
    await session.load();
  } catch {
    // Token vencido o revocado: se sigue sin sesión y el enrutador manda a ingresar.
    session.forget();
  }

  router.start(outlet, pintarCabecera);

  if (!session.me() && router.current() !== 'ingresar') {
    router.go('ingresar', { replace: true });
  }
}

arrancar().catch((error) => toast(error.message));
