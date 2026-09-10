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

cd php && vendor/bin/phpunit    # dominio + integración (esta se salta sin base)
./scripts/setup-test-db.sh      # base de las pruebas de integración
npm install && npm test         # pruebas del frontend (Vitest + jsdom)
cd php && php bin/create_tenant.php "Nombre" --branch="Sede" --branch-code=SED \
    --admin-email=dueno@x.com --admin-password="clave-larga"
cd php && php bin/delete_tenant.php "Nombre" --confirm="Nombre"   # irreversible
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

- **Fase 5 (configuración sin código)**, completa: catálogo único de
  permisos (F5.5), datos del restaurante editables (F5.4), modificadores
  desde la web (F5.1), horarios de sucursal (F5.3) y estados de pedido con
  sus transiciones (F5.2).

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

Sobre los horarios, tres cosas:

- **Una franja que cierra antes de la hora a la que abre cruza la
  medianoche.** El viernes de 20:00 a 02:00 abre el viernes por la noche y
  cierra la madrugada del sábado. Antes esa configuración se podía guardar y
  no abría nunca —ninguna hora es a la vez posterior a las 20:00 y anterior a
  las 02:00—, así que un restaurante nocturno quedaba cerrado siempre sin
  ningún error a la vista. `Domain\BranchSchedule` lo resuelve mirando
  también la franja del día anterior; la pantalla lo rotula "del día
  siguiente" para que nadie lo "corrija".
- **Sin ninguna franja la sucursal atiende siempre**, pero en cuanto hay una,
  todo canal sin cobertura queda cerrado. Es fácil de provocar —se configuran
  las horas del mostrador y se olvida el domicilio— y no da ninguna señal
  hasta que alguien intenta vender, así que `ScheduleRules::channelsWithoutWindows`
  lo calcula y la pantalla lo avisa. Una franja sin canal vale para todos, y
  con una sola nadie queda fuera.
- **Ver los horarios pide `settings.view`; tocarlos, `branches.manage`**, el
  mismo permiso que crear una sede: son la misma decisión de quién opera
  dónde y cuándo.

`ScheduleRepository` va aparte del `getSchedules` que ya tenía
`BranchRepository`: aquel es la lectura de operación y filtra por `is_active`;
este es el CRUD de configuración y necesita ver también las apagadas, porque
apagar una franja sin borrarla es cómo se cierra por temporada.

Sobre el editor de estados, cinco decisiones que conviene no deshacer:

- **Se valida la configuración resultante, no la edición.** Lo que deja un
  estado sin salida casi nunca es tocarlo a él, sino borrar el único al que
  llevaba. Cada operación relee el flujo entero y compara; como corre dentro
  de la transacción de la petición, negarse deshace la edición.
- **Una edición no puede introducir un problema nuevo, pero sobre un flujo ya
  roto se sigue pudiendo editar.** Si se exigiera una configuración impecable,
  una rota no se podría arreglar desde la web: cada paso de la reparación
  chocaría con lo que el paso siguiente iba a resolver, y el restaurante
  quedaría atrapado. Por eso `StatusMachineRules::problems` devuelve la lista
  y `ensureNotWorse` compara el antes con el después.
- **Problemas y avisos son distintos y se ven distinto.** Los problemas
  impiden operar —sin estado inicial no se crea ningún pedido; un estado no
  final sin salida deja el pedido atascado— y salen en rojo. Los avisos no
  impiden nada pero casi seguro están mal —sin categoría "completado" no se
  cierra un pedido, sin "anulado" no se anula, salidas en un estado final que
  nunca se usan, un estado al que no se llega— y salen en ámbar, porque son
  estados intermedios legítimos mientras alguien arma su flujo.
- **Un estado con pedidos encima o en la bitácora no se borra.**
  `orders.status_id` y `order_status_history.status_id` son claves foráneas
  sin `ON DELETE`: Postgres lo bloquearía igual, pero así el mensaje dice
  cuántos pedidos hay que mover, o que la salida es dejar de usarlo.
- **Un solo estado inicial lo impone `idx_status_initial_per_tenant`**
  (índice único parcial de la migración 001), no una comprobación en PHP; por
  eso mover la marca apaga la anterior antes de encender la nueva. Y el
  `required_permission` de cada transición se valida contra el catálogo de
  `Core\Permissions`, que desde F5.5 es la fuente.

El código del estado se genera a partir del nombre y no se pide: identifica la
fila en la base pero no decide nada —para eso está la categoría—, así que es
una cosa menos que inventar. `Services\StatusConfigService` define el flujo;
`Services\OrderStatusService`, que ya existía, lo opera avanzando un pedido
concreto.

- **Fase 6 (confianza y pulido)**, completa: pruebas de las pantallas
  críticas (F6.1), teclado en el mostrador (F6.2), contraseña propia con
  renovación de token (F6.3), exportar reportes y comparar períodos (F6.4) y
  dar de baja un restaurante (F6.5).

Escribir esas pruebas destapó lo que iban a destapar: **el modal de
modificadores dejaba agregar un producto sin cumplir su grupo obligatorio, y
dejaba pasarse del máximo.** El pedido se rechazaba al confirmarlo, con el
carrito lleno y alguien esperando. Ahora el botón nace deshabilitado con un
"Falta elegir: …" y las casillas se cierran al llegar al tope. No es una
segunda fuente de verdad —quien decide sigue siendo `Domain\ModifierValidation`
al crear el pedido— sino la guía para no llegar al final con algo que se va a
rechazar. La frase de la regla la sigue escribiendo el dominio: `/menu`
devuelve `rule` igual que `/menu/modifier-groups`, así que la pantalla de
venta y la de configuración dicen lo mismo.

Sobre el teclado, dos cosas:

- **`montarDialogo` de `ui.js` es lo único que da comportamiento a un
  diálogo**: Escape cierra, Tab cicla dentro, el foco entra al abrir y vuelve
  a donde estaba al cerrar. Cada diálogo arma su propio marcado —son muy
  distintos entre sí— pero los siete pasan por ahí. Sin el ciclo de Tab,
  tabular desde el último botón se va a la aplicación de atrás: quien navega
  con teclado se queda sin diálogo sin haberlo cerrado. Y el oyente va en
  captura, así que con un diálogo abierto la tecla es del diálogo y no de los
  atajos de la pantalla de atrás.
- **`/` lleva al buscador y Enter crea el pedido**, pero solo cuando no se
  está escribiendo en un campo: si no, `/` no se podría teclear en la
  dirección de un cliente. Enter sí funciona desde el buscador, que es donde
  están las manos. Las líneas del carrito son enfocables y las flechas
  cambian su cantidad, con el nombre y el número en `aria-label` para que un
  lector de pantalla lea el cambio.

Sobre la sesión, tres cosas:

- **El token se renueva antes de vencer, no después de un 401.** Dura ocho
  horas y un turno puede ser más largo; con la renovación reactiva, el 401 ya
  perdió el pedido que se estaba mandando. `api.js` lee el `exp` del token
  —para saber cuándo pedir uno nuevo, no para confiar en él: quien lo valida
  es el servidor con la firma— y renueva con diez minutos de margen, una sola
  vez aunque salgan varias peticiones a la vez.
- **Renovar no alarga la sesión indefinidamente.** El token lleva `auth_time`
  —cuándo la persona escribió su contraseña— y se arrastra de un token al
  siguiente, así que `Domain\SessionRenewal` lo mide contra ese momento y no
  contra la última emisión: si se midiera contra la emisión, cada renovación
  correría el límite y no sería un límite. Son 24 horas, más que cualquier
  turno. También se releen el rol y los permisos, así que un cambio de rol
  entra en vigor sin volver a entrar.
- **Cambiar la contraseña exige la actual, aunque la sesión esté abierta.**
  Una tableta desatendida en el mostrador es el caso común, y sin esa
  comprobación cualquiera que pase deja al dueño fuera de su propio sistema.
  Los tokens ya emitidos siguen valiendo hasta que venzan: no hay versión de
  sesión en el modelo, así que para cortar todo de inmediato hay que rotar
  `SECRET_KEY` —y eso echa a todo el mundo—.

Sobre los reportes y la baja de un restaurante, tres cosas:

- **El período anterior tiene la misma cantidad de días y termina justo
  antes.** Comparar siete días contra treinta daría una caída del 76% que no
  significa nada. Y cuando antes no había nada, el cambio viaja en `null` y
  la pantalla no muestra porcentaje: "subió un infinito por ciento" no es una
  lectura. Lo decide `Domain\PeriodComparison`; la pantalla solo lo pinta.
- **El CSV lo arma el servidor, no el navegador.** Las listas de ajustes en
  pantalla están recortadas a 200 filas, así que un CSV hecho con lo que se
  ve exportaría eso y nadie lo notaría. Va con punto y coma y con BOM
  (`Domain\Csv`): es lo que Excel en español abre bien de doble clic, aunque
  `pandas.read_csv` necesite entonces `sep=';'`. Y **una celda que empiece
  por `=`, `+`, `-` o `@` sale desactivada con un apóstrofo**: la hoja de
  cálculo la evaluaría como fórmula al abrir el archivo, y el motivo de una
  anulación es texto libre que cualquiera con permiso para anular escribe.
  Los números no lo llevan, o un descuento de -1500 dejaría de sumarse.
- **Dar de baja un restaurante es un script, no una migración con
  `ON DELETE`.** Siete claves foráneas bloquean el borrado en cascada, y
  cinco de ellas son justo las protecciones sobre las que están construidas
  las fases 5 y 6: un estado con pedidos encima no se borra, una opción ya
  vendida tampoco, los productos se archivan. Con `CASCADE`, borrar un estado
  borraría los pedidos que están en él. Y hacerlas `DEFERRABLE` movería el
  error al commit, o sea después de que el controlador ya respondió. Así que
  el orden lo pone `php/bin/delete_tenant.php`, que borra en una transacción
  y exige repetir el nombre exacto.

`php/tests/Core/RutasTest.php` compara cada ruta con el controlador que la
atiende: lo que la ruta declara entre llaves es lo que el controlador lee de
`$params`, y al revés. Atrapa la forma en que se rompió el borrado de un
origen de venta —la ruta decía `{channel_id}` y el controlador leía
`source_id`—: no hay error de sintaxis ni de tipos, la petición llega, el
parámetro sale nulo y revienta con un 500 que lleva adentro el nombre de un
método de PHP.

`php/tests/Core/LlamadasTest.php` recorre `php/src` y comprueba por reflexión
que los métodos que el código llama existan. Atrapa la forma concreta en que
esta sesión rompió dos veces: `OrderStatusService::buildMachine()` dejó de
existir al pisar el archivo con una clase nueva del mismo nombre, y
`RoleRepository::listPermissions()` se borró por parecer código muerto
mientras `TenantProvisioning` lo seguía usando —el alta de restaurantes quedó
rota y la suite en verde—.

**Pruebas de integración contra un Postgres de verdad**, en
`php/tests/Integration/`. Se saltan enteras si no hay base configurada, así
que nadie necesita Postgres para correr las de dominio; con
`./scripts/setup-test-db.sh` y las dos variables `TEST_*` en `php/.env`,
corren con `vendor/bin/phpunit` como el resto. Cada prueba crea su propia
empresa por el mismo camino que `bin/create_tenant.php` —el que estuvo roto—
y de ahí en adelante habla por la conexión `app()`, bajo RLS.

Cubren el alta y la sesión, el ciclo del pedido (idempotencia, grupo
obligatorio, cobro por partes, reembolso, las tres reglas de
`StatusChangeRules` contra el saldo real), la edición de un pedido abierto,
los movimientos del cajón, los descuentos con su tope por rol, la propina, las estaciones, el documento
fiscal con su numeración, los combos —el precio del paquete,
la composición congelada y el bloqueo al archivar— y el aislamiento entre
empresas,
que es lo único que no se puede comprobar sin base: RLS vive en Postgres y lo
que la hace funcionar —que el rol de la aplicación no sea dueño de las
tablas— no se ve desde PHP. Hay una prueba que comprueba justo eso.

