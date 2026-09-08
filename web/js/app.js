// Arranque: sesión, rutas y la estructura común.

import { icon } from './icons.js';
import * as router from './router.js';
import * as session from './session.js';
import { h, render, select, toast } from './ui.js';
import { admin } from './views/admin.js';
import { clientes } from './views/clientes.js';
import { cocina } from './views/cocina.js';
import { domicilios } from './views/domicilios.js';
import { ingresar } from './views/ingresar.js';
import { menu } from './views/menu.js';
import { pedidos } from './views/pedidos.js';
import { reportes } from './views/reportes.js';

// El orden decide qué pantalla ve primero cada quien según sus permisos: un
// cocinero no debería aterrizar en la caja.
const RUTAS = [
  { path: 'pedidos', label: 'Pedidos', icon: 'pedidos', permission: 'orders.create', view: pedidos },
  { path: 'cocina', label: 'Cocina', icon: 'cocina', permission: 'orders.view', view: cocina },
  { path: 'menu', label: 'Menú', icon: 'menu', permission: 'menu.edit', view: menu },
  {
    path: 'domicilios',
    label: 'Domicilios',
    icon: 'domicilio',
    permission: 'delivery.assign',
    // Solo si el restaurante reparte: por configuración, no por un
    // condicional sobre el tenant.
    visible: () => Boolean(session.me()?.channels.includes('delivery')),
    view: domicilios,
  },
  { path: 'clientes', label: 'Clientes', icon: 'clientes', permission: 'customers.view', view: clientes },
  { path: 'reportes', label: 'Reportes', icon: 'reportes', permission: 'reports.view', view: reportes },
  { path: 'admin', label: 'Administración', icon: 'admin', permission: 'settings.view', view: admin },
  { path: 'ingresar', public: true, view: ingresar },
];

const rail = document.getElementById('rail');
const topbar = document.getElementById('topbar');
const outlet = document.getElementById('vista');
const barraInferior = document.getElementById('barra-inferior');

const iniciales = (texto) =>
  texto
    .split(/\s+/)
    .slice(0, 2)
    .map((p) => p[0])
    .join('')
    .toUpperCase();

/**
 * Selector de sucursal. Solo aparece con más de una: en un restaurante de
 * una sola sede sería una decisión que nadie tiene que tomar.
 *
 * La elegida no se manda al backend como una verdad, sino como una petición:
 * `Api\Deps::activeBranchId` la valida contra el tenant del token.
 */
function selectorSucursal({ compacto = false } = {}) {
  const sucursales = session.branches();
  if (sucursales.length < 2) return null;

  return select(
    sucursales.map((b) => ({
      value: b.id,
      label: compacto ? b.code : b.name,
      selected: b.id === session.activeBranchId(),
    })),
    {
      'aria-label': 'Sucursal activa',
      class: `campo ${compacto ? 'h-8 text-[12px] py-0 pl-2 pr-6 w-auto' : 'h-8 text-[12.5px] py-0'}`,
      onChange: (event) => {
        session.setActiveBranch(event.target.value);
        // Cambiar de sucursal cambia el menú, los pedidos y el tablero: se
        // repinta la estructura (para que todos los selectores queden en la
        // misma) y se vuelve a montar la vista.
        pintarEstructura(rutaMontada);
        router.reload();
      },
    }
  );
}

function pintarRail(rutaActiva, usuario) {
  render(
    rail,
    // Identidad: iniciales sobre un cuadro sobrio. Sin logotipo inventado.
    h(
      'div',
      { class: 'flex items-center gap-2.5 h-14 px-3.5 lg:px-4 border-b border-[--linea]' },
      h(
        'span',
        { class: 'w-7 h-7 rounded-md bg-stone-900 text-white grid place-items-center text-[11px] font-semibold shrink-0' },
        iniciales(usuario.tenant_name)
      ),
      h('span', { class: 'hidden lg:block text-[13.5px] font-semibold truncate' }, usuario.tenant_name)
    ),

    h(
      'nav',
      { class: 'flex-1 p-2 lg:p-3 space-y-0.5', 'aria-label': 'Secciones' },
      router.menuRoutes().map((r) =>
        h(
          'a',
          {
            href: `#/${r.path}`,
            class: 'rail-item justify-center lg:justify-start',
            'aria-current': r.path === rutaActiva?.path ? 'page' : null,
            title: r.label,
          },
          icon(r.icon, { size: 18 }),
          h('span', { class: 'hidden lg:inline truncate' }, r.label)
        )
      )
    ),

    h(
      'div',
      { class: 'p-2 lg:p-3 border-t border-[--linea]' },
      h(
        'div',
        { class: 'hidden lg:block px-2 pb-2 space-y-2' },
        h(
          'div',
          {},
          h('div', { class: 'text-[13px] font-medium truncate' }, usuario.name),
          h(
            'div',
            { class: 'text-[12px] text-stone-500 truncate' },
            session.activeBranch()?.name ?? usuario.branch_name ?? 'Sin sucursal'
          )
        ),
        selectorSucursal()
      ),
      h(
        'button',
        {
          class: 'rail-item w-full justify-center lg:justify-start',
          title: 'Cerrar sesión',
          onClick: () => {
            session.forget();
            router.go('ingresar', { replace: true });
            pintarEstructura(null);
          },
        },
        icon('salir', { size: 18 }),
        h('span', { class: 'hidden lg:inline' }, 'Salir')
      )
    )
  );
}

