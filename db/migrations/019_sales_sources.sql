-- =========================================================
-- Origenes de venta con comision (F9.4).
--
-- Un pedido que entra por Rappi se prepara en la misma cocina y se cobra
-- igual, pero no vale lo mismo: la plataforma se queda un porcentaje. Hoy
-- ese pedido se registra como uno mas y el reporte dice que se vendieron
-- 50.000 cuando al restaurante le entraron 35.000. Con dos o tres
-- agregadores, esa diferencia es la que decide si el negocio gana o no.
--
-- Se llama "origen" y no "canal" a proposito: `orders.channel` ya existe y
-- responde otra pregunta —por donde se vendio (mostrador, mesa, domicilio),
-- que decide horarios, cocina y pantallas—. Este dice **de quien vino** la
-- venta y cuanto cuesta. Mezclarlos obligaria a que cada agregador
-- declarara si es domicilio o mostrador, y a repetir los horarios por cada
-- uno.
-- =========================================================

CREATE TABLE sales_sources (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(60) NOT NULL,
    -- Lo que se queda la plataforma. 0 es un origen propio con nombre
    -- ("Instagram", "Telefono"): sirve igual para saber de donde vienen las
    -- ventas aunque no cueste nada.
    commission_percent NUMERIC(5,2) NOT NULL DEFAULT 0
        CHECK (commission_percent >= 0 AND commission_percent <= 100),
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, name)
);

CREATE INDEX idx_sales_sources_tenant ON sales_sources(tenant_id) WHERE is_active;

-- De donde vino el pedido. Nulo es venta propia, que es el caso de casi
-- todos y no obliga a configurar nada.
--
-- ON DELETE RESTRICT y no SET NULL: un canal con ventas encima no se borra,
-- se apaga. Borrarlo convertiria las ventas de Rappi del mes pasado en
-- ventas propias y el reporte de comisiones mentiria hacia arriba.
ALTER TABLE orders ADD COLUMN sales_source_id UUID REFERENCES sales_sources(id) ON DELETE RESTRICT;
CREATE INDEX idx_orders_sales_source ON orders(sales_source_id) WHERE sales_source_id IS NOT NULL;

-- El porcentaje se congela con la venta, como el precio (principio 8).
-- Si se leyera del canal, renegociar la comision con la plataforma
-- reescribiria cuanto se gano en marzo. Nulo cuando la venta es propia.
ALTER TABLE orders ADD COLUMN commission_percent NUMERIC(5,2)
    CHECK (commission_percent IS NULL OR (commission_percent >= 0 AND commission_percent <= 100));

-- ---------- RLS ----------
ALTER TABLE sales_sources ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON sales_sources USING (tenant_id = current_tenant_id());

GRANT SELECT, INSERT, UPDATE, DELETE ON sales_sources TO resto_app;
