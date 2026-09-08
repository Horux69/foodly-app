// Arranque de la aplicación: sesión, rutas y la cáscara común.

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
  { path: 'pedidos', label: 'Pedidos', permission: 'orders.create', view: pedidos },
  { path: 'cocina', label: 'Cocina', permission: 'orders.view', view: cocina },
  { path: 'menu', label: 'Menú', permission: 'menu.edit', view: menu },
  { path: 'reportes', label: 'Reportes', permission: 'reports.view', view: reportes },
  { path: 'admin', label: 'Administración', permission: 'settings.view', view: admin },
  { path: 'ingresar', public: true, view: ingresar },
];

const cabecera = document.getElementById('cabecera');
const outlet = document.getElementById('vista');

function pintarCabecera(rutaActiva) {
  const usuario = session.me();

  if (!usuario) return render(cabecera);

  render(
    cabecera,
    h(
      'header',
      { class: 'bg-white border-b border-slate-200 sticky top-0 z-30' },
      h(
        'div',
        { class: 'max-w-7xl mx-auto px-4 h-14 flex items-center justify-between gap-4' },
        h(
          'div',
          { class: 'flex items-center gap-4 min-w-0' },
          h('span', { class: 'font-semibold text-slate-900 truncate' }, usuario.tenant_name),
          h(
            'nav',
            { class: 'flex gap-1 overflow-x-auto' },
            router.menuRoutes().map((r) =>
              h(
                'a',
                {
                  href: `#/${r.path}`,
                  class: `px-3 py-2 rounded-md text-sm font-medium whitespace-nowrap ${
                    r.path === rutaActiva?.path
                      ? 'bg-slate-900 text-white'
                      : 'text-slate-600 hover:bg-slate-100'
                  }`,
                },
                r.label
              )
            )
          )
        ),
        h(
          'div',
          { class: 'flex items-center gap-3 text-sm text-slate-500 min-w-0' },
          h(
            'span',
            { class: 'truncate hidden sm:inline' },
            `${usuario.name} · ${usuario.branch_name ?? 'sin sucursal'}`
          ),
          h(
            'button',
            {
              class: 'text-slate-600 hover:text-slate-900 font-medium',
              onClick: () => {
                session.forget();
                router.go('ingresar', { replace: true });
                pintarCabecera(null);
              },
            },
            'Salir'
          )
        )
      )
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

  router.start(outlet, (ruta) => pintarCabecera(ruta));

  if (!session.me() && router.current() !== 'ingresar') {
    router.go('ingresar', { replace: true });
  }
}

arrancar().catch((error) => toast(error.message));
