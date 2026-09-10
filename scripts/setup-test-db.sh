#!/usr/bin/env bash
#
# Base de datos para las pruebas de integración.
#
# Aparte de la de desarrollo a propósito: las pruebas crean y borran empresas,
# y hacerlo sobre la base con la que uno está probando a mano sería perder el
# trabajo del día. Se puede volver a correr cuando se quiera — recrea la base
# desde cero.
#
#   ./scripts/setup-test-db.sh
#   cd php && vendor/bin/phpunit          # las de integración ya no se saltan
#
# Sin esto, la suite de integración se salta entera y el resto corre igual.
set -euo pipefail

# Las rutas de abajo son relativas a la raíz del repositorio, no a scripts/.
cd "$(dirname "$0")/.."

DB="${TEST_DB_NAME:-resto_test}"
OWNER="${TEST_DB_OWNER:-resto}"
APP_ROLE="${TEST_DB_APP_ROLE:-resto_app}"
CONTENEDOR="${TEST_DB_CONTAINER:-resto_db}"

# Con el cliente psql instalado se usa ese. Si no está —lo normal en Windows,
# donde Postgres solo vive en el contenedor de docker-compose— se entra por
# docker exec. Sin esta salida el script moría con "psql: command not found",
# la base de pruebas no se creaba y las pruebas de integración se saltaban en
# silencio, que es la peor forma de fallar: la suite pasa igual.
if command -v psql >/dev/null 2>&1; then
  correr()  { psql -v ON_ERROR_STOP=1 -q -d "$1" -c "$2"; }
  archivo() { psql -v ON_ERROR_STOP=1 -q -d "$1" -f "$2"; }
else
  if ! docker exec "${CONTENEDOR}" true >/dev/null 2>&1; then
    echo "No hay psql instalado ni el contenedor '${CONTENEDOR}' está arriba." >&2
    echo "Levántalo con:  docker compose up -d db" >&2
    exit 1
  fi
  echo "Sin psql local: usando el contenedor ${CONTENEDOR}."
  # -f no sirve por docker exec porque el archivo está en el host, no dentro
  # del contenedor: se manda por la entrada estándar.
  correr()  { docker exec -i "${CONTENEDOR}" psql -v ON_ERROR_STOP=1 -q -U "${OWNER}" -d "$1" -c "$2"; }
  archivo() { docker exec -i "${CONTENEDOR}" psql -v ON_ERROR_STOP=1 -q -U "${OWNER}" -d "$1" < "$2"; }
fi

echo "Recreando ${DB}…"
correr postgres "DROP DATABASE IF EXISTS ${DB}"
correr postgres "CREATE DATABASE ${DB} OWNER ${OWNER}"

for migracion in db/migrations/*.sql; do
  echo "  $(basename "$migracion")"
  archivo "${DB}" "$migracion"
done

# Solo el catálogo de permisos: las pruebas crean sus propias empresas, y el
# tenant de demostración las haría depender de datos que pueden cambiar.
echo "  seeds/001_defaults.sql"
archivo "${DB}" db/seeds/001_defaults.sql

# El rol de la aplicación y sus permisos los deja la migración 002: no es
# dueño de las tablas, que es lo único que hace que Postgres le aplique RLS.
# Aquí solo se le pone con qué entrar, igual que hace setup-php.sh.
APP_PASSWORD="${TEST_DB_APP_PASSWORD:-resto_app_dev}"
correr "${DB}" "ALTER ROLE ${APP_ROLE} WITH LOGIN PASSWORD '${APP_PASSWORD}'"

echo
echo "Listo. Para que las pruebas la encuentren, en php/.env:"
echo "  TEST_DATABASE_URL=\"pgsql:host=localhost;port=5433;dbname=${DB};user=${OWNER};password=…\""
echo "  TEST_APP_DATABASE_URL=\"pgsql:host=localhost;port=5433;dbname=${DB};user=${APP_ROLE};password=…\""
