// Administración del restaurante: cómo opera, sucursales, impuestos, y
// usuarios con sus roles.
//
// Cada sección se dibuja según los permisos del token. Eso es comodidad, no
// seguridad: el backend rechaza por su cuenta lo que no corresponde.

import { api } from '../api.js';
import { money, percent } from '../format.js';
import { icon } from '../icons.js';
import * as router from '../router.js';
import { activeBranch, branchQuery, can, load as cargarSesion } from '../session.js';
import {
  badge, button, card, confirm, empty, errorBox, field, h, input, loading, pageHeader, render,
  section, select, skeleton, tabs, titledCard, toast,
} from '../ui.js';

const CANALES = [
  ['counter', 'Mostrador'],
  ['table', 'Mesa'],
  ['delivery', 'Domicilio'],
  ['whatsapp', 'WhatsApp'],
  ['app', 'App'],
];

// Los mismos que valida Domain\TenantSettings::BUSINESS_TYPES. Solo deciden
// los valores por defecto de un restaurante recién creado, así que cambiarlo
// después no cambia cómo opera: eso lo dicen los interruptores de abajo.
const MODELOS = [
  ['fast_food', 'Comida rápida'],
  ['table_service', 'Servicio en mesa'],
  ['delivery', 'Domicilios'],
];

const SECCIONES = [
  { clave: 'config', etiqueta: 'Cómo opera', icono: 'admin', permiso: 'settings.view' },
  { clave: 'sucursales', etiqueta: 'Sucursales', icono: 'sucursal', permiso: 'settings.view' },
  { clave: 'horarios', etiqueta: 'Horarios', icono: 'reloj', permiso: 'settings.view' },
  { clave: 'estados', etiqueta: 'Estados', icono: 'etiqueta', permiso: 'settings.view' },
  { clave: 'impuestos', etiqueta: 'Impuestos', icono: 'impuesto', permiso: 'settings.view' },
  // Aparece si el restaurante tiene el canal de domicilios activo: por
  // configuración, no por un condicional sobre el tenant.
  {
    clave: 'domicilios',
    etiqueta: 'Domicilios',
    icono: 'domicilio',
    permiso: 'settings.view',
    visible: ({ ajustes }) => ajustes.channels.includes('delivery'),
  },
  { clave: 'impresion', etiqueta: 'Impresión', icono: 'archivar', permiso: 'branches.manage' },
  { clave: 'facturacion', etiqueta: 'Facturación', icono: 'impuesto', permiso: 'settings.view' },
  { clave: 'equipo', etiqueta: 'Equipo', icono: 'clientes', permiso: 'users.manage' },
];

export async function admin(outlet) {
  const nav = h('div');
  const panel = h('div', { class: 'space-y-4' });
  render(
    outlet,
    pageHeader('Administración', { hint: 'Configura cómo opera tu restaurante y quién puede hacer qué.' }),
    nav,
    panel
  );
  render(panel, skeleton({ rows: 2 }));

  const estado = {};
  try {
    await recargar();
  } catch (error) {
    return render(panel, errorBox(error.message, () => admin(outlet)));
  }

  async function recargar() {
    const [ajustes, sucursales, impuestos] = await Promise.all([
      api.get('/settings'),
      api.get('/branches'),
      api.get('/tax-rates'),
    ]);
    Object.assign(estado, { ajustes, sucursales, impuestos });

    // Los perfiles de impresión son de la sucursal activa y los edita quien
    // administra sedes.
    if (can('branches.manage')) {
      estado.impresion = await api.get(`/print-profiles${branchQuery()}`);
    }

    estado.resoluciones = await api.get('/fiscal/resolutions');

    if (can('users.manage')) {
      const [roles, usuarios, permisos] = await Promise.all([
        api.get('/roles'),
        api.get('/users'),
        api.get('/permissions'),
      ]);
      Object.assign(estado, { roles, usuarios, permisos });
    }

    // Las zonas son de cada sucursal, así que se piden por la sucursal
    // activa: cambiar de sede en la barra lateral remonta la pantalla.
    const sede = activeBranch();
    estado.sede = sede;
    estado.zonas = sede && ajustes.channels.includes('delivery')
      ? await api.get(`/branches/${sede.id}/delivery-zones`)
      : [];
    estado.horarios = sede
      ? await api.get(`/branches/${sede.id}/schedules`)
      : { schedules: [], channels_without_windows: [] };
    // Los estados son del restaurante entero, no de una sede.
    estado.flujo = await api.get('/order-statuses');
  }

  const disponibles = SECCIONES.filter((s) => can(s.permiso) && (s.visible?.(estado) ?? true));
  let activa = disponibles[0]?.clave;

  function mostrar(clave) {
    activa = clave;
    render(
      nav,
      tabs(
        disponibles.map((s) => ({ key: s.clave, label: s.etiqueta })),
        activa,
        mostrar
      )
    );

    const refrescar = async () => {
      await recargar();
      mostrar(activa);
    };

    if (clave === 'config') render(panel, seccionConfig(estado));
    else if (clave === 'sucursales') render(panel, seccionSucursales(estado, refrescar));
    else if (clave === 'horarios') render(panel, seccionHorarios(estado, refrescar));
    else if (clave === 'estados') render(panel, seccionEstados(estado, refrescar));
    else if (clave === 'impuestos') render(panel, seccionImpuestos(estado, refrescar));
    else if (clave === 'domicilios') render(panel, seccionDomicilios(estado, refrescar));
    else if (clave === 'impresion') render(panel, seccionImpresion(estado, refrescar));
    else if (clave === 'facturacion') render(panel, seccionFacturacion(estado, refrescar));
    else render(panel, seccionEquipo(estado, refrescar));
  }

  mostrar(activa);
}

// =========================================================
// Facturación
// =========================================================

/**
 * La numeración autorizada.
 *
 * Es del restaurante y la autoriza la DIAN por resolución: prefijo, rango y
 * vigencia. Existe aunque no haya proveedor tecnológico conectado, porque el
 * papel que se entrega ya lleva el número — y quedarse sin rango es dejar de
 * facturar, así que se avisa antes.
 */
