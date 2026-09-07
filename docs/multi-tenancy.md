# Estrategia multi-empresa

## Dónde se resuelve el `tenant_id` en cada capa

| Capa | Responsabilidad |
|---|---|
| Autenticación | El token JWT lleva `tenant_id`, `branch_id`, rol y permisos. Nunca se acepta el tenant desde body o query params. |
| Dependencias (`deps.py`) | Punto único que extrae el tenant del token y lo inyecta en el contexto de la petición. |
| Base de datos | Row Level Security con `SET LOCAL app.current_tenant` por transacción. Red de seguridad si un query olvida el filtro. |
| Caché (futuro) | Toda llave de Redis debe incluir el `tenant_id` en su prefijo. |
| Storage de imágenes | Rutas segmentadas: `/tenants/{id}/menu/...` |

## Dónde vive cada tipo de regla de negocio

- **Configuración simple** (moneda, si pide propina, canales activos)
  → `tenants.settings` en JSONB, con `settings_version` y JSON Schema validado
  en la capa de aplicación.
- **Estructuras variables** (estados, roles, impuestos, zonas, horarios)
  → tablas propias con `tenant_id`. En JSONB perderías integridad referencial
  y capacidad de consulta.
- **Comportamiento que difiere en algoritmo** (asignación de repartidor,
  cálculo de tiempo estimado) → patrón Strategy seleccionado por
  `business_type`. Nunca `if tenant_id == 'X'`.

## Regla práctica

Si un restaurante nuevo requiere tocar código para operar, el diseño falló.
Si solo requiere llenar configuración, está bien.
