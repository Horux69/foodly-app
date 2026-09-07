# Diseño de base de datos — Plataforma de restaurantes

## Contexto del proyecto
Plataforma multi-tenant para restaurantes, enfocada inicialmente en comida rápida
pero diseñada para soportar distintos modelos de negocio (mostrador, mesa,
delivery). Fases futuras: agente conversacional para toma de pedidos y pago
vía WhatsApp.

## Stack definido
- Base de datos: PostgreSQL
- Backend: Python o PHP (por definir)
- Frontend actual: HTML, CSS/Tailwind, JavaScript puro

## Principios de diseño
1. **Multi-tenant desde el inicio**: casi toda entidad cuelga de `tenant_id`
   (directo o vía `branch_id`). Evaluar Row Level Security (RLS) en Postgres
   para aislar tenants a nivel de motor.
2. **Configuración en JSONB** (`tenants.settings`): reglas variables por
   negocio (canales habilitados, impuestos, moneda, si maneja mesas) sin
   requerir migraciones por cada cliente nuevo.
3. **Auditoría de estados**: `order_status_history` registra cada cambio de
   estado de un pedido, independiente del estado actual en `orders.status`.
4. **Precios congelados**: `order_items.unit_price` y
   `order_item_modifiers.price_delta` guardan el precio al momento de la
   compra, no una referencia viva al menú.
5. **Reutilización de modificadores**: `modifier_groups` / `modifiers` se
   definen una vez por tenant y se asignan a productos vía
   `item_modifier_groups` (N:N).
6. **Canal de pedido como campo explícito**: `orders.channel` (counter,
   table, delivery, whatsapp, app) — preparado para cuando el agente de
   WhatsApp empiece a crear pedidos por ese mismo canal.

## Entidades (ver 001_initial_schema.sql)
tenants, branches, users, customers, menu_categories, menu_items,
modifier_groups, modifiers, item_modifier_groups, tables, orders,
order_items, order_item_modifiers, order_status_history, payments,
delivery_info.

## Pendiente para siguientes fases (no incluido en el MVP)
- `promotions` / `coupons`
- `inventory` (control de stock por ingrediente)
- `reviews`
- Integración de agente conversacional (WhatsApp) y pasarela de pago