Escribirlas encontró un agujero real: **nada impedía cobrar más de lo que el
pedido debe.** Un 200000 donde iban 20000 dejaba el pedido "saldado" con el
saldo en negativo, la plata entraba al arqueo y el cajón cuadraba de más sin
que nada dijera por qué. `Domain\ChargeRules` es el espejo de
`RefundRules`: no se cobra más de lo que falta, ni sobre un pedido ya
saldado. Un vuelto no se registra como cobro, se entrega.

Sobre entrar cuando el correo se repite, dos cosas:

- **Cada empresa tiene un `slug`**, generado del nombre (`Domain\Slug`) y
  único. Es lo que se escribe al ingresar para decir en cuál entrar, y está
  pensado para que sobreviva a que lo dicten por teléfono: sin acentos, sin
  espacios, en minúsculas.
- **La empresa se decide después de comprobar la contraseña, no antes.**
  Bastaría con pedir el slug apenas hay más de una candidata, pero eso le
  contaría a cualquiera que escriba un correo en cuántas empresas existe. Así,
  dos personas distintas con el mismo correo en dos restaurantes entran cada
  una sin escribir nada más, y el slug solo se pide en el único caso de verdad
  ambiguo —mismo correo **y** misma contraseña—, que solo ve quien ya sabe
  entrar. Con la contraseña equivocada la respuesta es la de siempre. La
  pantalla revela el campo solo ante ese 409, y conserva la contraseña:
  volver a escribirla para responder "¿cuál?" sería absurdo.


Sobre los combos —varios productos completos a precio de paquete—, cuatro
decisiones que conviene no deshacer:

- **Un combo es un producto normal que declara qué lleva dentro.** No es un
  descuento repartido entre líneas ni un modificador gigante: tiene su propio
  `base_price`, su impuesto y su disponibilidad, y se vende como **una sola
  línea** a ese precio. Repartir el precio del paquete entre los componentes
  es de donde salen los centavos que no cuadran, y además dejaría el pedido
  con líneas cuyo precio no está en ninguna carta.
- **La composición se congela en el pedido**, igual que el nombre y el precio
  (principio 8). `order_item_components` guarda lo que llevaba al venderse, ya
  multiplicado por la cantidad pedida —dos combos son dos hamburguesas y
  cuatro gaseosas, y quien multiplica es `Domain\ComboRules::expand`—. Sin
  eso, la comanda de un pedido de ayer diría lo que el combo lleva hoy.
- **Archivar un producto que un combo lleva dentro está bloqueado.** El
  componente es `ON DELETE RESTRICT`, pero archivar no borra: vaciaría el
  combo en silencio, que se seguiría vendiendo al mismo precio con una cosa
  menos. El mensaje dice en qué combos está.
- **Un combo no se contiene a sí mismo, ni a través de otro.** El `CHECK` de
  la base atrapa el caso directo; el indirecto —A lleva B y B llevaría A— lo
  recorre `ComboRules` sobre la composición guardada. Un ciclo no se puede
  expandir para la comanda: la cocina necesita una lista de platos, no un
  bucle.

La cocina, la comanda impresa, el detalle del pedido y la pantalla de venta
muestran lo que lleva; el ticket también, porque el cliente compró un paquete
y tiene derecho a ver qué era. Con precio solo la línea del combo: los
componentes no llevan cifra propia porque no la tienen.

- **Fase 4 (servicio en mesa)**, en curso: modificar un pedido abierto
  (F4.0) —la pieza pesada que le faltaba al backend—, el estado de cada mesa
  (F4.1), el mapa del salón (F4.2), mover o unir cuentas (F4.3), el mesero
  a cargo (F4.4), los tiempos de la cuenta (F4.5) y la pre-cuenta (F4.6).

Sobre editar un pedido, cuatro decisiones que conviene no deshacer:

- **Se edita mientras el pedido siga en la casa**, y eso lo dice la categoría
  del estado (`new` y `kitchen`), nunca su código. Con el pedido listo, en
  camino, entregado o anulado ya no hay nada que corregir editando la venta.
  Con la cocina cocinando sí se puede, pero la pantalla avisa: hay que poder
  quitar el plato que el cliente canceló dos minutos después de pedirlo.
- **Lo que ya estaba conserva su precio; lo nuevo entra con el de hoy.** Es el
  principio 8 aplicado a la edición: si se recalculara todo, subir la carta
  reescribiría pedidos que el cliente ya vio. Por eso el total se rehace con
  `OrderTotalsCalculator::totalsFromLines`, que suma resultados de línea ya
  calculados en vez de reconstruir un `LineInput` desde una fila congelada
  —cuya tarifa pudo cambiar de porcentaje desde entonces—. La aritmética
  sigue viviendo en un solo lugar.
- **El pedido no puede quedar valiendo menos de lo ya cobrado**, y no puede
  quedarse sin líneas. Lo primero dejaría plata sin venta que la respalde
  —el mismo descuadre que impide anular un pedido cobrado— y la salida es
  reembolsar primero. Lo segundo sería anular por la puerta de atrás, sin
  motivo y sin quedar en el reporte. Las dos viven en `Domain\OrderEditRules`.
- **Cambiar la cantidad de un combo reescala lo que lleva.** Los componentes
  se congelan ya multiplicados, así que pasar de uno a tres tiene que volver a
  multiplicar; se hace sobre lo guardado y no releyendo el menú, porque la
  composición de la venta no cambia porque el combo haya cambiado hoy.

Cada cambio queda en la bitácora del pedido —la misma que ya lee el detalle—
con su autor: "Agrego 2x Gaseosa", "Quito 1x Papas", "Cambio Combo de 1 a 3".
El diálogo de modificadores se mudó a `web/js/views/modificadores-dialogo.js`
porque ahora lo abren dos pantallas; importarlo desde `pedidos.js` habría
creado un ciclo entre módulos.

