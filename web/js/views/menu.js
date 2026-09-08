// Administración del menú.
//
// Trabaja sobre /menu/catalog, no sobre /menu: aquí hacen falta el precio
// base sin los ajustes por sucursal y también los productos archivados, que
// es justo lo contrario de lo que necesita la pantalla de venta.

import { api } from '../api.js';
import { money, percent } from '../format.js';
import { can } from '../session.js';
import {
  badge, button, card, confirm, empty, errorBox, field, h, input, loading, render, select, titledCard, toast,
} from '../ui.js';

export async function menu(outlet) {
  render(outlet, loading('Cargando el catálogo…'));

  let catalogo;
  let impuestos = [];
  try {
    catalogo = await api.get('/menu/catalog');
    if (can('settings.view')) impuestos = await api.get('/tax-rates');
  } catch (error) {
    return render(outlet, errorBox(error.message, () => menu(outlet)));
  }

  let verArchivados = false;
  const lista = h('div', { class: 'space-y-4' });

  const recargar = async () => {
    catalogo = await api.get('/menu/catalog');
    pintar();
  };

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
            h('h2', { class: 'font-semibold text-slate-900' }, cat.name),
            !cat.is_active ? badge('Inactiva', 'warn') : null,
            h('span', { class: 'text-xs text-slate-500' }, `${items.length} producto${items.length === 1 ? '' : 's'}`)
          ),
          items.length
            ? h('div', { class: 'divide-y divide-slate-100' }, items.map(fila))
            : h('p', { class: 'text-sm text-slate-500' }, 'Sin productos en esta categoría.')
        );
      })
    );
  }

  function fila(item) {
    const precio = input({ type: 'number', min: '0', value: item.base_price, class: 'w-28 rounded-lg border border-slate-300 px-2 py-1.5 text-sm tabular-nums' });
    const impuesto = select(
      [
        { value: '', label: 'Exento', selected: !item.tax_rate_id },
        ...impuestos.map((t) => ({ value: t.id, label: t.name, selected: t.id === item.tax_rate_id })),
      ],
      { class: 'rounded-lg border border-slate-300 px-2 py-1.5 text-sm' }
    );

    return h(
      'div',
      { class: `py-3 flex flex-wrap items-center gap-3 ${item.is_archived ? 'opacity-50' : ''}` },
      h(
        'div',
        { class: 'flex-1 min-w-[180px]' },
        h(
          'div',
          { class: 'font-medium text-sm text-slate-900 flex items-center gap-2' },
          item.name,
          item.is_archived ? badge('Archivado') : null,
          !item.is_available && !item.is_archived ? badge('Agotado', 'warn') : null
        ),
        h('div', { class: 'text-xs text-slate-500' }, `${money(item.base_price)} · ${nombreImpuesto(item.tax_rate_id)}`)
      ),
      precio,
      impuesto,
      button('Guardar', {
        variant: 'secondary',
        onClick: async (e) => {
          e.currentTarget.disabled = true;
          try {
            await api.patch(`/menu/items/${item.id}`, {
              base_price: Number(precio.value),
              tax_rate_id: impuesto.value || null,
            });
            toast('Producto actualizado', 'ok');
            await recargar();
          } catch (error) {
            toast(error.message);
            e.currentTarget.disabled = false;
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

  render(
    outlet,
    h(
      'div',
      { class: 'space-y-4' },
      h('h1', { class: 'text-lg font-semibold text-slate-900' }, 'Menú'),

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
        { class: 'flex items-center gap-2 text-sm text-slate-600 px-1' },
        h('input', {
          type: 'checkbox',
          class: 'w-4 h-4 rounded border-slate-300',
          onChange: (e) => {
            verArchivados = e.target.checked;
            pintar();
          },
        }),
        'Mostrar productos archivados'
      ),

      lista
    )
  );

  refrescarSelects();
  pintar();
}