function seccionFacturacion(estado, refrescar) {
  const numero = input({ placeholder: '18764000001234', class: 'campo' });
  const prefijo = input({ placeholder: 'POS', class: 'campo w-24' });
  const desde = input({ type: 'number', min: '1', placeholder: '1', class: 'campo w-28 tabular-nums' });
  const hasta = input({ type: 'number', min: '1', placeholder: '5000', class: 'campo w-28 tabular-nums' });
  const vence = input({ type: 'date', class: 'campo' });
  const aviso = h('div');

  const crear = button('Activar resolución', {
    onClick: async () => {
      render(aviso);
      crear.disabled = true;
      try {
        await api.post(`/fiscal/resolutions${branchQuery()}`, {
          number: numero.value.trim(),
          prefix: prefijo.value.trim(),
          range_from: Number(desde.value || 0),
          range_to: Number(hasta.value || 0),
          valid_until: vence.value || null,
        });
        toast('Resolución activada', 'ok');
        await refrescar();
      } catch (error) {
        render(aviso, errorBox(error.message));
        crear.disabled = false;
      }
    },
  });

  const activa = estado.resoluciones.find((r) => r.is_active);

  return [
    section('Numeración autorizada', {
      hint: 'La resolución con la que se numeran los documentos de esta sucursal. Activar una nueva apaga la anterior.',
      body: h(
        'div',
        { class: 'space-y-2' },
        h(
          'div',
          { class: 'flex flex-wrap items-end gap-2' },
          field('Resolución', numero),
          field('Prefijo', prefijo),
          field('Desde', desde),
          field('Hasta', hasta),
          field('Vence', vence),
          crear
        ),
        aviso,
        activa && activa.running_out
          ? h(
              'div',
              { class: 'text-[13px] text-amber-800 bg-amber-50 rounded px-2 py-1.5' },
              `Quedan ${activa.remaining} números. Pide una resolución nueva antes de que se acaben: sin rango no se puede facturar.`
            )
          : null
      ),
      list: estado.resoluciones.length
        ? estado.resoluciones.map((r) =>
            h(
              'div',
              { class: 'fila' },
              h(
                'div',
                { class: 'flex-1 min-w-0' },
                h(
                  'div',
                  { class: 'text-[13.5px] text-stone-900 flex items-center gap-2' },
                  `${r.prefix}${r.range_from} – ${r.prefix}${r.range_to}`,
                  r.is_active ? badge('Activa', 'ok') : badge('Agotada o reemplazada')
                ),
                h(
                  'div',
                  { class: 'text-[12px] text-stone-500' },
                  [
                    `Resolución ${r.number}`,
                    r.valid_until ? `vence ${r.valid_until}` : 'sin vencimiento',
                    `quedan ${r.remaining}`,
                  ].join(' · ')
                )
              )
            )
          )
        : [h('p', { class: 'text-[13px] text-stone-500' }, 'Sin resolución: todavía no se puede emitir ningún documento.')],
    }),
  ];
}

// =========================================================
// Impresión
// =========================================================

const DOCUMENTOS = {
  comanda: { titulo: 'Comanda de cocina', ayuda: 'La que se manda a preparar. Una por estación.' },
  ticket: { titulo: 'Ticket del cliente', ayuda: 'El que se lleva quien paga.' },
  precuenta: { titulo: 'Pre-cuenta', ayuda: 'La que se lleva a la mesa antes de cobrar.' },
};

/**
 * Cómo imprime esta sucursal.
 *
 * Es por sede y no por empresa: la térmica de la sede nueva no tiene por qué
 * ser la misma que la de la principal. Sin tocar nada, todo sale como
 * siempre —80 mm y una copia—, así que nadie tiene que configurar esto para
 * poder imprimir.
 */
function seccionImpresion(estado, refrescar) {
  return section(`Impresión en ${activeBranch()?.name ?? 'esta sucursal'}`, {
    hint: 'El ancho del papel y cuántas copias sale cada documento. Solo afecta a esta sucursal.',
    list: estado.impresion.map((perfil) => {
      const info = DOCUMENTOS[perfil.document] ?? { titulo: perfil.document, ayuda: '' };
      const ancho = select(
        [58, 80].map((mm) => ({ value: String(mm), label: `${mm} mm`, selected: mm === perfil.width_mm })),
        { class: 'campo w-auto', 'aria-label': `Ancho de ${info.titulo}` }
      );
      const copias = select(
        [1, 2, 3].map((n) => ({ value: String(n), label: n === 1 ? '1 copia' : `${n} copias`, selected: n === perfil.copies })),
        { class: 'campo w-auto', 'aria-label': `Copias de ${info.titulo}` }
      );

      return h(
        'div',
        { class: 'fila' },
        h(
          'div',
          { class: 'flex-1 min-w-0' },
          h('div', { class: 'text-[13.5px] text-stone-900' }, info.titulo),
          h('div', { class: 'text-[12px] text-stone-500' }, info.ayuda)
        ),
        ancho,
        copias,
        button('Guardar', {
          variant: 'secondary',
          onClick: async (e) => {
            const boton = e.currentTarget;
            boton.disabled = true;
            try {
              await api.put(`/print-profiles/${perfil.document}${branchQuery()}`, {
                width_mm: Number(ancho.value),
                copies: Number(copias.value),
              });
              toast('Impresión actualizada', 'ok');
              await refrescar();
            } catch (error) {
              toast(error.message);
              boton.disabled = false;
            }
          },
        })
      );
    }),
  });
}

// =========================================================
// Cómo opera
// =========================================================

