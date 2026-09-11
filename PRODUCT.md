# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Personal de restaurantes que opera el sistema en el piso de venta: cajeros,
cocina (KDS), meseros, repartidores y administradores/dueños — con roles y
permisos configurables por cada restaurante-cliente (tenant). La mayoría opera
desde una tableta o un equipo fijo en el mostrador, la cocina o la caja,
durante el servicio, no en un escritorio con tiempo de sobra.

Fase futura ya prevista en el modelo (no implementada aún): un cliente final
del restaurante, que pide y paga por WhatsApp dentro de la conversación. El
canal `whatsapp` y `customers.phone` existen en el esquema para eso.

## Product Purpose

Foodly es un sistema operativo para restaurantes: toma de pedidos, cocina,
caja, domicilios y reportes en una sola plataforma. Existe para que un mismo
software sirva a modelos de negocio muy distintos — comida rápida, servicio en
mesa, domicilios — sin bifurcar el código: la diferencia entre un restaurante
y otro es configuración (`tenants.settings`, tablas de reglas por tenant), no
una rama distinta del programa. Éxito es que un restaurante nuevo se dé de
alta y opere completo (menú, mesas, impuestos, estados de pedido, permisos)
sin que nadie tenga que tocar código.

## Positioning

SaaS multi-tenant: una sola instalación sirve a múltiples restaurantes
independientes entre sí, aislados con Row Level Security de Postgres — cada
tenant no puede ver ni tocar los datos de otro, ni por error de programación
en un controlador. Lo que un vecino no podría copiar con la misma verdad es
esa adaptabilidad por configuración: mostrador, mesas o domicilios se
encienden con una etiqueta, no con un despliegue distinto, y el mismo modelo
de datos ya deja el camino tendido para un agente conversacional de WhatsApp
que tome pedidos y cobre dentro del chat, sin lógica de negocio propia —
llamará a los mismos servicios que usa hoy el panel web.

## Operating Context

Piso de venta de un restaurante, durante el servicio: mostrador, cocina, caja
y salón de mesas, cada uno con su propia pantalla (a menudo una tableta
compartida por varias personas en el mismo turno). Se usa con las manos
ocupadas y clientes esperando — de ahí atajos de teclado en el mostrador,
avisos sonoros en cocina y una cola de pedidos con tolerancia a cortes de red
(app instalable, service worker con reintento). Se imprime en impresoras
térmicas reales vía el navegador (comanda de cocina, ticket de cliente, corte
de caja), y hay un contrato ESC/POS ya definido para cuando exista un agente
local que hable con la impresora directamente.

Cada restaurante-cliente (tenant) puede tener varias sucursales, cada una con
sus propios horarios, impuestos, caja(s) y catálogo con precios propios.

## Capabilities and Constraints

**Construido y operando** (los 9 módulos del alcance funcional, migrados a
PHP): configuración y administración (sucursales, mesas, impuestos, usuarios
y roles configurables con catálogo de permisos fijo), menú con precio en
cascada por sucursal y modificadores, toma y edición de pedidos con precios
congelados al vender, cocina (KDS) por categoría de estado — nunca por
nombre —, caja con cobro parcial/múltiple método, división de cuenta,
reembolsos, descuentos con tope por rol, propina, arqueo con varias cajas por
sucursal, domicilios con zonas, tarifa, promesa de entrega y cuadre de
repartidor, base de clientes, reportes de cierre y comparación de períodos,
impresión (comanda/ticket/corte) y estaciones de preparación, servicio en
mesa (mapa del salón, mover/unir cuentas, mesero a cargo, tiempos de la
cuenta, pre-cuenta), orígenes de venta con comisión, documento fiscal
electrónico (numeración y contingencia; falta elegir proveedor autorizado),
tema claro/oscuro por dispositivo.

**Restricciones técnicas de diseño** que cualquier trabajo visual debe
respetar: SPA de módulos ES sin paso de compilación (Tailwind por CDN,
`web/app.css` propio); el DOM se construye con el helper `h()`, nunca
`innerHTML`; el papel impreso (`.doc` y variantes) no lleva tema, siempre
negro sobre blanco fijo; el tema es del dispositivo, no de la sesión ni de la
empresa.

**Explícitamente pendiente** (no inventar como si existiera): agente de
WhatsApp real, agente ESC/POS real (el contrato ya existe, el binario no),
identidad del ticket/inventario/costos, precios por canal y promociones,
bitácora de auditoría, límite de intentos de ingreso, alcance de usuario por
sucursal asignada, exportación contable.

## Brand Commitments

Nombre de producto: **Foodly**, ya aplicado en `web/index.html` (`<title>`) y
`web/manifest.webmanifest` (`name`/`short_name`/`description`).

Ya existe un mundo visual construido y documentado en `CLAUDE.md`: paleta
índigo/fría (`--acento` `#4F46E5`), tema claro/oscuro completo, Plus Jakarta
Sans de cuerpo y Space Grotesk en títulos/cifras/iniciales, radios suaves
(`--r` 10px, `--r-g` 16px). Preservarlo es responsabilidad de `document`/
`new-work`, no de este archivo — se deja constancia de que existe para que
una redirección de marca no lo trate como ausente.

## Evidence on Hand

No hay clientes, casos de estudio ni datos reales todavía: la base de
desarrollo es sintética (`admin@demo.local` / `admin123`, solo desarrollo) y
no debe citarse ni mostrarse como evidencia real en ningún diseño. Cualquier
cifra, testimonio o logo de restaurante en una pantalla o pieza de marketing
tiene que marcarse como placeholder mientras no exista un cliente real.

## Product Principles

- **Configuración, no código.** Un restaurante nuevo con reglas distintas
  (mostrador vs. mesas vs. domicilios, impuestos, estados de pedido, roles)
  se resuelve editando datos del tenant, nunca añadiendo una rama en el
  código de la aplicación.
- **El aislamiento entre empresas es innegociable.** El `tenant_id` viaja
  solo en el JWT y Postgres lo refuerza con RLS como red de seguridad; ningún
  atajo de producto puede pedir que el cliente lo mande él mismo.
- **Lo ya vendido no se reescribe.** Precios, nombres de producto,
  composición de combos y comisiones se congelan en el momento de la venta;
  cambiar la configuración de hoy no puede alterar en silencio un pedido de
  ayer.
- **Un tercero caído no puede impedir vender.** Pagos, documento fiscal e
  impresión están pensados como integraciones sustituibles (interfaz +
  contingencia), no como bloqueos de la operación del restaurante.
- **El agente de WhatsApp es una fase, no una excepción.** Todo lo que se
  construye hoy para el panel web debe poder servir mañana al mismo flujo
  conversacional, sin una segunda copia de la lógica de negocio.

## Accessibility & Inclusion

Sin estándar formal exigido. Se sigue el criterio ya aplicado: navegación por
teclado en el mostrador (`/` al buscador, Enter para crear el pedido, flechas
para cantidad en el carrito), `aria-label` en las líneas del carrito, y un
único helper de diálogo (`montarDialogo` en `web/js/ui.js`) que da foco al
abrir, cicla con Tab, cierra con Escape y devuelve el foco a donde estaba.
