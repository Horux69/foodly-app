-- =========================================================
-- Cuadre del repartidor (F9.1).
--
-- El domicilio ya sabe quien lo lleva y a que hora salio, pero no que pasa
-- con la plata: el repartidor vuelve con el efectivo de varios pedidos y
-- nadie cuadra eso contra nada. En un dia de treinta domicilios la
-- diferencia se descubre —si se descubre— en el arqueo de la caja, donde ya
-- no se puede decir de que pedido salio.
--
-- Es el mismo control del arqueo aplicado a otra caja: la del repartidor.
-- Por eso sigue sus dos reglas.
-- =========================================================

CREATE TABLE courier_settlements (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    branch_id UUID NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    courier_id UUID REFERENCES users(id) ON DELETE SET NULL,

    -- La ventana que cubre. El siguiente cuadre arranca donde termino este,
    -- que es lo que impide contar dos veces el mismo cobro sin tener que
    -- marcar pago por pago. Nulo en `from_at` es "desde siempre": el primer
    -- cuadre de un repartidor recoge todo lo que traiga pendiente.
    from_at TIMESTAMPTZ,
    to_at TIMESTAMPTZ NOT NULL DEFAULT now(),

    -- Lo que entrego, tal como se conto. El esperado NO se guarda: se
    -- calcula con los cobros reales de la ventana (Domain\CourierSettlement),
    -- igual que en el arqueo. Un esperado almacenado mentiria en cuanto se
    -- registre un reembolso de uno de esos pedidos.
    counted_cash NUMERIC(10,2) NOT NULL CHECK (counted_cash >= 0),
    note VARCHAR(255),

    created_by UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT courier_settlements_ventana CHECK (from_at IS NULL OR to_at > from_at)
);

-- El cuadre pendiente de un repartidor se pregunta por su ultimo cierre.
CREATE INDEX idx_courier_settlements_courier ON courier_settlements(courier_id, to_at DESC);

-- No se editan ni se borran: un cuadre equivocado se corrige con el
-- siguiente, como un movimiento del cajon se corrige con el contrario.

-- ---------- RLS ----------
ALTER TABLE courier_settlements ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON courier_settlements USING (tenant_id = current_tenant_id());

GRANT SELECT, INSERT, UPDATE, DELETE ON courier_settlements TO resto_app;
