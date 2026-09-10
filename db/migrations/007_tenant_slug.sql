-- =========================================================
-- Slug de empresa, para poder entrar cuando el correo se repite.
--
-- El login resolvia la empresa solo por el correo: si dos restaurantes
-- registraban el mismo, ganaba el primero y el segundo no podia entrar nunca
-- —sin ningun error que lo explicara—. Ahora cada empresa tiene un nombre
-- corto que se puede escribir al ingresar.
--
-- La funcion nueva devuelve TODAS las empresas donde ese correo esta activo,
-- en vez de la primera. Quien decide es AuthService, que verifica la
-- contrasena contra cada candidata: asi el caso ambiguo solo se le revela a
-- quien ya la sabe.
-- =========================================================

ALTER TABLE tenants ADD COLUMN IF NOT EXISTS slug VARCHAR(50);

-- Se rellena a partir del nombre, con el mismo criterio que Domain\Slug:
-- minusculas, sin acentos y con guiones. El desempate por fecha de creacion
-- deja el sufijo en la empresa mas nueva, que es la que todavia no lo usaba.
WITH normalizados AS (
    SELECT id,
           regexp_replace(
               trim(both '-' FROM regexp_replace(
                   lower(translate(name, 'áéíóúüñàèìòùç', 'aeiouunaeiouc')),
                   '[^a-z0-9]+', '-', 'g'
               )),
               '^$', 'empresa'
           ) AS base,
           row_number() OVER (
               PARTITION BY regexp_replace(
                   lower(translate(name, 'áéíóúüñàèìòùç', 'aeiouunaeiouc')),
                   '[^a-z0-9]+', '-', 'g'
               )
               ORDER BY created_at
           ) AS orden
      FROM tenants
     WHERE slug IS NULL
)
UPDATE tenants t
   SET slug = CASE WHEN n.orden = 1 THEN left(n.base, 50) ELSE left(n.base, 47) || '-' || n.orden END
  FROM normalizados n
 WHERE n.id = t.id;

ALTER TABLE tenants ALTER COLUMN slug SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tenants_slug_key') THEN
        ALTER TABLE tenants ADD CONSTRAINT tenants_slug_key UNIQUE (slug);
    END IF;
END $$;

-- ---------- Resolucion del login ----------

-- Todas las empresas donde ese correo tiene un usuario activo. Sigue siendo
-- la unica consulta que cruza empresas, y sigue acotada a devolver ids.
CREATE OR REPLACE FUNCTION auth_tenants_for_email(p_email VARCHAR) RETURNS SETOF UUID
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT tenant_id FROM users WHERE email = p_email AND is_active
$$;

-- La empresa con ese slug, si ademas tiene un usuario activo con ese correo.
CREATE OR REPLACE FUNCTION auth_tenant_for_login(p_email VARCHAR, p_slug VARCHAR) RETURNS UUID
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT u.tenant_id
      FROM users u
      JOIN tenants t ON t.id = u.tenant_id
     WHERE u.email = p_email AND u.is_active AND t.slug = p_slug
     LIMIT 1
$$;

GRANT EXECUTE ON FUNCTION auth_tenants_for_email(VARCHAR) TO resto_app;
GRANT EXECUTE ON FUNCTION auth_tenant_for_login(VARCHAR, VARCHAR) TO resto_app;

-- La vieja devolvia "la primera empresa que tenga ese correo", que es
-- justamente el error que esto arregla. Se quita para que nadie la reuse.
DROP FUNCTION IF EXISTS auth_tenant_for_email(VARCHAR);
