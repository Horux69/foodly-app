# Contexto del proyecto

Plataforma multi-tenant para restaurantes. Un mismo sistema debe adaptarse a
distintos modelos de negocio (comida rápida, servicio en mesa, domicilios)
**mediante configuración, no mediante código**.

Fase futura ya prevista en el modelo: agente conversacional que toma pedidos por
WhatsApp y cobra dentro de la conversación. No implementar aún, pero no romper
el camino: el canal `whatsapp` y `customers.phone` existen para eso.

## Stack

- PostgreSQL 16 con Row Level Security por tenant
- PHP 8.1+ con PDO, **sin framework**. La única dependencia de Composer es
  `firebase/php-jwt`, porque decodificar un JWT a mano es el tipo de cosa
  criptográfica que no vale la pena reinventar. Todo lo demás (router,
  validación de entrada, acceso a datos) es PHP nativo.
- Frontend: SPA de módulos ES en `web/`, con enrutado por hash y sin paso de
  build ni framework — se edita y se recarga. Tailwind por CDN y `web/app.css`
  para lo propio. `web/js/views/` tiene una vista por pantalla; `router.js`,
  `api.js`, `session.js` y `ui.js` son la base compartida.

**El DOM se construye con el helper `h()` de `web/js/ui.js`, nunca con
`innerHTML`.** `h()` escribe el texto con `textContent`, y por eso un producto
llamado `<img src=x onerror=...>` se ve como texto y no se ejecuta. La versión
multipágina anterior interpolaba nombres de la base dentro de `innerHTML` y
tenía ahí un XSS almacenado; no reintroducirlo.

El backend vive en `php/`. La carpeta `app/` es la implementación anterior en
Python/FastAPI, que se conserva solo como referencia mientras se valida la
migración; no se ejecuta y no debe recibir cambios nuevos.

## Lee esto antes de escribir código

- `docs/database-design.md` — modelo de datos y por qué está así
- `docs/functional-scope.md` — los 9 módulos y el orden de construcción
- `docs/multi-tenancy.md` — dónde se resuelve el tenant en cada capa
- `db/migrations/001_initial_schema.sql` — el esquema real, fuente de verdad
- `db/migrations/002_rls_hardening.sql` — por qué el rol de la app no es dueño
  de las tablas

## Principios no negociables

1. **El `tenant_id` viaja en el token JWT.** Nunca se acepta desde el body,
   query params ni headers del cliente. Es el principal vector de fuga de datos
   entre empresas.
2. **Un solo punto resuelve el tenant**: `php/src/Api/Deps.php:getContext`. No
   replicar esa lógica en controladores.
3. **RLS de Postgres como red de seguridad.** Cada request fija
   `app.current_tenant` vía `php/src/Core/Database.php:setTenantContext`.
   La app se conecta con `APP_DATABASE_URL` (rol `resto_app`), que **no** es
   dueño de las tablas: si lo fuera, Postgres saltearía las políticas y no
   protegerían nada. `DATABASE_URL` es la conexión administrativa y se usa
   solo para migraciones y alta de empresas. La única consulta que cruza
   empresas es `auth_tenant_for_email` (login), acotada a devolver un
   `tenant_id`.

   Ese ajuste es local a la transacción (como `SET LOCAL`), así que
   `setTenantContext` abre una si no hay ninguna y `public/index.php` la
   cierra al final de cada request: una transacción por petición, todo o
   nada. Sin esa transacción abierta, PDO en autocommit pierde el tenant
   entre una consulta y la siguiente.
4. **Nunca `if tenant_id == 'X'` en el código.** Las reglas variables van en
   `tenants.settings` (JSONB) o en tablas de configuración con `tenant_id`. Si
   un restaurante nuevo exige tocar código para operar, el diseño falló.
5. **Los totales se calculan solo en `php/src/Domain/OrderTotalsCalculator.php`.**
   No duplicar esa aritmética en controladores, servicios ni frontend. El
   dinero se maneja en **centavos enteros**, nunca en `float`: las columnas
   son `NUMERIC(10,2)` y `php/src/Core/Money.php` convierte en los bordes.
6. **Los estados de pedido son configurables por tenant.** Nunca comparar
   estados por `code` en lógica de negocio: usar `order_statuses.category`
   (`new`, `kitchen`, `ready`, `in_transit`, `completed`, `cancelled`). El KDS
   y los reportes se apoyan en la categoría, no en el nombre.
7. **Los roles son configurables, los permisos no.** El catálogo de permisos es
   fijo (`php/src/Core/Permissions.php`); los roles que los agrupan son por
   tenant. Autorizar con `Deps::require($ctx, 'permiso')`.
8. **Los precios se congelan en el pedido.** `order_items` guarda `unit_price`,
   `tax_rate`, `tax_amount` y `name_snapshot` del momento de la venta. Los
   productos se archivan (`is_archived`), nunca se borran.
9. **Precio efectivo en cascada**: `branch_menu_overrides.price` si existe, si
   no `menu_items.base_price`. Resolver en un único lugar.

## Arquitectura por capas

```
php/src/Domain/       lógica pura — sin BD, sin framework, testeable directo
php/src/Services/     casos de uso — orquestan dominio + repositorios
php/src/Repositories/ acceso a datos con PDO
php/src/Api/          router, controladores y resolución de auth
php/src/Models/       objetos de fila (readonly), hidratados desde PDO
php/src/Core/         config, conexiones, dinero, seguridad, permisos
php/public/index.php  front controller: sirve la API y el frontend de web/
```

