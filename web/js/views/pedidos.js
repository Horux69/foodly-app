// Toma de pedidos y caja del mostrador.
//
// Es la pantalla que más se usa y suele tocarse en una tableta, así que los
// productos son botones grandes y lo que el restaurante no usa (mesas,
// propina) directamente no aparece: lo dice su configuración, no un if.

import { api } from '../api.js';
import { money, moneyExact } from '../format.js';
import { me } from '../session.js';
import { badge, button, card, empty, errorBox, h, input, loading, render, toast } from '../ui.js';
import { canal } from './cocina.js';

export async function pedidos(outlet) {
  const contenido = h('div');
  let pestana = 'nuevo';

  const pestanas = h('div', { class: 'flex gap-2 mb-4' });

  function pintarPestanas() {
    render(
      pestanas,
      [
        ['nuevo', 'Nuevo pedido'],
        ['dia', 'Pedidos del día'],
      ].map(([clave, etiqueta]) =>
        h(
          'button',
          {
            class: `px-4 py-2 rounded-lg text-sm font-medium ${
              pestana === clave ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 border border-slate-200'
            }`,
            onClick: () => {
              pestana = clave;
              pintarPestanas();
              pintar();
            },
          },
          etiqueta
        )
      )
    );
  }

  function pintar() {
    if (pestana === 'nuevo') vistaNuevo(contenido);
    else vistaDelDia(contenido);
  }

  render(outlet, pestanas, contenido);
  pintarPestanas();
  pintar();
}

// =========================================================
// Nuevo pedido
// =========================================================