function seccionConfig({ ajustes }) {
  const editable = can('settings.edit');

  const nombre = input({ value: ajustes.name, maxlength: '150', disabled: !editable });
  const modelo = select(
    MODELOS.map(([value, label]) => ({ value, label, selected: value === ajustes.business_type })),
    { disabled: !editable }
  );
  const moneda = input({
    value: ajustes.currency,
    maxlength: '3',
    class: 'campo uppercase tabular-nums',
    disabled: !editable,
  });

  const casillas = CANALES.map(([code, nombre]) => ({
    code,
    control: h('input', {
      type: 'checkbox',
      class: 'w-4 h-4 rounded border-stone-300',
      checked: ajustes.channels.includes(code),
      disabled: !editable,
    }),
    nombre,
  }));
  const mesas = h('input', { type: 'checkbox', class: 'w-4 h-4 rounded', checked: ajustes.uses_tables, disabled: !editable });
  const propina = h('input', { type: 'checkbox', class: 'w-4 h-4 rounded', checked: ajustes.asks_tip, disabled: !editable });

  return [
    titledCard(
      'Cómo opera el restaurante',
      h(
        'div',
        { class: 'grid grid-cols-1 sm:grid-cols-[2fr_1fr_auto] gap-3 mb-5' },
        field('Nombre', nombre),
        field('Modelo de negocio', modelo, 'Solo fija los valores por defecto'),
        field('Moneda', moneda)
      ),
      h('div', { class: 'text-sm font-medium text-stone-700 mb-2' }, 'Canales de venta activos'),
      h(
        'div',
        { class: 'flex flex-wrap gap-4 mb-4' },
        casillas.map((c) => h('label', { class: 'flex items-center gap-2 text-sm' }, c.control, c.nombre))
      ),
      h(
        'div',
        { class: 'space-y-2 mb-4' },
        h('label', { class: 'flex items-center gap-2 text-sm' }, mesas, 'Maneja mesas'),
        h('label', { class: 'flex items-center gap-2 text-sm' }, propina, 'Pide propina')
      ),
      editable
        ? button('Guardar cambios', {
            onClick: async (e) => {
              // Se guarda antes de cualquier `await`: el navegador vacía
              // `currentTarget` en cuanto termina el despacho del evento, y
              // el diálogo de la moneda ocurre justo en medio.
              const guardar = e.currentTarget;
              const monedaNueva = moneda.value.trim().toUpperCase();
              const cambiaMoneda = monedaNueva !== ajustes.currency;

              // La API exige la confirmación aparte (`Domain\TenantProfile`),
              // así que esto no es solo cortesía: sin el visto bueno el
              // guardado se rechaza.
              if (cambiaMoneda) {
                const seguro = await confirm({
                  title: `¿Cambiar la moneda de ${ajustes.currency} a ${monedaNueva}?`,
                  message:
                    'Los pedidos que ya se emitieron no se reconvierten: sus cifras se quedan como están y pasarían a leerse en la moneda nueva.',
                  confirmLabel: 'Cambiar la moneda',
                });
                if (!seguro) return;
              }

              guardar.disabled = true;
              try {
                await api.patch('/settings', {
                  name: nombre.value.trim(),
                  business_type: modelo.value,
                  currency: monedaNueva,
                  confirm_currency_change: cambiaMoneda,
                  channels: casillas.filter((c) => c.control.checked).map((c) => c.code),
                  uses_tables: mesas.checked,
                  asks_tip: propina.checked,
                });
                toast('Configuración guardada', 'ok');
                // El nombre se lee en el rail y la moneda en cada cifra de la
                // aplicación: repintar solo esta pantalla dejaría las dos
                // viejas hasta la siguiente navegación.
                await recargarSesion();
              } catch (error) {
                toast(error.message);
                guardar.disabled = false;
              }
            },
          })
        : h('p', { class: 'text-xs text-stone-500' }, 'No tienes permiso para editar esta configuración.')
    ),
    h(
      'p',
      { class: 'text-xs text-stone-500 px-1' },
      'Esto cambia cómo se comporta el sistema sin tocar código: los canales apagados se rechazan al tomar un pedido, y sin mesas ni propina esos campos desaparecen de la pantalla de venta.'
    ),
  ];
}

/**
 * Vuelve a leer /auth/me y repinta todo: el enrutador llama de nuevo al
 * pintado de la estructura, así que el rail y esta pantalla salen con los
 * datos nuevos. Por eso no hace falta el `refrescar()` de la sección.
 */
async function recargarSesion() {
  await cargarSesion();
  await router.reload();
}

// =========================================================
// Sucursales
// =========================================================

function seccionSucursales({ sucursales, ajustes }, refrescar) {
  const gestiona = can('branches.manage');
  const panelMesas = h('div');

  const nombre = input({ placeholder: 'Ej. Sede Norte' });
  const codigo = input({ placeholder: 'NOR', maxlength: '10' });
  const direccion = input({ placeholder: 'Dirección' });
  const zona = input({ value: 'America/Bogota' });

  return [
    titledCard(
      'Sucursales',
      sucursales.length
        ? h(
            'div',
            { class: 'divide-y divide-stone-100' },
            sucursales.map((b) =>
              h(
                'div',
                { class: 'py-3 flex flex-wrap items-center gap-3' },
                h(
                  'div',
                  { class: 'flex-1 min-w-[180px]' },
                  h(
                    'div',
                    { class: 'font-medium text-sm text-stone-900 flex items-center gap-2' },
                    b.name,
                    badge(b.code),
                    b.is_active ? null : badge('Inactiva', 'warn')
                  ),
                  h('div', { class: 'text-xs text-stone-500' }, [b.timezone, b.address].filter(Boolean).join(' · '))
                ),
                gestiona
                  ? button(b.is_active ? 'Desactivar' : 'Activar', {
                      variant: 'secondary',
                      onClick: async () => {
                        if (
                          b.is_active &&
                          !(await confirm({
                            title: `¿Desactivar “${b.name}”?`,
                            message: 'Deja de estar disponible para operar. Puedes reactivarla después.',
                            confirmLabel: 'Desactivar',
                          }))
                        ) {
                          return;
                        }
                        try {
                          await api.patch(`/branches/${b.id}/active`, { is_active: !b.is_active });
                          await refrescar();
                        } catch (error) {
                          toast(error.message);
                        }
                      },
                    })
                  : null,
                gestiona
                  ? button('Mesas', { variant: 'secondary', onClick: () => verMesas(b, panelMesas, ajustes) })
                  : null
              )
            )
          )
        : empty('Sin sucursales'),
    ),

    gestiona
      ? titledCard(
          'Nueva sucursal',
          h(
            'div',
            { class: 'grid grid-cols-1 sm:grid-cols-2 gap-3' },
            field('Nombre', nombre),
            field('Código', codigo, 'Es el prefijo del número de pedido: NOR-00001.'),
            field('Dirección', direccion),
            field('Zona horaria', zona, 'Define a qué día pertenece cada venta en los reportes.')
          ),
          h(
            'div',
            { class: 'mt-3' },
            button('Crear sucursal', {
              onClick: async () => {
                try {
                  await api.post('/branches', {
                    name: nombre.value.trim(),
                    code: codigo.value.trim().toUpperCase(),
                    address: direccion.value.trim() || null,
                    timezone: zona.value.trim(),
                  });
                  toast('Sucursal creada', 'ok');
                  await refrescar();
                } catch (error) {
                  toast(error.message);
                }
              },
            })
          )
        )
      : null,

    panelMesas,
  ];
}

