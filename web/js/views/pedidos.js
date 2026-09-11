// Toma de pedidos y caja del mostrador.
//
// Es la pantalla que más se usa y suele tocarse en una tableta, de pie: los
// productos son objetivos grandes, el total está siempre visible y lo que el
// restaurante no usa (mesas, propina) no aparece, porque lo dice su
// configuración y no un condicional en el código.

import { api, query, uuid } from '../api.js';
import * as cola from '../cola.js';
import { date, money, moneyExact, time } from '../format.js';
import { icon } from '../icons.js';
import { activeBranchId, branchQuery, can, me } from '../session.js';
import {
  badge, button, card, clear, empty, errorBox, h, input, loading, render,
  select, skeleton, tabs, toast,
} from '../ui.js';
import { canal } from './cocina.js';
import { abrirModificadores } from './modificadores-dialogo.js';
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

/**
 * La mesa que venga en el hash (`#/pedidos?mesa=M4`).
 *
 * El enrutador trabaja con la ruta sin query, así que se lee aquí. Es lo que
 * hace que tocar una mesa libre en el salón abra la venta con la mesa ya
 * escrita en vez de obligar a teclearla otra vez.
 */
function mesaDelHash() {
  const [, consulta = ''] = window.location.hash.split('?');
  return new URLSearchParams(consulta).get('mesa') ?? '';
}