Sobre el salón (F4.1 y F4.2), cuatro cosas:

- **Que una mesa esté ocupada no es una columna, es una consulta.** Sale de
  la categoría del estado de su pedido —nunca de su código— así que al
  cerrarse la cuenta la mesa se libera sola. Un `is_occupied` guardado se
  desincroniza en cuanto alguien cobra desde otra pantalla.
- **Dos cuentas en la misma mesa se cuentan y se dicen.** Se muestra la más
  antigua, que es la que lleva más rato ocupándola, y el número de pedido
  desempata cuando comparten instante: sin ese desempate el salón las
  alternaría entre refrescos, porque `now()` es el mismo en toda una
  transacción.
- **Las coordenadas no tienen unidad y la pantalla las interpreta.** Guardar
  píxeles haría que el mismo plano se viera movido en una tableta y en un
  portátil. Todas las mesas nacen en 0,0, así que `Domain\FloorPlan` reparte
  en rejilla las que nadie colocó —sin ponerlas encima de las que sí— o el
  salón de quien nunca abrió el modo de edición sería una pila de mesas en
  una esquina.
- **Se coloca una mesa a la vez.** Arrastrar una mesa es un cambio; mandar el
  plano entero pisaría lo que otra persona acabara de mover desde otra
  tableta.

Sobre mover y unir cuentas (F4.3), dos decisiones:

- **La que se une se anula, no se borra.** Un pedido sin líneas no puede
  quedar abierto, y borrarlo perdería su número y su bitácora. Se anula
  primero —mientras todavía tiene sus líneas— y después se mueven: así no
  existe ni un instante un pedido abierto y vacío. Las dos bitácoras lo
  cuentan: "Unida a X" y "Se le unió Y".
- **No se une una cuenta con plata encima.** Con un cobro por medio habría
  que decidir a qué venta pertenece, y esa decisión no la puede tomar el
  sistema: primero se reembolsa. Es la misma regla que impide anular un
  pedido cobrado.

Las dos cuentas se bloquean en orden de id, no cada una la suya: dos uniones
cruzadas a la vez se esperarían en círculo. Y la pantalla pide elegir **una
mesa**, no un número de pedido — quien atiende piensa en mesas.

Tocar una mesa libre abre la venta con la mesa ya escrita (viaja en el hash);
tocar una ocupada abre su cuenta. Los minutos los calcula el servidor: el
reloj de una tableta que nadie sincroniza puede ir muy lejos.

Sobre el mesero a cargo (F4.4), cuatro decisiones que conviene no deshacer:

- **`orders.server_id` no es `created_by`.** Uno dice quién atiende y el otro
  quién tecleó, y en un restaurante de mesa no son la misma persona: la
  tableta del pasillo la usan todos, y en muchos sitios el cajero digita lo
  que el mesero le dicta. Sin la diferencia no se puede repartir la propina
  ni decir cuánto vendió cada quien, que es para lo que se lleva la cuenta
  por mesero. Por defecto atiende quien tomó el pedido: es lo cierto casi
  siempre, y sin el defecto el reporte arrancaría con una montonera de
  ventas sin dueño.
- **La ventana para cambiarlo es más ancha que la de editar los productos, y
  se cierra al cerrar la cuenta.** Que un mesero tome la mesa de otro a mitad
  de servicio es normal —un cambio de turno, un descanso— y ahí el pedido
  puede estar ya listo o en camino. Entregado o anulado, no: la propina de
  ese turno ya se repartió con un nombre, y cambiarlo reescribiría un reporte
  que quizás ya se pagó. Lo decide `Domain\ServerAssignment` por categoría
  del estado, y el detalle lo lee de `is_server_assignable` en vez de
  deducirlo.
- **Quien puede ser mesero se pregunta por permiso, no por nombre de rol.**
  Son los usuarios activos cuyo rol tiene `orders.create`, igual que los
  repartidores son los que tienen `delivery.complete`: el catálogo de
  permisos es fijo y el nombre del rol lo pone cada restaurante, así que
  preguntar por "mesero" solo funcionaría en los que lo hayan llamado así.
  Y sirve de algo: sin la comprobación se le podría asignar una cuenta —y
  con ella su parte de la propina— al contador.
- **Ponerle otro nombre a una cuenta pide `orders.assign_server`.** Tomar el
  pedido es una cosa y decir de quién es la mesa es otra: donde la propina se
  reparte, eso es mover plata y hay restaurantes donde lo autoriza el
  supervisor. Sin el permiso el campo no aparece y la cuenta queda a nombre
  de quien la tomó.

Sobre los tiempos de la cuenta (F4.5), cuatro decisiones que conviene no
deshacer:

- **El tiempo es de la línea, no del pedido.** En una mesa se pide todo junto
  y no se cocina todo junto. Sin tiempos, en una mesa de ocho los postres
  salen con las entradas —o el mesero toma tres pedidos distintos para la
  misma mesa, que después hay que cobrar juntos, que es peor—. La cuenta
  sigue siendo una y se cobra una vez; lo que se reparte es la comanda.
- **El primero se marcha solo al tomar el pedido, y es el primero *con
  líneas*.** Si esperara a que alguien lo marchara, un mesero que no
  conociera la función dejaría la comida sin pedir y el error se
  descubriría cuando el cliente preguntara. Y si el pedido no lleva
  entradas, los fuertes tienen que salir ya.
- **Solo sale a la cocina lo marchado.** `Domain\KitchenTickets` filtra por
  `fired_at` y agrupa por tiempo y por estación —las dos dimensiones de la
  comanda— para la impresión y para el tablero a la vez: si cada uno
  agrupara por su lado, tarde o temprano dirían cosas distintas. El tablero
  además dice qué falta por marchar; sin eso, la cocina da por despachado un
  pedido al que le falta el postre.
