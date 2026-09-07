-- =========================================================
-- Plataforma multi-tenant para restaurantes — Esquema v2
-- Incorpora: estados configurables, roles/permisos dinámicos,
-- overrides de menú por sucursal, impuestos configurables,
-- horarios, zonas de domicilio, secuencia de pedido e idempotencia.
-- =========================================================

CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ---------- TENANTS ----------
CREATE TABLE tenants (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(150) NOT NULL,
    business_type VARCHAR(50) NOT NULL DEFAULT 'fast_food',
    currency CHAR(3) NOT NULL DEFAULT 'COP',
    settings_version INT NOT NULL DEFAULT 1,
    settings JSONB NOT NULL DEFAULT '{}',
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------- BRANCHES (zona horaria por sucursal + secuencia de pedidos) ----------
CREATE TABLE branches (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(10) NOT NULL,
    timezone VARCHAR(50) NOT NULL DEFAULT 'America/Bogota',
    address VARCHAR(255),
    phone VARCHAR(30),
    order_seq BIGINT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, code)
);
CREATE INDEX idx_branches_tenant ON branches(tenant_id);

-- ---------- ROLES Y PERMISOS (configurables por tenant) ----------
CREATE TABLE permissions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    code VARCHAR(60) NOT NULL UNIQUE,
    description VARCHAR(200)
);

CREATE TABLE roles (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(100) NOT NULL,
    is_system BOOLEAN NOT NULL DEFAULT false,
    UNIQUE (tenant_id, code)
);

CREATE TABLE role_permissions (
    role_id UUID NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id UUID NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    branch_id UUID REFERENCES branches(id) ON DELETE SET NULL,
    role_id UUID NOT NULL REFERENCES roles(id),
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT true,
    last_login_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, email)
);
CREATE INDEX idx_users_tenant ON users(tenant_id);

-- ---------- CUSTOMERS ----------
CREATE TABLE customers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    phone VARCHAR(30) NOT NULL,
    name VARCHAR(150),
    email VARCHAR(150),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, phone)
);

-- ---------- IMPUESTOS CONFIGURABLES ----------
CREATE TABLE tax_rates (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(80) NOT NULL,            -- 'Impoconsumo 8%', 'IVA 19%', 'Exento'
    rate NUMERIC(5,4) NOT NULL DEFAULT 0, -- 0.0800
    included_in_price BOOLEAN NOT NULL DEFAULT true,
    is_default BOOLEAN NOT NULL DEFAULT false,
    is_active BOOLEAN NOT NULL DEFAULT true
);
CREATE UNIQUE INDEX idx_tax_default_per_tenant
    ON tax_rates(tenant_id) WHERE is_default;

-- ---------- ESTADOS DE PEDIDO CONFIGURABLES ----------
CREATE TABLE order_statuses (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(80) NOT NULL,
    -- category normaliza el significado para reportes y para el KDS,
    -- independiente del nombre que le ponga cada restaurante
    category VARCHAR(20) NOT NULL CHECK (category IN
        ('new','kitchen','ready','in_transit','completed','cancelled')),
    color VARCHAR(9),
    sort_order INT NOT NULL DEFAULT 0,
    is_initial BOOLEAN NOT NULL DEFAULT false,
    is_final BOOLEAN NOT NULL DEFAULT false,
    UNIQUE (tenant_id, code)
);
CREATE UNIQUE INDEX idx_status_initial_per_tenant
    ON order_statuses(tenant_id) WHERE is_initial;

CREATE TABLE order_status_transitions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    from_status_id UUID NOT NULL REFERENCES order_statuses(id) ON DELETE CASCADE,
    to_status_id UUID NOT NULL REFERENCES order_statuses(id) ON DELETE CASCADE,
    required_permission VARCHAR(60),
    UNIQUE (from_status_id, to_status_id)
);

-- ---------- MENÚ ----------
CREATE TABLE menu_categories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT true
);
CREATE INDEX idx_menu_categories_tenant ON menu_categories(tenant_id);

