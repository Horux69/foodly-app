// Toma de pedidos y caja del mostrador.
//
// Es la pantalla que más se usa y suele tocarse en una tableta, de pie: los
// productos son objetivos grandes, el total está siempre visible y lo que el
// restaurante no usa (mesas, propina) no aparece, porque lo dice su
// configuración y no un condicional en el código.

import { api, uuid } from '../api.js';
import { money, moneyExact } from '../format.js';
import { icon } from '../icons.js';
import { branchQuery, me } from '../session.js';
import { badge, button, card, empty, errorBox, h, input, render, section, skeleton, tabs, toast } from '../ui.js';
import { canal } from './cocina.js';

export async function pedidos(outlet) {
  const contenido = h('div');
  const barra = h('div');
  let activa = 'nuevo';

  const ITEMS = [
    { key: 'nuevo', label: 'Nuevo pedido' },
    { key: 'dia', label: 'Pedidos del día' },
  ];

  function pintarPestanas() {
    render(
      barra,
      tabs(ITEMS, activa, (clave) => {
        activa = clave;
        pintarPestanas();
        pintar();
      })
    );
  }

  const pintar = () => (activa === 'nuevo' ? vistaNuevo(contenido) : vistaDelDia(contenido));

  render(outlet, barra, contenido);
  pintarPestanas();
  pintar();
}

// =========================================================
// Nuevo pedido
// =========================================================