- **Marchar no cambia el estado del pedido.** El estado es de la cuenta y el
  tiempo es de la comanda: devolver el pedido a "en preparación" al marchar
  exigiría un camino de vuelta que el restaurante quizá no configuró, y la
  máquina de estados es suya. La comanda del tiempo sale impresa al
  marcharlo —marchar sin que salga el papel dejaría el pedido "mandado" para
  el sistema y sin nada en la cocina—.

Los nombres de los tiempos son configuración (`tenants.settings.courses`,
"Entradas/Fuertes/Postres" por defecto en un restaurante de mesa) y se editan
en Administración; la línea guarda el **número**, así que renombrar un tiempo
no reescribe lo ya vendido. Lista vacía significa que el restaurante no los
usa: todo sale junto y ninguna pantalla los menciona. Lo que se le agrega
después a un tiempo ya marchado sale de una vez —si esperara, esperaría a
que alguien marche algo que ya se marchó, o sea para siempre— y volver a
marchar un tiempo se rechaza en vez de reescribir su hora, que es lo que la
cocina lee para saber qué es nuevo.

Sobre la pre-cuenta (F4.6), tres cosas:

- **Es el ticket, no otro documento.** El papel que se lleva a la mesa antes
  de cobrar sale de la misma función con una marca: dos documentos distintos
  para la misma cuenta terminan diciendo cifras distintas. Hasta ahora la
  única forma de mostrarle el total a la mesa era cobrar.
- **No escribe nada.** No cierra la cuenta, no cobra y no toca el pedido; no
  hay endpoint. Y va marcada en grande como que no es factura y sin el
  número autorizado —con él encima, el papel se lee como el comprobante que
  todavía no es—.
- **La propina se sugiere ahí, y se dice que es voluntaria.** Es donde el
  cliente la decide, antes de que el cajero pregunte. Sale del mismo
  porcentaje configurado que ofrece la caja (`propinaSugerida`, una sola
  función para las dos pantallas): dos cifras distintas por la misma venta
  son una discusión en la mesa.

El reporte por mesero (`GET /reports/sales-by-server`) va aparte del que ya
existía por usuario, y **separa la venta de la propina**: sumadas, quien
recibió más propina aparecería vendiendo más. Cuando nadie asignó mesero cae
a `created_by` —que es también el valor por defecto al crear—, así que las
ventas anteriores a la columna no se amontonan en un "sin mesero" que no
significa nada. En el salón el nombre se lee en la mesa y "Mis mesas" atenúa
las ajenas en vez de esconderlas: un plano con huecos deja de parecerse al
salón. Ese filtro no se recuerda entre visitas —a diferencia de la estación
del KDS, que es de la tableta—: encontrarse el salón filtrado por lo que
eligió el turno anterior es ver libres mesas que no lo están.

- **Fase 7 (la caja completa)**, en curso: entradas y salidas de efectivo
  (F7.1), descuentos con motivo, autor y tope (F7.2), la propina al cobrar
  (F7.3), el corte X con su consecutivo (F7.5) y el vuelto (F7.6).

Sobre los movimientos del cajón, tres cosas:

- **El arqueo solo conocía ventas.** Un cajón real recibe y entrega plata
  todo el día por fuera de ellas: la sangría al llegar al tope, el pago al
  domiciliario, la compra de emergencia. Sin registrarlas el arqueo declara
  un faltante que no lo es, y un control que "siempre da mal" deja de usarse.
  Entran a `Domain\CashSessionTotals` como un movimiento más, así que el
  esperado sigue siendo un cálculo y no un dato guardado.
- **Del cajón no sale más de lo que hay** (`Domain\DrawerRules`), y el motivo
  es obligatorio. Lo primero porque un cajón en negativo no existe; lo
  segundo porque la lista existe para responder, al cerrar, en qué se fue la
  plata. Un movimiento equivocado se corrige con el contrario, como un
  reembolso corrige un cobro: no se editan ni se borran.
- **Registrar un movimiento pide `cash.movements`, no `cash.close`.** Cobrar
  es recibir lo de una venta; sacar 200.000 para el gas es otra cosa y en
  muchos restaurantes la autoriza otra persona. Y quien los registra sigue
  sin ver el cuadre: eso es `cash.close`, para que contar el cajón a ciegas
  siga siendo posible.

De paso apareció un fallo de antes: **la pantalla de caja nunca mandaba
`branch_id`**. Decía "Turno de Sede Norte" en el encabezado y abría, cobraba
y cuadraba el turno de la sucursal del token. Con una sola sede no se nota;
con dos, el cajero de una cuadra el cajón de la otra.

Sobre los descuentos (F7.2), tres decisiones:

- **Un descuento necesita motivo, y el motivo sale de un catálogo por
  empresa** (`discount_reasons`, sembrado con cuatro al dar de alta). Era la
  única forma de que salga plata de una venta sin dejar cobro ni reembolso
  detrás, y el reporte de ajustes lo listaba sin poder decir por qué.
- **`orders.discount` por fin se comprueba**, tanto al crear el pedido como
  al aplicarlo sobre uno abierto, y el tope vive en el rol
  (`roles.max_discount_percent`, null = sin tope): un cajero resuelve un
  reclamo de 5.000 sin llamar a nadie y el 100% lo autoriza otra persona. El
  tope es un porcentaje del subtotal, porque lo que se perdona es una
  proporción de la venta.
- **Un motivo usado no se borra, se apaga.** Igual que una opción vendida o
  un estado con pedidos encima: borrarlo dejaría descuentos históricos sin
  explicación.

Y la prueba que faltaba: **`PermissionsTest` comprueba ahora que todo permiso
del catálogo lo exija alguien.** `orders.edit` y `orders.discount` estaban
ahí desde el principio, ofrecidos en el editor de roles, sin que ningún
código los comprobara — una promesa falsa se ve igual que una cumplida desde
fuera. Los tres que no pasan por `Deps` van en una lista explícita: los exige
la máquina de estados contra el `required_permission` de cada transición.

