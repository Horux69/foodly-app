-- =========================================================
-- Tiempos de la cuenta y "marchar" (F4.5).
--
-- En una mesa se pide todo junto pero no se cocina todo junto: las entradas
-- salen primero y los postres cuando la mesa termina. Hasta ahora un pedido
-- entero llegaba a la cocina de una vez, asi que en una mesa de ocho los
-- postres salian con las entradas — o el mesero tenia que tomar tres
-- pedidos distintos para la misma mesa, que despues hay que cobrar juntos.
--
-- El tiempo es de la linea, no del pedido: la misma cuenta lleva lineas de
-- varios tiempos y cada uno se manda a la cocina cuando el mesero lo marcha.
-- =========================================================

-- El numero del tiempo. Como numero y no como texto: el nombre ("Entradas",
-- "Fuertes") lo pone cada restaurante en tenants.settings.courses, y
-- guardarlo aqui congelaria la etiqueta en cada linea vendida.
ALTER TABLE order_items ADD COLUMN course SMALLINT NOT NULL DEFAULT 1
    CHECK (course BETWEEN 1 AND 9);

-- Cuando se mando a la cocina. Nulo es "todavia no": la comanda de ese
-- tiempo no se ha impreso y el tablero no lo muestra.
ALTER TABLE order_items ADD COLUMN fired_at TIMESTAMPTZ;

-- Lo que ya se vendio se da por marchado: existia antes de la columna y se
-- preparo entero. Se fecha con el pedido y no con now(), o la cocina veria
-- de golpe como recien marchado todo el historico.
UPDATE order_items oi SET fired_at = o.created_at FROM orders o WHERE o.id = oi.order_id;

-- El tablero y la comanda piden las lineas de un pedido por tiempo.
CREATE INDEX idx_order_items_course ON order_items(order_id, course);
