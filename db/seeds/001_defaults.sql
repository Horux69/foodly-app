-- =========================================================
-- Datos semilla: catalogo global de permisos.
-- Los roles y estados se siembran POR TENANT al crearlo
-- (ver app/services/tenant_provisioning.py).
-- =========================================================

INSERT INTO permissions (code, description) VALUES
  ('settings.view',           'Ver configuracion del restaurante'),
  ('settings.edit',           'Editar configuracion del restaurante'),
  ('branches.manage',         'Gestionar sucursales'),
  ('users.manage',            'Gestionar usuarios y roles'),
  ('menu.view',               'Ver el menu'),
  ('menu.edit',               'Crear y editar productos y categorias'),
  ('menu.availability',       'Marcar productos como agotados'),
  ('orders.create',           'Crear pedidos'),
  ('orders.view',             'Ver pedidos'),
  ('orders.edit',             'Modificar pedidos abiertos'),
  ('orders.cancel',           'Cancelar pedidos'),
  ('orders.advance_kitchen',  'Avanzar estados de cocina'),
  ('orders.discount',         'Aplicar descuentos'),
  ('payments.register',       'Registrar pagos'),
  ('payments.refund',         'Anular o reembolsar pagos'),
  ('cash.close',              'Cerrar turno y arqueo'),
  ('cash.movements',          'Registrar entradas y salidas de efectivo'),
  ('delivery.assign',         'Asignar repartidores'),
  ('delivery.complete',       'Confirmar entregas'),
  ('customers.view',          'Ver la base de clientes y su historial'),
  ('customers.manage',        'Editar los datos de un cliente'),
  ('reports.view',            'Ver reportes')
ON CONFLICT (code) DO NOTHING;
