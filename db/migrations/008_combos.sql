-- =========================================================
-- Combos: varios productos completos a precio de paquete.
--
-- Hasta ahora no se podian modelar. Los modificadores suman o restan sobre
-- una linea, no "son" otro producto: un combo de hamburguesa + papas +
-- gaseosa a 28000 habia que venderlo como tres lineas sueltas y descontar a
-- mano, o como un producto suelto llamado "Combo" que la cocina no sabia
-- preparar porque no decia que llevaba.
--
-- Un combo es un producto normal de `menu_items` —con su precio, su impuesto
-- y su disponibilidad— que ademas declara que lleva dentro. Se vende como una
-- sola linea al precio del paquete: nada de repartir el descuento entre
-- componentes, que es de donde salen los centavos que no cuadran.
-- =========================================================

-- ---------- Que lleva un combo ----------
CREATE TABLE menu_item_components (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    -- El combo.
    parent_item_id UUID NOT NULL REFERENCES menu_items(id) ON DELETE CASCADE,
    -- Uno de los productos que lo componen.
    component_item_id UUID NOT NULL REFERENCES menu_items(id) ON DELETE RESTRICT,
    quantity INT NOT NULL DEFAULT 1 CHECK (quantity > 0),
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE (parent_item_id, component_item_id),
    -- Un combo que se contiene a si mismo no se puede preparar ni imprimir.
    CHECK (parent_item_id <> component_item_id)
);
CREATE INDEX idx_components_parent ON menu_item_components(parent_item_id);

-- ON DELETE RESTRICT en el componente y no CASCADE: borrar la gaseosa no
-- puede vaciar en silencio un combo que se sigue vendiendo. Los productos se
-- archivan, ademas, asi que este camino casi no se recorre.

-- ---------- Que llevaba cuando se vendio ----------
-- El precio y el nombre del producto se congelan en `order_items` (principio
-- 8); la composicion del combo tambien tiene que congelarse, o la comanda de
-- un pedido viejo mostraria lo que el combo lleva hoy.
CREATE TABLE order_item_components (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_item_id UUID NOT NULL REFERENCES order_items(id) ON DELETE CASCADE,
    menu_item_id UUID NOT NULL REFERENCES menu_items(id),
    name_snapshot VARCHAR(150) NOT NULL,
    quantity INT NOT NULL CHECK (quantity > 0),
    sort_order INT NOT NULL DEFAULT 0
);
CREATE INDEX idx_order_item_components_item ON order_item_components(order_item_id);

-- ---------- RLS ----------
-- Las dos cuelgan de tablas que ya tienen politica; la suya sube por ahi,
-- igual que item_modifier_groups y order_item_modifiers.
ALTER TABLE menu_item_components ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON menu_item_components USING (EXISTS (
    SELECT 1 FROM menu_items i
    JOIN menu_categories c ON c.id = i.category_id
    WHERE i.id = menu_item_components.parent_item_id AND c.tenant_id = current_tenant_id()));

ALTER TABLE order_item_components ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON order_item_components USING (EXISTS (
    SELECT 1 FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE oi.id = order_item_components.order_item_id AND o.tenant_id = current_tenant_id()));

GRANT SELECT, INSERT, UPDATE, DELETE ON menu_item_components TO resto_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON order_item_components TO resto_app;
