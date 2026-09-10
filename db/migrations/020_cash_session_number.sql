-- =========================================================
-- Consecutivo del arqueo y corte X (F7.5).
--
-- Un arqueo se archiva en papel y se busca por su numero: "el cierre 142".
-- Hasta ahora un turno solo tenia su id —un UUID que nadie dicta por
-- telefono— y su hora, que se repite si el mismo dia hay dos turnos.
--
-- El corte X —ver el cuadre a mitad de turno sin cerrarlo— no necesita
-- tabla: los totales ya se calculan en vivo (`Domain\CashSessionTotals`) y
-- `GET /cash/session` los devuelve a quien tiene `cash.close`. Lo que le
-- faltaba era un numero con el cual referirse a ese turno en el papel que
-- se imprime.
-- =========================================================

-- El contador vive en la sucursal, como el de pedidos: cada caja lleva su
-- serie y dos sedes no comparten numeracion.
ALTER TABLE branches ADD COLUMN cash_seq INTEGER NOT NULL DEFAULT 0;

ALTER TABLE cash_sessions ADD COLUMN session_number INTEGER;

-- Se asigna al abrir y no al cerrar: el numero identifica el turno desde
-- que empieza, que es lo que hace posible imprimir un corte X con el.
CREATE OR REPLACE FUNCTION next_cash_number(p_branch_id UUID)
RETURNS INTEGER AS $$
DECLARE
    v_seq INTEGER;
BEGIN
    UPDATE branches
       SET cash_seq = cash_seq + 1
     WHERE id = p_branch_id
    RETURNING cash_seq INTO v_seq;
    RETURN v_seq;
END;
$$ LANGUAGE plpgsql;

GRANT EXECUTE ON FUNCTION next_cash_number(UUID) TO resto_app;

-- Los turnos que ya existen se numeran por orden de apertura dentro de su
-- sucursal, y el contador queda donde corresponde: sin esto, el primer
-- turno nuevo empezaria en 1 y chocaria con el historico en el papel.
WITH numerados AS (
    SELECT id, branch_id, row_number() OVER (PARTITION BY branch_id ORDER BY opened_at) AS n
      FROM cash_sessions
)
UPDATE cash_sessions s SET session_number = numerados.n
  FROM numerados WHERE numerados.id = s.id;

UPDATE branches b
   SET cash_seq = coalesce((SELECT max(session_number) FROM cash_sessions WHERE branch_id = b.id), 0);

CREATE UNIQUE INDEX uq_cash_sessions_numero ON cash_sessions(branch_id, session_number);