CREATE TABLE menu_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    category_id UUID NOT NULL REFERENCES menu_categories(id) ON DELETE CASCADE,
    tax_rate_id UUID REFERENCES tax_rates(id),
    name VARCHAR(150) NOT NULL,
    description TEXT,
    base_price NUMERIC(10,2) NOT NULL CHECK (base_price >= 0),
    image_url VARCHAR(255),
    prep_minutes INT,
    is_available BOOLEAN NOT NULL DEFAULT true,
    is_archived BOOLEAN NOT NULL DEFAULT false,  -- soft delete: preserva histórico
    sort_order INT NOT NULL DEFAULT 0
);
CREATE INDEX idx_menu_items_category ON menu_items(category_id);

-- Precio y disponibilidad diferenciados por sucursal
CREATE TABLE branch_menu_overrides (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    branch_id UUID NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    menu_item_id UUID NOT NULL REFERENCES menu_items(id) ON DELETE CASCADE,
    price NUMERIC(10,2),          -- NULL = usa base_price
    is_available BOOLEAN,         -- NULL = usa is_available del item
    UNIQUE (branch_id, menu_item_id)
);

CREATE TABLE modifier_groups (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    min_select INT NOT NULL DEFAULT 0,
    max_select INT NOT NULL DEFAULT 1,
    is_required BOOLEAN NOT NULL DEFAULT false,
    CHECK (max_select >= min_select)
);

CREATE TABLE modifiers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    group_id UUID NOT NULL REFERENCES modifier_groups(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    price_delta NUMERIC(10,2) NOT NULL DEFAULT 0,
    is_available BOOLEAN NOT NULL DEFAULT true
);

CREATE TABLE item_modifier_groups (
    item_id UUID NOT NULL REFERENCES menu_items(id) ON DELETE CASCADE,
    group_id UUID NOT NULL REFERENCES modifier_groups(id) ON DELETE CASCADE,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (item_id, group_id)
);

-- ---------- HORARIOS Y ZONAS ----------
CREATE TABLE branch_schedules (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    branch_id UUID NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    weekday SMALLINT NOT NULL CHECK (weekday BETWEEN 0 AND 6),
    opens_at TIME NOT NULL,
    closes_at TIME NOT NULL,
    channel VARCHAR(20),  -- NULL = aplica a todos los canales
    is_active BOOLEAN NOT NULL DEFAULT true
);
CREATE INDEX idx_schedules_branch ON branch_schedules(branch_id, weekday);

CREATE TABLE delivery_zones (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    branch_id UUID NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    fee NUMERIC(10,2) NOT NULL DEFAULT 0,
    min_order NUMERIC(10,2) NOT NULL DEFAULT 0,
    est_minutes INT,
    polygon JSONB,
    is_active BOOLEAN NOT NULL DEFAULT true
);

CREATE TABLE tables (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    branch_id UUID NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    code VARCHAR(20) NOT NULL,
    capacity INT NOT NULL DEFAULT 4,
    is_active BOOLEAN NOT NULL DEFAULT true,
    UNIQUE (branch_id, code)
);

-- ---------- PEDIDOS ----------
CREATE TABLE orders (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    branch_id UUID NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    customer_id UUID REFERENCES customers(id) ON DELETE SET NULL,
    table_id UUID REFERENCES tables(id) ON DELETE SET NULL,
    status_id UUID NOT NULL REFERENCES order_statuses(id),
    created_by UUID REFERENCES users(id) ON DELETE SET NULL,
    order_number VARCHAR(30) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    idempotency_key VARCHAR(80),
    subtotal NUMERIC(10,2) NOT NULL DEFAULT 0,
    tax_total NUMERIC(10,2) NOT NULL DEFAULT 0,
    delivery_fee NUMERIC(10,2) NOT NULL DEFAULT 0,
    discount NUMERIC(10,2) NOT NULL DEFAULT 0,
    tip NUMERIC(10,2) NOT NULL DEFAULT 0,
    total NUMERIC(10,2) NOT NULL DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (branch_id, order_number),
    UNIQUE (tenant_id, idempotency_key)
);
CREATE INDEX idx_orders_branch_status ON orders(branch_id, status_id);
CREATE INDEX idx_orders_branch_created ON orders(branch_id, created_at DESC);

