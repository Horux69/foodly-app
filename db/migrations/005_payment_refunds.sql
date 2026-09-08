-- =========================================================
-- Reembolsos (fase 2)
--
-- El permiso payments.refund existia en el catalogo desde el principio y
-- ningun endpoint lo usaba: un cobro mal hecho no se podia revertir.
--
-- Un reembolso NO borra ni edita el cobro original: es una fila nueva de
-- payments que apunta a el. La caja necesita la historia —cuanto entro,
-- cuanto salio y cuando— y un UPDATE la borraria.
--
-- Como se distingue una fila de otra:
--
--   * Cobro:     refund_of_payment_id IS NULL.
--                status 'paid', o 'refunded' cuando ya se revirtio entero.
--   * Reembolso: refund_of_payment_id apunta al cobro, status 'paid'
--                (el reembolso si se ejecuto) y amount positivo.
--
-- El saldo entonces es: lo cobrado menos lo devuelto. Un reembolso parcial
-- deja el cobro en 'paid' y resta solo su importe; uno total lo marca
-- 'refunded'. La resta vive en Domain\PaymentBalance, como siempre.
--
-- created_by y note existen por la misma razon que la fila: un movimiento de
-- caja sin autor ni motivo no es historia, es un numero suelto. Aplican a
-- los cobros tambien —de ahi sale el reporte de ventas por usuario de F2.6.
-- =========================================================

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS refund_of_payment_id UUID REFERENCES payments(id) ON DELETE RESTRICT,
    ADD COLUMN IF NOT EXISTS created_by UUID REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS note VARCHAR(255);

-- Para resolver "cuanto se le ha devuelto a este cobro" sin recorrer la tabla.
CREATE INDEX IF NOT EXISTS idx_payments_refund_of ON payments (refund_of_payment_id)
    WHERE refund_of_payment_id IS NOT NULL;

-- Una fila no puede reembolsarse a si misma. Que un reembolso no apunte a
-- otro reembolso —la cadena tiene un solo eslabon, y por eso "cuanto se
-- devolvio" es una suma directa— lo impone PaymentService: un CHECK no
-- puede mirar otra fila.
ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_refund_not_chained;
ALTER TABLE payments ADD CONSTRAINT payments_refund_not_chained CHECK (
    refund_of_payment_id IS NULL OR refund_of_payment_id <> id
);