async function verMesas(sucursal, host, ajustes) {
  if (!ajustes.uses_tables) {
    return render(
      host,
      card(
        empty(
          'Este restaurante no maneja mesas',
          'Actívalo en “Cómo opera” para poder crearlas.'
        )
      )
    );
  }

  render(host, loading('Cargando mesas…'));
  let mesas;
  try {
    mesas = await api.get(`/branches/${sucursal.id}/tables`);
  } catch (error) {
    return render(host, errorBox(error.message));
  }

  const codigo = input({ placeholder: 'M1', class: 'w-28 rounded-lg border border-stone-300 px-3 py-2 text-sm' });
  const capacidad = input({ type: 'number', min: '1', value: '4', class: 'w-24 rounded-lg border border-stone-300 px-3 py-2 text-sm' });

  render(
    host,
    titledCard(
      `Mesas de ${sucursal.name}`,
      mesas.length
        ? h(
            'div',
            { class: 'flex flex-wrap gap-2 mb-3' },
            mesas.map((m) =>
              h(
                'span',
                { class: 'border border-stone-300 rounded-lg px-3 py-1.5 text-sm' },
                m.code,
                h('span', { class: 'text-stone-500' }, ` · ${m.capacity} personas`)
              )
            )
          )
        : h('p', { class: 'text-sm text-stone-500 mb-3' }, 'Esta sucursal todavía no tiene mesas.'),
      h(
        'div',
        { class: 'flex flex-wrap gap-2 items-end' },
        codigo,
        capacidad,
        button('Agregar mesa', {
          onClick: async () => {
            try {
              await api.post(`/branches/${sucursal.id}/tables`, {
                code: codigo.value.trim(),
                capacity: Number(capacidad.value || 4),
              });
              codigo.value = '';
              toast('Mesa creada', 'ok');
              await verMesas(sucursal, host, ajustes);
            } catch (error) {
              toast(error.message);
            }
          },
        })
      )
    )
  );
}

// =========================================================
// Horarios
// =========================================================

// Lunes = 0, la misma convención que `branch_schedules` y que
// `Domain\ScheduleRules::DIAS`.
const DIAS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

function seccionHorarios({ horarios, sede, sucursales }, refrescar) {
  const gestiona = can('branches.manage');

  if (!sede) {
    return card(
      empty('Elige una sucursal', 'Los horarios son de cada sede. Selecciona una en la barra lateral.', null, 'sucursal')
    );
  }

  const dia = select(DIAS.map((nombre, i) => ({ value: String(i), label: nombre })));
  const desde = input({ type: 'time', value: '10:00' });
  const hasta = input({ type: 'time', value: '22:00' });
  const canal = select([
    { value: '', label: 'Todos los canales' },
    ...CANALES.map(([code, nombre]) => ({ value: code, label: nombre })),
  ]);

  const nombreCanal = (code) => CANALES.find(([c]) => c === code)?.[1] ?? code;

  const filas = horarios.schedules;
  const sinCobertura = horarios.channels_without_windows;

  return [
    titledCard(
      `Horarios · ${sede.name}`,
      h(
        'div',
        { class: 'text-[13px] text-stone-600 space-y-1.5 mb-4 border-l-2 border-amber-300 pl-3' },
        h(
          'p',
          {},
          h('b', {}, 'Sin ninguna franja la sucursal atiende siempre.'),
          ' En cuanto haya una, solo se puede pedir dentro de las que apliquen al canal.'
        ),
        h(
          'p',
          {},
          h('b', {}, 'Una franja sin canal vale para todos.'),
          ' Poner una de mostrador y otra de domicilio es lo que permite cerrar los domicilios a las 10 y seguir atendiendo en la barra.'
        ),
        // La zona horaria sale de /branches, que es donde viaja: la de
        // /auth/me solo trae lo que el rail necesita para el selector.
        h('p', {}, `Las horas se leen en la zona horaria de la sede: ${sucursales.find((b) => b.id === sede.id)?.timezone ?? 'la suya'}.`)
      ),

      // Con horarios configurados, un canal sin franjas queda cerrado siempre
      // y no da ninguna señal hasta que alguien intenta vender.
      sinCobertura.length
        ? h(
            'p',
            { class: 'text-[13px] text-amber-800 bg-amber-50 border border-amber-200 rounded-[--r] px-3 py-2 mb-3' },
            `Sin franjas para ${sinCobertura.map(nombreCanal).join(' y ')}: por ahí no se puede pedir en ningún momento. Agrega una franja para ese canal, o una sin canal que valga para todos.`
          )
        : null,

      filas.length
        ? h(
            'div',
            { class: 'divide-y divide-stone-100' },
            filas.map((f) =>
              h(
                'div',
                { class: `py-2.5 flex flex-wrap items-center gap-3 ${f.is_active ? '' : 'opacity-50'}` },
                h('div', { class: 'w-24 text-sm font-medium text-stone-900' }, DIAS[f.weekday]),
                h(
                  'div',
                  { class: 'text-sm tabular-nums text-stone-700' },
                  `${f.opens_at} – ${f.closes_at}`,
                  // Cerrar antes de abrir no es un error de captura: es la
                  // franja nocturna, y decirlo evita que alguien la "corrija".
                  f.crosses_midnight ? h('span', { class: 'text-stone-500' }, ' (del día siguiente)') : null
                ),
                h(
                  'div',
                  { class: 'flex-1 min-w-[120px]' },
                  f.channel ? badge(nombreCanal(f.channel), 'info') : badge('Todos los canales')
                ),
                f.is_active ? null : badge('Apagada', 'warn'),
                gestiona
                  ? button(f.is_active ? 'Apagar' : 'Encender', {
                      variant: 'secondary',
                      onClick: async () => {
                        try {
                          await api.patch(`/schedules/${f.id}/active`, { is_active: !f.is_active });
                          await refrescar();
                        } catch (error) {
                          toast(error.message);
                        }
                      },
                    })
                  : null,
                gestiona
                  ? button('Borrar', {
                      variant: 'secondary',
                      onClick: async () => {
                        try {
                          await api.delete(`/schedules/${f.id}`);
                          await refrescar();
                        } catch (error) {
                          toast(error.message);
                        }
                      },
                    })
                  : null
              )
            )
          )
        : h('p', { class: 'text-sm text-stone-500' }, 'Sin franjas: esta sede atiende a cualquier hora.'),

      gestiona
        ? h(
            'div',
            { class: 'flex flex-wrap items-end gap-2 pt-4 mt-2 border-t border-stone-100' },
            h('div', { class: 'min-w-[130px]' }, field('Día', dia)),
            h('div', {}, field('Abre', desde)),
            h('div', {}, field('Cierra', hasta)),
            h('div', { class: 'min-w-[150px]' }, field('Canal', canal)),
            button('Agregar franja', {
              onClick: async (e) => {
                const boton = e.currentTarget;
                boton.disabled = true;
                try {
                  await api.post(`/branches/${sede.id}/schedules`, {
                    weekday: Number(dia.value),
                    opens_at: desde.value,
                    closes_at: hasta.value,
                    channel: canal.value || null,
                  });
                  toast('Franja agregada', 'ok');
                  await refrescar();
                } catch (error) {
                  toast(error.message);
                  boton.disabled = false;
                }
              },
            })
          )
        : null
    ),

    h(
      'p',
      { class: 'text-xs text-stone-500 px-1' },
      'Para una sede que cierra pasada la medianoche, pon la hora de cierre menor que la de apertura: “Viernes 20:00 – 02:00” abre el viernes por la noche y cierra la madrugada del sábado.'
    ),
  ];
}

