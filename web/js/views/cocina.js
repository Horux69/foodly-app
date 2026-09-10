// Tablero de cocina (KDS).
//
// Se mira desde lejos y con las manos ocupadas, así que el diseño prioriza
// otra cosa que el resto de la aplicación: número de pedido grande, espera
// visible de un vistazo y un botón por acción posible.
//
// Los pedidos se agrupan en columnas por categoría de estado, no por código:
// un restaurante puede llamar 'En preparación' a lo que otro llama 'En
// plancha', y ambos son 'kitchen'. Las acciones disponibles las declara el
// backend en `next_statuses`, según la máquina de estados configurada.

import { api } from '../api.js';
import { elapsed, minutesSince, time } from '../format.js';
import { icon } from '../icons.js';
import { branchQuery } from '../session.js';
import { badge, button, empty, errorBox, h, render, skeleton, toast } from '../ui.js';
import { abrirCancelacion } from './cancelar-pedido.js';
import { imprimirComanda } from './impresion.js';
import { abrirPedido } from './pedido-detalle.js';

const REFRESCO_MS = 15000;
const ATENTO_MINUTOS = 10;
const TARDE_MINUTOS = 15;

// Cómo se llama y se pinta cada columna. Cuáles existen lo decide el
// backend según los estados que el restaurante tenga configurados: uno de
// comida rápida no tiene 'in_transit' y esa columna, siempre vacía, sería
// ruido en la pantalla que más se mira de lejos.
const COLUMNAS = {
  new: { titulo: 'Por preparar', tono: 'neutral' },
  kitchen: { titulo: 'En cocina', tono: 'warn' },
  ready: { titulo: 'Listos para entregar', tono: 'ok' },
  in_transit: { titulo: 'En camino', tono: 'info' },
};

// Escritas enteras y no interpoladas: Tailwind no genera una clase que no
// aparezca literal en el código.
const REJILLA = { 1: 'md:grid-cols-1', 2: 'md:grid-cols-2', 3: 'md:grid-cols-3', 4: 'md:grid-cols-4' };

const AVISO_KEY = 'resto_aviso_cocina';
// La estación que mira esta pantalla se recuerda por dispositivo: la
// tableta de la barra es siempre la barra, la del pase es el pase.
const ESTACION_KEY = 'resto_estacion_cocina';
const LINEAS_KEY = 'resto_lineas_listas';

