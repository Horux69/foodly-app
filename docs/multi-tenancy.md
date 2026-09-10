# Estrategia multi-empresa

## Dónde se resuelve el `tenant_id` en cada capa

| Capa | Responsabilidad |
|---|---|
| Autenticación | El token JWT lleva `tenant_id`, `branch_id`, rol y permisos. Nunca se acepta el tenant desde body o query params. |
| Dependencias (`Api/Deps.php:getContext`) | Punto único que extrae el tenant del token y lo inyecta en el contexto de la petición. |
| Base de datos | Row Level Security con `SET LOCAL app.current_tenant` por transacción. Red de seguridad si un query olvida el filtro. |
| Caché (futuro) | Toda llave de Redis debe incluir el `tenant_id` en su prefijo. |
| Storage de imágenes | Rutas segmentadas: `/tenants/{id}/menu/...` |

## El `branch_id` es la excepción, y por eso se valida

El tenant nunca se acepta del cliente. La sucursal **sí**: un dueño con
varias sedes necesita poder decir cuál está mirando, y esa elección solo la
conoce el navegador. Por eso el selector de sucursal manda `?branch_id=` y
`Api/Deps.php:activeBranchId` es el único punto que lo lee:

1. Si no viene, se usa la sucursal asignada al usuario en el token.
2. Si viene, se busca **con el `tenant_id` del token** (y bajo RLS). Una
   sucursal de otra empresa no existe desde ahí: responde 404, no 403, porque
   desde el punto de vista de esa empresa no hay nada que negar.

Nunca se lee `branch_id` del body ni de un header, y ningún controlador
repite esa resolución. `/auth/me` devuelve las sucursales activas entre las
que el usuario puede moverse: son nombres de su propia empresa, así que no
exige el permiso de administración `settings.view` que pide `GET /branches`.

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
