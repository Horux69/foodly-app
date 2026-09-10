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

DB="${TEST_DB_NAME:-resto_test}"
OWNER="${TEST_DB_OWNER:-resto}"
APP_ROLE="${TEST_DB_APP_ROLE:-resto_app}"
PSQL=(psql -v ON_ERROR_STOP=1 -q)

echo "Recreando ${DB}…"
"${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS ${DB}"
"${PSQL[@]}" -d postgres -c "CREATE DATABASE ${DB} OWNER ${OWNER}"

for migracion in db/migrations/*.sql; do
  echo "  $(basename "$migracion")"
  "${PSQL[@]}" -d "${DB}" -f "$migracion"
done

# Solo el catálogo de permisos: las pruebas crean sus propias empresas, y el
# tenant de demostración las haría depender de datos que pueden cambiar.
echo "  seeds/001_defaults.sql"
"${PSQL[@]}" -d "${DB}" -f db/seeds/001_defaults.sql

# El rol de la aplicación y sus permisos los deja la migración 002: no es
# dueño de las tablas, que es lo único que hace que Postgres le aplique RLS.
# Aquí solo se le pone con qué entrar, igual que hace setup-php.sh.
APP_PASSWORD="${TEST_DB_APP_PASSWORD:-resto_app_dev}"
"${PSQL[@]}" -d "${DB}" -c "ALTER ROLE ${APP_ROLE} WITH LOGIN PASSWORD '${APP_PASSWORD}'"

echo
echo "Listo. Para que las pruebas la encuentren, en php/.env:"
echo "  TEST_DATABASE_URL=\"pgsql:host=localhost;port=5432;dbname=${DB};user=${OWNER};password=…\""
echo "  TEST_APP_DATABASE_URL=\"pgsql:host=localhost;port=5432;dbname=${DB};user=${APP_ROLE};password=…\""