// =========================================================
// Estados de pedido
// =========================================================

// El vocabulario fijo de la plataforma: los nombres los pone cada
// restaurante, estas seis categorías no. El KDS, los reportes y los filtros
// se apoyan en ellas y nunca en el nombre.
const CATEGORIAS = {
  new: 'Nuevo',
  kitchen: 'En cocina',
  ready: 'Listo',
  in_transit: 'En camino',
  completed: 'Completado',
  cancelled: 'Anulado',
};

function seccionEstados({ flujo }, refrescar) {
  const edita = can('settings.edit');
  const { statuses, transitions, permissions } = flujo;

  const salidasDe = (id) => transitions.filter((t) => t.from === id);

  /** Una tarjeta por estado: sus datos arriba y sus salidas abajo. */
  function tarjeta(estado) {
    const nombre = input({ value: estado.name, maxlength: '80', class: 'campo flex-1 min-w-[150px]', disabled: !edita });
    const categoria = select(
      Object.entries(CATEGORIAS).map(([value, label]) => ({ value, label, selected: value === estado.category })),
      { disabled: !edita }
    );
    const color = h('input', {
      type: 'color',
      value: estado.color ?? '#78716c',
      class: 'h-9 w-12 rounded-lg border border-stone-300 bg-white p-1',
      disabled: !edita,
    });
    const orden = input({ type: 'number', value: estado.sort_order, class: 'campo w-20 tabular-nums', disabled: !edita });
    const esFinal = h('input', {
      type: 'checkbox',
      class: 'w-4 h-4 rounded border-stone-300',
      checked: estado.is_final,
      disabled: !edita,
    });

    // Una fila por cada otro estado: marcada, se puede ir ahí. Verlas todas
    // —y no solo las configuradas— es lo que deja ver de un vistazo que un
    // estado se quedó sin ninguna salida.
    const destinos = statuses
      .filter((s) => s.id !== estado.id)
      .map((s) => {
        const actual = salidasDe(estado.id).find((t) => t.to === s.id);
        const marcado = h('input', {
          type: 'checkbox',
          class: 'w-4 h-4 rounded border-stone-300',
          checked: Boolean(actual),
          disabled: !edita,
        });
        const permiso = select(
          [
            { value: '', label: 'Cualquiera', selected: !actual?.permission },
            ...permissions.map((p) => ({
              value: p.code,
              label: p.description,
              selected: actual?.permission === p.code,
            })),
          ],
          { class: 'campo h-8 py-0 text-[12.5px] flex-1 min-w-[180px]', disabled: !edita }
        );
        return { estado: s, marcado, permiso };
      });

    return card(
      h(
        'div',
        { class: 'flex flex-wrap items-end gap-2' },
        h('div', { class: 'flex-1 min-w-[150px]' }, field('Nombre', nombre)),
        h('div', { class: 'min-w-[130px]' }, field('Categoría', categoria)),
        h('div', {}, field('Color', color)),
        h('div', {}, field('Orden', orden)),
        h('label', { class: 'flex items-center gap-1.5 text-[13px] text-stone-600 pb-2' }, esFinal, 'Final'),
        estado.is_initial
          ? badge('Inicial', 'ok')
          : edita
            ? button('Hacer inicial', {
                variant: 'secondary',
                onClick: async () => {
                  try {
                    await api.put(`/order-statuses/${estado.id}/initial`, {});
                    toast(`Los pedidos nuevos nacerán en “${estado.name}”`, 'ok');
                    await refrescar();
                  } catch (error) {
                    toast(error.message);
                  }
                },
              })
            : null
      ),

      h(
        'div',
        { class: 'mt-3 pt-3 border-t border-stone-100' },
        h('div', { class: 'text-[12.5px] font-medium text-stone-700 mb-1.5' }, 'Desde aquí se puede pasar a'),
        destinos.length
          ? h(
              'div',
              { class: 'space-y-1' },
              destinos.map(({ estado: destino, marcado, permiso }) =>
                h(
                  'div',
                  { class: 'flex flex-wrap items-center gap-2' },
                  h(
                    'label',
                    { class: 'flex items-center gap-2 text-[13px] w-44 shrink-0' },
                    marcado,
                    h('span', { class: 'truncate' }, destino.name)
                  ),
                  permiso
                )
              )
            )
          : h('p', { class: 'text-sm text-stone-500' }, 'No hay otros estados a los que ir.'),
        estado.is_final
          ? h(
              'p',
              { class: 'text-[12px] text-stone-500 mt-1.5' },
              'Es un estado final: de aquí no se sale, así que lo que se marque no se va a usar.'
            )
          : null
      ),

      edita
        ? h(
            'div',
            { class: 'flex flex-wrap gap-2 mt-3' },
            button('Guardar', {
              variant: 'secondary',
              onClick: async (e) => {
                const boton = e.currentTarget;
                boton.disabled = true;
                try {
                  await api.patch(`/order-statuses/${estado.id}`, {
                    name: nombre.value.trim(),
                    category: categoria.value,
                    color: color.value,
                    sort_order: Number(orden.value || 0),
                    is_final: esFinal.checked,
                  });
                  // Las salidas van aparte porque su comprobación mira el
                  // flujo entero: mandarlas juntas escondería cuál de las dos
                  // cosas se rechazó.
                  await api.put(`/order-statuses/${estado.id}/transitions`, {
                    transitions: destinos
                      .filter((d) => d.marcado.checked)
                      .map((d) => ({ to_status_id: d.estado.id, required_permission: d.permiso.value || null })),
                  });
                  toast('Estado actualizado', 'ok');
                  await refrescar();
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
                  await api.delete(`/order-statuses/${estado.id}`);
                  toast('Estado borrado', 'ok');
                  await refrescar();
                } catch (error) {
                  // Con pedidos encima, en la bitácora, o dejando a otro sin
                  // salida: el backend dice cuál.
                  toast(error.message);
                }
              },
            })
          )
        : null
    );
  }

  const nuevoNombre = input({ placeholder: 'Ej. En espera de repartidor' });
  const nuevaCategoria = select(Object.entries(CATEGORIAS).map(([value, label]) => ({ value, label })));
  const nuevoOrden = input({ type: 'number', value: String((statuses.at(-1)?.sort_order ?? 0) + 1) });
  const nuevoFinal = h('input', { type: 'checkbox', class: 'w-4 h-4 rounded border-stone-300' });

  return [
    titledCard(
      'Estados de pedido',
      h(
        'div',
        { class: 'text-[13px] text-stone-600 space-y-1.5 border-l-2 border-amber-300 pl-3' },
        h(
          'p',
          {},
          h('b', {}, 'El nombre es tuyo; la categoría, de la plataforma.'),
          ' Llámalo como quieras: el KDS, los reportes y los filtros se guían por la categoría y nunca por el nombre.'
        ),
        h(
          'p',
          {},
          h('b', {}, 'Un estado que no es final necesita al menos una salida.'),
          ' Sin ella, un pedido que llegue ahí no avanza ni se puede cerrar, y eso frena el servicio.'
        ),
        h('p', {}, 'El permiso de cada salida es quién puede hacer ese paso. “Cualquiera” significa que no pide ninguno.')
      )
    ),

    // Problemas y avisos los calcula Domain\StatusMachineRules, no esta
    // pantalla. Los primeros impiden operar y normalmente no aparecen —una
    // edición que los introduzca se rechaza—, pero hay que poder verlos:
    // sobre una configuración rota se sigue pudiendo editar, justamente para
    // arreglarla.
    ...(flujo.problems?.length
      ? [
          h(
            'div',
            { class: 'text-[13px] text-red-700 bg-red-50 border border-red-200 rounded-[--r] px-3 py-2 space-y-1' },
            h('p', { class: 'font-medium' }, 'El flujo está roto y hay que arreglarlo:'),
            flujo.problems.map((problema) => h('p', {}, problema))
          ),
        ]
      : []),

    ...(flujo.warnings.length
      ? [
          h(
            'div',
            { class: 'text-[13px] text-amber-800 bg-amber-50 border border-amber-200 rounded-[--r] px-3 py-2 space-y-1' },
            flujo.warnings.map((aviso) => h('p', {}, aviso))
          ),
        ]
      : []),

    ...statuses.map(tarjeta),

    edita
      ? titledCard(
          'Nuevo estado',
          h(
            'div',
            { class: 'flex flex-wrap items-end gap-3' },
            h('div', { class: 'flex-1 min-w-[200px]' }, field('Nombre', nuevoNombre)),
            h('div', { class: 'min-w-[140px]' }, field('Categoría', nuevaCategoria)),
            h('div', { class: 'w-24' }, field('Orden', nuevoOrden)),
            h('label', { class: 'flex items-center gap-1.5 text-[13px] text-stone-600 pb-2' }, nuevoFinal, 'Final'),
            button('Crear estado', {
              onClick: async (e) => {
                const boton = e.currentTarget;
                boton.disabled = true;
                try {
                  await api.post('/order-statuses', {
                    name: nuevoNombre.value.trim(),
                    category: nuevaCategoria.value,
                    sort_order: Number(nuevoOrden.value || 0),
                    is_final: nuevoFinal.checked,
                  });
                  nuevoNombre.value = '';
                  toast('Estado creado', 'ok');
                  await refrescar();
                } catch (error) {
                  // Un estado no final nace sin salidas, así que el backend
                  // lo rechaza y dice por qué.
                  toast(error.message);
                  boton.disabled = false;
                }
              },
            })
          ),
          h(
            'p',
            { class: 'text-[12.5px] text-stone-500 mt-2' },
            'Un estado nuevo nace sin salidas: créalo como final, o dale una salida desde su tarjeta apenas exista. Para que se use, marca en otro estado que se puede pasar a él.'
          )
        )
      : null,
  ];
}