async function vistaNuevo(host) {
  render(host, skeleton({ rows: 2 }));

  let menu;
  let motivos = [];
  let meseros = [];
  let origenes = [];
  try {
    // Los motivos solo si se van a poder usar: sin el permiso, el campo no
    // existe y la petición sería por nada. Con los meseros igual, y además
    // solo donde hay mesas que atender.
    [menu, motivos, meseros, origenes] = await Promise.all([
      api.get(`/menu${branchQuery()}`),
      can('orders.discount') ? api.get('/discount-reasons') : [],
      me().uses_tables && can('orders.assign_server') ? api.get('/servers') : [],
      // De dónde viene la venta (F9.4). Sin ninguno configurado —el caso de
      // casi todos— la pantalla ni lo menciona.
      api.get('/sales-sources').catch(() => []),
    ]);
  } catch (error) {
    return render(host, errorBox(error.message, () => vistaNuevo(host)));
  }

  const contexto = me();
  const carrito = [];
  let filtro = '';

  // El tiempo al que se están agregando los productos. Los nombres los pone
  // el restaurante (`tenants.settings.courses`); sin ninguno configurado no
  // hay tiempos y la pantalla ni los menciona.
  const tiempos = contexto.courses ?? [];
  let tiempoActivo = 1;

  // Llave de idempotencia del intento en curso. Vive mientras el pedido no
  // se confirme: si la respuesta se pierde por red lenta y el cajero vuelve
  // a tocar, el backend reconoce la llave y devuelve el pedido que ya creó
  // en vez de crear un segundo. Se renueva recién al confirmarse, que es
  // cuando empieza otro pedido.
  let intento = uuid();

  const panelMenu = h('div', { class: 'space-y-4' });

  const buscador = input({
    type: 'search',
    placeholder: 'Buscar un producto…  (tecla /)',
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
            class: `px-3 py-1.5 rounded-[--r] text-sm font-medium border transition ${
              c === canalActivo
                ? 'bg-stone-900 text-[--tinta-inversa] border-stone-900'
                : 'bg-[--panel] text-stone-600 border-stone-300 hover:border-stone-900'
            }`,
            onClick: () => {
              canalActivo = c;
              pintarCanal();
  pintarTiempos();
              recalcular();
            },
          },
          canal(c)
        )
      )
    );
  }

  // Si se llegó tocando una mesa libre en el salón, ya viene puesta.
  const mesa = input({ placeholder: 'Ej. M1', value: mesaDelHash() });
  const nombre = input({ placeholder: 'Nombre del cliente' });
  // Quién atiende la mesa, que en una tableta compartida del pasillo no es
  // quien tiene la sesión abierta. Arranca en uno mismo: es el caso común y
  // así el pedido nunca sale sin dueño.
  const origen = select(
    [
      { value: '', label: 'Venta propia' },
      ...origenes.map((o) => ({
        value: o.id,
        label: o.commission_percent > 0 ? `${o.name} (${o.commission_percent}%)` : o.name,
      })),
    ],
    { 'aria-label': 'Origen de la venta' }
  );

  const mesero = select(
    [
      { value: '', label: 'Sin mesero' },
      ...meseros.map((m) => ({ value: m.id, label: m.name })),
    ],
    { 'aria-label': 'Mesero a cargo' }
  );
  if (meseros.some((m) => m.id === me()?.user_id)) mesero.value = me().user_id;
  const domicilio = input({ type: 'number', min: '0', value: '0' });
  // El descuento es el único ajuste que saca plata de la venta sin dejar
  // cobro ni reembolso detrás: pide su propio permiso y un motivo. Quien no
  // lo tiene, no ve el campo — antes era una casilla libre para cualquiera
  // con caja.
  const puedeDescontar = can('orders.discount');
  const descuento = input({ type: 'number', min: '0', value: '0' });
  const motivoDescuento = select(
    [{ value: '', label: 'Motivo…' }, ...motivos.map((m) => ({ value: m.id, label: m.name }))],
    { 'aria-label': 'Motivo del descuento' }
  );
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
            class: 'w-full text-left px-2.5 py-1.5 rounded-[--r] border border-stone-200 hover:border-stone-900 text-sm flex items-center gap-2',
            onClick: () => usarCliente(c),
          },
          icon('clientes', { size: 15, class: 'text-stone-400 shrink-0' }),
          h('span', { class: 'font-medium truncate' }, c.name || 'Sin nombre'),
          h('span', { class: 'text-stone-600 tabular-nums text-xs' }, c.phone)
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
  const avisoZona = h('p', { class: 'text-[12px] text-stone-600' });

  const esDomicilio = h('input', {
    type: 'checkbox',
    class: 'w-4 h-4 rounded border-stone-300 accent-stone-900',
    onChange: () => {
      bloqueDomicilio.classList.toggle('hidden', !esDomicilio.checked);
      recalcular();
    },
  });

  [domicilio, descuento, propina].forEach((el) => el.addEventListener('change', recalcular));

  const lineas = h('div', { class: 'divide-y divide-stone-100' });
  const totales = h('div', { class: 'space-y-1.5 text-sm' });
  const contador = h('span');
  const crear = button('Crear pedido', { onClick: enviar, iconName: 'check', full: true, title: 'Enter' });

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
        // Lo que lleva el combo, para poder responder "¿y qué trae?" sin
        // salir de la pantalla de venta.
        item.components?.length
          ? h(
              'div',
              { class: 'text-[11px] text-stone-600 mt-0.5' },
              item.components.map((c) => `${c.quantity}× ${c.name}`).join(' · ')
            )
          : null,
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
    // La clave lleva el tiempo: la misma hamburguesa de entrada y de fuerte
    // son dos líneas, porque salen a la cocina en momentos distintos.
    const clave = `${item.id}|${modificadores.map((m) => m.id).sort().join(',')}|t${tiempoActivo}`;
    const existente = carrito.find((l) => l.clave === clave);
    if (existente) existente.cantidad += 1;
    else carrito.push({ clave, item, modificadores, cantidad: 1, tiempo: tiempoActivo });
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
          {
            class: 'py-2.5 flex items-start gap-2 rounded-[--r] focus:outline-none focus:ring-2 focus:ring-stone-400',
            // Con foco, las flechas cambian la cantidad: en un mostrador con
            // cola es más rápido que apuntar a un botón de 36 píxeles.
            tabindex: '0',
            role: 'group',
            'aria-label': `${linea.item.name}, ${linea.cantidad}. Flechas arriba y abajo para cambiar la cantidad`,
            onKeydown: (event) => {
              const paso = { ArrowUp: 1, '+': 1, ArrowDown: -1, '-': -1 }[event.key];
              if (paso === undefined) return;
              event.preventDefault();
              cambiarCantidad(linea.clave, paso);
              // Tras repintar, el foco vuelve a la línea equivalente; si
              // desapareció, al carrito.
              const filas = lineas.querySelectorAll('[role="group"]');
              (filas[carrito.findIndex((l) => l.clave === linea.clave)] ?? filas[0] ?? buscador).focus();
            },
          },
          h(
            'div',
            { class: 'flex-1 min-w-0' },
            h('div', { class: 'text-sm font-medium text-stone-900' }, linea.item.name),
            // Solo cuando hay tiempos: en un mostrador sería una etiqueta
            // repetida en todas las líneas que no significa nada.
            tiempos.length
              ? h('div', { class: 'text-[11px] text-stone-400' }, tiempos[linea.tiempo - 1] ?? `Tiempo ${linea.tiempo}`)
              : null,
            linea.modificadores.length
              ? h('div', { class: 'text-xs text-stone-600' }, linea.modificadores.map((m) => m.name).join(', '))
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

  /**
   * A qué tiempo se está agregando.
   *
   * Botones y no un desplegable, como el canal: es una decisión que se toma
   * varias veces mientras se toma el pedido y en un desplegable se olvida.
   */
  const selectorTiempo = h('div', { class: 'flex flex-wrap gap-1.5' });
  function pintarTiempos() {
    if (!tiempos.length) return;
    render(
      selectorTiempo,
      tiempos.map((nombre, indice) =>
        h(
          'button',
          {
            class: `px-2.5 py-1 rounded-full text-[12.5px] border transition ${
              indice + 1 === tiempoActivo
                ? 'bg-stone-900 text-[--tinta-inversa] border-stone-900'
                : 'bg-[--panel] text-stone-600 border-stone-300 hover:border-stone-900'
            }`,
            'aria-pressed': String(indice + 1 === tiempoActivo),
            onClick: () => {
              tiempoActivo = indice + 1;
              pintarTiempos();
            },
          },
          nombre
        )
      )
    );
  }

  const paso = (ico, onClick) =>
    h(
      'button',
      {
        class: 'w-9 h-9 inline-flex items-center justify-center rounded-[--r] border border-stone-300 text-stone-600 hover:border-stone-900 hover:text-stone-900 active:scale-95 transition',
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
    h('div', { class: 'flex justify-between text-stone-600' }, h('span', {}, etiqueta), h('span', { class: 'tabular-nums' }, valor));

  function cuerpo() {
    const entrega =
      esDomicilio.checked && direccion.value.trim()
        ? { address: direccion.value.trim(), zone_id: zona.value || null }
        : null;

    return {
      channel: canalActivo,
      table_code: contexto.uses_tables ? mesa.value.trim() || null : null,
      // Sin el permiso el campo no existe y el backend deja la cuenta a
      // nombre de quien la toma.
      server_id: meseros.length ? mesero.value || null : undefined,
      // Nulo es venta propia, que es el caso de casi todos.
      sales_source_id: origenes.length ? origen.value || null : undefined,
      customer_phone: telefono.value.trim() || null,
      customer_name: nombre.value.trim() || null,
      notes: notas.value.trim() || null,
      // Con zona, la tarifa la pone la zona y el backend ignora esto: por eso
      // el campo se deshabilita en pantalla en vez de mentir con una cifra.
      delivery_fee: Number(domicilio.value || 0),
      discount: puedeDescontar ? Number(descuento.value || 0) : 0,
      discount_reason_id: motivoDescuento.value || null,
      tip: contexto.asks_tip ? Number(propina.value || 0) : 0,
      delivery: entrega,
      items: carrito.map((l) => ({
        menu_item_id: l.item.id,
        quantity: l.cantidad,
        modifier_ids: l.modificadores.map((m) => m.id),
        // Solo el primer tiempo con líneas sale a la cocina al crear el
        // pedido; los demás esperan a que alguien los marche.
        course: l.tiempo ?? 1,
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
    const url = `/orders${branchQuery()}`;
    const enviado = { ...cuerpo(), idempotency_key: intento };
    try {
      const pedido = await api.post(url, enviado);
      toast(`Pedido ${pedido.order_number} creado por ${money(pedido.total)}`, 'ok');
      limpiar();
    } catch (error) {
      // `status` 0 es que la petición no salió del navegador: el pedido se
      // guarda y se reenvía solo. Cualquier otro código es una respuesta del
      // servidor —el pedido llegó y lo rechazó—, y encolarlo sería insistir
      // con algo que ya se sabe que no entra.
      if (error.status !== 0) {
        toast(error.message);
      } else {
        const cuantos = cola.encolar(url, enviado, resumenDelPedido());
        toast(`Sin conexión: el pedido quedó en cola (${cuantos}) y se enviará solo`, 'warn');
        limpiar();
      }
    } finally {
      crear.disabled = carrito.length === 0;
    }
  }

  /** Deja la pantalla lista para el siguiente pedido. */
  function limpiar() {
    // La llave se renueva también al encolar: el pedido de la cola se lleva
    // la suya, y si el siguiente reusara la misma, el backend creería que es
    // un reintento del anterior y devolvería aquel en vez de crear este.
    intento = uuid();
    carrito.length = 0;
    [telefono, nombre, mesa, direccion].forEach((el) => (el.value = ''));
    notas.value = '';
    render(sugerencias);
    pintarCarrito();
  }

  /** Con qué nombrar el pedido en la cola: en ella todavía no tiene número. */
  function resumenDelPedido() {
    const unidades = carrito.reduce((suma, l) => suma + l.cantidad, 0);
    const quien = nombre.value.trim() || telefono.value.trim();
    return `${unidades} ${unidades === 1 ? 'producto' : 'productos'}${quien ? ` · ${quien}` : ''}`;
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
          h('div', { class: 'text-xs font-medium text-stone-600 mb-1.5' }, 'Canal'),
          selectorCanal,
          contexto.uses_tables
            ? h(
                'div',
                { class: 'mt-3' },
                h('div', { class: 'text-xs font-medium text-stone-600 mb-1.5' }, 'Mesa'),
                mesa
              )
            : null,
          meseros.length
            ? h(
                'div',
                { class: 'mt-3' },
                h('div', { class: 'text-xs font-medium text-stone-600 mb-1.5' }, 'Mesero'),
                mesero
              )
            : null,
          tiempos.length
            ? h(
                'div',
                { class: 'mt-3' },
                h('div', { class: 'text-xs font-medium text-stone-600 mb-1.5' }, 'Agregando a'),
                selectorTiempo
              )
            : null,
          origenes.length
            ? h(
                'div',
                { class: 'mt-3' },
                h('div', { class: 'text-xs font-medium text-stone-600 mb-1.5' }, 'Origen'),
                origen
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
              puedeDescontar ? campoNumero('Descuento', descuento) : null,
              puedeDescontar ? campoNumero('Motivo', motivoDescuento) : null,
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
  pintarTiempos();
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

  // ---------- teclado ----------
  //
  // Un mostrador con cola se opera con las dos manos ocupadas: `/` lleva al
  // buscador y Enter crea el pedido. Solo cuando no se está escribiendo en un
  // campo —si no, `/` no se podría teclear en el nombre de un cliente— y solo
  // sin diálogo abierto, porque ahí la tecla es del diálogo.
  function atajos(event) {
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) return;
    if (document.querySelector('[role="dialog"]')) return;

    const enUnCampo = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);

    if (event.key === '/' && !enUnCampo) {
      event.preventDefault();
      buscador.focus();
      buscador.select();
      return;
    }
    // Desde el buscador también, que es donde están las manos: escribir el
    // producto, tocarlo y confirmar sin soltar el teclado.
    if (event.key === 'Enter' && (!enUnCampo || document.activeElement === buscador)) {
      if (crear.disabled) return;
      event.preventDefault();
      enviar();
    }
  }

  document.addEventListener('keydown', atajos);

  return {
    destroy() {
      clearTimeout(temporizador);
      clearTimeout(temporizadorCliente);
      document.removeEventListener('keydown', atajos);
    },
  };
}

const campoNumero = (etiqueta, control) =>
  h('label', { class: 'block' }, h('span', { class: 'text-xs text-stone-600' }, etiqueta), control);


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
          h('div', { class: 'text-xs font-medium text-stone-600 mb-2' }, `Cobrar ${money(saldo.pending)}`),
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
                  // `currentTarget` se guarda antes del primer `await`: el navegador lo deja
                  // en null en cuanto termina el despacho del evento, y sin esto el `catch`
                  // no podria volver a habilitar el boton.
                  const boton = event.currentTarget;
                  boton.disabled = true;
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
                    boton.disabled = false;
                  }
                },
              })
            )
          )
        )
      : null
  );
}
