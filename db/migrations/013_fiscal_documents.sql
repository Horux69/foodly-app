-- =========================================================
-- Documento fiscal por venta (F12.1).
--
-- Es la fila de la matriz competitiva que no es una mejora sino un permiso
-- para jugar: en Colombia el documento equivalente P.O.S. electronico entro
-- por calendario y un restaurante no puede usar como caja algo que no lo
-- emita. La fecha que le aplica a cada cliente la confirma su contador.
--
-- Dos piezas, y la separacion entre ellas es la decision importante:
--
--   * La **numeracion** es del restaurante y la autoriza la DIAN por
--     resolucion: prefijo, rango y vigencia. Existe aunque no haya proveedor
--     tecnologico conectado, porque el papel que se entrega ya la lleva.
--   * La **transmision** es de un proveedor autorizado, y puede fallar. Que
--     falle no puede impedir vender: el documento queda numerado y en
--     contingencia, para retransmitirlo despues.
-- =========================================================

CREATE TABLE fiscal_resolutions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    -- Por sucursal: cada punto de venta suele tener su propio rango.
    branch_id UUID REFERENCES branches(id) ON DELETE CASCADE,
    -- El numero de la resolucion, tal como lo emitio la autoridad.
    number VARCHAR(40) NOT NULL,
    prefix VARCHAR(10) NOT NULL DEFAULT '',
    range_from BIGINT NOT NULL CHECK (range_from > 0),
    range_to BIGINT NOT NULL,
    -- El ultimo consecutivo usado. Arranca por debajo del inicio del rango.
    current_number BIGINT NOT NULL,
    valid_until DATE,
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (range_to >= range_from),
    CHECK (current_number >= range_from - 1 AND current_number <= range_to)
);

-- Una sola resolucion activa por sucursal, impuesto por la base y no por una
-- comprobacion en PHP: dos resoluciones activas repartirian consecutivos y
-- eso es justo lo que no se puede deshacer.
CREATE UNIQUE INDEX idx_resolucion_activa_por_sucursal
    ON fiscal_resolutions (tenant_id, coalesce(branch_id::text, 'todas'))
    WHERE is_active;

ALTER TABLE fiscal_resolutions ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON fiscal_resolutions USING (tenant_id = current_tenant_id());
GRANT SELECT, INSERT, UPDATE, DELETE ON fiscal_resolutions TO resto_app;

-- ---------- El documento emitido ----------
CREATE TABLE fiscal_documents (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    -- Uno por pedido: emitir dos veces la misma venta es el error que no se
    -- puede corregir sin una nota de credito.
    order_id UUID NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE,
    resolution_id UUID REFERENCES fiscal_resolutions(id),
    prefix VARCHAR(10) NOT NULL DEFAULT '',
    number BIGINT NOT NULL,
    -- 'accepted' la valido el proveedor; 'contingency' esta numerado y sin
    -- transmitir; 'rejected' la autoridad lo devolvio.
    status VARCHAR(20) NOT NULL DEFAULT 'contingency',
    -- El identificador que devuelve la autoridad (CUFE en Colombia).
    external_id VARCHAR(200),
    -- Lo que respondio el proveedor, tal cual: sin esto, un rechazo es un
    -- mensaje que nadie puede reconstruir tres meses despues.
    provider VARCHAR(40) NOT NULL DEFAULT 'local',
    response JSONB,
    total NUMERIC(10,2) NOT NULL,
    issued_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    transmitted_at TIMESTAMPTZ
);
CREATE INDEX idx_fiscal_documents_status ON fiscal_documents(status) WHERE status = 'contingency';

ALTER TABLE fiscal_documents ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON fiscal_documents USING (EXISTS (
    SELECT 1 FROM orders o
    WHERE o.id = fiscal_documents.order_id AND o.tenant_id = current_tenant_id()));

GRANT SELECT, INSERT, UPDATE, DELETE ON fiscal_documents TO resto_app;
