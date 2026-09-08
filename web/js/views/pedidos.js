// Toma de pedidos y caja del mostrador.
//
// Es la pantalla que más se usa y suele tocarse en una tableta, de pie: los
// productos son objetivos grandes, el total está siempre visible y lo que el
// restaurante no usa (mesas, propina) no aparece, porque lo dice su
// configuración y no un condicional en el código.

import { api, query, uuid } from '../api.js';
import { date, money, moneyExact, time } from '../format.js';
import { icon } from '../icons.js';
import { activeBranchId, branchQuery, can, me } from '../session.js';
import {
  badge,
  button,
  card,
  clear,
  empty,
  errorBox,
  h,
  input,
  loading,
  render,
  select,
  skeleton,
  tabs,
  toast,
} from '../ui.js';
import { canal } from './cocina.js';
import { abrirPedido, metodoPago, tonoEstado } from './pedido-detalle.js';

export async function pedidos(outlet) {
  const contenido = h('div');
  const barra = h('div');
  let activa = 'nuevo';
  // Cada pestaña puede dejar algo corriendo (la espera del buscador, el
  // temporizador de totales). Se apaga al cambiar de pestaña y al salir de
  // la pantalla, igual que el enrutador hace con las vistas.
  let vivaActual = null;

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

  async function pintar() {
    vivaActual?.destroy?.();
    vivaActual = (await (activa === 'nuevo' ? vistaNuevo(contenido) : vistaDelDia(contenido))) ?? null;
  }

  render(outlet, barra, contenido);
  pintarPestanas();
  await pintar();

  return {
    destroy() {
      vivaActual?.destroy?.();
    },
  };
}

// =========================================================
// Nuevo pedido
// =========================================================

