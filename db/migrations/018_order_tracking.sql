-- =========================================================
-- Seguimiento del pedido para el cliente (F9.2).
--
-- Hoy el cliente que pide un domicilio no sabe nada hasta que suena el
-- timbre: llama al restaurante, alguien deja la caja para contestar y le
-- dice "ya va en camino" sin mirar. El seguimiento es la pantalla que
-- responde eso sola, y la que hace util el mensaje de WhatsApp que vendra
-- despues.
-- =========================================================

-- El token es la llave del enlace publico. 32 caracteres hexadecimales
-- —16 bytes de aleatoriedad— porque es un enlace que se manda por
-- WhatsApp: si fuera adivinable, cualquiera podria pasearse por los pedidos
-- ajenos probando numeros. El id del pedido no sirve para esto: es un UUID
-- que la aplicacion usa por dentro y que aparece en las URL del panel.
ALTER TABLE orders ADD COLUMN tracking_token VARCHAR(32) UNIQUE;

-- Los pedidos que ya existen no necesitan enlace —nadie va a seguir un
-- pedido de la semana pasada— y dejarlos en nulo es mas honesto que
-- inventarles uno que nunca se entrego.

-- La consulta del enlace publico no tiene sesion, asi que tampoco tiene
-- tenant del cual filtrar y la politica de `orders` la dejaria en cero
-- filas. Como en el login (`auth_tenant_for_email`), una funcion
-- SECURITY DEFINER deliberadamente miserable: recibe un token y devuelve
-- solo a que empresa pertenece. Con eso la aplicacion fija el contexto y
-- sigue leyendo bajo RLS como cualquier otra peticion.
CREATE OR REPLACE FUNCTION tracking_tenant_for_token(p_token VARCHAR) RETURNS UUID
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT tenant_id FROM orders WHERE tracking_token = p_token LIMIT 1
$$;

GRANT EXECUTE ON FUNCTION tracking_tenant_for_token(VARCHAR) TO resto_app;