// =========================================================
// Impuestos
// =========================================================

function seccionImpuestos({ impuestos }, refrescar) {
  const edita = can('settings.edit');
  const nombre = input({ placeholder: 'Ej. IVA 19 %' });
  const tasa = input({ type: 'number', step: '0.0001', min: '0', max: '0.9999', placeholder: '0.19' });
  const incluido = h('input', { type: 'checkbox', class: 'w-4 h-4 rounded', checked: true });
  const porDefecto = h('input', { type: 'checkbox', class: 'w-4 h-4 rounded' });

  return [
    titledCard(
      'Impuestos',
      impuestos.length
        ? h(
            'div',
            { class: 'divide-y divide-stone-100' },
            impuestos.map((t) =>
              h(
                'div',
                { class: 'py-3 flex flex-wrap items-center gap-3' },
                h(
                  'div',
                  { class: 'flex-1 min-w-[180px]' },
                  h('div', { class: 'font-medium text-sm text-stone-900' }, t.name),
                  h(
                    'div',
                    { class: 'text-xs text-stone-500 tabular-nums' },
                    `${percent(t.rate)} · ${t.included_in_price ? 'incluido en el precio' : 'se suma al precio'}`
                  )
                ),
                t.is_default
                  ? badge('Por defecto', 'ok')
                  : edita
                    ? button('Hacer predeterminado', {
                        variant: 'secondary',
                        onClick: async () => {
                          try {
                            await api.put(`/tax-rates/${t.id}/default`);
                            await refrescar();
                          } catch (error) {
                            toast(error.message);
                          }
                        },
                      })
                    : null
              )
            )
          )
        : empty('Sin impuestos configurados'),
    ),

    edita
      ? titledCard(
          'Nuevo impuesto',
          h(
            'div',
            { class: 'grid grid-cols-1 sm:grid-cols-2 gap-3' },
            field('Nombre', nombre),
            field('Tasa', tasa, 'Va como fracción: 8 % se escribe 0.08.')
          ),
          h(
            'div',
            { class: 'flex flex-wrap gap-4 mt-3 text-sm' },
            h('label', { class: 'flex items-center gap-2' }, incluido, 'Incluido en el precio'),
            h('label', { class: 'flex items-center gap-2' }, porDefecto, 'Dejarlo como predeterminado')
          ),
          h(
            'div',
            { class: 'mt-3' },
            button('Crear impuesto', {
              onClick: async () => {
                try {
                  await api.post('/tax-rates', {
                    name: nombre.value.trim(),
                    rate: Number(tasa.value || 0),
                    included_in_price: incluido.checked,
                    is_default: porDefecto.checked,
                  });
                  nombre.value = '';
                  tasa.value = '';
                  toast('Impuesto creado', 'ok');
                  await refrescar();
                } catch (error) {
                  toast(error.message);
                }
              },
            })
          )
        )
      : null,
  ];
}

