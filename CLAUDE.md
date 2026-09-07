# Contexto del proyecto

Plataforma multi-tenant para restaurantes. Un mismo sistema debe adaptarse a
distintos modelos de negocio (comida rápida, servicio en mesa, domicilios)
**mediante configuración, no mediante código**.

Fase futura ya prevista en el modelo: agente conversacional que toma pedidos por
WhatsApp y cobra dentro de la conversación. No implementar aún, pero no romper
el camino: el canal `whatsapp` y `customers.phone` existen para eso.

## Stack

- PostgreSQL 16 con Row Level Security por tenant
- Python 3.11+ / FastAPI / SQLAlchemy 2 / Pydantic 2
- Frontend: HTML, CSS/Tailwind, JavaScript vanilla (aún no iniciado)

## Lee esto antes de escribir código

- `docs/database-design.md` — modelo de datos y por qué está así
- `docs/functional-scope.md` — los 9 módulos y el orden de construcción
- `docs/multi-tenancy.md` — dónde se resuelve el tenant en cada capa
- `db/migrations/001_initial_schema.sql` — el esquema real, fuente de verdad

## Principios no negociables

1. **El `tenant_id` viaja en el token JWT.** Nunca se acepta desde el body,
   query params ni headers del cliente. Es el principal vector de fuga de datos
   entre empresas.
2. **Un solo punto resuelve el tenant**: `app/api/deps.py:get_context`. No
   replicar esa lógica en controladores.
3. **RLS de Postgres como red de seguridad.** Cada request ejecuta
   `SET LOCAL app.current_tenant` vía `app/core/database.py:set_tenant_context`.
4. **Nunca `if tenant_id == 'X'` en el código.** Las reglas variables van en
   `tenants.settings` (JSONB) o en tablas de configuración con `tenant_id`. Si
   un restaurante nuevo exige tocar código para operar, el diseño falló.
5. **Los totales se calculan solo en `app/domain/order_totals.py`.** No
   duplicar esa aritmética en controladores, servicios ni frontend.
6. **Los estados de pedido son configurables por tenant.** Nunca comparar
   estados por `code` en lógica de negocio: usar `order_statuses.category`
   (`new`, `kitchen`, `ready`, `in_transit`, `completed`, `cancelled`). El KDS
   y los reportes se apoyan en la categoría, no en el nombre.
7. **Los roles son configurables, los permisos no.** El catálogo de permisos es
   fijo (`app/core/permissions.py`); los roles que los agrupan son por tenant.
   Autorizar con la dependencia `require('permiso')`.
8. **Los precios se congelan en el pedido.** `order_items` guarda `unit_price`,
   `tax_rate`, `tax_amount` y `name_snapshot` del momento de la venta. Los
   productos se archivan (`is_archived`), nunca se borran.
9. **Precio efectivo en cascada**: `branch_menu_overrides.price` si existe, si
   no `menu_items.base_price`. Resolver en un único lugar.

## Arquitectura por capas

```
app/domain/       lógica pura — sin BD, sin framework, testeable directo
app/services/     casos de uso — orquestan dominio + repositorios
app/repositories/ acceso a datos
app/api/          endpoints HTTP + dependencias de auth
app/models/       modelos SQLAlchemy
app/schemas/      Pydantic de entrada/salida
```

Regla: el dominio no importa nada de las capas superiores. Cuando llegue el
agente de WhatsApp, debe llamar a los mismos servicios que usa el panel web —
no tener lógica de negocio propia.

## Comandos

```bash
./scripts/setup.sh              # levanta Postgres, aplica esquema, siembra demo
uvicorn app.main:app --reload   # API en :8000, docs en /docs
pytest                          # tests de dominio
ruff check app tests            # lint
```

Usuario de demo: `admin@demo.local` / `admin123` (solo desarrollo).

## Estado actual y siguiente paso

Hecho: esquema v2, lógica de dominio (totales y máquina de estados) con tests,
dependencias de auth, seeds.

Carpetas `models/`, `schemas/`, `services/`, `repositories/` están vacías a
propósito: se llenan módulo por módulo.

**Siguiente**: módulo 1 (configuración y administración) — modelos SQLAlchemy de
`tenants`, `branches`, `roles`, `permissions`, `users`; provisioning de tenant
(sembrar estados/roles/impuestos por defecto según `business_type`); endpoint de
login que emita el JWT con tenant y permisos.

Después: módulo 2 (menú), módulo 3 (pedidos), módulos 4 y 5 (cocina y caja),
módulo 8 (reportes). Ver `docs/functional-scope.md`.

## Convenciones

- Migraciones SQL numeradas en `db/migrations/`, nunca editar una ya aplicada
- Nombres de tablas y columnas en inglés, en snake_case
- Comentarios y documentación en español
- `NUMERIC(10,2)` para dinero, jamás `FLOAT`
- Toda tabla nueva con datos de negocio necesita `tenant_id` (directo o vía
  `branch_id`) y su política RLS