const ESPERA_CLIENTE_MS = 350;

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
  const nombre = input({ placeholder: 'Nombre del cliente' });
  const domicilio = input({ type: 'number', min: '0', value: '0' });
  const descuento = input({ type: 'number', min: '0', value: '0' });
  const propina = input({ type: 'number', min: '0', value: '0' });
  const notas = h('textarea', { rows: '2', placeholder: 'Notas para la cocina', class: 'campo' });

  // --- cliente conocido ---
  // Al teclear el teléfono se busca en la base y se ofrece lo que ya se sabe
  // de esa persona. Es la diferencia entre teclear un pedido telefónico
  // completo y confirmarlo.
  const sugerencias = h('div', { class: 'space-y-1' });
  let temporizadorCliente = null;

  const telefono = input({
    placeholder: 'Teléfono',
    type: 'tel',
    oninput: () => {
      clearTimeout(temporizadorCliente);
      render(sugerencias);
      const termino = telefono.value.trim();
      // Menos de tres dígitos devuelve media base: no es una sugerencia.
      if (!can('customers.view') || termino.length < 3) return;
      temporizadorCliente = setTimeout(() => buscarCliente(termino), ESPERA_CLIENTE_MS);
    },
  });

  async function buscarCliente(termino) {
    let encontrados;
    try {
      encontrados = await api.get(`/customers${query({ q: termino })}`);
    } catch {
      return; // sugerir es una comodidad: si falla, se teclea a mano
    }
    if (telefono.value.trim() !== termino) return; // ya siguió escribiendo

    render(
      sugerencias,
      encontrados.slice(0, 4).map((c) =>
        h(
          'button',
          {
            class: 'w-full text-left px-2.5 py-1.5 rounded-lg border border-stone-200 hover:border-stone-900 text-sm flex items-center gap-2',
            onClick: () => usarCliente(c),
          },
          icon('clientes', { size: 15, class: 'text-stone-400 shrink-0' }),
          h('span', { class: 'font-medium truncate' }, c.name || 'Sin nombre'),
          h('span', { class: 'text-stone-500 tabular-nums text-xs' }, c.phone)
        )
      )
    );
  }

  async function usarCliente(cliente) {
    telefono.value = cliente.phone;
    nombre.value = cliente.name ?? '';
    render(sugerencias);

    // La última dirección solo se pide si hace falta: en un pedido de
    // mostrador no aporta nada.
    if (!esDomicilio.checked || direccion.value.trim() !== '') return;
    try {
      const detalle = await api.get(`/customers/${cliente.id}`);
      if (detalle.last_address) direccion.value = detalle.last_address;
    } catch {
      // sin dirección previa se escribe a mano
    }
  }

  // --- domicilio ---
  // Lo que convierte un pedido en domicilio es que traiga dirección, no el
  // canal: el restaurante puede haber bautizado sus canales como quiera
  // (misma regla que aplica OrderController::deliveryFrom).
  const bloqueDomicilio = h('div', { class: 'hidden space-y-2 mt-2' });
  const direccion = input({ placeholder: 'Dirección de entrega' });
  const zona = select([{ value: '', label: 'Sin zona' }], { onChange: recalcular });
  const avisoZona = h('p', { class: 'text-[12px] text-stone-500' });

  const esDomicilio = h('input', {
    type: 'checkbox',
    class: 'w-4 h-4 rounded border-stone-300 accent-amber-700',
    onChange: () => {
      bloqueDomicilio.classList.toggle('hidden', !esDomicilio.checked);
      recalcular();
    },
  });

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
        const t = await api.post(`/orders/preview${branchQuery()}`, cuerpoPreview());
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
          ),
          // Lo redactó Domain\DeliveryRules; aquí no se compara nada.
          t.delivery_warning
            ? h(
                'p',
                { class: 'text-[12.5px] text-amber-800 bg-amber-50 border border-amber-200 rounded-[--r] px-2 py-1.5 mt-2' },
                t.delivery_warning
              )
            : null
        );
      } catch (error) {
        render(totales, h('p', { class: 'text-sm text-red-600' }, error.message));
      }
    }, 250);
  }

  const fila = (etiqueta, valor) =>
    h('div', { class: 'flex justify-between text-stone-500' }, h('span', {}, etiqueta), h('span', { class: 'tabular-nums' }, valor));

  function cuerpo() {
    const entrega =
      esDomicilio.checked && direccion.value.trim()
        ? { address: direccion.value.trim(), zone_id: zona.value || null }
        : null;

    return {
      channel: canalActivo,
      table_code: contexto.uses_tables ? mesa.value.trim() || null : null,
      customer_phone: telefono.value.trim() || null,
      customer_name: nombre.value.trim() || null,
      notes: notas.value.trim() || null,
      // Con zona, la tarifa la pone la zona y el backend ignora esto: por eso
      // el campo se deshabilita en pantalla en vez de mentir con una cifra.
      delivery_fee: Number(domicilio.value || 0),
      discount: Number(descuento.value || 0),
      tip: contexto.asks_tip ? Number(propina.value || 0) : 0,
      delivery: entrega,
      items: carrito.map((l) => ({
        menu_item_id: l.item.id,
        quantity: l.cantidad,
        modifier_ids: l.modificadores.map((m) => m.id),
      })),
    };
  }

  /**
   * Previsualizar no necesita la dirección, solo la zona: la tarifa y el
   * mínimo dependen de ella y de nada más. Va suelta y no dentro de
   * `delivery` justamente por eso —si esperara a que haya dirección escrita,
   * el total no cambiaría al elegir la zona, que es cuando el cajero mira.
   */
  function cuerpoPreview() {
    const { delivery, ...resto } = cuerpo();
    return { ...resto, zone_id: esDomicilio.checked ? zona.value || null : null };
  }

  async function enviar() {
    crear.disabled = true;
    try {
      const pedido = await api.post(`/orders${branchQuery()}`, { ...cuerpo(), idempotency_key: intento });
      toast(`Pedido ${pedido.order_number} creado por ${money(pedido.total)}`, 'ok');
      intento = uuid();
      carrito.length = 0;
      [telefono, nombre, mesa, direccion].forEach((el) => (el.value = ''));
      notas.value = '';
      render(sugerencias);
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
            sugerencias,
            nombre,
            h(
              'label',
              { class: 'flex items-center gap-2 text-sm text-stone-700 cursor-pointer pt-1' },
              esDomicilio,
              'Es un domicilio'
            ),
            bloqueDomicilio,
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

  render(
    bloqueDomicilio,
    direccion,
    zona,
    avisoZona
  );

  pintarCanal();
  pintarMenu();
  pintarCarrito();
  cargarZonas();

  /**
   * Las zonas de la sucursal activa. Solo las activas: una zona dada de baja
   * no es un destino al que se pueda seguir repartiendo.
   */
  async function cargarZonas() {
    if (!activeBranchId()) return;

    let zonas;
    try {
      zonas = await api.get(`/branches/${activeBranchId()}/delivery-zones`);
    } catch {
      // Sin zonas configuradas el domicilio se cobra con el importe de al
      // lado, que es como funcionaba hasta ahora.
      return;
    }

    const activas = zonas.filter((z) => z.is_active);
    if (!activas.length) {
      render(avisoZona, 'Esta sede no tiene zonas configuradas: el envío se cobra con el importe de abajo.');
      return;
    }

    render(
      zona,
      h('option', { value: '' }, 'Sin zona'),
      activas.map((z) => h('option', { value: z.id }, `${z.name} · ${money(z.fee)}`))
    );

    const explicar = () => {
      const elegida = activas.find((z) => z.id === zona.value);
      // Con zona, la tarifa la pone la zona: el campo de importe se
      // deshabilita en vez de mostrar una cifra que el backend va a ignorar.
      domicilio.disabled = Boolean(elegida);
      render(
        avisoZona,
        elegida
          ? `La tarifa la pone la zona: ${money(elegida.fee)}.${
              Number(elegida.min_order) ? ` Mínimo ${money(elegida.min_order)} de subtotal.` : ''
            }`
          : 'Sin zona, se cobra el envío que escribas abajo.'
      );
    };
    zona.addEventListener('change', explicar);
    explicar();
  }

  return {
    destroy() {
      clearTimeout(temporizador);
      clearTimeout(temporizadorCliente);
    },
  };
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

// El filtro va por categoría de estado, nunca por código: cada restaurante
// bautiza sus estados como quiere ("En plancha", "En preparación") pero la
// categoría es la parte que la plataforma entiende igual en todas.
const CATEGORIAS = [
  { valor: '', etiqueta: 'Todos los estados' },
  { valor: 'new', etiqueta: 'Nuevos' },
  { valor: 'kitchen', etiqueta: 'En cocina' },
  { valor: 'ready', etiqueta: 'Listos' },
  { valor: 'in_transit', etiqueta: 'En camino' },
  { valor: 'completed', etiqueta: 'Completados' },
  { valor: 'cancelled', etiqueta: 'Cancelados' },
];

const ESPERA_BUSQUEDA_MS = 300;

const ICONO_METODO = { cash: 'dinero', card: 'etiqueta', transfer: 'domicilio' };

/**
 * La lista de pedidos.
 *
 * Antes traía los 50 últimos sin filtro y pedía el saldo de cada uno por
 * separado: hasta 51 peticiones para pintar una pantalla. Ahora el saldo
 * viene dentro de cada fila y la ventana la deciden los filtros, así que un
 * restaurante con 400 pedidos al día puede encontrar uno.
 */
function vistaDelDia(host) {
  const filtros = { q: '', status_category: '', channel: '', from_date: '', to_date: '' };
  let cursor = null;
  let cargando = false;
  let temporizadorBusqueda = null;

  const lista = h('div', { class: 'space-y-3' });
  const pie = h('div', { class: 'flex justify-center' });

  const buscador = input({
    type: 'search',
    placeholder: 'Número de pedido o teléfono',
    class: 'campo pl-10',
    // Con espera: un mostrador teclea "3001234567" y no hacen falta diez
    // consultas para llegar al mismo resultado.
    oninput: () => {
      clearTimeout(temporizadorBusqueda);
      temporizadorBusqueda = setTimeout(() => {
        filtros.q = buscador.value.trim();
        recargar();
      }, ESPERA_BUSQUEDA_MS);
    },
  });

  const estado = select(
    CATEGORIAS.map((c) => ({ value: c.valor, label: c.etiqueta })),
    { class: 'campo w-auto', 'aria-label': 'Estado', onChange: (e) => cambiar('status_category', e.target.value) }
  );

  const canalFiltro = select(
    [{ value: '', label: 'Todos los canales' }, ...me().channels.map((c) => ({ value: c, label: canal(c) }))],
    { class: 'campo w-auto', 'aria-label': 'Canal', onChange: (e) => cambiar('channel', e.target.value) }
  );

  const desde = input({ type: 'date', class: 'campo w-auto', 'aria-label': 'Desde', onChange: (e) => cambiar('from_date', e.target.value) });
  const hasta = input({ type: 'date', class: 'campo w-auto', 'aria-label': 'Hasta', onChange: (e) => cambiar('to_date', e.target.value) });

  const limpiar = button('Limpiar', {
    variant: 'subtle',
    onClick: () => {
      Object.keys(filtros).forEach((k) => (filtros[k] = ''));
      buscador.value = '';
      [estado, canalFiltro, desde, hasta].forEach((el) => (el.value = ''));
      recargar();
    },
  });

  function cambiar(clave, valor) {
    filtros[clave] = valor;
    recargar();
  }

  const hayFiltros = () => Object.values(filtros).some(Boolean);

  render(
    host,
    h(
      'div',
      { class: 'space-y-4' },
      h(
        'div',
        { class: 'flex flex-wrap items-center gap-2' },
        h(
          'div',
          { class: 'relative flex-1 min-w-[220px]' },
          h('span', { class: 'absolute left-3 top-1/2 -translate-y-1/2 text-stone-400 pointer-events-none' }, icon('buscar', { size: 18 })),
          buscador
        ),
        estado,
        canalFiltro,
        desde,
        hasta,
        limpiar
      ),
      lista,
      pie
    )
  );

  async function cargar({ reemplazar }) {
    if (cargando) return;
    cargando = true;
    render(pie, loading(reemplazar ? 'Cargando…' : 'Trayendo más…'));

    let pagina;
    try {
      pagina = await api.get(`/orders${branchQuery({ ...filtros, cursor })}`);
    } catch (error) {
      cargando = false;
      render(pie);
      if (reemplazar) render(lista, errorBox(error.message, () => cargar({ reemplazar: true })));
      else render(pie, errorBox(error.message, () => cargar({ reemplazar: false })));
      return;
    }

    if (reemplazar) clear(lista);
    cursor = pagina.next_cursor;
    cargando = false;

    for (const pedido of pagina.items) lista.append(tarjetaPedido(pedido, recargar));

    if (!lista.childElementCount) {
      render(
        lista,
        h(
          'div',
          { class: 'seccion' },
          hayFiltros()
            ? empty('Ningún pedido coincide', 'Prueba con otro estado, otro canal u otras fechas.', null, 'buscar')
            : empty('Todavía no hay pedidos', 'Los que crees aparecerán aquí.', null, 'pedidos')
        )
      );
    }

    render(
      pie,
      cursor ? button('Cargar más', { variant: 'secondary', onClick: () => cargar({ reemplazar: false }) }) : null
    );
  }

  function recargar() {
    cursor = null;
    render(lista, skeleton({ rows: 3 }));
    return cargar({ reemplazar: true });
  }

  recargar();

  return {
    destroy() {
      clearTimeout(temporizadorBusqueda);
    },
  };
}

function tarjetaPedido(pedido, refrescar) {
  // El saldo llega dentro del pedido: la resta la hizo Domain\PaymentBalance,
  // aquí no se calcula nada.
  const saldo = pedido.balance;
  const pendiente = !saldo.is_settled;

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
          { class: 'flex items-center gap-2 flex-wrap' },
          h(
          'button',
          {
            class: 'font-semibold text-stone-900 tabular-nums hover:underline',
            onClick: () => abrirPedido(pedido.id, { alCambiar: refrescar }),
          },
          pedido.order_number
        ),
          pedido.status ? badge(pedido.status.name, tonoEstado(pedido.status.category)) : null,
          badge(canal(pedido.channel)),
          pedido.table_code ? badge(`Mesa ${pedido.table_code}`) : null
        ),
        h(
          'div',
          { class: 'text-sm text-stone-600 mt-1' },
          pedido.items.map((i) => `${i.quantity}× ${i.name_snapshot}`).join(', ')
        ),
        h('div', { class: 'text-xs text-stone-400 mt-0.5' }, `${date(pedido.created_at)} · ${time(pedido.created_at)}`)
      ),
      h(
        'div',
        { class: 'text-right shrink-0' },
        h('div', { class: 'font-semibold text-stone-900 tabular-nums' }, money(pedido.total)),
        pendiente
          ? h('div', { class: 'text-xs text-amber-700 mt-0.5' }, `Falta ${money(saldo.pending)}`)
          : badge('Pagado', 'ok', 'check')
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
            // Los métodos los declara el backend (`PaymentProviders`) y llegan
            // en la sesión: el día que entre una pasarela real aparece aquí
            // sola. Este es el atajo del mostrador —cobrar todo con un
            // toque—; el cobro parcial vive en el detalle del pedido.
            (me().payment_methods ?? []).map((metodo) =>
              button(metodoPago(metodo), {
                variant: 'secondary',
                iconName: ICONO_METODO[metodo] ?? 'dinero',
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
