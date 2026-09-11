// El mapa del salón.
//
// Para un restaurante de mesa es la pantalla principal: quien atiende no
// piensa en "mesa M7", piensa en la del rincón. Por eso las mesas se dibujan
// donde están y no en una lista alfabética.
//
// Existe solo si el restaurante maneja mesas (`uses_tables`), por
// configuración y no por un condicional sobre el tenant. Y lo que decide si
// una mesa está ocupada es la categoría del estado de su pedido, que llega
// resuelta del backend: una pantalla que lo dedujera de códigos se rompería
// en el primer restaurante que renombre sus estados.

import { api } from '../api.js';
import { money, time } from '../format.js';
import { icon } from '../icons.js';
import * as router from '../router.js';
import { activeBranch, can, me } from '../session.js';
import { button, empty, errorBox, h, pageHeader, render, skeleton, toast } from '../ui.js';
import { abrirPedido } from './pedido-detalle.js';

/** Cada cuánto se repinta el salón. Igual que el KDS: nadie recarga a mano. */
const REFRESCO_MS = 20000;

/** El lado de una casilla del plano, en píxeles. La coordenada no tiene unidad. */
const CASILLA = 108;

export async function salon(outlet) {
  const plano = h('div');
  const marca = h('span', { class: 'flex items-center gap-1.5 text-xs text-stone-500' });

  let editando = false;
  let mesas = [];
  let vivo = true;
  // El filtro de "mis mesas" no se recuerda entre visitas: a diferencia de
  // la estación del KDS —que es de la tableta— esto es de quien mira ahora,
  // y encontrarse el salón filtrado por lo que eligió el turno anterior es
  // ver mesas libres que no lo están.
  let soloMias = false;

  const mias = button('Mis mesas', {
    variant: 'secondary',
    iconName: 'usuario',
    onClick: () => {
      soloMias = !soloMias;
      mias.setAttribute('aria-pressed', String(soloMias));
      render(mias, icon(soloMias ? 'check' : 'usuario', { size: 16 }), h('span', {}, 'Mis mesas'));
      pintar();
    },
  });
  mias.setAttribute('aria-pressed', 'false');

  const puedeColocar = can('branches.manage');
  const editar = button('Acomodar mesas', {
    variant: 'secondary',
    onClick: () => {
      editando = !editando;
      render(
        editar,
        icon(editando ? 'check' : 'admin', { size: 16 }),
        h('span', {}, editando ? 'Listo' : 'Acomodar mesas')
      );
      pintar();
    },
  });

  render(
    outlet,
    pageHeader(`Salón de ${activeBranch()?.name ?? 'la sucursal'}`, {
      hint: 'Toca una mesa para abrir su cuenta o empezar una nueva.',
      actions: [marca, mias, puedeColocar ? editar : null],
    }),
    plano
  );
  render(plano, skeleton({ rows: 2 }));

  await refrescar();
  const temporizador = setInterval(refrescar, REFRESCO_MS);

  async function refrescar() {
    if (!vivo) return;
    try {
      mesas = await api.get(`/branches/${activeBranch()?.id}/tables/status`);
    } catch (error) {
      if (vivo) render(plano, errorBox(error.message, refrescar));
      return;
    }
    if (!vivo) return;
    render(marca, icon('reloj', { size: 14 }), `Actualizado a las ${time(new Date().toISOString())}`);
    pintar();
  }

  function pintar() {
    const activas = mesas.filter((m) => m.is_active);
    if (!activas.length) {
      return render(
        plano,
        h(
          'div',
          { class: 'seccion' },
          empty(
            'Todavía no hay mesas',
            'Créalas en Administración › Sucursales y aparecerán aquí para acomodarlas.',
            can('branches.manage')
              ? button('Ir a Administración', { variant: 'secondary', onClick: () => router.go('admin') })
              : null,
            'admin'
          )
        )
      );
    }

    const ancho = Math.max(...activas.map((m) => m.pos_x)) + 1;
    const alto = Math.max(...activas.map((m) => m.pos_y)) + 1;

    render(
      plano,
      h(
        'div',
        { class: 'seccion p-4 overflow-x-auto' },
        h(
          'div',
          {
            class: 'relative mx-auto',
            style: `width:${ancho * CASILLA}px;height:${alto * CASILLA}px;min-width:${ancho * CASILLA}px`,
          },
          activas.map(mesaEnPlano)
        )
      ),
      editando
        ? h(
            'p',
            { class: 'text-[13px] text-stone-500 mt-2' },
            'Arrastra las mesas para colocarlas como están en el salón. Se guardan solas.'
          )
        : null
    );
  }

  function mesaEnPlano(mesa) {
    const ocupada = mesa.order_id !== null;
    // Con el filtro puesto las demás se atenúan en vez de desaparecer: el
    // salón es un plano, y un plano con huecos deja de parecerse al salón.
    const ajena = soloMias && ocupada && mesa.server_id !== me()?.user_id;
    const tono = ocupada
      ? 'bg-amber-50 border-amber-300 text-amber-900'
      : 'bg-[--panel] border-[--linea] text-stone-500';

    const nodo = h(
      'button',
      {
        class: `absolute flex flex-col items-center justify-center gap-0.5 border text-center transition-colors
                ${mesa.shape === 'round' ? 'rounded-full' : 'rounded-xl'} ${tono}
                ${editando ? 'cursor-move' : 'hover:border-amber-400'} ${ajena ? 'opacity-40' : ''}`,
        style: `left:${mesa.pos_x * CASILLA}px;top:${mesa.pos_y * CASILLA}px;width:${CASILLA - 12}px;height:${CASILLA - 12}px`,
        'aria-label': ocupada
          ? `Mesa ${mesa.code}, ocupada hace ${mesa.occupied_minutes} minutos, ${money(mesa.total)}` +
            (mesa.server_name ? `, atiende ${mesa.server_name}` : '')
          : `Mesa ${mesa.code}, libre`,
        onClick: () => (editando ? null : tocar(mesa)),
      },
      h('span', { class: 'text-[15px] font-semibold' }, mesa.code),
      ocupada
        ? h('span', { class: 'text-[12px] tabular-nums' }, money(mesa.total))
        : h('span', { class: 'text-[12px]' }, `${mesa.capacity} puestos`),
      ocupada ? h('span', { class: 'text-[11px]' }, `${mesa.occupied_minutes} min`) : null,
      // Quién atiende: es lo primero que se busca al mirar el salón de lejos.
      ocupada && mesa.server_name
        ? h('span', { class: 'text-[11px] truncate max-w-full px-1' }, mesa.server_name)
        : null,
      // Dos cuentas en la misma mesa se dicen: esconder una sería perderla.
      mesa.open_orders > 1
        ? h('span', { class: 'text-[11px] font-medium' }, `${mesa.open_orders} cuentas`)
        : null
    );

    if (editando && puedeColocar) arrastrable(nodo, mesa);
    return nodo;
  }

  /**
   * Arrastrar para colocar.
   *
   * Con eventos de puntero y no de ratón: la pantalla que más se usa para
   * esto es una tableta. La posición se redondea a la casilla más cercana,
   * así que el salón queda alineado sin que nadie apunte con precisión.
   */
  function arrastrable(nodo, mesa) {
    nodo.addEventListener('pointerdown', (inicio) => {
      inicio.preventDefault();
      nodo.setPointerCapture(inicio.pointerId);
      const x0 = mesa.pos_x * CASILLA;
      const y0 = mesa.pos_y * CASILLA;

      const mover = (evento) => {
        nodo.style.left = `${Math.max(0, x0 + evento.clientX - inicio.clientX)}px`;
        nodo.style.top = `${Math.max(0, y0 + evento.clientY - inicio.clientY)}px`;
      };

      const soltar = async (evento) => {
        nodo.removeEventListener('pointermove', mover);
        nodo.removeEventListener('pointerup', soltar);

        const x = Math.max(0, Math.round((x0 + evento.clientX - inicio.clientX) / CASILLA));
        const y = Math.max(0, Math.round((y0 + evento.clientY - inicio.clientY) / CASILLA));
        if (x === mesa.pos_x && y === mesa.pos_y) return pintar();

        mesa.pos_x = x;
        mesa.pos_y = y;
        pintar();
        try {
          await api.put(`/branches/${activeBranch()?.id}/tables/${mesa.id}/place`, {
            pos_x: x,
            pos_y: y,
            shape: mesa.shape,
          });
        } catch (error) {
          toast(error.message);
          await refrescar();
        }
      };

      nodo.addEventListener('pointermove', mover);
      nodo.addEventListener('pointerup', soltar);
    });
  }

  /** Tocar una mesa: su cuenta si la tiene, o una nueva con la mesa puesta. */
  function tocar(mesa) {
    if (mesa.order_id) {
      return abrirPedido(mesa.order_id, { alCambiar: refrescar });
    }
    if (!can('orders.create')) return;
    // La mesa viaja en el hash y la pantalla de venta la deja puesta: quien
    // toca una mesa libre no tiene que volver a escribir su código.
    router.go(`pedidos?mesa=${encodeURIComponent(mesa.code)}`);
  }

  return {
    destroy() {
      vivo = false;
      clearInterval(temporizador);
    },
  };
}

/** El salón existe solo si el restaurante maneja mesas. */
export const usaMesas = () => me()?.uses_tables === true;
