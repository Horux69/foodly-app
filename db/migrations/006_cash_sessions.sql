-- =========================================================
-- Cierre de turno y arqueo (fase 2)
--
-- El permiso cash.close existia en el catalogo desde el principio y ningun
-- endpoint lo usaba: un restaurante no podia cerrar el dia con el sistema.
--
-- Un turno de caja es un intervalo con una base de apertura y un conteo de
-- cierre. Lo que lo hace util es la tercera columna implicita: que cada cobro
-- sepa a que turno pertenece (payments.cash_session_id). Sin eso, "cuanto
-- deberia haber en el cajon" habria que deducirlo por rango de fechas, y un
-- turno que cruza la medianoche o dos cajas abiertas el mismo dia lo
-- romperian.
--
-- El conteo se guarda tal como se conto (counted_cash) y la diferencia NO se
-- guarda: se calcula en Domain\CashSessionTotals a partir de los movimientos
-- reales. Una diferencia almacenada es una cifra que envejece —si manana
-- aparece un reembolso de ese turno, la guardada mentiria y la calculada no.
-- =========================================================

CREATE TABLE IF NOT EXISTS cash_sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    branch_id UUID NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    opened_by UUID REFERENCES users(id) ON DELETE SET NULL,
    closed_by UUID REFERENCES users(id) ON DELETE SET NULL,
    -- Base: con lo que arranca el cajon para poder dar vuelto.
    opening_float NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (opening_float >= 0),
    -- Lo que se conto al cerrar. NULL mientras el turno sigue abierto.
    counted_cash NUMERIC(10,2) CHECK (counted_cash >= 0),
    note VARCHAR(255),
    opened_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    closed_at TIMESTAMPTZ,
    -- Un turno cerrado sin conteo no es un arqueo, es una fecha.
    CONSTRAINT cash_sessions_cierre_completo CHECK (
        (closed_at IS NULL AND counted_cash IS NULL)
        OR (closed_at IS NOT NULL AND counted_cash IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS idx_cash_sessions_branch ON cash_sessions (branch_id, opened_at DESC);

-- Una sola caja abierta por sucursal. Es la regla que hace que "el turno
-- actual" sea una pregunta con una sola respuesta, y la impone Postgres y no
-- una comprobacion en PHP: dos cajeros abriendo a la vez pasarian la
-- comprobacion y dejarian dos turnos abiertos.
CREATE UNIQUE INDEX IF NOT EXISTS uq_cash_sessions_abierta_por_sucursal
    ON cash_sessions (branch_id) WHERE closed_at IS NULL;

-- A que turno pertenece cada cobro. ON DELETE SET NULL y no CASCADE: borrar
-- un turno jamas debe llevarse por delante la plata que se cobro en el.
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS cash_session_id UUID REFERENCES cash_sessions(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_payments_cash_session ON payments (cash_session_id)
    WHERE cash_session_id IS NOT NULL;

-- ---------- RLS ----------
ALTER TABLE cash_sessions ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tenant_isolation ON cash_sessions;
CREATE POLICY tenant_isolation ON cash_sessions USING (tenant_id = current_tenant_id());

-- El ALTER DEFAULT PRIVILEGES de la 002 ya alcanza a las tablas nuevas, pero
-- se concede explicito para que esta migracion se sostenga sola.
GRANT SELECT, INSERT, UPDATE, DELETE ON cash_sessions TO resto_app;