/** En móvil no hay lateral: el nombre del restaurante va arriba y la
 *  navegación abajo, donde alcanza el pulgar. */
function pintarTopbar(rutaActiva, usuario) {
  render(
    topbar,
    h(
      'header',
      { class: 'md:hidden flex items-center justify-between gap-3 h-14 px-4 bg-white border-b border-[--linea] sticky top-0 z-30' },
      h(
        'div',
        { class: 'flex items-center gap-2.5 min-w-0' },
        h(
          'span',
          { class: 'w-7 h-7 rounded-md bg-stone-900 text-white grid place-items-center text-[11px] font-semibold shrink-0' },
          iniciales(usuario.tenant_name)
        ),
        h(
          'div',
          { class: 'min-w-0' },
          h('div', { class: 'text-[13.5px] font-semibold leading-tight truncate' }, rutaActiva?.label ?? usuario.tenant_name),
          h(
            'div',
            { class: 'text-[11.5px] text-stone-500 leading-tight truncate' },
            session.activeBranch()?.name ?? usuario.branch_name ?? 'Sin sucursal'
          )
        )
      ),
      selectorSucursal({ compacto: true }),
      h(
        'button',
        {
          class: 'boton boton-sutil',
          'aria-label': 'Cerrar sesión',
          onClick: () => {
            session.forget();
            router.go('ingresar', { replace: true });
            pintarEstructura(null);
          },
        },
        icon('salir', { size: 18 })
      )
    )
  );
}

function pintarBarraInferior(rutaActiva) {
  render(
    barraInferior,
    h(
      'nav',
      {
        class: 'md:hidden fixed bottom-0 inset-x-0 z-30 bg-white border-t border-[--linea] flex',
        'aria-label': 'Secciones',
      },
      router.menuRoutes().map((r) => {
        const activa = r.path === rutaActiva?.path;
        return h(
          'a',
          {
            href: `#/${r.path}`,
            'aria-current': activa ? 'page' : null,
            class: `flex-1 flex flex-col items-center justify-center gap-0.5 min-h-[56px] relative ${
              activa ? 'text-stone-900' : 'text-stone-400'
            }`,
          },
          activa ? h('span', { class: 'absolute top-0 h-[2px] w-7 bg-stone-900' }) : null,
          icon(r.icon, { size: 19 }),
          h('span', { class: 'text-[10.5px] font-medium' }, r.label)
        );
      })
    )
  );
}

let rutaMontada = null;

function pintarEstructura(rutaActiva) {
  rutaMontada = rutaActiva;
  const usuario = session.me();

  if (!usuario) {
    rail.classList.add('hidden');
    rail.classList.remove('md:flex');
    // Con arrow y no `forEach(render)`: forEach pasa (elemento, indice,
    // array), y como render(el, ...children) toma el resto como hijos,
    // terminaba intentando meter el propio rail dentro de si mismo.
    [rail, topbar, barraInferior].forEach((el) => render(el));
    return;
  }

  rail.classList.add('md:flex');
  pintarRail(rutaActiva, usuario);
  pintarTopbar(rutaActiva, usuario);
  pintarBarraInferior(rutaActiva);
}

async function arrancar() {
  router.define(RUTAS);

  try {
    await session.load();
  } catch {
    // Token vencido o revocado: se sigue sin sesión y el enrutador manda a ingresar.
    session.forget();
  }

  router.start(outlet, pintarEstructura);

  if (!session.me() && router.current() !== 'ingresar') {
    router.go('ingresar', { replace: true });
  }
}

arrancar().catch((error) => toast(error.message));
