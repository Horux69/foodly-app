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
  `firebase/php-jwt` (v7), porque decodificar un JWT a mano es el tipo de cosa
  criptográfica que no vale la pena reinventar. Todo lo demás (router,
  validación de entrada, acceso a datos) es PHP nativo.

**`SECRET_KEY` necesita al menos 32 bytes.** HS256 firma con HMAC-SHA256 y una
clave más corta debilita la firma; php-jwt las rechaza desde la v7 y
`Core\Config` lo comprueba al arrancar para que el fallo salga como un error de
configuración y no como un `Provided key is too short` en mitad de un login.
Cada entorno genera la suya —`setup-php.sh` lo hace al crear el `.env`— con
`php -r "echo bin2hex(random_bytes(32));"`. Cambiarla invalida los tokens ya
emitidos: todo el mundo tiene que volver a entrar.
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
npm install && npm test         # pruebas del frontend (Vitest + jsdom)
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

Los nueve módulos del alcance funcional están construidos y migrados a PHP. La
API cubre configuración, administración (sucursales, mesas, impuestos, usuarios
y roles), menú, pedidos, cocina, caja, reportes, domicilios y clientes.

Un restaurante nuevo se da de alta con `php/bin/create_tenant.php` y se
configura entero desde la web, sin SQL ni código.

Domicilios y clientes cierran el MVP: zonas con tarifa y mínimo, asignación de
repartidor, sellado de la hora de salida y de entrega, y una base de clientes
que se llena sola con el teléfono de cada pedido (la llave que usará el agente
de WhatsApp). Tres detalles del módulo que conviene no deshacer:

- **La tarifa la pone la zona, no quien pide.** Si el pedido trae `zone_id`, el
  `delivery_fee` del cuerpo se ignora.
- **El mínimo se mide contra el subtotal**, nunca contra el total: contar el
  envío para alcanzarlo sería hacer trampa (`Domain\DeliveryRules`).
- **La hora de salida y la de entrega se sellan por categoría del estado**
  (`in_transit` y `completed`), no por su código, y solo si estaban vacías.

Sobre eso se construyeron dos fases de trabajo en la web:

- **Fase 0 (cimientos)**: selector de sucursal activa —el `branch_id` viaja
  como `?branch_id=` y lo resuelve solo `Deps::activeBranchId`—, llave de
  idempotencia al crear pedidos y cobros, lista de pedidos con filtros,
  búsqueda y paginación keyset, y el arnés de pruebas del frontend.
- **Fase 1 (sacar a la luz lo construido)**: pantalla de clientes, cliente
  conocido y domicilio real al tomar el pedido, tablero de domicilios, zonas
  de reparto en Administración, detalle de pedido con bitácora, precio por
  sucursal en el menú y edición de los permisos de un rol.

- **Fase 2 (cerrar el ciclo del dinero)**, en curso y partida en trozos.
  Hechos: cobro parcial y por varios métodos (F2.1), división de cuenta
  (F2.2), reembolsos (F2.3), cierre de turno con arqueo (F2.5) y los
  reportes de cierre (F2.6).

Sobre los reembolsos, tres detalles que conviene no deshacer:

- **Un reembolso no borra ni edita el cobro**: es una fila nueva de `payments`
  que apunta a él (`refund_of_payment_id`). La caja necesita saber qué entró y
  qué salió, y un `UPDATE` sobre el cobro borraría justo eso.
- **El cobro original cuenta aunque esté marcado `refunded`.** Ese estado es la
  etiqueta de "ya se revirtió"; quien lo revierte es su fila de reembolso.
  Descontarlo también dejaría el saldo al doble.
- **La cadena tiene un solo eslabón**: un reembolso no se reembolsa. Para
  deshacerlo se vuelve a cobrar.

Sobre el arqueo, cuatro decisiones que conviene no deshacer:

- **La diferencia se calcula, no se guarda.** Se guarda lo contado
  (`counted_cash`) y la base; el esperado y la diferencia salen de
  `Domain\CashSessionTotals` con los movimientos reales. Una diferencia
  almacenada al cerrar mentiría en cuanto se registre un reembolso de ese
  turno.
- **Solo el efectivo cuadra contra un conteo.** Lo cobrado con tarjeta o
  transferencia se reporta por método pero no pasa por el cajón. Cuál método
  es el físico se le pasa al dominio, no se adivina.
- **Una sola caja abierta por sucursal**, y lo impone un índice único parcial
  (`WHERE closed_at IS NULL`), no una comprobación en PHP: dos cajeros
  abriendo a la vez la pasarían los dos.