Sobre la propina (F7.3), dos cosas:

- **Se decide al cobrar, no al pedir.** Antes viajaba en el cuerpo de
  `POST /orders` y no se podía tocar después: había que adivinarla antes de
  que el cliente pagara. Ahora `PUT /orders/{id}/tip` la fija sobre una
  cuenta abierta, sube el total y con él el saldo, así que el cajero cobra
  una sola vez. El porcentaje sugerido es configuración del restaurante
  (`tenants.settings.tip_percent`, 10 por defecto) y no una constante.
- **Es voluntaria y quitarla es un toque.** `Domain\TipRules` acepta el cero
  sin justificación y frena lo que casi seguro es un cero de más —una
  propina mayor que la venta—, que es mejor descubrir en el mostrador que en
  el arqueo. Quitar una propina ya cobrada choca con la misma regla que
  cualquier edición: primero se reembolsa.

Con F4.4 ya se reparte por mesero: el reporte de ventas por mesero lleva su
propina en una columna aparte.

Sobre el corte X y el consecutivo (F7.5), tres cosas:

- **El corte X no necesitó backend.** Los totales ya se calculaban en vivo
  y `GET /cash/session` los devuelve a quien tiene `cash.close`; lo que
  faltaba era el papel. Sale del mismo documento que el cierre —quien
  archiva compara dos cortes del mismo formato— con la diferencia dicha en
  grande: un X que se confunda con un cierre hace contar el cajón dos
  veces.
- **El consecutivo se asigna al abrir, no al cerrar.** Un arqueo se archiva
  en papel y se busca por su número; si se asignara al cerrar, el corte X
  de un turno abierto no tendría con qué nombrarse. Lo da Postgres en la
  misma sentencia del `INSERT` (`next_cash_number`, como el número de
  pedido): dos cajeros abriendo a la vez no pueden sacar el mismo.
- **Al cerrar, la pantalla ya no se va sola.** Antes volvía a la apertura a
  los 2,5 segundos; ahora espera, porque la cifra del cierre es la que el
  cajero anota y hay un papel que imprimir.

Sobre el vuelto (F7.6), dos cosas:

- **El vuelto no es un cobro y no se guarda.** Es plata que sale del cajón
  por un cobro que ya se registró entero: como movimiento negativo se
  contaría dos veces en el arqueo, y como cobro es justo lo que
  `Domain\ChargeRules` impide. Se calcula, se muestra en grande y ahí
  termina; el cobro manda lo que se cobra y nada más. Una prueba comprueba
  que no viaje en la petición.
- **Su aritmética vive en el navegador, a diferencia de la del pedido.** Se
  recalcula con cada tecla y no se persiste en ninguna parte: un viaje al
  servidor por pulsación, con cola en la caja, sería peor que la resta. Es
  el mismo criterio de la propina sugerida. Los billetes de atajo
  (`billetesUtiles`) son los que alcanzan a cubrir el cobro más el importe
  exacto: ofrecer 2.000 para una cuenta de 80.000 es ruido.

- **Fase 8 (impresión y estaciones)**, en curso: estaciones de preparación
  (F8.1) y perfiles de impresión (F8.2).

Sobre las estaciones, tres decisiones:

- **La estación es del tenant, el ruteo va por categoría.** La cocina de una
  marca se organiza igual en todas sus sedes; lo que cambia por sede es a qué
  impresora va cada una, y eso es F8.2. Y "las bebidas a la barra" es como se
  piensa una carta: una excepción por producto se resuelve moviéndolo de
  categoría.
- **Lo que no tiene estación no se pierde: sale en la comanda general.** Una
  categoría recién creada, o el postre que nadie clasificó, se imprimen igual.
  La alternativa sería que un plato no se preparara porque nadie configuró
  algo, y eso se descubre vendiendo. Sin ninguna estación —el caso de casi
  todos— sale una sola comanda, como antes.
- **Quien reparte es `Domain\StationRouting`, no la pantalla.** Llega hecho
  en `kitchen_tickets`, tanto en el detalle del pedido como en el tablero: si
  la comanda impresa y el KDS agruparan cada uno por su lado, tarde o
  temprano dirían cosas distintas. La línea del pedido lleva ahora la
  `category_id` de su producto —la de hoy, no la congelada: rutear es una
  decisión de operación, no parte del precio.

Sobre los perfiles de impresión (F8.2), dos cosas:

- **El ancho y las copias son configuración de la sucursal**, no constantes
  del CSS. Una térmica de 58 mm cortaba los nombres largos, y en muchos
  restaurantes el ticket se archiva por duplicado. Un rollo de 58 imprime 48
  mm útiles y uno de 80 imprime 72: son los anchos de las térmicas, no una
  proporción. Sin configurar nada se imprime como siempre.
- **Imprimir no depende de que la configuración esté disponible.** Los
  perfiles se leen una vez por sucursal y se guardan en memoria; si la
  petición falla —o es la primera impresión de la sesión— sale con los
  valores por defecto en vez de esperar a la red. Con el cliente enfrente, un
  ticket con el ancho equivocado es mejor que ninguno.

El tablero filtra por estación y **la elegida se recuerda por dispositivo**,
igual que el interruptor del aviso: la tableta de la barra es siempre la
barra. Si esa estación se borra, el filtro se suelta solo en vez de dejar el
tablero vacío para siempre. Una estación con categorías encima no se borra,
y el mensaje dice cuántas hay que mover.

- **Fase 9 (domicilios que compiten)**, en curso: el cuadre del repartidor
  (F9.1), el seguimiento para el cliente (F9.2), la promesa de entrega
  (F9.5) y los orígenes de venta con comisión (F9.4).

Sobre el cuadre del repartidor, cuatro decisiones:

- **Es el arqueo aplicado a otra caja: la del repartidor.** Sale con pedidos
  que se cobran contra entrega y vuelve con efectivo que nadie cuadraba
  contra nada; la diferencia aparecía —si aparecía— en el arqueo del cajón,
  donde ya no se puede decir de qué pedido salió. Por eso pide `cash.close`
  y no `delivery.assign`: es el mismo control, y ver cuánto debería haber
  antes de contarlo es justo lo que ese permiso separa.
