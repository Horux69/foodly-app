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
   fijo y vive en `php/src/Core/Permissions.php`: es lo que valida un rol, lo
   que responde `GET /permissions` y lo que comprueba `Deps::require`. La
   tabla `permissions` de la base sigue existiendo porque `role_permissions`
   apunta a ella, pero es el destino del join, no el catálogo; las dos se
   mantienen iguales y `php/tests/Core/PermissionsTest.php` compara el arreglo
   contra los `INSERT` de `db/`. Los roles que los agrupan son por tenant.
   Autorizar con `Deps::require($ctx, 'permiso')`.
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

- **Fase 2 (cerrar el ciclo del dinero)**, completa: cobro parcial y por
  varios métodos (F2.1), división de cuenta (F2.2), reembolsos (F2.3),
  cancelación con motivo (F2.4), cierre de turno con arqueo (F2.5) y los
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

Sobre la anulación, la regla que se decidió y sus dos consecuencias:

- **Un pedido con plata encima no se anula: primero se reembolsa.** Cancelar
  dejaría un cobro sin venta que lo respalde —la caja cuadraría de más y el
  cliente se quedaría sin su plata y sin su pedido— y el sistema no podría
  distinguir eso de un descuadre. Se mira el **neto**, así que un pedido
  cobrado y reembolsado entero sí se anula: ese es el camino que la regla
  obliga a recorrer.
- **Anular exige decir por qué.** El motivo va a `order_status_history.note` y
  de ahí al reporte de anulaciones. Sin él, esa lista no responde la pregunta
  que se le hace.

Las dos viven en `Domain\StatusChangeRules`, junto con la que ya existía —no
se completa un pedido sin saldar—, porque las tres responden lo mismo: qué
exige el dinero del pedido para entrar a un estado de cierta categoría. La
máquina de estados sigue respondiendo la otra pregunta, la de qué transiciones
configuró el tenant y con qué permiso.

- **Fase 3 (impresión y piso de venta)**, completa: comanda de cocina y
  ticket de cliente (F3.1), reimpresión (F3.2), aviso de pedido nuevo en
  cocina (F3.3), KDS completo (F3.4) e instalable con tolerancia a cortes de
  red (F3.5).

Sobre la impresión, tres cosas:

- **Se imprime con el navegador, no por ESC/POS**, a propósito: funciona con
  cualquier impresora que tenga driver, no ata el producto a un modelo y no
  exige un servicio local corriendo en el restaurante. ESC/POS queda para
  cuando un cliente lo pida.
- **El documento se arma en `#impresion`**, hijo directo de `<body>`, y
  `@media print` esconde a sus hermanos. No se abre otra ventana: habría que
  recargar la aplicación entera y suele chocar con el bloqueador de
  emergentes justo cuando hay cola.
- **La limpieza va por `afterprint` y por el foco de la ventana, nunca por
  temporizador.** `print()` no bloquea en todos los navegadores, y un plazo
  ciego puede borrar el documento con el diálogo abierto — que es imprimir
  una hoja en blanco. Si aun así no se limpiara, la aplicación no se ve
  afectada: `#impresion` está oculto fuera de `@media print`.

La comanda no lleva precios (a la cocina el dinero no le sirve) y el ticket no
lleva las notas de preparación. La reimpresión sale marcada en grande: una
comanda repetida sin avisar es un plato preparado dos veces.

Sobre el tablero de cocina, tres cosas:

- **Las columnas las decide la configuración del restaurante**, no la
  pantalla: `GET /kitchen/orders` devuelve `columns` con las categorías para
  las que el tenant tiene estados. Uno de comida rápida no ve `in_transit`, y
  esa columna siempre vacía sería ruido en la pantalla que más se mira de
  lejos.
- **Lo despachado hace poco (30 min) sigue en el tablero**, para recuperar el
  pedido que se marcó listo por error. Los botones son los que declara la
  máquina de estados: si el restaurante no configuró camino de vuelta no
  habrá ninguno, y eso es correcto — el tablero no inventa atajos.
- **Las marcas de "línea ya preparada" viven en el navegador**, no en la
  base: son una ayuda para quien está cocinando ahora, no un dato del
  pedido. La consecuencia es que dos pantallas de cocina no las comparten. Si
  hiciera falta que sí, es una columna en `order_items` y un endpoint, no un
  parche sobre esto. Se podan cuando el pedido deja el tablero.