async function vistaNuevo(host) {
  render(host, skeleton({ rows: 2 }));

  let menu;
  try {
    menu = await api.get(`/menu${branchQuery()}`);
  } catch (error) {
    return render(host, errorBox(error.message, () => vistaNuevo(host)));
  }

  const contexto = me();
  const carrito = [];
  let filtro = '';

  // Llave de idempotencia del intento en curso. Vive mientras el pedido no
  // se confirme: si la respuesta se pierde por red lenta y el cajero vuelve
  // a tocar, el backend reconoce la llave y devuelve el pedido que ya creó
  // en vez de crear un segundo. Se renueva recién al confirmarse, que es
  // cuando empieza otro pedido.
  let intento = uuid();

  const panelMenu = h('div', { class: 'space-y-4' });

  const buscador = input({
    type: 'search',
    placeholder: 'Buscar un producto…',
    class: 'campo pl-10',
    oninput: (e) => {
      filtro = e.target.value.trim().toLowerCase();
      pintarMenu();
    },
  });

  // --- canal: botones visibles, no un desplegable donde se olvida ---
  let canalActivo = contexto.channels[0];
  const selectorCanal = h('div', { class: 'flex flex-wrap gap-1.5' });
  function pintarCanal() {
    render(
      selectorCanal,
      contexto.channels.map((c) =>
        h(
          'button',
          {
            class: `px-3 py-1.5 rounded-lg text-sm font-medium border transition ${
              c === canalActivo
                ? 'bg-stone-900 text-white border-stone-900'
                : 'bg-white text-stone-600 border-stone-300 hover:border-stone-900'
            }`,
            onClick: () => {
              canalActivo = c;
              pintarCanal();
              recalcular();
            },
          },
          canal(c)
        )
      )
    );
  }

  const mesa = input({ placeholder: 'Ej. M1' });
  const telefono = input({ placeholder: 'Teléfono', type: 'tel' });
  const nombre = input({ placeholder: 'Nombre del cliente' });
  const domicilio = input({ type: 'number', min: '0', value: '0' });
  const descuento = input({ type: 'number', min: '0', value: '0' });
  const propina = input({ type: 'number', min: '0', value: '0' });
  const notas = h('textarea', { rows: '2', placeholder: 'Notas para la cocina', class: 'campo' });

  [domicilio, descuento, propina].forEach((el) => el.addEventListener('change', recalcular));

  const lineas = h('div', { class: 'divide-y divide-stone-100' });
  const totales = h('div', { class: 'space-y-1.5 text-sm' });
  const contador = h('span');
  const crear = button('Crear pedido', { onClick: enviar, iconName: 'check', full: true });

  // ---------- menú ----------

  function pintarMenu() {
    const categorias = menu
      .map((cat) => ({ ...cat, items: cat.items.filter((i) => !filtro || i.name.toLowerCase().includes(filtro)) }))
      .filter((cat) => cat.items.length);

    if (!menu.length) {
      return render(
        panelMenu,
        h(
          'div',
          { class: 'seccion' },
          empty(
            'Este restaurante todavía no tiene menú',
            'Agrega categorías y productos desde la pantalla Menú para empezar a vender.',
            button('Ir al menú', { variant: 'secondary', iconName: 'menu', onClick: () => (location.hash = '#/menu') }),
            'menu'
          )
        )
      );
    }
    if (!categorias.length) {
      return render(
        panelMenu,
        h('div', { class: 'seccion' }, empty('Ningún producto coincide', `No encontramos “${filtro}”.`, null, 'buscar'))
      );
    }

    render(
      panelMenu,
      categorias.map((cat) =>
        card(
          h(
            'div',
            { class: 'flex items-center gap-2 mb-3' },
            h('h2', { class: 'font-semibold text-stone-900' }, cat.name),
            h('span', { class: 'text-xs text-stone-400' }, `${cat.items.length}`)
          ),
          h('div', { class: 'grid grid-cols-2 sm:grid-cols-3 gap-2' }, cat.items.map(botonProducto))
        )
      )
    );
  }

  function botonProducto(item) {
    return h(
      'button',
      { class: 'producto', disabled: !item.is_available, onClick: () => elegir(item) },
      h(
        'div',
        {},
        h('div', { class: 'font-medium text-sm text-stone-900 leading-snug' }, item.name),
        item.modifier_groups.length
          ? h('div', { class: 'text-[11px] text-stone-400 mt-0.5' }, 'Con opciones')
          : null
      ),
      h(
        'div',
        { class: 'flex items-center justify-between mt-2' },
        h('span', { class: 'text-sm font-semibold text-amber-800' }, money(item.price)),
        !item.is_available ? badge('Agotado', 'danger') : null
      )
    );
  }

  function elegir(item) {
    if (!item.modifier_groups.length) return agregar(item, []);
    abrirModificadores(item, (seleccion) => agregar(item, seleccion));
  }

  // ---------- carrito ----------

  function agregar(item, modificadores) {
    // La clave agrupa líneas idénticas: el mismo producto con la misma
    // selección suma cantidad en vez de duplicarse.
    const clave = `${item.id}|${modificadores.map((m) => m.id).sort().join(',')}`;
    const existente = carrito.find((l) => l.clave === clave);
    if (existente) existente.cantidad += 1;
    else carrito.push({ clave, item, modificadores, cantidad: 1 });
    pintarCarrito();
  }

  function cambiarCantidad(clave, delta) {
    const linea = carrito.find((l) => l.clave === clave);
    if (!linea) return;
    linea.cantidad += delta;
    if (linea.cantidad <= 0) carrito.splice(carrito.indexOf(linea), 1);
    pintarCarrito();
  }

  function pintarCarrito() {
    crear.disabled = carrito.length === 0;
    const unidades = carrito.reduce((suma, l) => suma + l.cantidad, 0);
    render(contador, unidades ? badge(`${unidades}`, 'warn') : null);

    if (!carrito.length) {
      render(
        lineas,
        h(
          'div',
          { class: 'py-8 text-center' },
          h(
            'div',
            { class: 'inline-flex items-center justify-center w-10 h-10 rounded-full bg-stone-100 text-stone-300 mb-2' },
            icon('pedidos', { size: 20 })
          ),
          h('p', { class: 'text-sm text-stone-400' }, 'Toca un producto para empezar')
        )
      );
      render(totales);
      return;
    }

    render(
      lineas,
      carrito.map((linea) =>
        h(
          'div',
          { class: 'py-2.5 flex items-start gap-2' },
          h(
            'div',
            { class: 'flex-1 min-w-0' },
            h('div', { class: 'text-sm font-medium text-stone-900' }, linea.item.name),
            linea.modificadores.length
              ? h('div', { class: 'text-xs text-stone-500' }, linea.modificadores.map((m) => m.name).join(', '))
              : null
          ),
          h(
            'div',
            { class: 'flex items-center gap-1 shrink-0' },
            paso('menos', () => cambiarCantidad(linea.clave, -1)),
            h('span', { class: 'w-7 text-center text-sm font-semibold tabular-nums' }, linea.cantidad),
            paso('mas', () => cambiarCantidad(linea.clave, 1))
          )
        )
      )
    );
    recalcular();
  }

  const paso = (ico, onClick) =>
    h(
      'button',
      {
        class: 'w-9 h-9 inline-flex items-center justify-center rounded-lg border border-stone-300 text-stone-600 hover:border-stone-900 hover:text-stone-900 active:scale-95 transition',
        onClick,
      },
      icon(ico, { size: 16 })
    );

  // ---------- totales ----------
  // Los calcula el backend. El frontend nunca repite esa aritmética
  // (principio 5): pide la previsualización y muestra lo que llega.

  let temporizador = null;
  function recalcular() {
    clearTimeout(temporizador);
    if (!carrito.length) return render(totales);

    temporizador = setTimeout(async () => {
      try {
        const t = await api.post(`/orders/preview${branchQuery()}`, cuerpo());
        render(
          totales,
          fila('Subtotal', money(t.subtotal)),
          Number(t.tax_total) ? fila('Impuesto incluido', moneyExact(t.tax_total)) : null,
          Number(t.delivery_fee) ? fila('Domicilio', money(t.delivery_fee)) : null,
          Number(t.discount) ? fila('Descuento', `− ${money(t.discount)}`) : null,
          Number(t.tip) ? fila('Propina', money(t.tip)) : null,
          h(
            'div',
            { class: 'flex justify-between items-baseline pt-2.5 mt-1.5 border-t border-stone-200' },
            h('span', { class: 'font-medium text-stone-700' }, 'Total'),
            h('span', { class: 'text-2xl font-bold tracking-tight text-stone-900' }, money(t.total))
          )
        );
      } catch (error) {
        render(totales, h('p', { class: 'text-sm text-red-600' }, error.message));
      }
    }, 250);
  }

  const fila = (etiqueta, valor) =>
    h('div', { class: 'flex justify-between text-stone-500' }, h('span', {}, etiqueta), h('span', { class: 'tabular-nums' }, valor));

  function cuerpo() {
    return {
      channel: canalActivo,
      table_code: contexto.uses_tables ? mesa.value.trim() || null : null,
      customer_phone: telefono.value.trim() || null,
      customer_name: nombre.value.trim() || null,
      notes: notas.value.trim() || null,
      delivery_fee: Number(domicilio.value || 0),
      discount: Number(descuento.value || 0),
      tip: contexto.asks_tip ? Number(propina.value || 0) : 0,
      items: carrito.map((l) => ({
        menu_item_id: l.item.id,
        quantity: l.cantidad,
        modifier_ids: l.modificadores.map((m) => m.id),
      })),
    };
  }

  async function enviar() {
    crear.disabled = true;
    try {
      const pedido = await api.post(`/orders${branchQuery()}`, { ...cuerpo(), idempotency_key: intento });
      toast(`Pedido ${pedido.order_number} creado por ${money(pedido.total)}`, 'ok');
      intento = uuid();
      carrito.length = 0;
      [telefono, nombre, mesa].forEach((el) => (el.value = ''));
      notas.value = '';
      pintarCarrito();
    } catch (error) {
      toast(error.message);
    } finally {
      crear.disabled = carrito.length === 0;
    }
  }

  // ---------- armado ----------

  render(
    host,
    h(
      'div',
      { class: 'grid grid-cols-1 lg:grid-cols-3 gap-4' },
      h(
        'div',
        { class: 'lg:col-span-2 space-y-4' },
        h(
          'div',
          { class: 'relative' },
          h('span', { class: 'absolute left-3 top-1/2 -translate-y-1/2 text-stone-400 pointer-events-none' }, icon('buscar', { size: 18 })),
          buscador
        ),
        panelMenu
      ),
      h(
        'aside',
        { class: 'space-y-3 h-fit lg:sticky lg:top-[4.5rem]' },
        card(
          h(
            'div',
            { class: 'flex items-center justify-between mb-3' },
            h('h2', { class: 'font-semibold text-stone-900' }, 'Pedido'),
            contador
          ),
          h('div', { class: 'text-xs font-medium text-stone-500 mb-1.5' }, 'Canal'),
          selectorCanal,
          contexto.uses_tables
            ? h(
                'div',
                { class: 'mt-3' },
                h('div', { class: 'text-xs font-medium text-stone-500 mb-1.5' }, 'Mesa'),
                mesa
              )
            : null,
          h('div', { class: 'mt-3' }, lineas),
          totales,
          h('div', { class: 'mt-3' }, crear)
        ),
        h(
          'details',
          { class: 'seccion p-4' },
          h(
            'summary',
            { class: 'flex items-center gap-2 cursor-pointer text-sm font-medium text-stone-700' },
            icon('usuario', { size: 16 }),
            'Cliente y ajustes'
          ),
          h(
            'div',
            { class: 'mt-3 space-y-2' },
            telefono,
            nombre,
            h(
              'div',
              { class: 'grid grid-cols-2 gap-2' },
              campoNumero('Domicilio', domicilio),
              campoNumero('Descuento', descuento),
              contexto.asks_tip ? campoNumero('Propina', propina) : null
            ),
            notas
          )
        )
      )
    )
  );

  pintarCanal();
  pintarMenu();
  pintarCarrito();
}