// =========================================================
// Domicilios: zonas de reparto
// =========================================================

/**
 * Zonas de reparto de la sucursal activa.
 *
 * Las dos reglas que el backend impone van escritas en la pantalla, no en un
 * comentario: son las que generan discusiones con el restaurante, y una
 * pantalla que no las diga las convierte en una sorpresa.
 */
function seccionDomicilios({ zonas, sede }, refrescar) {
  const gestiona = can('branches.manage');

  if (!sede) {
    return card(
      empty(
        'Elige una sucursal',
        'Las zonas de reparto son de cada sede. Selecciona una en la barra lateral.',
        null,
        'sucursal'
      )
    );
  }

  const nombre = input({ placeholder: 'Ej. Centro' });
  const tarifa = input({ type: 'number', min: '0', placeholder: '5000' });
  const minimo = input({ type: 'number', min: '0', value: '0' });
  const minutos = input({ type: 'number', min: '1', placeholder: '30' });

  return [
    titledCard(
      `Zonas de reparto · ${sede.name}`,
      h(
        'div',
        { class: 'text-[13px] text-stone-600 space-y-1.5 mb-4 border-l-2 border-amber-300 pl-3' },
        h(
          'p',
          {},
          h('b', {}, 'La tarifa la pone la zona.'),
          ' Si el pedido llega con una zona, se cobra el envío de la zona y se ignora el importe que venga en el pedido.'
        ),
        h(
          'p',
          {},
          h('b', {}, 'El mínimo se mide contra el subtotal,'),
          ' nunca contra el total: contar el envío para alcanzar el mínimo sería hacer trampa.'
        )
      ),
      zonas.length
        ? h(
            'div',
            { class: 'divide-y divide-stone-100' },
            zonas.map((z) =>
              h(
                'div',
                { class: 'py-3 flex flex-wrap items-center gap-3' },
                h(
                  'div',
                  { class: 'flex-1 min-w-[180px]' },
                  h(
                    'div',
                    { class: 'font-medium text-sm text-stone-900 flex items-center gap-2' },
                    z.name,
                    z.is_active ? null : badge('Inactiva', 'warn')
                  ),
                  h(
                    'div',
                    { class: 'text-xs text-stone-500' },
                    [
                      `Envío ${money(z.fee)}`,
                      Number(z.min_order) ? `mínimo ${money(z.min_order)} de subtotal` : 'sin mínimo',
                      z.est_minutes ? `${z.est_minutes} min estimados` : null,
                    ]
                      .filter(Boolean)
                      .join(' · ')
                  )
                ),
                gestiona
                  ? button(z.is_active ? 'Desactivar' : 'Activar', {
                      variant: 'secondary',
                      onClick: async () => {
                        try {
                          await api.patch(`/delivery-zones/${z.id}/active`, { is_active: !z.is_active });
                          await refrescar();
                        } catch (error) {
                          toast(error.message);
                        }
                      },
                    })
                  : null
              )
            )
          )
        : empty(
            'Esta sede no tiene zonas',
            'Sin zonas, un domicilio se cobra con el envío que traiga el pedido y sin mínimo.',
            null,
            'domicilio'
          )
    ),

    gestiona
      ? titledCard(
          'Nueva zona',
          h(
            'div',
            { class: 'grid grid-cols-1 sm:grid-cols-2 gap-3' },
            field('Nombre', nombre),
            field('Tarifa de envío', tarifa, 'Lo que se cobra por llevar a esta zona.'),
            field('Pedido mínimo', minimo, 'Medido contra el subtotal. 0 para no exigir mínimo.'),
            field('Minutos estimados', minutos, 'Opcional. Lo que se le promete al cliente.')
          ),
          h(
            'div',
            { class: 'mt-3' },
            button('Crear zona', {
              onClick: async (e) => {
                // `currentTarget` se guarda antes del primer `await`: el navegador lo deja
                // en null en cuanto termina el despacho del evento, y sin esto el `catch`
                // no podria volver a habilitar el boton.
                const boton = e.currentTarget;
                boton.disabled = true;
                try {
                  await api.post(`/branches/${sede.id}/delivery-zones`, {
                    name: nombre.value.trim(),
                    fee: Number(tarifa.value || 0),
                    min_order: Number(minimo.value || 0),
                    est_minutes: minutos.value.trim() === '' ? null : Number(minutos.value),
                  });
                  toast('Zona creada', 'ok');
                  await refrescar();
                } catch (error) {
                  toast(error.message);
                  boton.disabled = false;
                }
              },
            })
          )
        )
      : null,
  ];
}

// =========================================================
// Equipo: usuarios y roles
// =========================================================

/**
 * La rejilla de casillas del catálogo de permisos.
 *
 * La comparten el alta de un rol y la edición de uno existente: son la misma
 * decisión —qué permisos agrupa este rol— y tenerla escrita dos veces era la
 * forma segura de que se separaran.
 */
function rejillaPermisos(permisos, seleccionados = []) {
  const casillas = permisos.map((p) => ({
    code: p.code,
    control: h('input', {
      type: 'checkbox',
      class: 'mt-0.5 w-4 h-4 rounded border-stone-300',
      checked: seleccionados.includes(p.code),
    }),
    descripcion: p.description,
  }));

  const nodo = h(
    'div',
    { class: 'grid grid-cols-1 sm:grid-cols-2 gap-1 max-h-64 overflow-y-auto border border-stone-200 rounded-lg p-2' },
    casillas.map((p) =>
      h(
        'label',
        { class: 'flex items-start gap-2 text-sm py-0.5' },
        p.control,
        h(
          'span',
          {},
          h('span', { class: 'font-mono text-xs' }, p.code),
          h('br'),
          h('span', { class: 'text-xs text-stone-500' }, p.descripcion ?? '')
        )
      )
    )
  );

  return { nodo, elegidos: () => casillas.filter((p) => p.control.checked).map((p) => p.code) };
}