El aviso de pedido nuevo compara los ids entre refrescos, suena con dos
pitidos sintetizados (sin archivo: no hay paso de compilación donde meter un
binario, y así también suena sin red) y cuenta en el título de la
pestaña. El interruptor se recuerda **por dispositivo**: que la cocina
abierta al comedor quiera silencio no dice nada de lo que quiera la tableta
del mostrador.

Sobre la aplicación instalable y la cola sin red, cuatro decisiones que
conviene no deshacer:

- **El service worker va a la red primero y a la caché solo como respaldo**,
  al revés de la receta habitual de PWA. Sin paso de compilación,
  `web/js/app.js` se llama igual antes y después de cada cambio: una caché
  que ganara serviría código viejo hasta que alguien acertara a invalidarla.
  Con la red primero, quien tiene conexión ve siempre lo último —el servidor
  ya manda `Cache-Control: no-cache`, así que lo que no cambió se resuelve
  con un 304— y por eso `VERSION` en `web/sw.js` **no** hay que subirla en
  cada despliegue. Sí hay tope de espera (3,5 s): la red intermitente no
  siempre falla, a veces se cuelga, que frente a la pantalla es peor.
- **De la API solo se guardan `/auth/me` y `/menu`.** La sesión, para poder
  arrancar sin red —sin ella la aplicación se cree sin sesión y manda a una
  pantalla de ingreso que sin red no puede ingresar— y la carta, para poder
  seguir vendiendo. Un tablero de cocina o un arqueo servidos de hace horas
  serían peores que un error honesto. Al salir se tira esa caché
  (`session.forget` avisa al worker): en una tableta compartida, el
  `/auth/me` del turno anterior le daría al siguiente sus permisos y su
  sucursal.
- **La cola reenvía con la misma llave de idempotencia con que se tomó el
  pedido, y renueva la del formulario al encolar.** Lo primero es lo que
  hace seguro reenviar: el caso común no es que la petición no llegue, sino
  que se pierda la respuesta, y sin la llave cada reintento sería una venta
  duplicada. Lo segundo es su espejo: si el pedido siguiente reusara la
  llave del que quedó en cola, el backend lo tomaría por un reintento y
  devolvería aquel — una venta perdida sin ningún error a la vista.
- **Lo que el servidor rechaza (4xx) sale de la cola anotado; lo que falla
  por red o por un 5xx se conserva.** Un pedido con un producto archivado o
  una sucursal cerrada no va a entrar nunca y al frente de la cola taparía a
  los que sí pueden; un 500 puede ser de un minuto. Lo descartado no
  desaparece en silencio: queda en un aviso rojo hasta que alguien lo da por
  visto, porque hay que volver a tomar ese pedido.

El precache de `web/sw.js` está escrito a mano —no hay empaquetador que lo
derive— y `web/tests/pwa.test.js` lo compara contra los archivos en disco:
una vista nueva que no se agregue ahí desaparecería justo al cortarse el
internet. Los iconos se generan con `php scripts/generar-iconos.php` desde
la misma figura que `web/iconos/app.svg`, para no dejar binarios sin origen.
Tailwind llega por CDN y es lo único que no se puede precargar: se guarda
sobre la marcha la primera vez que responde, porque un `addAll` que dependa
de un tercero dejaría al worker sin instalar y a la tableta sin nada.

- **Fase 5 (configuración sin código)**, en curso y partida en trozos.
  Hechos: catálogo único de permisos (F5.5), datos del restaurante
  editables (F5.4) y modificadores desde la web (F5.1).

Sobre esos dos, tres cosas:

- **El catálogo de permisos es uno y está en el código.** Antes había dos y
  no coincidían: `Core\Permissions::CATALOG` no se usaba en ninguna parte y
  le faltaban los dos permisos de clientes que la migración 003 sí había
  insertado. Ahora el arreglo de PHP es la fuente —valida los roles, responde
  `GET /permissions` y lo comprueba `Deps::require`— y la tabla es solo el
  destino del join. Una prueba compara los dos contra los `INSERT` de `db/`,
  así que volver a separarlos pone la suite en rojo.
