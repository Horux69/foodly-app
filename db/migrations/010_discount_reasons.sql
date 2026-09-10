-- =========================================================
-- Descuentos con motivo, autor y tope (F7.2).
--
-- Hasta ahora el descuento era un numero suelto en el cuerpo de
-- POST /orders, bajo el permiso de crear pedidos: cualquiera con caja podia
-- cerrar un pedido de 80.000 en 0, y el reporte de ajustes lo listaba sin
-- poder decir por que. El permiso 'orders.discount' existia en el catalogo
-- desde el principio y no lo comprobaba nadie.
--
-- Tres piezas: el catalogo de motivos por empresa, la marca en el pedido de
-- cual se uso y quien lo aplico, y el tope por rol.
-- =========================================================

CREATE TABLE discount_reasons (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(80) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT true,
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE (tenant_id, name)
);

ALTER TABLE discount_reasons ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON discount_reasons USING (tenant_id = current_tenant_id());
GRANT SELECT, INSERT, UPDATE, DELETE ON discount_reasons TO resto_app;

-- ---------- El descuento del pedido ----------
-- Sin ON DELETE: un motivo que ya se uso no se borra, se desactiva. Igual
-- que una opcion de modificador vendida (F5.1) o un estado con pedidos
-- encima (F5.2): borrarlo dejaria descuentos historicos sin explicacion.
ALTER TABLE orders
    ADD COLUMN discount_reason_id UUID REFERENCES discount_reasons(id),
    -- Quien lo autorizo, que no siempre es quien tomo el pedido.
    ADD COLUMN discount_by UUID REFERENCES users(id) ON DELETE SET NULL;

-- ---------- El tope por rol ----------
-- NULL es "sin tope", que es lo que tiene el admin. Un cajero con 10 puede
-- dar hasta el 10% del subtotal y ni un peso mas, sin llamar a nadie.
ALTER TABLE roles
    ADD COLUMN max_discount_percent NUMERIC(5,2) CHECK (max_discount_percent >= 0 AND max_discount_percent <= 100);

-- ---------- Motivos por defecto ----------
-- Los cuatro que aparecen en cualquier restaurante. Se siembran para las
-- empresas que ya existen; las nuevas los reciben al darlas de alta.
INSERT INTO discount_reasons (tenant_id, name, sort_order)
SELECT t.id, m.name, m.sort_order
FROM tenants t
CROSS JOIN (VALUES
    ('Cortesía', 0),
    ('Reclamo del cliente', 1),
    ('Empleado', 2),
    ('Convenio', 3)
) AS m(name, sort_order)
ON CONFLICT (tenant_id, name) DO NOTHING;