export async function cocina(outlet) {
  const tablero = h('div');
  const filtroEstacion = h('div');
  let estacion = leerEstacion();
  const marca = h('span', { class: 'flex items-center gap-1.5 text-xs text-stone-500' });
  const aviso = crearAviso();
  const interruptor = h('button', { class: 'boton boton-secundario', onClick: cambiarAviso });

  function pintarInterruptor() {
    render(
      interruptor,
      icon(aviso.activo ? 'alerta' : 'cerrar', { size: 16 }),
      h('span', {}, aviso.activo ? 'Avisa al entrar' : 'Sin aviso')
    );
    interruptor.title = aviso.activo
      ? 'Suena y cuenta en la pestaña cuando entra un pedido'
      : 'El tablero se actualiza en silencio';
  }

  function leerEstacion() {
    try {
      return localStorage.getItem(ESTACION_KEY) || null;
    } catch {
      return null;
    }
  }

  function elegirEstacion(id) {
    estacion = id;
    try {
      if (id === null) localStorage.removeItem(ESTACION_KEY);
      else localStorage.setItem(ESTACION_KEY, id);
    } catch {
      // Sin almacenamiento el filtro vale para esta sesión y ya.
    }
    refrescar();
  }

  /**
   * Las estaciones que el restaurante configuró. Sin ninguna no hay filtro
   * que mostrar: no todos los restaurantes reparten la cocina.
   */
  function pintarFiltro(estaciones) {
    if (!estaciones.length) return render(filtroEstacion);

    // Una estación que se borró mientras esta pantalla estaba abierta deja
    // de existir: se vuelve a "Todo" en vez de mostrar un tablero vacío.
    if (estacion !== null && !estaciones.some((e) => e.id === estacion)) estacion = null;

    render(
      filtroEstacion,
      h(
        'div',
        { class: 'flex flex-wrap items-center gap-1.5' },
        [{ id: null, name: 'Todo' }, ...estaciones].map((e) =>
          h(
            'button',
            {
              class: `boton ${e.id === estacion ? 'boton-primario' : 'boton-secundario'}`,
              'aria-pressed': e.id === estacion ? 'true' : 'false',
              onClick: () => elegirEstacion(e.id),
            },
            e.name
          )
        )
      )
    );
  }

  function cambiarAviso() {
    aviso.alternar();
    pintarInterruptor();
  }

  render(
    outlet,
    h(
      'div',
      { class: 'space-y-4' },
      h(
        'div',
        { class: 'flex flex-wrap items-center justify-between gap-2' },
        h('h1', { class: 'text-xl font-semibold tracking-tight text-stone-900' }, 'Tablero de cocina'),
        h('div', { class: 'flex items-center gap-3' }, marca, interruptor)
      ),
      filtroEstacion,
      tablero
    )
  );
  pintarInterruptor();
  render(tablero, skeleton({ rows: 2 }));

  let vivo = true;
  // null en el primer refresco: sirve de línea base para no anunciar como
  // nuevos los pedidos que ya estaban al abrir la pantalla.
  let conocidos = null;
  const listas = lineasListas();

  await refrescar();
  const temporizador = setInterval(refrescar, REFRESCO_MS);
  window.addEventListener('focus', aviso.visto);

  async function refrescar() {
    if (!vivo) return;

    let tablero_;
    try {
      tablero_ = await api.get(`/kitchen/orders${branchQuery()}`);
    } catch (error) {
      if (vivo) render(tablero, errorBox(error.message, refrescar));
      return;
    }
    if (!vivo) return;

    pintarFiltro(tablero_.stations ?? []);

    // Filtrar en la pantalla y no en el servidor: el tablero ya viene
    // entero, y así cambiar de estación es instantáneo en vez de otra
    // vuelta a la red con la cocina llena.
    const soloDeLaEstacion = (pedido) => ({
      ...pedido,
      items: estacion === null ? pedido.items : pedido.items.filter((i) => i.station_id === estacion),
    });
    const conLineas = (lista) => lista.map(soloDeLaEstacion).filter((p) => p.items.length);

    const pedidos = conLineas(tablero_.orders);
    const despachados = conLineas(tablero_.dispatched);

    // Lo que entró desde el refresco anterior. Con las manos ocupadas nadie
    // mira la pantalla, así que se avisa en vez de repintar en silencio.
    const ids = new Set(pedidos.map((p) => p.id));
    if (conocidos !== null) {
      const nuevos = pedidos.filter((p) => !conocidos.has(p.id));
      if (nuevos.length) aviso.entraron(nuevos.length);
    }
    conocidos = ids;

    // Las marcas de línea de pedidos que ya no están se olvidan: si no, el
    // almacenamiento crecería sin fin con ids de ayer.
    listas.podar(pedidos.flatMap((p) => p.items.map((i) => i.id)));

    render(marca, icon('reloj', { size: 14 }), `Actualizado a las ${time(new Date().toISOString())}`);

    if (!pedidos.length && !despachados.length) {
      render(
        tablero,
        h(
          'div',
          { class: 'seccion' },
          empty('Todo al día', 'Los pedidos nuevos aparecen aquí solos, sin recargar.', null, 'check')
        )
      );
      return;
    }

    const columnas = tablero_.columns.filter((c) => COLUMNAS[c]);

    render(
      tablero,
      h(
        'div',
        { class: 'grid grid-cols-1 gap-4 items-start' },
        h(
          'div',
          { class: `grid grid-cols-1 ${REJILLA[Math.min(columnas.length, 4)] ?? REJILLA[3]} gap-4 items-start` },
          columnas.map((categoria) => {
            const columna = COLUMNAS[categoria];
            const suyos = pedidos.filter((p) => p.status.category === categoria);
            return h(
              'section',
              { class: 'space-y-3' },
              h(
                'div',
                { class: 'flex items-center gap-2 px-1' },
                h('h2', { class: 'text-sm font-semibold text-stone-700' }, columna.titulo),
                badge(String(suyos.length), columna.tono)
              ),
              suyos.length
                ? suyos.map((pedido) => ticket(pedido, refrescar, listas))
                : h(
                    'p',
                    { class: 'text-sm text-stone-400 px-1 py-6 text-center border border-dashed border-stone-200 rounded-xl' },
                    'Nada aquí'
                  )
            );
          })
        ),
        despachados.length ? bloqueDespachados(despachados, refrescar, listas) : null
      )
    );
  }

  return {
    destroy() {
      vivo = false;
      clearInterval(temporizador);
      window.removeEventListener('focus', aviso.visto);
      aviso.destroy();
    },
  };
}