async function vistaNuevo(host) {
  render(host, loading('Cargando el menú…'));

  let menu;
  try {
    menu = await api.get('/menu');
  } catch (error) {
    return render(host, errorBox(error.message, () => vistaNuevo(host)));
  }

  const contexto = me();
  const carrito = [];
  let filtro = '';

  const panelMenu = h('div', { class: 'lg:col-span-2 space-y-4' });
  const panelCarrito = h('aside', { class: 'space-y-3' });

  const buscador = input({
    type: 'search',
    placeholder: 'Buscar un producto…',
    oninput: (e) => {
      filtro = e.target.value.trim().toLowerCase();
      pintarMenu();
    },
  });

  // --- selección de canal, visible y no escondida en un desplegable ---
  let canalActivo = contexto.channels[0];
  const selectorCanal = h('div', { class: 'flex flex-wrap gap-2' });
  function pintarCanal() {
    render(
      selectorCanal,
      contexto.channels.map((c) =>
        h(
          'button',
          {
            class: `px-3 py-1.5 rounded-lg text-sm font-medium border ${
              c === canalActivo
                ? 'bg-slate-900 text-white border-slate-900'
                : 'bg-white text-slate-600 border-slate-300 hover:border-slate-900'
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
  const nombre = input({ placeholder: 'Nombre' });
  const domicilio = input({ type: 'number', min: '0', value: '0' });
  const descuento = input({ type: 'number', min: '0', value: '0' });
  const propina = input({ type: 'number', min: '0', value: '0' });
  const notas = h('textarea', {
    rows: '2',
    placeholder: 'Notas para la cocina',
    class: 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm',
  });

  [domicilio, descuento, propina].forEach((el) => el.addEventListener('change', recalcular));

  const lineas = h('div', { class: 'divide-y divide-slate-100' });
  const totales = h('div', { class: 'space-y-1 text-sm' });
  const crear = button('Crear pedido', { onClick: enviar, class: 'w-full bg-slate-900 text-white rounded-lg py-3 font-medium hover:bg-slate-800 disabled:opacity-40' });

  // ---------- menú ----------

  function pintarMenu() {
    const categorias = menu
      .map((cat) => ({
        ...cat,
        items: cat.items.filter((i) => !filtro || i.name.toLowerCase().includes(filtro)),
      }))
      .filter((cat) => cat.items.length);

    if (!menu.length) {
      return render(
        panelMenu,
        card(empty('Este restaurante todavía no tiene menú', 'Agrega categorías y productos desde la pantalla Menú.'))
      );
    }
    if (!categorias.length) {
      return render(panelMenu, card(empty('Ningún producto coincide', `No encontramos “${filtro}”.`)));
    }

    render(
      panelMenu,
      categorias.map((cat) =>
        card(
          h('h2', { class: 'font-semibold text-slate-900 mb-3' }, cat.name),
          h(
            'div',
            { class: 'grid grid-cols-2 sm:grid-cols-3 gap-2' },
            cat.items.map((item) => botonProducto(item))
          )
        )
      )
    );
  }

  function botonProducto(item) {
    return h(
      'button',
      {
        disabled: !item.is_available,
        class: `text-left border rounded-lg p-3 min-h-[76px] transition ${
          item.is_available
            ? 'border-slate-200 hover:border-slate-900 hover:bg-slate-50'
            : 'border-slate-200 opacity-50 cursor-not-allowed'
        }`,
        onClick: () => elegir(item),
      },
      h('div', { class: 'font-medium text-sm text-slate-900' }, item.name),
      h('div', { class: 'text-sm text-slate-500 mt-1' }, money(item.price)),
      !item.is_available ? h('div', { class: 'text-xs text-red-600 mt-1' }, 'Agotado') : null,
      item.modifier_groups.length
        ? h('div', { class: 'text-xs text-slate-400 mt-1' }, 'Requiere elegir opciones')
        : null
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

    if (!carrito.length) {
      render(lineas, h('p', { class: 'py-8 text-center text-sm text-slate-400' }, 'Todavía no agregaste productos'));
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
            h('div', { class: 'text-sm font-medium text-slate-900' }, linea.item.name),
            linea.modificadores.length
              ? h('div', { class: 'text-xs text-slate-500' }, linea.modificadores.map((m) => m.name).join(', '))
              : null
          ),
          h(
            'div',
            { class: 'flex items-center gap-1 shrink-0' },
            pasoCantidad('−', () => cambiarCantidad(linea.clave, -1)),
            h('span', { class: 'w-7 text-center text-sm font-medium tabular-nums' }, linea.cantidad),
            pasoCantidad('+', () => cambiarCantidad(linea.clave, 1))
          )
        )
      )
    );
    recalcular();
  }

  function pasoCantidad(signo, onClick) {
    return h(
      'button',
      { class: 'w-8 h-8 rounded-lg border border-slate-300 text-slate-600 hover:border-slate-900', onClick },
      signo
    );
  }

  // ---------- totales ----------
  // Los calcula el backend. El frontend nunca repite esa aritmética
  // (principio 5): pide la previsualización y muestra lo que llega.

  let temporizador = null;
  function recalcular() {
    clearTimeout(temporizador);
    if (!carrito.length) return render(totales);
    temporizador = setTimeout(async () => {
      try {
        const t = await api.post('/orders/preview', cuerpo());
        render(
          totales,
          fila('Subtotal', money(t.subtotal)),
          Number(t.tax_total) ? fila('Impuesto incluido', moneyExact(t.tax_total)) : null,
          Number(t.delivery_fee) ? fila('Domicilio', money(t.delivery_fee)) : null,
          Number(t.discount) ? fila('Descuento', `− ${money(t.discount)}`) : null,
          Number(t.tip) ? fila('Propina', money(t.tip)) : null,
          h(
            'div',
            { class: 'flex justify-between font-semibold text-slate-900 text-base pt-2 mt-1 border-t border-slate-200' },
            h('span', {}, 'Total'),
            h('span', {}, money(t.total))
          )
        );
      } catch (error) {
        render(totales, h('p', { class: 'text-sm text-red-600' }, error.message));
      }
    }, 250);
  }

  const fila = (etiqueta, valor) =>
    h('div', { class: 'flex justify-between text-slate-600' }, h('span', {}, etiqueta), h('span', {}, valor));

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
    crear.textContent = 'Creando…';
    try {
      const pedido = await api.post('/orders', cuerpo());
      toast(`Pedido ${pedido.order_number} creado por ${money(pedido.total)}`, 'ok');
      carrito.length = 0;
      [telefono, nombre, mesa].forEach((el) => (el.value = ''));
      notas.value = '';
      pintarCarrito();
    } catch (error) {
      toast(error.message);
    } finally {
      crear.textContent = 'Crear pedido';
      crear.disabled = carrito.length === 0;
    }
  }

  // ---------- armado ----------

  render(
    host,
    h(
      'div',
      { class: 'grid grid-cols-1 lg:grid-cols-3 gap-4' },
      h('div', { class: 'lg:col-span-2 space-y-4' }, card(buscador), panelMenu),
      h(
        'aside',
        { class: 'space-y-3 h-fit lg:sticky lg:top-4' },
        card(
          h('h2', { class: 'font-semibold text-slate-900 mb-3' }, 'Pedido'),
          h('div', { class: 'text-xs font-medium text-slate-600 mb-1.5' }, 'Canal'),
          selectorCanal,
          contexto.uses_tables
            ? h('div', { class: 'mt-3' }, h('div', { class: 'text-xs font-medium text-slate-600 mb-1.5' }, 'Mesa'), mesa)
            : null,
          h('div', { class: 'mt-3' }, lineas),
          totales,
          h('div', { class: 'mt-3' }, crear)
        ),
        h(
          'details',
          { class: 'bg-white rounded-xl border border-slate-200 p-4' },
          h('summary', { class: 'cursor-pointer text-sm font-medium text-slate-700' }, 'Cliente y ajustes'),
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
  h('label', { class: 'block' }, h('span', { class: 'text-xs text-slate-600' }, etiqueta), control);

// ---------- modificadores ----------

function abrirModificadores(item, alConfirmar) {
  const cuerpo = h('div', { class: 'p-4 space-y-4' });
  const seleccion = new Map(); // grupo -> Set de modificadores

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
          h('span', { class: 'font-medium text-sm text-slate-900' }, grupo.name),
          h(
            'span',
            { class: 'text-xs text-slate-500' },
            grupo.is_required ? 'Obligatorio' : `Hasta ${grupo.max_select}`
          )
        ),
        h(
          'div',
          { class: 'space-y-1' },
          grupo.modifiers.map((m) =>
            h(
              'label',
              { class: `flex items-center gap-2 text-sm py-1 ${m.is_available ? '' : 'opacity-40'}` },
              h('input', {
                type: unico ? 'radio' : 'checkbox',
                name: `g-${grupo.id}`,
                disabled: !m.is_available,
                onChange: (e) => {
                  const elegidos = seleccion.get(grupo.id);
                  if (unico) elegidos.clear();
                  if (e.target.checked) elegidos.add(m);
                  else elegidos.delete(m);
                },
              }),
              h('span', { class: 'flex-1' }, m.name),
              Number(m.price_delta) ? h('span', { class: 'text-slate-500' }, `+ ${money(m.price_delta)}`) : null
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
      class: 'fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4',
      onClick: (e) => e.target === overlay && cerrar(),
    },
    h(
      'div',
      { class: 'bg-white rounded-xl max-w-md w-full max-h-[85vh] overflow-y-auto' },
      h('div', { class: 'p-4 border-b border-slate-200' }, h('h3', { class: 'font-semibold text-slate-900' }, item.name)),
      cuerpo,
      h(
        'div',
        { class: 'p-4 border-t border-slate-200 flex gap-2 sticky bottom-0 bg-white' },
        button('Cancelar', { variant: 'secondary', onClick: cerrar, class: 'flex-1 rounded-lg px-4 py-2 text-sm font-medium border border-slate-300 text-slate-700' }),
        button('Agregar', {
          class: 'flex-1 rounded-lg px-4 py-2 text-sm font-medium bg-slate-900 text-white',
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
  render(host, loading('Cargando pedidos…'));

  let pedidos;
  try {
    pedidos = await api.get('/orders');
  } catch (error) {
    return render(host, errorBox(error.message, () => vistaDelDia(host)));
  }

  if (!pedidos.length) {
    return render(host, card(empty('Todavía no hay pedidos', 'Los que crees aparecerán aquí.')));
  }

  // El saldo lo sabe el backend; aquí no se resta nada.
  const saldos = await Promise.all(
    pedidos.map((p) => api.get(`/orders/${p.id}/balance`).catch(() => null))
  );

  render(
    host,
    h(
      'div',
      { class: 'space-y-3' },
      pedidos.map((pedido, i) => tarjetaPedido(pedido, saldos[i], () => vistaDelDia(host)))
    )
  );
}

function tarjetaPedido(pedido, saldo, refrescar) {
  const pendiente = saldo && !saldo.is_settled;

  return card(
    h(
      'div',
      { class: 'flex flex-wrap items-start justify-between gap-2' },
      h(
        'div',
        {},
        h(
          'div',
          { class: 'flex items-center gap-2' },
          h('span', { class: 'font-semibold text-slate-900' }, pedido.order_number),
          badge(canal(pedido.channel))
        ),
        h(
          'div',
          { class: 'text-sm text-slate-600 mt-1' },
          pedido.items.map((i) => `${i.quantity}× ${i.name_snapshot}`).join(', ')
        )
      ),
      h(
        'div',
        { class: 'text-right' },
        h('div', { class: 'font-semibold text-slate-900' }, money(pedido.total)),
        saldo
          ? pendiente
            ? h('div', { class: 'text-xs text-amber-600' }, `Falta ${money(saldo.pending)}`)
            : h('div', { class: 'text-xs text-emerald-600' }, 'Pagado')
          : null
      )
    ),
    pendiente && me().permissions.includes('payments.register')
      ? h(
          'div',
          { class: 'mt-3 flex flex-wrap gap-2' },
          [
            ['cash', 'efectivo'],
            ['card', 'tarjeta'],
            ['transfer', 'transferencia'],
          ].map(([metodo, etiqueta]) =>
            button(`Cobrar ${money(saldo.pending)} en ${etiqueta}`, {
              variant: 'secondary',
              onClick: async (event) => {
                event.currentTarget.disabled = true;
                try {
                  await api.post(`/orders/${pedido.id}/payments`, { method: metodo, amount: saldo.pending });
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
      : null
  );
}
