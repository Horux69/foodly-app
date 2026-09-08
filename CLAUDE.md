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
- Frontend: HTML, CSS/Tailwind, JavaScript vanilla en `web/`, servido por la
  misma app. Sin paso de build y sin framework: se edita y se recarga.

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
   La app se conecta con `APP_DATABASE_URL` (rol `resto_app`), que **no** es
   dueño de las tablas: si lo fuera, Postgres saltearía las políticas y no
   protegerían nada. `DATABASE_URL` es la conexión administrativa y se usa
   solo para migraciones y alta de empresas. La única consulta que cruza
   empresas es `auth_tenant_for_email` (login), acotada a devolver un
   `tenant_id`.
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
uvicorn app.main:app --reload   # API en :8000, docs en /docs, web en /
pytest                          # dominio + integracion
pytest tests/integration        # solo integracion (requiere Postgres arriba)
ruff check app tests scripts    # lint

python scripts/create_tenant.py "Nombre" --branch "Sede" --branch-code SED \
    --admin-email dueno@x.com --admin-password "clave-larga"
```

Los tests de integracion corren contra una base aparte (`<db>_test`) que se
recrea en cada sesion con el esquema y las semillas reales. Si Postgres no
esta arriba se saltan, y los de dominio siguen corriendo.

Usuario de demo: `admin@demo.local` / `admin123` (solo desarrollo).

## Estado actual y siguiente paso

Hecho: módulos 1, 2, 3, 4, 5, 8 y 9. La API cubre configuración, administración
(sucursales, mesas, impuestos, usuarios y roles), menú, pedidos, cocina, caja y
reportes. Hay interfaz web en `web/` servida por la misma app: login, toma de
pedidos, KDS, menú, administración y reportes.

Un restaurante nuevo se da de alta con `scripts/create_tenant.py` y se configura
entero desde la web, sin SQL ni código.

**Siguiente**: módulos 6 y 7 (domicilios y clientes) — `delivery_zones` con
tarifa y mínimo, asignación de repartidor, gestión de clientes. Sus tablas y
modelos ya existen; faltan servicios y endpoints. Ver `docs/functional-scope.md`.

Pendientes conocidos:

- El frontend no tiene pruebas automatizadas.
- El login resuelve la empresa a partir del email: si dos empresas registran el
  mismo correo, queda ambiguo (gana el primero). Se resolvería con subdominio o
  slug de empresa en la pantalla de login.
- No hay control de concurrencia sobre un mismo pedido: dos cajeros que avancen
  el estado a la vez podrían pisarse.

## Convenciones

- Migraciones SQL numeradas en `db/migrations/`, nunca editar una ya aplicada
- Nombres de tablas y columnas en inglés, en snake_case
- Comentarios y documentación en español
- `NUMERIC(10,2)` para dinero, jamás `FLOAT`
- Toda tabla nueva con datos de negocio necesita `tenant_id` (directo o vía
  `branch_id`) y su política RLS