/**
 * Lo despachado hace poco, para recuperar el pedido que se marcó listo por
 * error.
 *
 * Los botones son los que declara la máquina de estados del restaurante: si
 * no configuró camino de vuelta no habrá ninguno, y eso es correcto — el
 * tablero no inventa atajos. Con F5.2 el restaurante podrá configurarlo
 * desde la web.
 */
function bloqueDespachados(pedidos, refrescar, listas) {
  return h(
    'details',
    { class: 'seccion p-4' },
    h(
      'summary',
      { class: 'flex items-center gap-2 cursor-pointer text-sm font-medium text-stone-700' },
      icon('archivar', { size: 16 }),
      `Despachados hace poco (${pedidos.length})`
    ),
    h(
      'div',
      { class: 'grid grid-cols-1 md:grid-cols-3 gap-3 mt-3' },
      pedidos.map((pedido) => ticket(pedido, refrescar, listas))
    )
  );
}

/**
 * Una línea del pedido, marcable como ya preparada.
 *
 * En un pedido de doce productos, saber cuáles ya salieron es la diferencia
 * entre ir contando de memoria y no. La marca es local a esta pantalla: ver
 * `lineasListas()`.
 */
function linea(item, listas) {
  const marcada = listas.tiene(item.id);

  const fila = h(
    'li',
    {},
    h(
      'button',
      {
        class: `w-full text-left flex gap-2.5 rounded-lg px-1 -mx-1 py-0.5 transition ${
          marcada ? 'opacity-45' : 'hover:bg-stone-50'
        }`,
        'aria-pressed': marcada ? 'true' : 'false',
        title: marcada ? 'Marcada como preparada' : 'Marcar como preparada',
        onClick: () => {
          listas.alternar(item.id);
          fila.replaceWith(linea(item, listas));
        },
      },
      // La cantidad va aparte y con peso: es lo primero que busca cocina.
      h(
        'span',
        { class: `inline-flex items-center justify-center min-w-[26px] h-[26px] px-1.5 rounded-md text-sm font-bold tabular-nums shrink-0 ${
          marcada ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-100 text-stone-900'
        }` },
        marcada ? icon('check', { size: 15 }) : item.quantity
      ),
      h(
        'div',
        { class: 'min-w-0' },
        h(
          'span',
          { class: `text-[15px] font-medium text-stone-900 ${marcada ? 'line-through' : ''}` },
          item.name_snapshot
        ),
        // Un combo se prepara por partes: la cocina necesita los platos, no
        // el nombre del paquete.
        item.components?.length
          ? h(
              'div',
              { class: 'text-xs text-stone-700' },
              item.components.map((c) => `${c.quantity}× ${c.name_snapshot}`).join(' · ')
            )
          : null,
        item.modifiers.length
          ? h('div', { class: 'text-xs text-stone-500' }, item.modifiers.join(' · '))
          : null,
        item.notes
          ? h(
              'div',
              { class: 'flex items-center gap-1 text-xs text-amber-800 bg-amber-50 rounded px-1.5 py-0.5 mt-1' },
              icon('alerta', { size: 12 }),
              item.notes
            )
          : null
      )
    )
  );

  return fila;
}

/** La demora se señala con color y con el grosor del borde izquierdo, para
 *  que se distinga desde lejos y no dependa solo del color. */
/**
 * Las líneas de la tarjeta, con su tiempo cuando hay más de uno.
 *
 * Con un solo tiempo —lo normal, y siempre en un mostrador— no se nombra:
 * una etiqueta que sale en todas las tarjetas no distingue ninguna.
 */
function lineasPorTiempo(pedido, listas) {
  const numeros = [...new Set(pedido.items.map((i) => i.course ?? 1))].sort((a, b) => a - b);
  if (numeros.length < 2) return pedido.items.map((item) => linea(item, listas));

  const nombre = (n) =>
    pedido.kitchen_tickets?.find((t) => (t.course ?? 1) === n)?.course_name ?? `Tiempo ${n}`;

  return numeros.flatMap((n) => [
    h('li', { class: 'text-[11px] font-semibold uppercase tracking-wide text-stone-500 pt-1' }, nombre(n)),
    ...pedido.items.filter((i) => (i.course ?? 1) === n).map((item) => linea(item, listas)),
  ]);
}