- **Abrir pide `payments.register`; ver el cuadre y cerrar piden
  `cash.close`.** Así, en un restaurante que separe los roles, quien cuenta el
  cajón no ve antes cuánto debería haber — que es el control que hace útil un
  arqueo. Cobrar no exige turno abierto: lo que se cobre sin turno queda con
  `cash_session_id` nulo y no entra en ningún arqueo.

Sobre la división de cuenta, dos cosas:

- **El reparto en partes iguales lo calcula `Domain\BillSplit`, no el
  navegador.** 65000 entre tres no da redondo, y dos divisiones con
  `toFixed(2)` dejarían un centavo pendiente para siempre: el pedido nunca
  saldaría y aparecería en el arqueo de todos los turnos siguientes. El resto
  se reparte de a un centavo entre las primeras partes.
- **Se cobra una parte a la vez y se vuelve a repartir el saldo que queda.**
  Así las cuentas cierran solas sin llevar registro de quién ya pagó.
  Dividir no cambia el pedido ni sus totales: cada parte es un pago parcial
  más contra el mismo saldo.

Sobre los reportes de cierre, dos cosas:

- **Siguen la plata, no el pedido.** Los ingresos por método se fechan por el
  cobro y cuentan también lo cobrado sobre pedidos aún abiertos; los ajustes,
  por cuándo se anuló, se devolvió o se aplicó el descuento. Por eso su total
  no coincide con el de venta, y la pantalla lo dice: si no, la diferencia
  parece un error de la aplicación.
- **Las listas de ajustes se recortan a 200 filas, pero sus totales no.** Se
  calculan con funciones de ventana, que corren antes del `LIMIT`. Un reporte
  que muestre 200 anulaciones y diga que suman solo esas 200 no sirve para
  cuadrar.

**Siguiente**: lo único que queda de la fase 2 es la cancelación con motivo
(F2.4). El endpoint de cambio de estado ya acepta `note` y la guarda en
`order_status_history`, y el reporte de anulaciones ya la muestra: falta el
campo en la pantalla y la regla de negocio.

Antes de F2.4 hay una decisión de negocio pendiente: **si un pedido con pagos
debe exigir reembolso antes de poder cancelarse.** Ata F2.3 con F2.4 y no la
resuelve el código.

Pendientes conocidos:

- No hay pruebas de integración en PHP. El dominio sí tiene suite (PHPUnit);
  el resto se validó a mano contra un Postgres real y manejando el frontend en
  un navegador. La suite `tests/` de Python cubre el backend retirado.
- **Un tenant no se puede borrar.** Varias claves foráneas apuntan a tablas que
  el borrado en cascada intenta vaciar primero (`menu_items.tax_rate_id`,
  `users.role_id`, `orders.status_id`, `order_items.menu_item_id`, entre otras),
  así que Postgres se traba. Dar de baja un restaurante hoy exige borrar a mano
  en orden. Se arregla con una migración que defina `ON DELETE` en esas claves.
- **El frontend tiene arnés de pruebas, pero cubre poco todavía.**
  `web/tests/` corre con Vitest sobre jsdom y monta la aplicación real —el
  esqueleto sale de `web/index.html`, no de una copia— sin introducir paso de
  compilación: `php/public/index.php` sigue sirviendo los mismos módulos ES.
  Cubre el arranque con y sin sesión, la pantalla de clientes, el tablero de
  domicilios, el cobro, el reembolso y la división de cuenta desde el detalle
  del pedido, la caja y los reportes de cierre.
  Faltan la toma de pedido con modificadores obligatorios y el avance de
  estado en cocina. Cada pantalla nueva debería llegar con la suya.

  Existe porque la pantalla de login estuvo rota desde `c697b9e` hasta
  `99e54ea` por un `[rail, topbar, barraInferior].forEach(render)` — `forEach`
  pasa `(elemento, indice, array)` y `render(el, ...children)` tomaba el resto
  como hijos, así que intentaba meter el rail dentro de sí mismo. Nadie lo vio
  porque esa rama solo corre con la sesión cerrada, y en desarrollo siempre
  había token en `localStorage`. La primera prueba del arnés es justo esa, y
  se comprobó que falla al reintroducir el `forEach`.
- El login resuelve la empresa a partir del email: si dos empresas registran el
  mismo correo, queda ambiguo (gana el primero). Se resolvería con subdominio o
  slug de empresa en la pantalla de login.
- Un combo (varios productos completos a precio de paquete) no se puede
  modelar: los modificadores suman o restan sobre una línea, no "son" otro
  producto con su propia receta.

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
