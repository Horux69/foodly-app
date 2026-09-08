-- =========================================================
-- Row Level Security de verdad
--
-- El esquema v1 dejo politicas en 5 tablas de 25, y la aplicacion se conecta
-- como dueña de las tablas: Postgres saltea RLS para el owner, asi que la
-- "red de seguridad" del principio 3 no estaba protegiendo nada. Esta
-- migracion cubre todas las tablas con datos de negocio y crea un rol de
-- aplicacion que si queda sujeto a las politicas.
--
-- El aislamiento sigue viniendo de los filtros por tenant_id en las
-- consultas; esto es la red debajo, para el query que algun dia olvide uno.
-- =========================================================

-- ---------- Tenant de la transaccion ----------
-- NULLIF evita que un `SET app.current_tenant = ''` reviente el cast: sin
-- tenant valido la funcion devuelve NULL y ninguna politica deja pasar nada.
CREATE OR REPLACE FUNCTION current_tenant_id() RETURNS UUID
LANGUAGE sql STABLE
AS $$
    SELECT NULLIF(current_setting('app.current_tenant', true), '')::UUID
$$;

-- ---------- Autenticacion: la unica excepcion ----------
-- En login todavia no hay tenant del cual filtrar, y la politica de `users`
-- dejaria la consulta en cero filas. Esta funcion corre con los privilegios
-- de su dueño (SECURITY DEFINER) y es deliberadamente miserable: recibe un
-- email y devuelve solo el tenant al que pertenece. Con eso la aplicacion ya
-- puede fijar el contexto y seguir bajo RLS como cualquier otra consulta.
CREATE OR REPLACE FUNCTION auth_tenant_for_email(p_email VARCHAR) RETURNS UUID
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT tenant_id FROM users WHERE email = p_email AND is_active LIMIT 1
$$;

-- ---------- Politicas ----------
-- Se recrean las cinco existentes para que usen current_tenant_id().
DROP POLICY IF EXISTS tenant_isolation_orders ON orders;
DROP POLICY IF EXISTS tenant_isolation_customers ON customers;
DROP POLICY IF EXISTS tenant_isolation_menu_categories ON menu_categories;
DROP POLICY IF EXISTS tenant_isolation_branches ON branches;
DROP POLICY IF EXISTS tenant_isolation_users ON users;

-- Tablas con tenant_id propio.
-- Sin FOR ni WITH CHECK, la expresion de USING gobierna tanto lo que se ve
-- como lo que se puede insertar.
ALTER TABLE branches ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON branches USING (tenant_id = current_tenant_id());

ALTER TABLE roles ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON roles USING (tenant_id = current_tenant_id());

ALTER TABLE users ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON users USING (tenant_id = current_tenant_id());

ALTER TABLE customers ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON customers USING (tenant_id = current_tenant_id());

ALTER TABLE tax_rates ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON tax_rates USING (tenant_id = current_tenant_id());

ALTER TABLE order_statuses ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON order_statuses USING (tenant_id = current_tenant_id());

ALTER TABLE menu_categories ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON menu_categories USING (tenant_id = current_tenant_id());

ALTER TABLE modifier_groups ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON modifier_groups USING (tenant_id = current_tenant_id());

ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON orders USING (tenant_id = current_tenant_id());

-- El propio tenant: cada empresa solo se ve a si misma.
ALTER TABLE tenants ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON tenants USING (id = current_tenant_id());

-- Tablas que cuelgan de otra. La subconsulta tambien pasa por la politica de
-- la tabla padre, asi que la condicion queda comprobada dos veces; se deja
-- explicita para que cada politica se pueda leer sola.
ALTER TABLE branch_schedules ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON branch_schedules USING (EXISTS (
    SELECT 1 FROM branches b
    WHERE b.id = branch_schedules.branch_id AND b.tenant_id = current_tenant_id()));

ALTER TABLE delivery_zones ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON delivery_zones USING (EXISTS (
    SELECT 1 FROM branches b
    WHERE b.id = delivery_zones.branch_id AND b.tenant_id = current_tenant_id()));

ALTER TABLE tables ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON tables USING (EXISTS (
    SELECT 1 FROM branches b
    WHERE b.id = tables.branch_id AND b.tenant_id = current_tenant_id()));

ALTER TABLE role_permissions ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON role_permissions USING (EXISTS (
    SELECT 1 FROM roles r
    WHERE r.id = role_permissions.role_id AND r.tenant_id = current_tenant_id()));

ALTER TABLE order_status_transitions ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON order_status_transitions USING (EXISTS (
    SELECT 1 FROM order_statuses s
    WHERE s.id = order_status_transitions.from_status_id
      AND s.tenant_id = current_tenant_id()));

ALTER TABLE menu_items ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON menu_items USING (EXISTS (
    SELECT 1 FROM menu_categories c
    WHERE c.id = menu_items.category_id AND c.tenant_id = current_tenant_id()));

ALTER TABLE branch_menu_overrides ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON branch_menu_overrides USING (EXISTS (
    SELECT 1 FROM branches b
    WHERE b.id = branch_menu_overrides.branch_id AND b.tenant_id = current_tenant_id()));

ALTER TABLE modifiers ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON modifiers USING (EXISTS (
    SELECT 1 FROM modifier_groups g
    WHERE g.id = modifiers.group_id AND g.tenant_id = current_tenant_id()));

ALTER TABLE item_modifier_groups ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON item_modifier_groups USING (EXISTS (
    SELECT 1 FROM menu_items i
    JOIN menu_categories c ON c.id = i.category_id
    WHERE i.id = item_modifier_groups.item_id AND c.tenant_id = current_tenant_id()));

ALTER TABLE order_items ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON order_items USING (EXISTS (
    SELECT 1 FROM orders o
    WHERE o.id = order_items.order_id AND o.tenant_id = current_tenant_id()));

ALTER TABLE order_item_modifiers ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON order_item_modifiers USING (EXISTS (
    SELECT 1 FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE oi.id = order_item_modifiers.order_item_id
      AND o.tenant_id = current_tenant_id()));

ALTER TABLE order_status_history ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON order_status_history USING (EXISTS (
    SELECT 1 FROM orders o
    WHERE o.id = order_status_history.order_id AND o.tenant_id = current_tenant_id()));

ALTER TABLE payments ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON payments USING (EXISTS (
    SELECT 1 FROM orders o
    WHERE o.id = payments.order_id AND o.tenant_id = current_tenant_id()));

ALTER TABLE delivery_info ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON delivery_info USING (EXISTS (
    SELECT 1 FROM orders o
    WHERE o.id = delivery_info.order_id AND o.tenant_id = current_tenant_id()));

-- `permissions` queda a proposito sin politica: es el catalogo fijo de la
-- plataforma, igual para todas las empresas y sin dato de nadie.

-- ---------- Rol de aplicacion ----------
-- Sin LOGIN ni clave aca: eso lo pone scripts/setup.sh desde el entorno, para
-- no dejar una credencial en el repositorio. El punto es que este rol NO sea
-- dueño de las tablas, que es lo unico que hace que las politicas se apliquen.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'resto_app') THEN
        CREATE ROLE resto_app NOLOGIN;
    END IF;
END $$;

GRANT USAGE ON SCHEMA public TO resto_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO resto_app;
GRANT EXECUTE ON FUNCTION next_order_number(UUID) TO resto_app;
GRANT EXECUTE ON FUNCTION current_tenant_id() TO resto_app;
GRANT EXECUTE ON FUNCTION auth_tenant_for_email(VARCHAR) TO resto_app;

-- Las tablas que se creen despues tambien quedan alcanzadas.
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO resto_app;