function urgencia(minutos) {
  if (minutos >= TARDE_MINUTOS) return { clase: 'ticket-tarde', texto: 'text-red-700 font-semibold' };
  if (minutos >= ATENTO_MINUTOS) return { clase: 'ticket-atento', texto: 'text-amber-700 font-medium' };
  return { clase: 'ticket-fresco', texto: 'text-stone-500' };
}

function ticket(pedido, refrescar, listas) {
  const espera = minutesSince(pedido.created_at);
  const nivel = urgencia(espera);

  return h(
    'article',
    // Sin animación de entrada: el tablero se repinta cada 15 segundos y una
    // sacudida en cada refresco distrae más de lo que aporta.
    { class: `ticket ${nivel.clase} p-4 flex flex-col gap-3` },

    h(
      'div',
      { class: 'flex items-start justify-between gap-2' },
      h(
        'div',
        { class: 'min-w-0' },
        h(
          'button',
          {
            class: 'text-2xl font-bold tracking-tight text-stone-900 tabular-nums hover:underline',
            title: 'Ver el detalle',
            onClick: () => abrirPedido(pedido.id, { alCambiar: refrescar }),
          },
          pedido.order_number
        ),
        h(
          'div',
          { class: 'flex items-center gap-1.5 text-xs text-stone-500 mt-0.5' },
          icon(pedido.table_code ? 'mesa' : 'domicilio', { size: 14 }),
          pedido.table_code ? `Mesa ${pedido.table_code}` : canal(pedido.channel)
        )
      ),
      h(
        'div',
        { class: `flex items-center gap-1 text-sm shrink-0 ${nivel.texto}` },
        icon('reloj', { size: 15 }),
        elapsed(pedido.created_at)
      )
    ),

    h('ul', { class: 'space-y-2' }, lineasPorTiempo(pedido, listas)),

    // Lo que el mesero todavía no ha marchado. Sin decirlo, la cocina da
    // por despachado un pedido al que le falta el postre.
    pedido.pending_courses?.length
      ? h(
          'div',
          { class: 'text-xs text-amber-800 bg-amber-50 rounded px-1.5 py-1' },
          `Falta por marchar: ${pedido.pending_courses.map((c) => c.name).join(', ')}`
        )
      : null,

    h(
      'div',
      { class: 'flex flex-wrap gap-2 mt-auto pt-1' },
      // Sale marcada como reimpresión: una comanda repetida sin avisar es
      // un plato preparado dos veces.
      button('Reimprimir', {
        variant: 'secondary',
        iconName: 'archivar',
        onClick: () => imprimirComanda(pedido, { reimpresion: true }),
      }),
      pedido.next_statuses.map((estado) =>
        button(estado.name, {
          variant: estado.category === 'cancelled' ? 'danger' : 'primary',
          iconName: estado.category === 'cancelled' ? 'cerrar' : 'check',
          onClick: (event) => avanzar(event.currentTarget, pedido, estado, refrescar),
        })
      )
    )
  );
}

async function avanzar(boton, pedido, estado, refrescar) {
  // Anular exige motivo y no se puede si el pedido tiene plata encima; el
  // diálogo compartido se encarga de las dos cosas.
  if (estado.category === 'cancelled') {
    return abrirCancelacion(pedido, estado, refrescar);
  }

  boton.disabled = true;
  try {
    await api.post(`/orders/${pedido.id}/status`, { to_status_id: estado.id });
    await refrescar();
  } catch (error) {
    // Aquí aterrizan las reglas del backend: falta de permiso para esa
    // transición, o un pedido que no se puede completar sin estar pagado.
    toast(error.message);
    boton.disabled = false;
  }
}

const NOMBRE_CANAL = {
  counter: 'Mostrador',
  table: 'Mesa',
  delivery: 'Domicilio',
  whatsapp: 'WhatsApp',
  app: 'App',
};

export function canal(code) {
  return NOMBRE_CANAL[code] ?? code;
}

// =========================================================
// Aviso de pedido nuevo (F3.3)
// =========================================================

/**
 * Avisa cuando entra un pedido: un sonido corto y un contador en el título
 * de la pestaña.
 *
 * Hace falta porque el tablero se repinta cada quince segundos en silencio,
 * y en una cocina nadie está mirando la pantalla: se entera cuando pasa por
 * delante. El contador en la pestaña es para cuando el KDS comparte
 * navegador con otra cosa.
 *
 * Lleva interruptor y se recuerda **por dispositivo**, no por usuario: que la
 * cocina abierta al comedor quiera silencio no dice nada de lo que quiera la
 * tableta del mostrador.
 */