/**
 * Una fila de rol, con sus permisos editables.
 *
 * Antes un rol se creaba y quedaba congelado: `PUT /roles/{id}/permissions`
 * existía y nadie lo llamaba, así que corregir un rol significaba crear otro
 * y mover a la gente. Los roles del sistema no se tocan y el backend los
 * rechaza: el rol admin es la salida de emergencia del restaurante y
 * quitarle `users.manage` dejaría a la empresa sin nadie que pueda
 * devolvérselo.
 */
function filaRol(rol, permisos, refrescar) {
  const editor = h('div');
  let abierto = false;

  const resumen = h(
    'div',
    { class: 'text-xs text-stone-500 mt-1' },
    `${rol.permissions.length} permisos: ${rol.permissions.join(', ')}`
  );

  function alternar() {
    abierto = !abierto;
    if (!abierto) return render(editor);

    const rejilla = rejillaPermisos(permisos, rol.permissions);
    const guardar = button('Guardar permisos', {
      onClick: async () => {
        guardar.disabled = true;
        try {
          await api.put(`/roles/${rol.id}/permissions`, { permissions: rejilla.elegidos() });
          toast('Permisos actualizados', 'ok');
          await refrescar();
        } catch (error) {
          toast(error.message);
          guardar.disabled = false;
        }
      },
    });

    render(
      editor,
      h(
        'div',
        { class: 'mt-3 space-y-3' },
        rejilla.nodo,
        h('div', { class: 'flex gap-2' }, guardar, button('Cancelar', { variant: 'secondary', onClick: alternar }))
      )
    );
  }

  return h(
    'div',
    { class: 'py-3' },
    h(
      'div',
      { class: 'flex flex-wrap items-center gap-2' },
      h(
        'div',
        { class: 'flex-1 min-w-[180px]' },
        h(
          'div',
          { class: 'font-medium text-sm text-stone-900 flex items-center gap-2' },
          rol.name,
          badge(rol.code),
          rol.is_system ? badge('Del sistema', 'info') : null
        ),
        resumen
      ),
      rol.is_system
        ? h('span', { class: 'text-xs text-stone-400' }, 'No se puede modificar')
        : button('Editar permisos', { variant: 'secondary', onClick: alternar })
    ),
    editor
  );
}

function seccionEquipo({ usuarios, roles, permisos, sucursales }, refrescar) {
  const nombre = input({ placeholder: 'Nombre y apellido', autocomplete: 'off' });
  const correo = input({ placeholder: 'correo@restaurante.com', autocomplete: 'off' });
  // new-password evita que el navegador rellene aquí la clave de quien está
  // usando el sistema y se cree otro usuario con esa credencial.
  const clave = input({ type: 'password', placeholder: 'Mínimo 8 caracteres', autocomplete: 'new-password' });
  const rol = select(roles.map((r) => ({ value: r.id, label: r.name })));
  const sucursal = select([
    { value: '', label: 'Sin sucursal fija' },
    ...sucursales.map((b) => ({ value: b.id, label: b.name })),
  ]);

  const rolCodigo = input({ placeholder: 'mesero' });
  const rolNombre = input({ placeholder: 'Mesero' });
  const permisosNuevoRol = rejillaPermisos(permisos);

  return [
    titledCard(
      'Usuarios',
      h(
        'div',
        { class: 'divide-y divide-stone-100' },
        usuarios.map((u) =>
          h(
            'div',
            { class: 'py-3 flex flex-wrap items-center gap-3' },
            h(
              'div',
              { class: 'flex-1 min-w-[180px]' },
              h('div', { class: 'font-medium text-sm text-stone-900' }, u.name),
              h('div', { class: 'text-xs text-stone-500' }, `${u.email} · ${u.role_code}`)
            ),
            u.is_active ? badge('Activo', 'ok') : badge('Inactivo'),
            button(u.is_active ? 'Desactivar' : 'Activar', {
              variant: 'secondary',
              onClick: async () => {
                if (
                  u.is_active &&
                  !(await confirm({
                    title: `¿Desactivar a ${u.name}?`,
                    message: 'No podrá volver a ingresar hasta que lo reactives.',
                    confirmLabel: 'Desactivar',
                  }))
                ) {
                  return;
                }
                try {
                  await api.patch(`/users/${u.id}/active`, { is_active: !u.is_active });
                  await refrescar();
                } catch (error) {
                  toast(error.message);
                }
              },
            })
          )
        )
      )
    ),

    titledCard(
      'Nuevo usuario',
      h(
        'div',
        { class: 'grid grid-cols-1 sm:grid-cols-2 gap-3' },
        field('Nombre', nombre),
        field('Correo', correo),
        field('Contraseña', clave),
        field('Rol', rol),
        h('div', { class: 'sm:col-span-2' }, field('Sucursal', sucursal))
      ),
      h(
        'div',
        { class: 'mt-3' },
        button('Crear usuario', {
          onClick: async () => {
            try {
              await api.post('/users', {
                name: nombre.value.trim(),
                email: correo.value.trim(),
                password: clave.value,
                role_id: rol.value,
                branch_id: sucursal.value || null,
              });
              [nombre, correo, clave].forEach((el) => (el.value = ''));
              toast('Usuario creado', 'ok');
              await refrescar();
            } catch (error) {
              toast(error.message);
            }
          },
        })
      )
    ),

    titledCard(
      'Roles',
      h('div', { class: 'divide-y divide-stone-100' }, roles.map((r) => filaRol(r, permisos, refrescar)))
    ),

    titledCard(
      'Nuevo rol',
      h(
        'div',
        { class: 'grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3' },
        field('Código', rolCodigo),
        field('Nombre', rolNombre)
      ),
      permisosNuevoRol.nodo,
      h(
        'div',
        { class: 'mt-3' },
        button('Crear rol', {
          onClick: async () => {
            try {
              await api.post('/roles', {
                code: rolCodigo.value.trim(),
                name: rolNombre.value.trim(),
                permissions: permisosNuevoRol.elegidos(),
              });
              rolCodigo.value = '';
              rolNombre.value = '';
              toast('Rol creado', 'ok');
              await refrescar();
            } catch (error) {
              toast(error.message);
            }
          },
        })
      ),
      h(
        'p',
        { class: 'text-xs text-stone-500 mt-2' },
        'El catálogo de permisos es fijo; lo configurable es cómo se agrupan en roles.'
      )
    ),
  ];
}
