// Administración del menú.
//
// Trabaja sobre /menu/catalog, no sobre /menu: aquí hacen falta el precio
// base sin los ajustes por sucursal y también los productos archivados, que
// es justo lo contrario de lo que necesita la pantalla de venta.

import { api } from '../api.js';
import { money, percent } from '../format.js';
import { activeBranch, branchQuery, branches, can } from '../session.js';
import {
  badge, button, card, confirm, empty, errorBox, field, h, input, pageHeader, render, select, skeleton, titledCard, toast,
} from '../ui.js';

export async function menu(outlet) {
  render(outlet, skeleton({ rows: 3 }));

  let catalogo;
  let impuestos = [];
  try {
    catalogo = await api.get(`/menu/catalog${branchQuery()}`);
    if (can('settings.view')) impuestos = await api.get('/tax-rates');
  } catch (error) {
    return render(outlet, errorBox(error.message, () => menu(outlet)));
  }

  let verArchivados = false;
  const lista = h('div', { class: 'space-y-4' });

  const recargar = async () => {
    catalogo = await api.get(`/menu/catalog${branchQuery()}`);
    pintar();
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
          e.currentTarget.disabled = true;
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
      pageHeader('Menú', {
        hint: porSucursal
          ? `Lo que aquí cambies se refleja de inmediato en la pantalla de venta. La columna ámbar es el precio en ${porSucursal.name}: vacía, se vende al precio base.`
          : 'Lo que aquí cambies se refleja de inmediato en la pantalla de venta.',
      }),

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
