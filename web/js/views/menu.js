// Administración del menú.
//
// Trabaja sobre /menu/catalog, no sobre /menu: aquí hacen falta el precio
// base sin los ajustes por sucursal y también los productos archivados, que
// es justo lo contrario de lo que necesita la pantalla de venta.

import { api } from '../api.js';
import { money, percent } from '../format.js';
import { activeBranch, branchQuery, branches, can } from '../session.js';
import {
  badge, button, card, confirm, empty, errorBox, field, h, input, pageHeader, render, select, skeleton,
  tabs, titledCard, toast,
} from '../ui.js';

export async function menu(outlet) {
  render(outlet, skeleton({ rows: 3 }));

  let catalogo;
  let grupos = [];
  let impuestos = [];
  try {
    [catalogo, grupos] = await Promise.all([
      api.get(`/menu/catalog${branchQuery()}`),
      api.get('/menu/modifier-groups'),
    ]);
    if (can('settings.view')) impuestos = await api.get('/tax-rates');
  } catch (error) {
    return render(outlet, errorBox(error.message, () => menu(outlet)));
  }

  let verArchivados = false;
  const lista = h('div', { class: 'space-y-4' });

  const recargar = async () => {
    [catalogo, grupos] = await Promise.all([
      api.get(`/menu/catalog${branchQuery()}`),
      api.get('/menu/modifier-groups'),
    ]);
    pintar();
    if (pestana === 'opciones') pintarPanel();
  };

  // El precio por sucursal solo tiene sentido con más de una: en un local
  // único, "el precio de esta sede" y "el precio" son la misma cifra.
  const porSucursal = branches().length > 1 ? activeBranch() : null;

  function nombreImpuesto(id) {
    const impuesto = impuestos.find((t) => t.id === id);
    if (!id) return 'Exento';
    return impuesto ? `${impuesto.name} · ${percent(impuesto.rate)}` : 'Con impuesto';
  }

  function pintar() {
    if (!catalogo.categories.length) {
      return render(
        lista,
        card(empty('Todavía no hay categorías', 'Crea una arriba para empezar a armar el menú.'))
      );
    }

    render(
      lista,
      catalogo.categories.map((cat) => {
        const items = catalogo.items.filter(
          (i) => i.category_id === cat.id && (verArchivados || !i.is_archived)
        );
        return card(
          h(
            'div',
            { class: 'flex items-center gap-2 mb-3' },
            h('h2', { class: 'font-semibold text-stone-900' }, cat.name),
            !cat.is_active ? badge('Inactiva', 'warn') : null,
            h('span', { class: 'text-xs text-stone-500' }, `${items.length} producto${items.length === 1 ? '' : 's'}`)
          ),
          items.length
            ? h('div', { class: 'divide-y divide-stone-100' }, items.map(fila))
            : h('p', { class: 'text-sm text-stone-500' }, 'Sin productos en esta categoría.')
        );
      })
    );
  }

  function fila(item) {
    const precio = input({ type: 'number', min: '0', value: item.base_price, class: 'w-28 rounded-lg border border-stone-300 px-2 py-1.5 text-sm tabular-nums' });
    const impuesto = select(
      [
        { value: '', label: 'Exento', selected: !item.tax_rate_id },
        ...impuestos.map((t) => ({ value: t.id, label: t.name, selected: t.id === item.tax_rate_id })),
      ],
      { class: 'rounded-lg border border-stone-300 px-2 py-1.5 text-sm' }
    );

    // Vacío significa "esta sede no ajusta el precio", no cero: el producto
    // se vende al precio base. Es también la forma de deshacer un ajuste.
    const ajuste = item.branch_override?.price ?? null;
    const precioSucursal = porSucursal
      ? input({
          type: 'number',
          min: '0',
          value: ajuste ?? '',
          placeholder: 'Precio base',
          title: `Precio en ${porSucursal.name}`,
          class: 'w-32 rounded-lg border border-amber-300 bg-amber-50/40 px-2 py-1.5 text-sm tabular-nums',
        })
      : null;

    return h(
      'div',
      { class: `py-3 flex flex-wrap items-center gap-3 ${item.is_archived ? 'opacity-50' : ''}` },
      h(
        'div',
        { class: 'flex-1 min-w-[180px]' },
        h(
          'div',
          { class: 'font-medium text-sm text-stone-900 flex items-center gap-2' },
          item.name,
          item.is_archived ? badge('Archivado') : null,
          !item.is_available && !item.is_archived ? badge('Agotado', 'warn') : null
        ),
        h(
          'div',
          { class: 'text-xs text-stone-500' },
          `${money(item.base_price)} · ${nombreImpuesto(item.tax_rate_id)}`,
          porSucursal && ajuste !== null
            ? h('span', { class: 'text-amber-800' }, ` · en ${porSucursal.name} ${money(ajuste)}`)
            : null
        )
      ),
      precio,
      precioSucursal,
      impuesto,
      button('Guardar', {
        variant: 'secondary',
        onClick: async (e) => {
          // `currentTarget` se guarda antes del primer `await`: el navegador lo deja
          // en null en cuanto termina el despacho del evento, y sin esto el `catch`
          // no podria volver a habilitar el boton.
          const boton = e.currentTarget;
          boton.disabled = true;
          try {
            await api.patch(`/menu/items/${item.id}`, {
              base_price: Number(precio.value),
              tax_rate_id: impuesto.value || null,
            });
            // El ajuste de sucursal solo se escribe si cambió: así una
            // corrección del precio base no crea un override en la sede que
            // se esté mirando.
            if (precioSucursal) {
              const nuevo = precioSucursal.value.trim() === '' ? null : Number(precioSucursal.value);
              if (nuevo !== (ajuste === null ? null : Number(ajuste))) {
                await api.put(`/menu/items/${item.id}/branch-override${branchQuery()}`, {
                  price: nuevo,
                  // Se conserva lo que la sucursal ya decía de la
                  // disponibilidad: aquí solo se está tocando el precio.
                  is_available: item.branch_override?.is_available ?? null,
                });
              }
            }
            toast('Producto actualizado', 'ok');
            await recargar();
          } catch (error) {
            toast(error.message);
            boton.disabled = false;
          }
        },
      }),
      !item.is_archived
        ? button(item.is_available ? 'Marcar agotado' : 'Reactivar', {
            variant: 'secondary',
            onClick: async () => {
              try {
                await api.patch(`/menu/items/${item.id}/availability`, { is_available: !item.is_available });
                await recargar();
              } catch (error) {
                toast(error.message);
              }
            },
          })
        : null,
      !item.is_archived
        ? button(`Opciones · ${item.modifier_group_ids.length}`, {
            variant: 'secondary',
            onClick: () => abrirGruposDelProducto(item),
          })
        : null,
      button(item.is_archived ? 'Desarchivar' : 'Archivar', {
        variant: 'secondary',
        onClick: async () => {
          if (
            !item.is_archived &&
            !(await confirm({
              title: `¿Archivar “${item.name}”?`,
              message:
                'Deja de aparecer en el menú y no se puede volver a pedir. Los pedidos anteriores conservan su precio y su nombre.',
              confirmLabel: 'Archivar',
            }))
          ) {
            return;
          }
          try {
            await api.patch(`/menu/items/${item.id}`, { is_archived: !item.is_archived });
            await recargar();
          } catch (error) {
            toast(error.message);
          }
        },
      })
    );
  }


  // =========================================================
  // Opciones (grupos de modificadores)
  // =========================================================

  /**
   * Elegir qué grupos tiene un producto.
   *
   * El orden de la lista es el orden en que se van a pedir —primero el
   * término de la carne, después las adiciones—, así que se manda el mismo
   * que se ve, y el backend lo guarda en `sort_order`.
   */
  function abrirGruposDelProducto(item) {
    if (!grupos.length) {
      return toast('Todavía no hay grupos de opciones: créalos en la pestaña Opciones', 'warn');
    }

    const casillas = grupos.map((g) => ({
      grupo: g,
      control: h('input', {
        type: 'checkbox',
        class: 'w-4 h-4 rounded border-stone-300 mt-0.5 shrink-0',
        checked: item.modifier_group_ids.includes(g.id),
      }),
    }));

    const cerrar = () => overlay.remove();
    const guardar = button('Guardar', {
      onClick: async () => {
        guardar.disabled = true;
        try {
          await api.put(`/menu/items/${item.id}/modifier-groups`, {
            group_ids: casillas.filter((c) => c.control.checked).map((c) => c.grupo.id),
          });
          cerrar();
          toast('Opciones del producto actualizadas', 'ok');
          await recargar();
        } catch (error) {
          toast(error.message);
          guardar.disabled = false;
        }
      },
    });

    const overlay = h(
      'div',
      {
        class: 'fixed inset-0 z-50 bg-stone-900/30 flex items-center justify-center p-4',
        onClick: (e) => e.target === overlay && cerrar(),
      },
      h(
        'div',
        {
          class: 'aparece bg-white rounded-[--r-g] max-w-md w-full p-5 shadow-xl border border-[--linea] max-h-[80vh] overflow-y-auto',
          role: 'dialog',
          'aria-modal': 'true',
        },
        h('h3', { class: 'text-[15px] font-semibold' }, `Opciones de “${item.name}”`),
        h(
          'p',
          { class: 'text-[13px] text-stone-500 mt-1 mb-3' },
          'Al pedir este producto se preguntará por cada grupo marcado, en este orden.'
        ),
        h(
          'div',
          { class: 'space-y-2' },
          casillas.map(({ grupo, control }) =>
            h(
              'label',
              { class: 'flex items-start gap-2 text-sm cursor-pointer' },
              control,
              h(
                'span',
                {},
                h('span', { class: 'font-medium' }, grupo.name),
                h('span', { class: 'text-stone-500' }, ` · ${grupo.rule}`),
                h(
                  'span',
                  { class: 'block text-[12px] text-stone-400' },
                  grupo.modifiers.length
                    ? grupo.modifiers.map((m) => m.name).join(', ')
                    : 'Sin opciones todavía'
                )
              )
            )
          )
        ),
        h(
          'div',
          { class: 'flex justify-end gap-2 mt-5' },
          button('Cancelar', { variant: 'secondary', onClick: cerrar }),
          guardar
        )
      )
    );

    document.body.append(overlay);
  }

  /** Una opción del grupo: nombre, cuánto suma o resta, y si se ofrece. */
  function filaOpcion(opcion) {
    const nombre = input({ value: opcion.name, class: 'campo flex-1 min-w-[140px]' });
    // Puede ser negativo: "sin queso" descuenta.
    const precio = input({ type: 'number', value: opcion.price_delta, class: 'campo w-28 tabular-nums' });
    const disponible = h('input', {
      type: 'checkbox',
      class: 'w-4 h-4 rounded border-stone-300',
      checked: opcion.is_available,
    });

    return h(
      'div',
      { class: 'py-2 flex flex-wrap items-center gap-2' },
      nombre,
      precio,
      h('label', { class: 'flex items-center gap-1.5 text-[13px] text-stone-600' }, disponible, 'Se ofrece'),
      button('Guardar', {
        variant: 'secondary',
        onClick: async (e) => {
          const boton = e.currentTarget;
          boton.disabled = true;
          try {
            await api.patch(`/menu/modifiers/${opcion.id}`, {
              name: nombre.value.trim(),
              price_delta: Number(precio.value || 0),
              is_available: disponible.checked,
            });
            toast('Opción actualizada', 'ok');
            await recargar();
          } catch (error) {
            toast(error.message);
            boton.disabled = false;
          }
        },
      }),
      button('Borrar', {
        variant: 'secondary',
        onClick: async () => {
          try {
            await api.delete(`/menu/modifiers/${opcion.id}`);
            await recargar();
          } catch (error) {
            // Una opción ya vendida no se borra, y el backend explica por qué.
            toast(error.message);
          }
        },
      })
    );
  }

  function tarjetaGrupo(grupo) {
    const nombre = input({ value: grupo.name, class: 'campo flex-1 min-w-[160px]' });
    const minimo = input({ type: 'number', min: '0', value: grupo.min_select, class: 'campo w-20 tabular-nums' });
    const maximo = input({ type: 'number', min: '1', value: grupo.max_select, class: 'campo w-20 tabular-nums' });
    const obligatorio = h('input', {
      type: 'checkbox',
      class: 'w-4 h-4 rounded border-stone-300',
      checked: grupo.is_required,
      // Obligatorio con mínimo 0 se contradice y el backend lo rechaza: la
      // casilla sube el mínimo para que no haya que adivinarlo.
      onChange: (e) => {
        if (e.target.checked && Number(minimo.value || 0) < 1) minimo.value = '1';
      },
    });

    const nuevaOpcion = input({ placeholder: 'Ej. Tres cuartos', class: 'campo flex-1 min-w-[140px]' });
    const nuevoPrecio = input({ type: 'number', value: '0', class: 'campo w-28 tabular-nums' });

    const sinOfrecer = grupo.is_required && !grupo.modifiers.some((m) => m.is_available);

    return card(
      h(
        'div',
        { class: 'flex flex-wrap items-end gap-2 mb-3' },
        h('div', { class: 'flex-1 min-w-[160px]' }, field('Nombre del grupo', nombre)),
        h('div', {}, field('Mínimo', minimo)),
        h('div', {}, field('Máximo', maximo)),
        h('label', { class: 'flex items-center gap-1.5 text-[13px] text-stone-600 pb-2' }, obligatorio, 'Obligatorio'),
        button('Guardar', {
          variant: 'secondary',
          onClick: async (e) => {
            const boton = e.currentTarget;
            boton.disabled = true;
            try {
              await api.patch(`/menu/modifier-groups/${grupo.id}`, {
                name: nombre.value.trim(),
                min_select: Number(minimo.value || 0),
                max_select: Number(maximo.value || 1),
                is_required: obligatorio.checked,
              });
              toast('Grupo actualizado', 'ok');
              await recargar();
            } catch (error) {
              toast(error.message);
              boton.disabled = false;
            }
          },
        }),
        button('Borrar grupo', {
          variant: 'secondary',
          onClick: async () => {
            try {
              await api.delete(`/menu/modifier-groups/${grupo.id}`);
              toast('Grupo borrado', 'ok');
              await recargar();
            } catch (error) {
              // En uso o ya vendido: el backend dice cuál de las dos.
              toast(error.message);
            }
          },
        })
      ),

      h(
        'div',
        { class: 'flex flex-wrap items-center gap-2 mb-2' },
        badge(grupo.rule, grupo.is_required ? 'warn' : 'neutral'),
        h(
          'span',
          { class: 'text-[12.5px] text-stone-500' },
          grupo.used_by_items === 0
            ? 'Sin asignar a ningún producto'
            : `Lo usan ${grupo.used_by_items} producto${grupo.used_by_items === 1 ? '' : 's'}`
        )
      ),

      // Un grupo obligatorio sin nada que elegir vuelve impedible cualquier
      // producto que lo tenga, y el error saldría en el mostrador.
      sinOfrecer
        ? h(
            'p',
            { class: 'text-[12.5px] text-amber-800 bg-amber-50 border border-amber-200 rounded-[--r] px-2 py-1.5 mb-2' },
            'Es obligatorio y no tiene ninguna opción que se ofrezca: mientras siga así, los productos con este grupo no se pueden pedir.'
          )
        : null,

      grupo.modifiers.length
        ? h('div', { class: 'divide-y divide-stone-100' }, grupo.modifiers.map(filaOpcion))
        : h('p', { class: 'text-sm text-stone-500 py-2' }, 'Todavía no tiene opciones.'),

      h(
        'div',
        { class: 'flex flex-wrap items-end gap-2 pt-3 mt-1 border-t border-stone-100' },
        h('div', { class: 'flex-1 min-w-[140px]' }, field('Nueva opción', nuevaOpcion)),
        h('div', {}, field('Suma o resta', nuevoPrecio)),
        button('Agregar', {
          variant: 'secondary',
          onClick: async () => {
            try {
              await api.post(`/menu/modifier-groups/${grupo.id}/modifiers`, {
                name: nuevaOpcion.value.trim(),
                price_delta: Number(nuevoPrecio.value || 0),
              });
              nuevaOpcion.value = '';
              nuevoPrecio.value = '0';
              await recargar();
            } catch (error) {
              toast(error.message);
            }
          },
        })
      )
    );
  }

  const grupoNombre = input({ placeholder: 'Ej. Término de la carne' });
  const grupoMinimo = input({ type: 'number', min: '0', value: '0' });
  const grupoMaximo = input({ type: 'number', min: '1', value: '1' });
  const grupoObligatorio = h('input', {
    type: 'checkbox',
    class: 'w-4 h-4 rounded border-stone-300',
    onChange: (e) => {
      if (e.target.checked && Number(grupoMinimo.value || 0) < 1) grupoMinimo.value = '1';
    },
  });

  function panelOpciones() {
    return [
      titledCard(
        'Nuevo grupo de opciones',
        h(
          'div',
          { class: 'flex flex-wrap items-end gap-3' },
          h('div', { class: 'flex-1 min-w-[200px]' }, field('Nombre', grupoNombre)),
          h('div', { class: 'w-24' }, field('Mínimo', grupoMinimo)),
          h('div', { class: 'w-24' }, field('Máximo', grupoMaximo)),
          h('label', { class: 'flex items-center gap-1.5 text-[13px] text-stone-600 pb-2' }, grupoObligatorio, 'Obligatorio'),
          button('Crear grupo', {
            onClick: async () => {
              try {
                await api.post('/menu/modifier-groups', {
                  name: grupoNombre.value.trim(),
                  min_select: Number(grupoMinimo.value || 0),
                  max_select: Number(grupoMaximo.value || 1),
                  is_required: grupoObligatorio.checked,
                });
                grupoNombre.value = '';
                toast('Grupo creado', 'ok');
                await recargar();
              } catch (error) {
                toast(error.message);
              }
            },
          })
        ),
        h(
          'p',
          { class: 'text-[12.5px] text-stone-500 mt-2' },
          'Un grupo agrupa opciones de un mismo producto: el término de la carne, el tamaño, las adiciones. Después se asigna a los productos que lo usen.'
        )
      ),

      ...(grupos.length
        ? grupos.map(tarjetaGrupo)
        : [
            card(
              empty(
                'Todavía no hay grupos de opciones',
                'Crea uno arriba y asígnalo a los productos que lo necesiten.',
                null,
                'etiqueta'
              )
            ),
          ]),
    ];
  }

  // ---------- formularios de alta ----------

  const catNombre = input({ placeholder: 'Ej. Hamburguesas' });
  const catOrden = input({ type: 'number', value: '0' });

  const itemCategoria = select([]);
  const itemNombre = input({ placeholder: 'Ej. Hamburguesa clásica' });
  const itemPrecio = input({ type: 'number', min: '0', placeholder: '18000' });
  const itemImpuesto = select([]);
  const itemDescripcion = input({ placeholder: 'Descripción (opcional)' });

  function refrescarSelects() {
    render(
      itemCategoria,
      catalogo.categories.map((c) => h('option', { value: c.id }, c.name))
    );
    render(
      itemImpuesto,
      h('option', { value: '' }, 'Impuesto por defecto del restaurante'),
      impuestos.map((t) => h('option', { value: t.id }, t.name))
    );
  }

  function panelProductos() {
    return [
      titledCard(
        'Nueva categoría',
        h(
          'div',
          { class: 'flex flex-wrap gap-2 items-end' },
          h('div', { class: 'flex-1 min-w-[200px]' }, field('Nombre', catNombre)),
          h('div', { class: 'w-28' }, field('Orden', catOrden)),
          button('Crear categoría', {
            onClick: async (e) => {
              try {
                await api.post('/menu/categories', {
                  name: catNombre.value.trim(),
                  sort_order: Number(catOrden.value || 0),
                });
                catNombre.value = '';
                toast('Categoría creada', 'ok');
                await recargar();
                refrescarSelects();
              } catch (error) {
                toast(error.message);
              }
            },
          })
        )
      ),

      titledCard(
        'Nuevo producto',
        h(
          'div',
          { class: 'grid grid-cols-1 sm:grid-cols-2 gap-3' },
          field('Categoría', itemCategoria),
          field('Nombre', itemNombre),
          field('Precio base', itemPrecio),
          field('Impuesto', itemImpuesto, 'Si no eliges, hereda el impuesto por defecto del restaurante.'),
          h('div', { class: 'sm:col-span-2' }, field('Descripción', itemDescripcion))
        ),
        h(
          'div',
          { class: 'mt-3' },
          button('Crear producto', {
            onClick: async () => {
              try {
                await api.post('/menu/items', {
                  category_id: itemCategoria.value,
                  name: itemNombre.value.trim(),
                  base_price: Number(itemPrecio.value || 0),
                  description: itemDescripcion.value.trim() || null,
                  tax_rate_id: itemImpuesto.value || null,
                });
                itemNombre.value = '';
                itemPrecio.value = '';
                itemDescripcion.value = '';
                toast('Producto creado', 'ok');
                await recargar();
              } catch (error) {
                toast(error.message);
              }
            },
          })
        )
      ),

      h(
        'label',
        { class: 'flex items-center gap-2 text-sm text-stone-600 px-1' },
        h('input', {
          type: 'checkbox',
          class: 'w-4 h-4 rounded border-stone-300',
          checked: verArchivados,
          onChange: (e) => {
            verArchivados = e.target.checked;
            pintar();
          },
        }),
        'Mostrar productos archivados'
      ),

      lista,
    ];
  }

  // ---------- armado ----------

  // Dos pestañas y no una pantalla sola: los grupos de opciones se tocan una
  // vez cada varios meses y los precios todos los días. Mezclarlos dejaría lo
  // frecuente debajo de lo raro.
  const PESTANAS = [
    { key: 'productos', label: 'Productos' },
    { key: 'opciones', label: 'Opciones' },
  ];
  let pestana = 'productos';
  const barra = h('div');
  const panel = h('div', { class: 'space-y-4' });

  function pintarPestanas() {
    render(
      barra,
      tabs(PESTANAS, pestana, (clave) => {
        pestana = clave;
        pintarPestanas();
        pintarPanel();
      })
    );
  }

  function pintarPanel() {
    render(panel, ...(pestana === 'productos' ? panelProductos() : panelOpciones()));
  }

  render(
    outlet,
    h(
      'div',
      { class: 'space-y-4' },
      pageHeader('Menú', {
        hint: porSucursal
          ? `Lo que aquí cambies se refleja de inmediato en la pantalla de venta. La columna ámbar es el precio en ${porSucursal.name}: vacía, se vende al precio base.`
          : 'Lo que aquí cambies se refleja de inmediato en la pantalla de venta.',
      }),
      barra,
      panel
    )
  );

  pintarPestanas();
  pintarPanel();

  refrescarSelects();
  pintar();
}
