# Foodly — plan de continuación

Guía de traspaso para retomar el trabajo en otra sesión de Claude Code.
`CLAUDE.md`, en la raíz del repositorio, es la fuente de verdad completa
(arquitectura, principios, y el detalle de "no deshacer" de cada tarjeta ya
construida) — léelo primero. Este archivo es un mapa rápido de qué está
hecho, qué falta y cómo se trabaja aquí, para no tener que reconstruir el
contexto leyendo commit por commit.

## Cómo se trabaja en este repositorio

- **Rama de desarrollo**: `claude/plan-obra-fase-0-id966w`. Todo el trabajo
  reciente vive ahí, y se acaba de fusionar a `main` (fast-forward, sin
  conflictos) para que se pueda revisar en local.
- **Una tarjeta = un commit.** Cada carta del plan de obra se cierra con:
  código + pruebas (dominio y, cuando aplica, integración contra Postgres) +
  verificación en un navegador real contra el servidor de desarrollo +
  actualización de `CLAUDE.md` documentando las decisiones de "no deshacer".
  No se pasa a la siguiente tarjeta sin los tres.
- **Antes de escribir código**: `docs/database-design.md`,
  `docs/functional-scope.md`, `docs/multi-tenancy.md`, y sobre todo
  `CLAUDE.md` — tiene una sección por fase con las decisiones que ya se
  tomaron y por qué, para no repetir un error ya resuelto (la lista de
  `php/tests/Core/RutasTest.php` y `LlamadasTest.php` explica dos de esos
  errores concretos).
- **Comandos**: ver la sección "Comandos" de `CLAUDE.md`. Postgres puede
  necesitar `sudo pg_ctlcluster 16 main start` si el contenedor se reinició.

## Hecho hasta ahora

Fases completas: **2, 3, 4, 5, 6, 9**. Fases en curso con varias cartas ya
hechas: **7** (F7.1–F7.6, las seis), **8** (F8.1–F8.3), **12** (solo F12.1).
Además, una identidad visual nueva aplicada a toda la aplicación (paleta
índigo, tipografías, tema claro/oscuro) — ver la sección "Identidad visual"
de `CLAUDE.md`.

Dos cartas se resolvieron con alcance acotado, a propósito, por decisión del
usuario en esta sesión:

- **F9.3 (zonas de reparto dibujadas en mapa)**: se dibuja y guarda el
  polígono de cada zona (`Domain\DeliveryZonePolygon`, Leaflet por CDN en
  Administración), pero **no** hay asignación automática de zona por
  ubicación al tomar el pedido — eso seguiría pidiendo geocodificar la
  dirección contra un servicio de terceros, y quedó fuera.
- **F8.3 (agente ESC/POS)**: solo se construyó el contrato del backend
  (`Domain\EscPos`, `Domain\EscPosTicket`, `Domain\EscPosComanda`, los
  endpoints `GET /orders/{id}/escpos/ticket` y `.../escpos/comanda`). El
  agente local que de verdad hablaría con una impresora térmica (USB,
  serial o red) **no se construyó**: es un binario fuera de este stack
  PHP/JS, con su propia decisión de plataforma, que el usuario prefirió
  dejar para después.

## Pendiente

Del segundo plan de obra (fases 7 a 12), lo que falta:

- **El agente ESC/POS de verdad** — el binario que reciba los bytes de
  F8.3 y los mande a una impresora térmica. Necesita decisiones que ya se
  le plantearon al usuario una vez (plataforma del agente, protocolo de
  impresora, si el cajón de dinero entra) — conviene volver a preguntar
  antes de construir, no asumir.
- **La identidad del ticket** — mencionada junto al agente ESC/POS en el
  plan original, sin desarrollar todavía.
- **Inventario y costos** — no iniciado.
- **Precios por canal y promociones** — no iniciado.
- **Lo que falta de cumplimiento (fase 12)**: bitácora de auditoría, límite
  de intentos de ingreso, alcance por sucursal (que un cajero solo opere su
  sucursal asignada — necesita un permiso nuevo, no un parche en `Deps`),
  exportación contable.

Pendientes conocidos, documentados como decisiones aplazadas y no como
errores (detalle completo en la sección "Pendientes conocidos" de
`CLAUDE.md`):

- Sin límite de intentos en `/auth/login` — es una decisión de despliegue
  (límite por IP), no una carta del modelo.
- Cualquier usuario puede operar cualquier sucursal activa del tenant —
  falta un permiso ("puede cambiar de sucursal").
- Un combo no puede llevar modificadores propios de sus componentes.
- El slug de empresa se escribe a mano; con subdominio por empresa no
  haría falta.

## Al retomar

1. Leer `CLAUDE.md` completo — es largo porque cada decisión importante
   está ahí, con el porqué.
2. Correr las dos suites (`cd php && vendor/bin/phpunit` y
   `npm test`) para confirmar que se parte de verde.
3. Preguntar al usuario qué sigue antes de empezar F8.3-agente o F9.3 (ya
   resueltas en su alcance acotado) — y antes de tocar el agente ESC/POS o
   cualquier otra pieza que dependa de una decisión de producto que no está
   en este documento.
