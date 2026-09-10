-- =========================================================
-- Estaciones de preparacion (F8.1).
--
-- La comanda se imprimia entera en una hoja. Una cocina con barra, plancha y
-- fríos necesita que cada estacion reciba solo lo suyo: si no, alguien
-- recorta el papel con tijeras o se pierde un plato. Y el tablero de cocina
-- muestra todo a todos, que en hora pico es ruido.
--
-- La estacion es del tenant y no de la sucursal: la cocina de una marca se
-- organiza igual en todas sus sedes. Lo que cambia por sede es a que
-- impresora va cada una, y eso es la tarjeta siguiente (F8.2).
--
-- El ruteo va por categoria del menu y no por producto: "las bebidas a la
-- barra" es como se piensa una carta, y una excepcion por producto se puede
-- resolver moviendolo de categoria. Si algun dia hace falta de verdad, es
-- una columna en menu_items y el mismo dominio la resuelve en cascada.
-- =========================================================

CREATE TABLE stations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(60) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT true,
    UNIQUE (tenant_id, name)
);

ALTER TABLE stations ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON stations USING (tenant_id = current_tenant_id());
GRANT SELECT, INSERT, UPDATE, DELETE ON stations TO resto_app;

-- Sin ON DELETE CASCADE: borrar una estacion no puede dejar categorias
-- apuntando al vacio en silencio. El servicio exige moverlas antes.
ALTER TABLE menu_categories
    ADD COLUMN station_id UUID REFERENCES stations(id);

-- Nadie tiene estaciones al empezar, y eso es valido: sin ninguna, la
-- comanda sale entera como hasta ahora.
