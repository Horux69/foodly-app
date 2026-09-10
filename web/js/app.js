// Arranque: sesión, rutas y la estructura común.

import * as cola from './cola.js';
import { icon } from './icons.js';
import * as router from './router.js';
import * as session from './session.js';
import { confirm, h, render, select, toast } from './ui.js';
import { admin } from './views/admin.js';
import { abrirCuenta } from './views/cuenta.js';
import { caja } from './views/caja.js';
import { clientes } from './views/clientes.js';
import { cocina } from './views/cocina.js';
import { salon, usaMesas } from './views/salon.js';
import { domicilios } from './views/domicilios.js';
import { ingresar } from './views/ingresar.js';
import { menu } from './views/menu.js';
import { pedidos } from './views/pedidos.js';
import { reportes } from './views/reportes.js';

// El orden decide qué pantalla ve primero cada quien según sus permisos: un
// cocinero no debería aterrizar en la caja.
const RUTAS = [
  { path: 'pedidos', label: 'Pedidos', icon: 'pedidos', permission: 'orders.create', view: pedidos },
  {
    path: 'salon',
    label: 'Salón',
    icon: 'mesa',
    permission: 'orders.view',
    // Solo si el restaurante maneja mesas: por configuración, igual que
    // Domicilios con su canal.
    visible: usaMesas,
    view: salon,
  },
  { path: 'cocina', label: 'Cocina', icon: 'cocina', permission: 'orders.view', view: cocina },
  {
    path: 'caja',
    label: 'Caja',
    icon: 'dinero',
    // Sin `permission` porque son dos: la abre quien cobra y la cuadra quien
    // cierra, y cualquiera de los dos tiene algo que hacer en la pantalla.
    visible: () => session.canAny('payments.register', 'cash.close'),
    view: caja,
  },
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

/**
 * Lo que quedó sin enviar. Solo aparece cuando hay algo: un indicador de
 * "todo bien" permanente se vuelve parte del fondo y deja de leerse.
 *
 * Va donde el selector de sucursal —rail en escritorio, topbar en móvil—
 * porque es la franja que está en todas las pantallas, y quien toma pedidos
 * tiene que verlo sin salir de la suya.
 */
function avisoCola({ compacto = false } = {}) {
  const enCola = cola.pendientes();
  const rechazados = cola.rechazados();
  if (!enCola.length && !rechazados.length) return null;

  // En móvil no hay sitio para la frase: queda el número, y el nombre
  // completo va en `aria-label` para que no se pierda quien no ve la
  // pantalla.
  const chip = (cuantos, texto, tono, alPulsar, titulo) =>
    h(
      'button',
      {
        class: `flex items-center justify-center gap-1.5 rounded-[--r] border px-2 py-1 text-[11.5px] font-medium ${
          compacto ? 'shrink-0' : 'w-full'
        } ${tono}`,
        onClick: alPulsar,
        title: titulo,
        'aria-label': compacto ? texto : null,
      },
      icon('alerta', { size: 14 }),
      h('span', { class: 'truncate' }, compacto ? String(cuantos) : texto)
    );

  return h(
    'div',
    { class: 'space-y-1', role: 'status' },
    enCola.length
      ? chip(
          enCola.length,
          `${enCola.length} sin enviar`,
          'border-amber-300 bg-amber-50 text-amber-800',
          () => reintentarCola(),
          enCola.map((e) => e.descripcion).join('\n')
        )
      : null,
    rechazados.length
      ? chip(
          rechazados.length,
          `${rechazados.length} rechazados`,
          'border-red-300 bg-red-50 text-red-700',
          () => verRechazos(rechazados),
          'El servidor los rechazó: hay que volver a tomarlos'
        )
      : null
  );
}

async function reintentarCola() {
  const { enviados, descartados } = await cola.reenviar();
  if (enviados.length) toast(`${enviados.length} pedido(s) enviados`, 'ok');
  else if (!descartados.length) toast('Todavía sin conexión', 'warn');
}

async function verRechazos(rechazados) {
  const detalle = rechazados.map((r) => `${r.descripcion}: ${r.motivo}`).join(' — ');
  const olvidar = await confirm({
    title: 'El servidor rechazó estos pedidos',
    message: `${detalle}. Hay que volver a tomarlos: no se van a reintentar solos.`,
    confirmLabel: 'Ya los anoté',
    variant: 'secondary',
  });
  if (olvidar) cola.olvidarRechazos();
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
        selectorSucursal(),
        avisoCola()
      ),
      h(
        'button',
        {
          class: 'rail-item w-full justify-center lg:justify-start',
          title: 'Mi cuenta',
          onClick: abrirCuenta,
        },
        icon('usuario', { size: 18 }),
        h('span', { class: 'hidden lg:inline' }, 'Mi cuenta')
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
      avisoCola({ compacto: true }),
      h(
        'button',
        { class: 'boton boton-sutil', 'aria-label': 'Mi cuenta', onClick: abrirCuenta },
        icon('usuario', { size: 18 })
      ),
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

/**
 * El service worker: instalable y con la aplicación y la carta en caché.
 *
 * Se registra desde aquí y no con un `<script>` suelto en el HTML: así queda
 * junto al resto del arranque y bajo la misma comprobación.
 *
 * Y esa comprobación no es de cortesía: `navigator.serviceWorker` solo existe
 * en contexto seguro, y una tableta que entra por http://192.168.x.x no lo
 * es. Ahí la aplicación sigue funcionando, solo que sin caché ni instalación.
 */
function registrarServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch((error) => {
    // Que no se pueda instalar no puede dejar sin aplicación a nadie.
    console.warn('No se pudo registrar el service worker', error);
  });
}

async function arrancar() {
  router.define(RUTAS);

  try {
    await session.load();
  } catch {
    // Token vencido o revocado: se sigue sin sesión y el enrutador manda a ingresar.
    session.forget();
  }

  registrarServiceWorker();

  // Lo que quedó sin enviar se reintenta solo, y el contador se repinta con
  // la estructura: aparece y desaparece sin que la vista tenga que saberlo.
  cola.suscribir(() => pintarEstructura(rutaMontada));
  cola.arrancar();

  router.start(outlet, pintarEstructura);

  if (!session.me() && router.current() !== 'ingresar') {
    router.go('ingresar', { replace: true });
  }
}

arrancar().catch((error) => toast(error.message));
