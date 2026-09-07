# Alcance funcional por módulos

## 1. Configuración y administración
Alta de empresas, sucursales, usuarios y reglas de operación.
- Tablas: `tenants`, `branches`, `roles`, `permissions`, `users`,
  `branch_schedules`, `tax_rates`, `order_statuses`, `order_status_transitions`
- Al crear un tenant se siembran estados, roles e impuestos por defecto según
  su `business_type`. Sin eso el restaurante no puede operar.

## 2. Menú y catálogo
- Tablas: `menu_categories`, `menu_items`, `modifier_groups`, `modifiers`,
  `item_modifier_groups`, `branch_menu_overrides`
- Precio efectivo en cascada: `branch_menu_overrides.price` si existe, si no
  `menu_items.base_price`. Resolver en un solo lugar del código.

## 3. Toma de pedidos
- Tablas: `orders`, `order_items`, `order_item_modifiers`, `tables`, `customers`
- Validaciones: disponibilidad en la sucursal, `min_select`/`max_select` de
  modificadores, sucursal en horario para el canal, congelado de precios.
- Totales: `subtotal` → `tax_total` → `+ delivery_fee` → `- discount`
  → `+ tip` → `total`. Ver `app/domain/order_totals.py`.

## 4. Cocina (KDS)
- Filtra por `order_statuses.category = 'kitchen'`, nunca por `code`. Así
  funciona igual aunque cada restaurante nombre sus estados distinto.

## 5. Caja y pagos
- Tablas: `payments`, `orders`
- La suma de `payments` con `status = 'paid'` debe igualar `orders.total` antes
  de permitir el estado final. El gateway debe ser una interfaz
  (`PaymentProvider`) para que WhatsApp Pay entre sin tocar el resto.

## 6. Domicilios
- Tablas: `delivery_info`, `delivery_zones`
- Si el subtotal no alcanza el `min_order` de la zona, el pedido no se confirma.

## 7. Clientes
- El `phone` único por tenant es lo que permitirá al agente de WhatsApp
  reconocer al cliente automáticamente.

## 8. Reportes
- Ventas por período/sucursal/canal, productos más vendidos, ticket promedio,
  tiempos de preparación (desde `order_status_history`), horas pico.

## 9. Motor de configuración (transversal)
Capa que resuelve `tenants.settings` y las tablas de configuración para
responder "¿este tenant maneja mesas?", "¿pide propina?", "¿qué canales tiene
activos?". Todos los módulos la consultan.

## Orden de construcción
1 y 2 primero, luego 3, después 4 y 5 en paralelo, y 8 al cierre del MVP.
Los módulos 6 y 7 quedan listos para cuando entre el agente de WhatsApp.