- **Lo esperado se calcula, se guarda lo entregado.** Igual que el arqueo, y
  por lo mismo: un reembolso registrado mañana le baja solo lo que debe. Un
  esperado almacenado mentiría desde ese momento. Solo cuenta el efectivo:
  lo que el cliente pagó con tarjeta no pasa por sus manos.
- **La ventana la marca el cuadre anterior.** `courier_settlements` guarda
  el intervalo, y el siguiente arranca donde terminó el último. Es lo que
  impide exigir dos veces el mismo cobro sin tener que marcar pago por pago
  —y por eso no se cuadra a quien no debe nada: cerraría la ventana sobre lo
  que entre un minuto después—.
- **No mueve el cajón.** El efectivo de un domicilio ya entró a la caja como
  el cobro del pedido; registrarlo además como entrada de cajón lo contaría
  dos veces en el arqueo. El cuadre dice quién trajo qué, no vuelve a
  contar la plata.

Sobre el seguimiento del cliente (F9.2), cuatro decisiones:

- **Es el único camino sin sesión aparte del login, y por eso devuelve
  poco.** No se responde lo que se tiene a mano sino lo que el cliente ya
  sabe de su propio pedido: número, estado, qué pidió, cuánto, y a dónde va.
  Ni su teléfono, ni su nombre, ni ids internos, ni el motivo de una
  anulación, ni con qué método se cobró. Una prueba lo comprueba buscando
  esos datos en la respuesta.
- **El tenant sale del token, y de ahí en adelante manda RLS.** La misma
  salida del login: una función `SECURITY DEFINER` deliberadamente miserable
  (`tracking_tenant_for_token`) que solo devuelve a qué empresa pertenece.
  Con eso se fija el contexto y la lectura va filtrada como cualquier otra,
  así que un token no puede alcanzar el pedido de otra empresa ni por un
  error de programación.
- **El token nace con el pedido y son 16 bytes al azar.** Generarlo al
  pedirlo dejaría que dos pantallas crearan dos; y un enlace que viaja por
  WhatsApp tiene que ser inadivinable —el id del pedido no sirve: es lo que
  el panel enseña en sus URL—. La forma se comprueba antes de consultar, así
  que quien prueba tokens no pone a la base a trabajar.
- **El paso lo decide la categoría del estado; el nombre lo pone el
  restaurante.** `Domain\OrderTracking` dibuja la línea de progreso por
  categoría —igual en cualquier flujo— y al lado se lee el nombre que el
  restaurante le puso, que es el que usa cuando el cliente llama. Un pedido
  que se recoge no muestra "En camino": un paso que nunca se va a encender
  parece un pedido atascado. Y anulado no es el último paso, es la
  interrupción del camino.

`web/seguimiento.html` va aparte de la aplicación y no comparte sus módulos:
quien la abre no tiene sesión —la aplicación lo mandaría a la pantalla de
ingreso—, llega desde un enlace de WhatsApp con datos móviles y la abre una
vez. Se explica sola, sin Tailwind por CDN, y arma el DOM con su propio `h()`
por lo mismo que la aplicación: el nombre de un producto es texto de la base.
El enlace se copia desde el detalle del pedido, en el bloque de entrega.

Sobre la promesa de entrega (F9.5), tres decisiones:

- **La promesa se sella al tomar el pedido y no se recalcula.**
  `delivery_zones.est_minutes` existía y no lo miraba nadie: era
  configuración sin consecuencia. Al crear el pedido con zona se convierte
  en una hora concreta —lo que se le dijo al cliente—; recalcularla con el
  tiempo que queda haría que ningún pedido llegara tarde nunca, que es la
  única forma de que el indicador no sirva para nada.
- **Sin zona no hay promesa, y se dice.** Prometer un tiempo inventado es
  peor que no prometer, y el reporte mide contra los que prometieron algo:
  contar un pedido sin zona como incumplido sería mentir al revés.
- **El aviso llega antes del incumplimiento.** `Domain\DeliveryPromise`
  marca `at_risk` diez minutos antes: ahí todavía se puede apurar la cocina
  o llamar al cliente. Enterarse al minuto siguiente de la hora prometida
  ya no sirve de nada — y un domicilio tarde que nadie vio es una reseña de
  una estrella. El tablero lo pinta (ámbar y rojo) y el reporte de venta
  cierra el ciclo: qué porcentaje llegó a tiempo y cuánto se demoran los
  que no.

Sobre los orígenes de venta (F9.4), tres decisiones:

- **Origen no es canal, y por eso son dos columnas.** `orders.channel` dice
  por dónde se vendió —mostrador, mesa, domicilio— y con eso se deciden
  horarios, cocina y pantallas. `orders.sales_source_id` dice **de quién
  vino** la venta: Rappi, DiDi, el Instagram del dueño. Mezclarlos obligaría
  a que cada agregador declarara si es domicilio o mostrador, y a repetir
  los horarios por cada uno. Sin ninguno configurado —el caso de casi
  todos— nada cambia y ninguna pantalla los menciona.
- **El porcentaje se congela con la venta**, como el precio (principio 8):
  renegociar la comisión con la plataforma no puede reescribir cuánto se
  ganó en marzo. La cifra de plata, en cambio, se calcula
  (`Domain\SourceCommission`), porque el total puede cambiar mientras la
  cuenta esté abierta. Se cobra sobre el total, que es como cobran las
  plataformas, y se redondea al centavo: truncar dejaría al restaurante
  reportando de menos en cada pedido.
- **Un origen con ventas no se borra, se apaga.** Igual que un motivo de
  descuento usado: borrarlo convertiría las ventas de Rappi del mes pasado
  en ventas propias y el reporte de comisiones mentiría hacia arriba.

