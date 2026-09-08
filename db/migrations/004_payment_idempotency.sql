-- =========================================================
-- La llave de idempotencia de un pago pasa a ser unica
--
-- `orders` ya tiene UNIQUE (tenant_id, idempotency_key), pero `payments`
-- guardaba la llave sin restriccion: PaymentService leia primero y escribia
-- despues, y entre esas dos consultas cabe el reintento. Dos peticiones
-- simultaneas con la misma llave —un doble toque, o la cola offline de la
-- fase 3 reenviando— no encontraban nada y cobraban dos veces.
--
-- Con el indice unico, la segunda no entra; PaymentService la reconoce por
-- el SQLSTATE 23505 y devuelve el cobro que ya existia, que es justo lo que
-- la llave prometia.
--
-- Parcial (WHERE ... IS NOT NULL) porque la llave es opcional: un cobro
-- hecho desde una integracion que no la manda no debe chocar con otro.
-- =========================================================

CREATE UNIQUE INDEX IF NOT EXISTS uq_payments_order_idempotency
    ON payments (order_id, idempotency_key)
    WHERE idempotency_key IS NOT NULL;
