# Plataforma de Restaurantes

API multi-tenant para gestión de restaurantes, adaptable a distintos modelos de
negocio (comida rápida, servicio en mesa, domicilios) mediante configuración y
no mediante código.

## Stack

- **Base de datos**: PostgreSQL 16 (con Row Level Security por tenant)
- **Backend**: Python 3.11+ / FastAPI / SQLAlchemy 2
- **Frontend**: HTML, CSS/Tailwind, JavaScript (pendiente)

## Puesta en marcha

```bash
git clone <url-del-repo>
cd resto-platform

# Levanta Postgres, aplica esquema y siembra datos de demo
./scripts/setup.sh

# Dependencias de Python
python -m venv .venv && source .venv/bin/activate
pip install -r requirements-dev.txt

# API en http://localhost:8000/docs
uvicorn app.main:app --reload

# Tests
pytest
```

Adminer queda en `http://localhost:8080` (servidor `db`, usuario `resto`).

Usuario de demo: `admin@demo.local` / `admin123` (solo desarrollo).

## Estructura

```
app/
  api/          Endpoints HTTP y dependencias de autenticación
  core/         Configuración, base de datos, seguridad, permisos
  domain/       Lógica de negocio pura (sin BD, sin framework)
  models/       Modelos SQLAlchemy
  schemas/      Esquemas Pydantic (entrada/salida de la API)
  services/     Casos de uso (orquestan dominio + repositorios)
  repositories/ Acceso a datos
db/
  migrations/   Esquema SQL versionado
  seeds/        Datos semilla (permisos, tenant de demo)
docs/           Diseño de base de datos y alcance funcional
tests/          Pruebas de la lógica de dominio
```

## Principios no negociables

1. **El `tenant_id` viaja en el token JWT.** Nunca se acepta desde el body ni
   desde query params.
2. **Un solo punto resuelve el tenant**: `app/api/deps.py:get_context`. No
   replicar esa lógica en controladores.
3. **RLS de Postgres como red de seguridad.** Cada request ejecuta
   `SET LOCAL app.current_tenant`. Si un query olvida el `WHERE tenant_id`, el
   motor no devuelve datos ajenos.
4. **Las reglas variables van en configuración, no en código.** Nunca
   `if tenant_id == 'X'`. Si un restaurante nuevo exige tocar código, el diseño
   falló.
5. **Los totales se calculan en un solo lugar**: `app/domain/order_totals.py`.
   No duplicar esa aritmética en controladores ni en el frontend.
6. **Los precios se congelan en el pedido.** `order_items` guarda
   `unit_price`, `tax_rate` y `name_snapshot` del momento de la venta.

## Contexto para Claude Code

El archivo [`CLAUDE.md`](CLAUDE.md) en la raíz carga automáticamente el contexto
del proyecto al abrir una sesión de Claude Code.

## Documentación

- [`docs/database-design.md`](docs/database-design.md) — modelo de datos y decisiones
- [`docs/functional-scope.md`](docs/functional-scope.md) — módulos y alcance funcional
- [`docs/multi-tenancy.md`](docs/multi-tenancy.md) — estrategia multi-empresa

## Roadmap

- [x] Modelo de datos v2 (estados y roles configurables, impuestos, horarios)
- [ ] Módulo 1: configuración y administración
- [ ] Módulo 2: menú y catálogo
- [ ] Módulo 3: toma de pedidos
- [ ] Módulos 4 y 5: cocina (KDS) y caja
- [ ] Módulo 8: reportes
- [ ] Fase futura: agente conversacional por WhatsApp y pagos in-chat