const campoNumero = (etiqueta, control) =>
  h('label', { class: 'block' }, h('span', { class: 'text-xs text-stone-500' }, etiqueta), control);

// ---------- modificadores ----------

function abrirModificadores(item, alConfirmar) {
  const cuerpo = h('div', { class: 'p-4 space-y-5' });
  const seleccion = new Map();

  for (const grupo of item.modifier_groups) {
    const unico = grupo.max_select === 1;
    seleccion.set(grupo.id, new Set());

    cuerpo.append(
      h(
        'div',
        {},
        h(
          'div',
          { class: 'flex items-baseline justify-between mb-2' },
          h('span', { class: 'font-medium text-sm text-stone-900' }, grupo.name),
          grupo.is_required ? badge('Obligatorio', 'warn') : h('span', { class: 'text-xs text-stone-500' }, `Hasta ${grupo.max_select}`)
        ),
        h(
          'div',
          { class: 'space-y-0.5' },
          grupo.modifiers.map((m) =>
            h(
              'label',
              {
                class: `flex items-center gap-2.5 text-sm py-2 px-2 -mx-2 rounded-lg cursor-pointer hover:bg-stone-50 ${
                  m.is_available ? '' : 'opacity-40 cursor-not-allowed'
                }`,
              },
              h('input', {
                type: unico ? 'radio' : 'checkbox',
                name: `g-${grupo.id}`,
                class: 'w-4 h-4 accent-amber-700',
                disabled: !m.is_available,
                onChange: (e) => {
                  const elegidos = seleccion.get(grupo.id);
                  if (unico) elegidos.clear();
                  if (e.target.checked) elegidos.add(m);
                  else elegidos.delete(m);
                },
              }),
              h('span', { class: 'flex-1' }, m.name),
              Number(m.price_delta)
                ? h('span', { class: 'text-sm font-medium text-amber-800' }, `+ ${money(m.price_delta)}`)
                : null
            )
          )
        )
      )
    );
  }

  const cerrar = () => overlay.remove();
  const overlay = h(
    'div',
    {
      class: 'fixed inset-0 z-50 bg-stone-900/40 backdrop-blur-[2px] flex items-end sm:items-center justify-center p-0 sm:p-4',
      onClick: (e) => e.target === overlay && cerrar(),
    },
    h(
      'div',
      { class: 'aparece bg-white rounded-t-2xl sm:rounded-xl max-w-md w-full max-h-[85vh] overflow-y-auto shadow-xl' },
      h(
        'div',
        { class: 'p-4 border-b border-stone-200 flex items-center justify-between gap-2 sticky top-0 bg-white' },
        h('h3', { class: 'font-semibold text-stone-900' }, item.name),
        h(
          'button',
          { class: 'text-stone-400 hover:text-stone-900 p-1', onClick: cerrar, 'aria-label': 'Cerrar' },
          icon('cerrar', { size: 20 })
        )
      ),
      cuerpo,
      h(
        'div',
        { class: 'p-4 border-t border-stone-200 flex gap-2 sticky bottom-0 bg-white' },
        button('Cancelar', { variant: 'secondary', onClick: cerrar, full: true }),
        button('Agregar', {
          iconName: 'mas',
          full: true,
          onClick: () => {
            alConfirmar([...seleccion.values()].flatMap((s) => [...s]));
            cerrar();
          },
        })
      )
    )
  );

  document.body.append(overlay);
}

