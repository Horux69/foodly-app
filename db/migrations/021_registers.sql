-- =========================================================
-- Varias cajas por sucursal (F7.4).
--
-- Hasta ahora un indice unico parcial imponia un solo turno abierto por
-- sucursal, y eso era correcto mientras "la caja" fuera una. Un
-- restaurante con dos puntos de cobro —el mostrador y la barra, o dos
-- registradoras en fila— necesita dos cajones que cuadran por separado:
-- con uno solo, el faltante de una caja se compensa con el sobrante de la
-- otra y el arqueo deja de decir nada.
--
-- El turno pasa a colgar de la caja, no de la sede. Y una sede sin cajas
-- configuradas sigue funcionando igual que hoy: su turno tiene
-- `register_id` nulo, que es "el cajon de la sucursal".
-- =========================================================

CREATE TABLE registers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    branch_id UUID NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    name VARCHAR(60) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (branch_id, name)
);

CREATE INDEX idx_registers_branch ON registers(branch_id) WHERE is_active;

-- En que caja se abrio el turno. Nulo es el cajon de la sucursal, que es lo
-- que tienen todos los turnos que ya existen y lo que seguira teniendo
-- quien no configure cajas.
--
-- ON DELETE RESTRICT: una caja con turnos encima no se borra, se apaga.
-- Borrarla dejaria arqueos historicos sin decir de que cajon eran.
ALTER TABLE cash_sessions ADD COLUMN register_id UUID REFERENCES registers(id) ON DELETE RESTRICT;

-- Un turno abierto por caja. Y, para la sede sin cajas, uno solo con
-- register_id nulo: son dos indices porque un UNIQUE normal trata cada
-- NULL como distinto y dejaria abrir dos turnos "de la sucursal".
DROP INDEX IF EXISTS uq_cash_sessions_abierta_por_sucursal;

CREATE UNIQUE INDEX uq_cash_sessions_abierta_por_caja
    ON cash_sessions (register_id) WHERE closed_at IS NULL AND register_id IS NOT NULL;

CREATE UNIQUE INDEX uq_cash_sessions_abierta_sin_caja
    ON cash_sessions (branch_id) WHERE closed_at IS NULL AND register_id IS NULL;

-- ---------- RLS ----------
ALTER TABLE registers ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON registers USING (tenant_id = current_tenant_id());

GRANT SELECT, INSERT, UPDATE, DELETE ON registers TO resto_app;
