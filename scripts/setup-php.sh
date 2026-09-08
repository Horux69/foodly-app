#!/usr/bin/env bash
# Arranque local del backend PHP. Levanta la misma base que scripts/setup.sh
# (mismo esquema, mismas politicas RLS, mismas semillas) y deja el servidor
# listo para servir la API y el frontend de web/ desde el mismo origen.
set -euo pipefail

cd "$(dirname "$0")/.."

echo ">> Levantando Postgres"
docker compose up -d db
until docker compose exec -T db pg_isready -U resto >/dev/null 2>&1; do sleep 1; done

echo ">> Aplicando esquema y politicas RLS"
docker compose exec -T db psql -q -U resto -d resto_platform < db/migrations/001_initial_schema.sql
docker compose exec -T db psql -q -U resto -d resto_platform < db/migrations/002_rls_hardening.sql

echo ">> Habilitando el rol de aplicacion"
# Este rol no es dueño de las tablas: eso es justamente lo que hace que las
# politicas RLS lo alcancen. La clave vive en el entorno, no en el repositorio.
APP_DB_PASSWORD="${APP_DB_PASSWORD:-resto_app_dev}"
docker compose exec -T db psql -q -U resto -d resto_platform \
  -c "ALTER ROLE resto_app WITH LOGIN PASSWORD '${APP_DB_PASSWORD}';"

echo ">> Sembrando permisos y tenant de demo"
docker compose exec -T db psql -q -U resto -d resto_platform < db/seeds/001_defaults.sql
docker compose exec -T db psql -q -U resto -d resto_platform < db/seeds/002_demo_tenant.sql

echo ">> Instalando dependencias PHP"
cd php
[ -f .env ] || cp .env.example .env
composer install --no-interaction

echo
echo ">> Listo. Levanta la aplicacion con:"
echo "     cd php && php -S localhost:8000 -t public public/index.php"
echo
echo "   Aplicacion : http://localhost:8000/        (frontend de web/)"
echo "   API        : http://localhost:8000/api/v1"
echo "   Usuario    : admin@demo.local / admin123"
echo
echo "   Pruebas del dominio:  cd php && vendor/bin/phpunit"
echo "   Alta de restaurante:  cd php && php bin/create_tenant.php \"Nombre\" \\"
echo "                           --branch=\"Sede\" --branch-code=SED \\"
echo "                           --admin-email=dueno@x.com --admin-password=\"clave-larga\""
