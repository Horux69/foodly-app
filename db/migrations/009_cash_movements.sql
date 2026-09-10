-- =========================================================
-- Entradas y salidas de efectivo del cajon (F7.1).
--
-- El arqueo ya existia pero solo conocia cobros y reembolsos, y un cajon real
-- recibe y entrega plata todo el dia por fuera de las ventas: la sangria
-- cuando se acumula demasiado, el pago al domiciliario, la compra de
-- emergencia, el prestamo de la caja de al lado.
--
-- Sin registrarlos, el arqueo declara un faltante que no es un faltante —y un
-- control que "siempre da mal" deja de usarse, que es peor que no tenerlo.
-- =========================================================

CREATE TABLE cash_movements (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    cash_session_id UUID NOT NULL REFERENCES cash_sessions(id) ON DELETE CASCADE,
    -- 'in' entra al cajon, 'out' sale. El importe siempre es positivo: el
    -- signo lo pone el tipo, igual que en payments lo pone refund_of_payment_id.
    kind VARCHAR(3) NOT NULL CHECK (kind IN ('in', 'out')),
    -- Por que se movio la plata. Obligatorio: un movimiento sin motivo no
    -- responde la pregunta que se le hace al arqueo al final del turno.
    reason VARCHAR(120) NOT NULL CHECK (length(trim(reason)) > 0),
    amount NUMERIC(10,2) NOT NULL CHECK (amount > 0),
    created_by UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_cash_movements_session ON cash_movements(cash_session_id);

-- No se editan ni se borran: un movimiento equivocado se corrige con el
-- contrario, como un reembolso corrige un cobro. Por eso no hay updated_at.

-- ---------- RLS ----------
-- Cuelga de cash_sessions, que ya tiene tenant_id y su politica.
ALTER TABLE cash_movements ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON cash_movements USING (EXISTS (
    SELECT 1 FROM cash_sessions s
    WHERE s.id = cash_movements.cash_session_id AND s.tenant_id = current_tenant_id()));

GRANT SELECT, INSERT, UPDATE, DELETE ON cash_movements TO resto_app;

-- ---------- Permiso ----------
-- Aparte de payments.register: cobrar es recibir plata de una venta, sacarla
-- del cajon es otra cosa y en muchos restaurantes la autoriza otra persona.
INSERT INTO permissions (code, description) VALUES
  ('cash.movements', 'Registrar entradas y salidas de efectivo')
ON CONFLICT (code) DO NOTHING;

-- Los roles de sistema ya existen y se quedarian sin acceso a algo de su
-- propio restaurante.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.is_system AND p.code = 'cash.movements'
ON CONFLICT DO NOTHING;
