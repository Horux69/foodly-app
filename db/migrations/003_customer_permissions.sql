-- =========================================================
-- Permisos de clientes (modulo 7)
--
-- El catalogo de permisos es fijo para los tenants pero la plataforma si lo
-- extiende. Una base de clientes es una preocupacion distinta de los pedidos
-- —tiene datos personales y se usa para marketing— asi que merece sus
-- propios permisos en vez de colgarse de orders.view.
-- =========================================================

INSERT INTO permissions (code, description) VALUES
  ('customers.view',   'Ver la base de clientes y su historial'),
  ('customers.manage', 'Editar los datos de un cliente')
ON CONFLICT (code) DO NOTHING;

-- Los roles de sistema (el admin de cada empresa) ya existen y se quedarian
-- sin acceso a una funcionalidad de su propio restaurante. Se los damos.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.is_system
  AND p.code IN ('customers.view', 'customers.manage')
ON CONFLICT DO NOTHING;