- **Un permiso mal escrito se rompe ruidoso.** `Deps::require('orders.cancell')`
  no lo tiene nadie: la pantalla queda cerrada para todo el mundo y el 403
  repite el código equivocado como si fuera cierto. Se comprueba contra el
  catálogo antes de negar, y una prueba recorre `php/src` buscando los
  literales de `Deps::require` para atraparlo antes de desplegar. Lo mismo
  vale para `role_permissions`: si un código del catálogo no tiene fila, el
  `INSERT ... SELECT` insertaría cero y el permiso quedaría concedido en la
  pantalla y ausente en la base, así que ahora falla.
- **Cambiar la moneda exige confirmarlo aparte.** Los pedidos ya emitidos
  guardan sus cifras sin moneda: cambiar el código no reconvierte nada, solo
  hace que lo histórico se lea con el símbolo equivocado. La confirmación la
  pide `Domain\TenantProfile` y no el navegador, porque una pantalla no es el
  único cliente de la API. Y **cambiar el modelo de negocio congela cómo
  opera hoy el restaurante**: el tipo solo fija los valores por defecto de
  las claves que el tenant nunca guardó, así que sin esto uno con `settings`
  vacío pasaría de mostrador a mesas —encendiendo el canal `table`— por
  elegir otra etiqueta en un desplegable.

Sobre los modificadores, cuatro decisiones que conviene no deshacer:

- **La forma de un grupo la valida `Domain\ModifierGroupRules`, no solo el
  `CHECK` de la base.** La base solo exige `max_select >= min_select`, que
  deja pasar `0` y `0`: un grupo obligatorio con máximo 0 no se puede
  satisfacer con ninguna cantidad, así que ningún pedido con un producto de
  ese grupo se podría crear — y el error saldría en el mostrador y no al
  configurarlo. Lo mismo con obligatorio y mínimo 0, que se contradice:
  `ModifierValidation` ya exige al menos una selección, así que el 0 guardado
  mentiría sobre lo que el grupo pide. La casilla de la pantalla sube el
  mínimo sola para no dejar que el rechazo sea la forma de enterarse.
- **Lo que ya se vendió no se borra.** Una opción referenciada por
  `order_item_modifiers` no se puede borrar —la clave foránea lo bloquearía
  de todos modos— y la salida es marcarla como no disponible: deja de
  ofrecerse y los pedidos viejos siguen entendiéndose. Un grupo asignado a
  productos tampoco se borra, y el mensaje dice a cuántos: borrarlo
  cambiaría la carta en silencio.
- **La frase de la regla la escribe el dominio** (`ModifierGroupRules::describe`)
  y viaja en el campo `rule`. Así "Elige 1" u "Opcional, hasta 3" se leen
  igual en la web y en cualquier otro cliente, incluido el agente de WhatsApp
  cuando llegue.
- **La pantalla avisa cuando un grupo obligatorio no tiene nada que
  ofrecer.** Es configuración válida y momentánea —se crea el grupo antes que
  sus opciones—, pero mientras dure vuelve impedibles todos los productos que
  lo tengan, y eso no se puede descubrir vendiendo.

`ModifierRepository` va aparte de `MenuRepository` porque es otro agregado con
su CRUD completo, y `/menu/catalog` trae `modifier_group_ids` por producto —
solo los ids, en orden, porque la pantalla ya tiene los grupos enteros de
`/menu/modifier-groups`. El orden es el que se guarda en `sort_order` y el que
se pregunta al tomar el pedido: primero el término de la carne, después las
adiciones. El router aprendió `DELETE`, y un 204 ya no lleva cuerpo.

**Siguiente**: lo que queda de la fase 5 —horarios de sucursal (F5.3) y
estados de pedido con sus transiciones (F5.2, la más riesgosa: un error ahí
congela la operación del restaurante). La fase 4, servicio en mesa, el plan
la deja condicionada a que haya clientes de ese modelo, y la fase 6
(confianza y pulido) corre en paralelo.

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
  del pedido, la caja, los reportes de cierre, la anulación con motivo, la
  impresión de comanda y ticket, el tablero de cocina, la cola de pedidos
  tomados sin red junto con el manifiesto y el precache del service worker,
  los datos del restaurante, los grupos de modificadores con su asignación a
  productos, y que un botón que falla vuelva a servir.
  Faltan los modificadores obligatorios al tomar el pedido y el avance de
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