// =========================================================
// Pedidos del día
// =========================================================

async function vistaDelDia(host) {
  render(host, skeleton({ rows: 3 }));

  let pedidos;
  try {
    pedidos = await api.get(`/orders${branchQuery()}`);
  } catch (error) {
    return render(host, errorBox(error.message, () => vistaDelDia(host)));
  }

  if (!pedidos.length) {
    return render(
      host,
      h('div', { class: 'seccion' }, empty('Todavía no hay pedidos', 'Los que crees aparecerán aquí.', null, 'pedidos'))
    );
  }

  // El saldo lo sabe el backend; aquí no se resta nada.
  const saldos = await Promise.all(pedidos.map((p) => api.get(`/orders/${p.id}/balance`).catch(() => null)));

  render(
    host,
    h('div', { class: 'space-y-3' }, pedidos.map((p, i) => tarjetaPedido(p, saldos[i], () => vistaDelDia(host))))
  );
}

function tarjetaPedido(pedido, saldo, refrescar) {
  const pendiente = saldo && !saldo.is_settled;

  // Una llave por tarjeta, compartida por los tres métodos. Es a propósito:
  // si el cobro en efectivo se registró pero la respuesta se perdió, tocar
  // "Tarjeta" devuelve ese cobro en vez de cobrar dos veces. La tarjeta se
  // vuelve a pintar tras cada cobro exitoso, así que el siguiente cobro
  // parcial del mismo pedido ya trae otra llave.
  const cobro = uuid();

  return card(
    h(
      'div',
      { class: 'flex flex-wrap items-start justify-between gap-3' },
      h(
        'div',
        { class: 'min-w-0' },
        h(
          'div',
          { class: 'flex items-center gap-2' },
          h('span', { class: 'font-semibold text-stone-900 tabular-nums' }, pedido.order_number),
          badge(canal(pedido.channel))
        ),
        h(
          'div',
          { class: 'text-sm text-stone-600 mt-1' },
          pedido.items.map((i) => `${i.quantity}× ${i.name_snapshot}`).join(', ')
        )
      ),
      h(
        'div',
        { class: 'text-right shrink-0' },
        h('div', { class: 'font-semibold text-stone-900 tabular-nums' }, money(pedido.total)),
        saldo
          ? pendiente
            ? h('div', { class: 'text-xs text-amber-700 mt-0.5' }, `Falta ${money(saldo.pending)}`)
            : badge('Pagado', 'ok', 'check')
          : null
      )
    ),
    pendiente && me().permissions.includes('payments.register')
      ? h(
          'div',
          { class: 'mt-3 pt-3 border-t border-stone-100' },
          h('div', { class: 'text-xs font-medium text-stone-500 mb-2' }, `Cobrar ${money(saldo.pending)}`),
          h(
            'div',
            { class: 'flex flex-wrap gap-2' },
            [
              ['cash', 'Efectivo', 'dinero'],
              ['card', 'Tarjeta', 'etiqueta'],
              ['transfer', 'Transferencia', 'domicilio'],
            ].map(([metodo, etiqueta, ico]) =>
              button(etiqueta, {
                variant: 'secondary',
                iconName: ico,
                onClick: async (event) => {
                  event.currentTarget.disabled = true;
                  try {
                    await api.post(`/orders/${pedido.id}/payments`, {
                      method: metodo,
                      amount: saldo.pending,
                      idempotency_key: cobro,
                    });
                    toast('Pago registrado', 'ok');
                    refrescar();
                  } catch (error) {
                    toast(error.message);
                    event.currentTarget.disabled = false;
                  }
                },
              })
            )
          )
        )
      : null
  );
}
