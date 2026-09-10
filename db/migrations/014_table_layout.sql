-- =========================================================
-- El plano del salon (F4.2).
--
-- Las mesas existian con su codigo y su capacidad, pero sin sitio: no habia
-- forma de dibujar el salon, que en un restaurante de mesa es la pantalla
-- principal. Quien atiende no piensa en "mesa M7", piensa en la del rincon.
--
-- Las coordenadas son numeros sin unidad y la pantalla las interpreta: no se
-- guardan pixeles porque el mismo plano se mira en una tableta y en un
-- portatil, y una posicion en pixeles se veria movida en cada pantalla.
-- =========================================================

ALTER TABLE tables
    ADD COLUMN pos_x INT NOT NULL DEFAULT 0 CHECK (pos_x >= 0),
    ADD COLUMN pos_y INT NOT NULL DEFAULT 0 CHECK (pos_y >= 0),
    -- Redonda o cuadrada: es como se reconoce una mesa de un vistazo.
    ADD COLUMN shape VARCHAR(10) NOT NULL DEFAULT 'square'
        CHECK (shape IN ('square', 'round'));

-- Todas arrancan en 0,0 y la pantalla las reparte en rejilla hasta que
-- alguien las coloque: un salon vacio no puede ser una pila de mesas
-- encimadas en una esquina.