Un archivo por clase, con el nombre de la clase: el autoload PSR-4 de Composer
resuelve por convención y varias clases en un mismo archivo lo rompen.

Regla: el dominio no importa nada de las capas superiores. Cuando llegue el
agente de WhatsApp, debe llamar a los mismos servicios que usa el panel web —
no tener lógica de negocio propia.

## Comandos

```bash
./scripts/setup-php.sh          # Postgres + esquema + semillas + composer install
cd php && php -S localhost:8000 -t public public/index.php   # app en :8000

cd php && vendor/bin/phpunit    # pruebas del dominio
cd php && php bin/create_tenant.php "Nombre" --branch="Sede" --branch-code=SED \
    --admin-email=dueno@x.com --admin-password="clave-larga"
```

`setup-php.sh` es para una base vacía: reaplica `001_initial_schema.sql`, que
no tiene `IF NOT EXISTS`, así que falla si el esquema ya está. Con la base ya
creada alcanza con copiar `php/.env.example` a `php/.env` y correr
`composer install`.

En Windows, ese `composer install` hay que lanzarlo desde Git Bash y no desde
PowerShell: el PHP de Laragon no trae `ext-zip`, y Composer solo evita clonar
cada paquete por git (lentísimo) si encuentra el binario `unzip` en el PATH,
que está en Git Bash. Y no borrar `php/composer.lock`: sin lock Composer hace
`update` y el aviso de seguridad de `firebase/php-jwt` lo aborta.

La aplicación queda en `http://localhost:8000/` (frontend de `web/`) y la API
bajo `/api/v1`, en el mismo origen: sin CORS de por medio.

Usuario de demo: `admin@demo.local` / `admin123` (solo desarrollo).

## Estado actual y siguiente paso

Hecho: módulos 1, 2, 3, 4, 5, 8 y 9, ya migrados a PHP. La API cubre
configuración, administración (sucursales, mesas, impuestos, usuarios y roles),
menú, pedidos, cocina, caja y reportes. Hay interfaz web en `web/` servida por
la misma app: login, toma de pedidos, KDS, menú, administración y reportes.

Un restaurante nuevo se da de alta con `php/bin/create_tenant.php` y se
configura entero desde la web, sin SQL ni código.

**Siguiente**: portar a PHP los módulos 6 y 7 (domicilios y clientes) —
`delivery_zones` con tarifa y mínimo, asignación de repartidor, gestión de
clientes. Sus tablas existen y sus permisos también
(`db/migrations/003_customer_permissions.sql`); faltan repositorios, servicios
y endpoints en `php/`. **La implementación en Python de `app/` sirve de
especificación**: `app/services/delivery_service.py`,
`app/services/customer_service.py` y sus tests en `tests/integration/` dicen
exactamente qué reglas hay que reproducir.

Pendientes conocidos:

- No hay pruebas de integración en PHP. El dominio sí tiene suite (PHPUnit);
  el resto se validó a mano contra un Postgres real y manejando el frontend en
  un navegador. La suite `tests/` de Python cubre el backend retirado.
- **Un tenant no se puede borrar.** Varias claves foráneas apuntan a tablas que
  el borrado en cascada intenta vaciar primero (`menu_items.tax_rate_id`,
  `users.role_id`, `orders.status_id`, `order_items.menu_item_id`, entre otras),
  así que Postgres se traba. Dar de baja un restaurante hoy exige borrar a mano
  en orden. Se arregla con una migración que defina `ON DELETE` en esas claves.
- `firebase/php-jwt` está fijado en v6.11.1, alcanzada por
  GHSA-2x45-7fc3-mxwq (severidad baja). El arreglo está en 7.0.0, que es un
  major: hay que subir la restricción de `composer.json` y revisar la API de
  `JWT::decode`. Mientras tanto `composer update` queda bloqueado por el aviso;
  `composer install` desde el lock sí funciona.
- El frontend no tiene pruebas automatizadas.
- El login resuelve la empresa a partir del email: si dos empresas registran el
  mismo correo, queda ambiguo (gana el primero). Se resolvería con subdominio o
  slug de empresa en la pantalla de login.
- Falta reembolsos: el permiso `payments.refund` existe pero ningún endpoint lo
  usa, así que caja no puede revertir un cobro.
- Un combo (varios productos completos a precio de paquete) no se puede
  modelar: los modificadores suman o restan sobre una línea, no "son" otro
  producto con su propia receta.
- Domicilios y clientes no tienen pantallas propias: cuando existan en PHP se
  manejarán por API hasta que se les haga vista.

Ya resuelto en la migración: el avance de estado usa `SELECT ... FOR UPDATE`,
así que dos cajeros que avancen el mismo pedido a la vez ya no se pisan.

## Convenciones

- Migraciones SQL numeradas en `db/migrations/`, nunca editar una ya aplicada
- Nombres de tablas y columnas en inglés, en snake_case
- Comentarios y documentación en español
- `NUMERIC(10,2)` para dinero en la base; centavos enteros en PHP, jamás `float`
- La API responde en snake_case (`is_active`, `base_price`): el frontend de
  `web/` ya consume esas claves y no debe tener que cambiar
- Bindear booleanos a Postgres con `Row::pgBool()`: PDO manda un `false` de PHP
  como cadena vacía y la columna `BOOLEAN` lo rechaza
- Toda tabla nueva con datos de negocio necesita `tenant_id` (directo o vía
  `branch_id`) y su política RLS
