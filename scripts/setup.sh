#!/usr/bin/env bash
set -euo pipefail

echo ">> Copiando .env"
[ -f .env ] || cp .env.example .env

echo ">> Levantando Postgres"
docker compose up -d db
until docker compose exec -T db pg_isready -U resto >/dev/null 2>&1; do sleep 1; done

echo ">> Aplicando esquema"
docker compose exec -T db psql -U resto -d resto_platform < db/migrations/001_initial_schema.sql

echo ">> Sembrando permisos"
docker compose exec -T db psql -U resto -d resto_platform < db/seeds/001_defaults.sql

echo ">> Sembrando tenant de demo"
docker compose exec -T db psql -U resto -d resto_platform < db/seeds/002_demo_tenant.sql

echo ">> Listo. Instala dependencias con: pip install -r requirements-dev.txt"
echo ">> Levanta la API con: uvicorn app.main:app --reload"
