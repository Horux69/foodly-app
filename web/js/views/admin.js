// Administración del restaurante: cómo opera, sucursales, impuestos, y
// usuarios con sus roles.
//
// Cada sección se dibuja según los permisos del token. Eso es comodidad, no
// seguridad: el backend rechaza por su cuenta lo que no corresponde.

import { api } from '../api.js';
import { percent } from '../format.js';
import { icon } from '../icons.js';
import { can } from '../session.js';
import {
  badge, button, card, confirm, empty, errorBox, field, h, input, loading, pageHeader, render,
  select, skeleton, titledCard, toast,
} from '../ui.js';

const CANALES = [
  ['counter', 'Mostrador'],
  ['table', 'Mesa'],
  ['delivery', 'Domicilio'],
  ['whatsapp', 'WhatsApp'],
  ['app', 'App'],
];

const SECCIONES = [
  { clave: 'config', etiqueta: 'Cómo opera', icono: 'admin', permiso: 'settings.view' },
  { clave: 'sucursales', etiqueta: 'Sucursales', icono: 'sucursal', permiso: 'settings.view' },
  { clave: 'impuestos', etiqueta: 'Impuestos', icono: 'impuesto', permiso: 'settings.view' },
  { clave: 'equipo', etiqueta: 'Equipo', icono: 'clientes', permiso: 'users.manage' },
];

export async function admin(outlet) {
  const nav = h('div', { class: 'flex flex-wrap gap-1 p-1 bg-stone-200/60 rounded-xl w-fit mb-4' });
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

    if (can('users.manage')) {
      const [roles, usuarios, permisos] = await Promise.all([
        api.get('/roles'),
        api.get('/users'),
        api.get('/permissions'),
      ]);
      Object.assign(estado, { roles, usuarios, permisos });
    }
  }

  const disponibles = SECCIONES.filter((s) => can(s.permiso));
  let activa = disponibles[0]?.clave;

  function mostrar(clave) {
    activa = clave;
    render(
      nav,
      disponibles.map((s) =>
        h(
          'button',
          {
            class: `flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition ${
              s.clave === activa ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-600 hover:text-stone-900'
            }`,
            onClick: () => mostrar(s.clave),
          },
          icon(s.icono, { size: 16 }),
          s.etiqueta
        )
      )
    );

    const refrescar = async () => {
      await recargar();
      mostrar(activa);
    };

    if (clave === 'config') render(panel, seccionConfig(estado, refrescar));
    else if (clave === 'sucursales') render(panel, seccionSucursales(estado, refrescar));
    else if (clave === 'impuestos') render(panel, seccionImpuestos(estado, refrescar));
    else render(panel, seccionEquipo(estado, refrescar));
  }

  mostrar(activa);
}

// =========================================================
// Cómo opera
// =========================================================

function seccionConfig({ ajustes }, refrescar) {
  const editable = can('settings.edit');
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
        'p',
        { class: 'text-sm text-stone-600 mb-4' },
        `${ajustes.name} · ${ajustes.business_type} · ${ajustes.currency}`
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
              e.currentTarget.disabled = true;
              try {
                await api.patch('/settings', {
                  channels: casillas.filter((c) => c.control.checked).map((c) => c.code),
                  uses_tables: mesas.checked,
                  asks_tip: propina.checked,
                });
                toast('Configuración guardada', 'ok');
                await refrescar();
              } catch (error) {
                toast(error.message);
                e.currentTarget.disabled = false;
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
// Equipo: usuarios y roles
// =========================================================

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
  const casillasPermisos = permisos.map((p) => ({
    code: p.code,
    control: h('input', { type: 'checkbox', class: 'mt-0.5 w-4 h-4 rounded border-stone-300' }),
    descripcion: p.description,
  }));

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
      h(
        'div',
        { class: 'divide-y divide-stone-100' },
        roles.map((r) =>
          h(
            'div',
            { class: 'py-3' },
            h(
              'div',
              { class: 'font-medium text-sm text-stone-900 flex items-center gap-2' },
              r.name,
              badge(r.code),
              r.is_system ? badge('Del sistema', 'info') : null
            ),
            h(
              'div',
              { class: 'text-xs text-stone-500 mt-1' },
              `${r.permissions.length} permisos: ${r.permissions.join(', ')}`
            )
          )
        )
      )
    ),

    titledCard(
      'Nuevo rol',
      h(
        'div',
        { class: 'grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3' },
        field('Código', rolCodigo),
        field('Nombre', rolNombre)
      ),
      h(
        'div',
        { class: 'grid grid-cols-1 sm:grid-cols-2 gap-1 max-h-64 overflow-y-auto border border-stone-200 rounded-lg p-2' },
        casillasPermisos.map((p) =>
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
      ),
      h(
        'div',
        { class: 'mt-3' },
        button('Crear rol', {
          onClick: async () => {
            try {
              await api.post('/roles', {
                code: rolCodigo.value.trim(),
                name: rolNombre.value.trim(),
                permissions: casillasPermisos.filter((p) => p.control.checked).map((p) => p.code),
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