CREATE TABLE order_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id UUID NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    menu_item_id UUID NOT NULL REFERENCES menu_items(id),
    name_snapshot VARCHAR(150) NOT NULL,
    quantity INT NOT NULL CHECK (quantity > 0),
    unit_price NUMERIC(10,2) NOT NULL,
    tax_rate NUMERIC(5,4) NOT NULL DEFAULT 0,
    tax_amount NUMERIC(10,2) NOT NULL DEFAULT 0,
    line_total NUMERIC(10,2) NOT NULL DEFAULT 0,
    notes VARCHAR(255)
);
CREATE INDEX idx_order_items_order ON order_items(order_id);

CREATE TABLE order_item_modifiers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_item_id UUID NOT NULL REFERENCES order_items(id) ON DELETE CASCADE,
    modifier_id UUID NOT NULL REFERENCES modifiers(id),
    name_snapshot VARCHAR(100) NOT NULL,
    price_delta NUMERIC(10,2) NOT NULL DEFAULT 0
);

CREATE TABLE order_status_history (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id UUID NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    status_id UUID NOT NULL REFERENCES order_statuses(id),
    changed_by UUID REFERENCES users(id) ON DELETE SET NULL,
    note VARCHAR(255),
    changed_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_status_history_order ON order_status_history(order_id, changed_at);

CREATE TABLE payments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id UUID NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    method VARCHAR(30) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    amount NUMERIC(10,2) NOT NULL CHECK (amount > 0),
    external_reference VARCHAR(150),
    idempotency_key VARCHAR(80),
    paid_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_payments_order ON payments(order_id);

CREATE TABLE delivery_info (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id UUID NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE,
    zone_id UUID REFERENCES delivery_zones(id),
    courier_id UUID REFERENCES users(id) ON DELETE SET NULL,
    address VARCHAR(255) NOT NULL,
    lat NUMERIC(10,7),
    lng NUMERIC(10,7),
    estimated_time TIMESTAMPTZ,
    dispatched_at TIMESTAMPTZ,
    delivered_at TIMESTAMPTZ
);

-- =========================================================
-- Numeración de pedidos segura ante concurrencia
-- =========================================================
CREATE OR REPLACE FUNCTION next_order_number(p_branch_id UUID)
RETURNS VARCHAR AS $$
DECLARE
    v_seq BIGINT;
    v_code VARCHAR(10);
BEGIN
    UPDATE branches
       SET order_seq = order_seq + 1
     WHERE id = p_branch_id
    RETURNING order_seq, code INTO v_seq, v_code;
    RETURN v_code || '-' || LPAD(v_seq::TEXT, 5, '0');
END;
$$ LANGUAGE plpgsql;

-- =========================================================
-- Row Level Security: aislamiento de tenants a nivel de motor
-- La aplicación debe ejecutar por transacción:
--   SET LOCAL app.current_tenant = '<uuid>';
-- =========================================================
ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation_orders ON orders
    USING (tenant_id = current_setting('app.current_tenant', true)::UUID);

ALTER TABLE customers ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation_customers ON customers
    USING (tenant_id = current_setting('app.current_tenant', true)::UUID);

ALTER TABLE menu_categories ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation_menu_categories ON menu_categories
    USING (tenant_id = current_setting('app.current_tenant', true)::UUID);

ALTER TABLE branches ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation_branches ON branches
    USING (tenant_id = current_setting('app.current_tenant', true)::UUID);

ALTER TABLE users ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation_users ON users
    USING (tenant_id = current_setting('app.current_tenant', true)::UUID);
