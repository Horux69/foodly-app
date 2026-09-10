-- =========================================================
-- Perfiles de impresion por sucursal (F8.2).
--
-- El ancho del documento estaba fijo en el CSS (72mm, que entra en una
-- termica de 80) y las copias eran siempre una. Una impresora de 58mm corta
-- los nombres largos, y en muchos restaurantes el ticket se imprime por
-- duplicado —uno para el cliente y otro que se archiva—.
--
-- Es por sucursal y no por empresa: la termica de la sede nueva no tiene por
-- que ser la misma que la de la principal.
-- =========================================================

CREATE TABLE print_profiles (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    branch_id UUID NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    -- Que documento: 'comanda' (cocina), 'ticket' (cliente), 'precuenta'.
    document VARCHAR(20) NOT NULL,
    -- Ancho del papel en milimetros. Solo hay dos formatos en el mercado.
    width_mm INT NOT NULL DEFAULT 80 CHECK (width_mm IN (58, 80)),
    copies INT NOT NULL DEFAULT 1 CHECK (copies BETWEEN 1 AND 3),
    UNIQUE (branch_id, document)
);

-- Sin fila para un documento se usan los valores por defecto del dominio, y
-- eso es lo normal: nadie tiene que configurar nada para imprimir.

ALTER TABLE print_profiles ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON print_profiles USING (EXISTS (
    SELECT 1 FROM branches b
    WHERE b.id = print_profiles.branch_id AND b.tenant_id = current_tenant_id()));

GRANT SELECT, INSERT, UPDATE, DELETE ON print_profiles TO resto_app;