function crearAviso() {
  const tituloOriginal = document.title;
  let activo = leerPreferencia();
  let sinVer = 0;
  let audio = null;

  function leerPreferencia() {
    try {
      // Suena salvo que alguien lo haya apagado: un KDS que no avisa es el
      // problema que esto viene a resolver.
      return localStorage.getItem(AVISO_KEY) !== 'no';
    } catch {
      return true;
    }
  }

  /**
   * Dos pitidos cortos, sintetizados en vez de servidos como archivo: no hay
   * paso de compilación donde meter un binario, y así también suena cuando la
   * tableta se quede sin red (fase 3.5).
   *
   * El navegador puede tener el audio bloqueado hasta que alguien toque la
   * pantalla. Si es el caso no se insiste: queda el contador del título.
   */
  function pitar() {
    try {
      audio ??= new (window.AudioContext ?? window.webkitAudioContext)();
      if (audio.state === 'suspended') audio.resume();
      if (audio.state !== 'running') return;

      [0, 0.18].forEach((retraso) => {
        const inicio = audio.currentTime + retraso;
        const osc = audio.createOscillator();
        const gan = audio.createGain();
        osc.connect(gan);
        gan.connect(audio.destination);
        osc.frequency.value = 880;
        // Rampa en vez de encender y apagar en seco, que suena a chasquido.
        gan.gain.setValueAtTime(0.0001, inicio);
        gan.gain.exponentialRampToValueAtTime(0.15, inicio + 0.01);
        gan.gain.exponentialRampToValueAtTime(0.0001, inicio + 0.14);
        osc.start(inicio);
        osc.stop(inicio + 0.15);
      });
    } catch {
      // Sin audio disponible el tablero sigue sirviendo; queda el título.
    }
  }

  function pintarTitulo() {
    document.title = sinVer ? `(${sinVer}) ${tituloOriginal}` : tituloOriginal;
  }

  return {
    get activo() {
      return activo;
    },

    alternar() {
      activo = !activo;
      try {
        localStorage.setItem(AVISO_KEY, activo ? 'si' : 'no');
      } catch {
        // Sin almacenamiento la elección vale para esta sesión.
      }
      // Al encenderlo suena una vez: confirma que hay sonido y, de paso,
      // desbloquea el audio, que necesita un gesto de la persona.
      if (activo) pitar();
      return activo;
    },

    entraron(cuantos) {
      sinVer += cuantos;
      pintarTitulo();
      if (activo) pitar();
    },

    /** Alguien volvió a la pestaña: ya se enteró. */
    visto() {
      if (!sinVer) return;
      sinVer = 0;
      pintarTitulo();
    },

    destroy() {
      document.title = tituloOriginal;
    },
  };
}

// =========================================================
// Líneas ya preparadas (F3.4)
// =========================================================

/**
 * Marcas de "esta línea ya está" dentro de un pedido grande.
 *
 * Viven en el navegador y no en la base a propósito: son una ayuda para
 * quien está cocinando ahora mismo, no un dato del pedido. La consecuencia
 * es que dos pantallas de cocina no las comparten; si algún día hiciera
 * falta que sí, es una columna en `order_items` y un endpoint, no un parche
 * sobre esto.
 *
 * Se guardan en localStorage para que sobrevivan al refresco de la página,
 * que en una tableta pasa más de lo que uno quisiera.
 */
function lineasListas() {
  let marcadas = new Set();
  try {
    marcadas = new Set(JSON.parse(localStorage.getItem(LINEAS_KEY) ?? '[]'));
  } catch {
    // Contenido corrupto o sin almacenamiento: se empieza de cero.
  }

  const guardar = () => {
    try {
      localStorage.setItem(LINEAS_KEY, JSON.stringify([...marcadas]));
    } catch {
      // La marca sigue valiendo en memoria.
    }
  };

  return {
    tiene: (itemId) => marcadas.has(itemId),

    alternar(itemId) {
      if (marcadas.has(itemId)) marcadas.delete(itemId);
      else marcadas.add(itemId);
      guardar();
      return marcadas.has(itemId);
    },

    /** Olvida las marcas de líneas que ya no están en el tablero. */
    podar(idsVigentes) {
      const vigentes = new Set(idsVigentes);
      const antes = marcadas.size;
      marcadas = new Set([...marcadas].filter((id) => vigentes.has(id)));
      if (marcadas.size !== antes) guardar();
    },
  };
}
