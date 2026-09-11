// El diálogo que pregunta por los modificadores de un producto.
//
// Vive aparte porque lo abren dos pantallas: la de venta al agregar al
// carrito, y el detalle del pedido al agregarle una línea a una cuenta ya
// abierta (F4.0). Importarlo desde `pedidos.js` habría creado un ciclo, que
// en módulos ES no falla pero deja variables sin inicializar según quién
// cargue primero.
//
// No es una segunda fuente de verdad: quien decide sigue siendo
// `Domain\ModifierValidation` al crear el pedido. Esto es la guía para no
// llegar al final con algo que se va a rechazar — el botón nace
// deshabilitado con un "Falta elegir: …" y las casillas se cierran al llegar
// al tope.

import { money } from '../format.js';
import { icon } from '../icons.js';
import { badge, button, clear, h, montarDialogo, render } from '../ui.js';

export function abrirModificadores(item, alConfirmar) {
  const cuerpo = h('div', { class: 'p-4 space-y-5' });
  const seleccion = new Map();
  // Las casillas de cada grupo, para poder cerrarlas al llegar a su máximo.
  const casillas = new Map();

  for (const grupo of item.modifier_groups) {
    const unico = grupo.max_select === 1;
    seleccion.set(grupo.id, new Set());
    casillas.set(grupo.id, []);

    cuerpo.append(
      h(
        'div',
        {},
        h(
          'div',
          { class: 'flex items-baseline justify-between mb-2 gap-2' },
          h('span', { class: 'font-medium text-sm text-stone-900' }, grupo.name),
          // La frase la escribe el dominio (`ModifierGroupRules::describe`) y
          // llega en `rule`: así la pantalla de venta y la de configuración
          // dicen exactamente lo mismo.
          h(
            'span',
            { class: 'text-xs shrink-0' },
            grupo.is_required ? badge(grupo.rule ?? 'Obligatorio', 'warn') : h('span', { class: 'text-stone-600' }, grupo.rule ?? `Hasta ${grupo.max_select}`)
          )
        ),
        h(
          'div',
          { class: 'space-y-0.5' },
          grupo.modifiers.map((m) => {
            const control = h('input', {
              type: unico ? 'radio' : 'checkbox',
              name: `g-${grupo.id}`,
              class: 'w-4 h-4 accent-stone-900',
              disabled: !m.is_available,
              onChange: (e) => {
                const elegidos = seleccion.get(grupo.id);
                if (unico) elegidos.clear();
                if (e.target.checked) elegidos.add(m);
                else elegidos.delete(m);
                revisar();
              },
            });
            casillas.get(grupo.id).push({ modificador: m, control });

            return h(
              'label',
              {
                class: `flex items-center gap-2.5 text-sm py-2 px-2 -mx-2 rounded-[--r] cursor-pointer hover:bg-stone-50 ${
                  m.is_available ? '' : 'opacity-40 cursor-not-allowed'
                }`,
              },
              control,
              h('span', { class: 'flex-1' }, m.name),
              Number(m.price_delta)
                ? h('span', { class: 'text-sm font-medium text-amber-800' }, `+ ${money(m.price_delta)}`)
                : null
            );
          })
        )
      )
    );
  }

  /**
   * Lo que falta para poder agregar.
   *
   * No es una segunda fuente de verdad —quien decide sigue siendo
   * `Domain\ModifierValidation` al crear el pedido— sino la guía para no
   * llegar hasta el final con algo que se va a rechazar. Enterarse al
   * confirmar, con el carrito lleno y alguien esperando, es la peor forma.
   */
  function faltantes() {
    return item.modifier_groups
      .filter((g) => {
        const cuantos = seleccion.get(g.id).size;
        return (g.is_required && cuantos === 0) || cuantos < g.min_select;
      })
      .map((g) => g.name);
  }

  function revisar() {
    // Al llegar al máximo se cierran las que quedan sin marcar, en vez de
    // dejar marcarlas y rechazarlo después. Los grupos de una sola son
    // radios: el navegador ya los limita.
    for (const grupo of item.modifier_groups) {
      if (grupo.max_select === 1) continue;
      const lleno = seleccion.get(grupo.id).size >= grupo.max_select;
      for (const { modificador, control } of casillas.get(grupo.id)) {
        control.disabled = !modificador.is_available || (lleno && !control.checked);
      }
    }

    const faltan = faltantes();
    agregar.disabled = faltan.length > 0;
    render(aviso, faltan.length ? `Falta elegir: ${faltan.join(', ')}.` : null);
  }

  const aviso = h('p', { class: 'text-[12.5px] text-amber-800 px-4 pb-1' });

  const agregar = button('Agregar', {
    iconName: 'mas',
    full: true,
    onClick: () => {
      alConfirmar([...seleccion.values()].flatMap((s) => [...s]));
      cerrar();
    },
  });

  let desmontar;
  const cerrar = () => desmontar();
  const overlay = h(
    'div',
    {
      class: 'fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-end sm:items-center justify-center p-0 sm:p-4',
      onClick: (e) => e.target === overlay && cerrar(),
    },
    h(
      'div',
      {
        class: 'aparece bg-[--panel] rounded-t-2xl sm:rounded-[--r-g] max-w-md w-full max-h-[85vh] overflow-y-auto shadow-xl',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-label': `Opciones de ${item.name}`,
      },
      h(
        'div',
        { class: 'p-4 border-b border-stone-200 flex items-center justify-between gap-2 sticky top-0 bg-[--panel]' },
        h('h3', { class: 'font-semibold text-stone-900' }, item.name),
        h(
          'button',
          { class: 'text-stone-400 hover:text-stone-900 p-1', onClick: cerrar, 'aria-label': 'Cerrar' },
          icon('cerrar', { size: 20 })
        )
      ),
      cuerpo,
      aviso,
      h(
        'div',
        { class: 'p-4 border-t border-stone-200 flex gap-2 sticky bottom-0 bg-[--panel]' },
        button('Cancelar', { variant: 'secondary', onClick: cerrar, full: true }),
        agregar
      )
    )
  );

  desmontar = montarDialogo(overlay, { alCerrar: cerrar });
  // El estado inicial también se calcula: con un grupo obligatorio, el botón
  // nace deshabilitado y el aviso dice qué falta.
  revisar();
}