El reporte de venta muestra el bruto, la comisión y el neto por origen, que
es la pregunta que viene a responder: el dueño ve 50.000 de venta y le
entran 35.000.

- **Fase 12 (cumplimiento y confianza)**, en curso: el documento electrónico
  (F12.1).

Sobre el documento fiscal, cuatro decisiones:

- **Numerar y transmitir son dos cosas distintas.** La numeración es del
  restaurante y la autoriza la DIAN por resolución (prefijo, rango y
  vigencia); existe aunque no haya proveedor tecnológico conectado, porque el
  papel que se entrega ya la lleva. La transmisión es de un tercero y puede
  fallar. Separarlas es lo que permite lo siguiente.
- **Que el proveedor falle no puede impedir vender.** El documento se numera,
  se guarda y se imprime; si la transmisión no sale, queda en
  `contingency` para reintentarla. Un restaurante que no puede cobrar porque
  un tercero está caído devuelve el producto y pierde al cliente.
- **El consecutivo se consume antes de transmitir.** Si la transmisión falla,
  ese número ya es de ese documento y no se le puede dar a otro: un número
  saltado se explica, uno repetido no se corrige sin nota de crédito. Y la
  resolución se lee con `FOR UPDATE`, porque dos cajas emitiendo a la vez
  leerían el mismo consecutivo.
- **Sin proveedor el documento queda en contingencia, no en aceptado.**
  `LocalFiscalProvider` no finge éxito: decir 'accepted' sin haber
  transmitido nada sería mentir en el único sitio donde la mentira la
  descubre la autoridad y no el usuario.

`Services\FiscalProvider` es el mismo patrón que `PaymentProvider`: cuando
entre un proveedor autorizado de verdad se agrega a `FiscalProviders` y se
elige por configuración del tenant, sin tocar el servicio ni los
controladores. **Falta elegir cuál** —eso necesita cuenta y credenciales del
cliente—; lo que ya funciona sin él es numerar, imprimir con el número,
guardar y reintentar.

Un pedido tiene un documento y solo uno, y lo impone la clave única: volver a
emitirlo devuelve el que ya existe, como una llave de idempotencia. Se emite
sobre una venta saldada, porque el documento dice cuánto se cobró.

**Siguiente**: lo que queda de las fases 7 a 12 del segundo plan de obra
—el resto de la caja (varias cajas, corte X, vueltas y redondeo), el agente
ESC/POS opcional y la identidad del ticket, los domicilios (liquidación del
repartidor, seguimiento público, zonas en mapa, canales de agregador,
promesa de entrega), inventario y costos, precios por canal y promociones, y
lo que falta de cumplimiento (bitácora de auditoría, límite de intentos de
ingreso, alcance por sucursal, exportación contable)—. La fase 4 quedó
completa.

Pendientes conocidos:

- La suite `tests/` de Python cubre el backend retirado y no se corre.
- **El frontend tiene arnés de pruebas, y ya cubre casi toda pantalla; lo que
  falta es el detalle dentro de cada una.**
  `web/tests/` corre con Vitest sobre jsdom y monta la aplicación real —el
  esqueleto sale de `web/index.html`, no de una copia— sin introducir paso de
  compilación: `php/public/index.php` sigue sirviendo los mismos módulos ES.
  Cubre el arranque con y sin sesión, la pantalla de clientes, el tablero de
  domicilios, el cobro, el reembolso y la división de cuenta desde el detalle
  del pedido, la caja, los reportes de cierre, la anulación con motivo, la
  impresión de comanda y ticket, el tablero de cocina, la cola de pedidos
  tomados sin red junto con el manifiesto y el precache del service worker,
  los datos del restaurante, los grupos de modificadores con su asignación a
  productos, los horarios de sucursal, el editor de estados y transiciones,
  la toma de pedido con un grupo obligatorio, el avance de estado en cocina,
  el cobro por partes hasta saldar, los atajos de teclado y el foco de los
  diálogos, la renovación del token y el cambio de contraseña, la comparación
  entre períodos con su descarga en CSV, los combos en la pantalla del menú,
  la edición de un pedido abierto, los movimientos del cajón, el descuento
  con motivo y permiso, la propina al cobrar, las estaciones de preparación
  con su filtro y su comanda partida, el documento fiscal con su número en el
  ticket, y que un botón que falla vuelva a servir.
  Cada pantalla nueva debería llegar con la suya.

  Existe porque la pantalla de login estuvo rota desde `c697b9e` hasta
  `99e54ea` por un `[rail, topbar, barraInferior].forEach(render)` — `forEach`
  pasa `(elemento, indice, array)` y `render(el, ...children)` tomaba el resto
  como hijos, así que intentaba meter el rail dentro de sí mismo. Nadie lo vio
  porque esa rama solo corre con la sesión cerrada, y en desarrollo siempre
  había token en `localStorage`. La primera prueba del arnés es justo esa, y
  se comprobó que falla al reintroducir el `forEach`.
- El slug de empresa se escribe a mano cuando hace falta; con subdominio por
  empresa no haría falta nunca, pero eso es despliegue, no código.
- **No hay límite de intentos de ingreso.** Nada frena a quien pruebe
  contraseñas contra `/auth/login` salvo el costo del bcrypt. Corresponde
  resolverlo donde se despliega (un límite por IP en el servidor web) o con
  una tabla de intentos, que es una decisión de infraestructura y no de
  modelo.
- **Cualquier usuario de la empresa puede operar cualquier sucursal activa.**
  El `?branch_id=` se valida contra el tenant, no contra la sucursal asignada
  al usuario: es lo que hace posible el selector del dueño multi-sede, pero
  también deja que un cajero de una sede cobre en la caja de otra. Acotarlo
  pide un permiso nuevo ("puede cambiar de sucursal"), no un parche en
  `Deps`.
- Un combo no puede llevar modificadores propios de sus componentes: se
  eligen sobre la línea del combo, no "el término de la carne que va dentro".
  Para eso haría falta que cada componente fuese su propia línea, y entonces
  el precio del paquete tendría que repartirse.

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
