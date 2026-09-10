-- =========================================================
-- El mesero a cargo de la cuenta (F4.4).
--
-- Hasta ahora un pedido solo sabia quien lo digito (`created_by`), y en un
-- restaurante de mesa esas dos personas no siempre son la misma: en una
-- tableta compartida en el pasillo, o cuando el cajero toma el pedido que le
-- dicta el mesero, `created_by` responde "quien tecleo" y no "quien atiende".
--
-- Sin la diferencia no se puede repartir la propina ni decir cuanto vendio
-- cada quien, que es justo para lo que se lleva la cuenta por mesero.
-- =========================================================

-- ON DELETE SET NULL y no RESTRICT: un usuario se puede dar de baja y la
-- venta de ayer sigue existiendo. Lo que se pierde es a quien atendio, no el
-- pedido — igual que `created_by`, que ya se comporta asi.
ALTER TABLE orders ADD COLUMN server_id UUID REFERENCES users(id) ON DELETE SET NULL;

-- Parcial: en un restaurante de mostrador la columna es nula en todas las
-- filas y un indice completo seria peso muerto.
CREATE INDEX idx_orders_server ON orders(server_id) WHERE server_id IS NOT NULL;

-- ---------- Permiso ----------
-- Aparte de 'orders.create': tomar un pedido es una cosa y decir de quien es
-- la mesa es otra. Donde la propina se reparte, poner el nombre de otro en
-- una cuenta es mover plata, y hay restaurantes donde eso lo hace el
-- supervisor. Sin este permiso el pedido queda a nombre de quien lo tomo.
INSERT INTO permissions (code, description) VALUES
  ('orders.assign_server', 'Asignar el mesero a cargo de una cuenta')
ON CONFLICT (code) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.is_system AND p.code = 'orders.assign_server'
ON CONFLICT DO NOTHING;
