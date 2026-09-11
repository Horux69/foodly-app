---
name: Foodly
description: Sistema operativo para restaurantes — mostrador, cocina, caja y salón en una paleta índigo fría, plana y de alta densidad.
colors:
  tinta-indigo: "#4F46E5"
  esmeralda: "#0E9F6E"
  ambar: "#B7791F"
  carmin: "#DC2626"
  acento-suave: "#EEF0FF"
  esmeralda-suave: "#E7F7F0"
  ambar-suave: "#FDF6E7"
  carmin-suave: "#FDEEEE"
  lienzo: "#ECEEF6"
  panel: "#FFFFFF"
  panel-hundido: "#F7F8FC"
  linea: "#DCE0EE"
  linea-fuerte: "#C6CBDF"
  tinta: "#101322"
  tinta-media: "#585E7A"
  tinta-tenue: "#9AA0B8"
  tinta-inversa: "#FFFFFF"
typography:
  headline:
    fontFamily: "'Space Grotesk', sans-serif"
    fontSize: "19px"
    fontWeight: 600
    lineHeight: 1.25
    letterSpacing: "-0.011em"
  label:
    fontFamily: "'Space Grotesk', sans-serif"
    fontSize: "13px"
    fontWeight: 600
    lineHeight: 1.3
    letterSpacing: "0.01em"
  numeric:
    fontFamily: "'Space Grotesk', sans-serif"
    fontSize: "14px"
    fontWeight: 500
    lineHeight: 1.3
    letterSpacing: "normal"
  body:
    fontFamily: "'Plus Jakarta Sans', system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.45
    letterSpacing: "normal"
  body-dense:
    fontFamily: "'Plus Jakarta Sans', system-ui, sans-serif"
    fontSize: "13.5px"
    fontWeight: 500
    lineHeight: 1.3
    letterSpacing: "normal"
rounded:
  sm: "10px"
  lg: "16px"
components:
  button-primary:
    backgroundColor: "{colors.tinta-indigo}"
    textColor: "{colors.tinta-inversa}"
    typography: "{typography.body-dense}"
    rounded: "{rounded.sm}"
    padding: "0 0.75rem"
    height: "34px"
  button-secondary:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.tinta-media}"
    typography: "{typography.body-dense}"
    rounded: "{rounded.sm}"
    padding: "0 0.75rem"
    height: "34px"
  button-danger:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.carmin}"
    typography: "{typography.body-dense}"
    rounded: "{rounded.sm}"
    padding: "0 0.75rem"
    height: "34px"
  button-primary-big:
    backgroundColor: "{colors.tinta-indigo}"
    textColor: "{colors.tinta-inversa}"
    typography: "{typography.body}"
    rounded: "{rounded.sm}"
    padding: "0 0.75rem"
    height: "44px"
  badge-ok:
    backgroundColor: "{colors.esmeralda-suave}"
    textColor: "{colors.esmeralda}"
    typography: "{typography.label}"
    rounded: "4px"
    padding: "0.125rem 0.375rem"
  badge-danger:
    backgroundColor: "{colors.carmin-suave}"
    textColor: "{colors.carmin}"
    typography: "{typography.label}"
    rounded: "4px"
    padding: "0.125rem 0.375rem"
  input-field:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.tinta}"
    typography: "{typography.body}"
    rounded: "{rounded.sm}"
    padding: "0.4375rem 0.625rem"
  card-section:
    backgroundColor: "{colors.panel}"
    rounded: "{rounded.lg}"
---

# Design System: Foodly

## Overview

**Creative North Star: "The Calm Control Room"**

Foodly opera en el momento más ruidoso de un restaurante — hora pico, tableta compartida, manos ocupadas, cliente esperando — y por eso su pantalla hace exactamente lo contrario que el salón que la rodea: se queda quieta. El índigo frío no decora, señala; el resto del sistema es casi monocromo — un lienzo gris-azulado, paneles blancos, líneas finas en vez de bordes de tarjeta — para que el único color con intención (el acento, o un estado ok/aviso/peligro) se note de inmediato sin tener que competir con nada. Es una sala de control, no un anuncio: la calma es funcional, no estética.

La superficie es plana por decisión, no por omisión — la elevación (sombra + borde) se reserva para lo que de verdad flota por encima de la pantalla: un diálogo, un menú, un toast. Todo lo demás — secciones, filas, botones, el propio piso de venta — vive sobre la misma superficie continua, separado por una línea de 1px. Es información de alta densidad (14px base, filas de 34px) pensada para escanearse de un vistazo y no para pasearse por ella, con una sola excepción deliberada: la pantalla de venta, donde manda el dedo y no el ojo, y los objetivos táctiles crecen a 44px.

El sistema vive completo en modo claro y en modo oscuro, elegido por el dispositivo y no por la sesión — la tableta de la cocina no cambia de opinión sobre su tema porque cambió quien la usa — y en ningún caso llega al papel: lo impreso (comanda, ticket, corte de caja) es siempre negro sobre blanco fijo, porque una térmica no entiende de temas.

**Key Characteristics:**
- Plano por defecto; la sombra existe solo para lo que flota, y siempre acompañada de un borde de 1px, nunca sola.
- Un único acento de marca (índigo); todo lo demás es neutro salvo los tres colores de estado (ok/aviso/peligro), que son funcionales, no decorativos.
- Separación por línea fina, no por tarjeta — la tarjeta se reserva para el objeto suelto (un ticket de cocina, un diálogo).
- Space Grotesk para lo que se lee de un vistazo (títulos, cifras, etiquetas cortas); Plus Jakarta Sans para todo lo demás.
- Densidad alta en la operación de escritorio/tableta administrativa; objetivos grandes solo donde se toca con el dedo de pie.
- Tema completo claro/oscuro por dispositivo; el papel impreso nunca lo lleva.

## Colors

Una paleta casi monocroma — grises con temperatura azulada, nunca grises neutros — sobre la que el índigo y los tres colores de estado son las únicas notas de color con permiso para llamar la atención.

### Primary
- **Tinta Índigo** (`#4F46E5` claro / `#9994F0` oscuro): el único acento de marca. Marca lo interactivo con intención — botón principal, ítem activo del menú, foco de un campo, número de cifra destacada — y por eso aparece en poca superficie de cualquier pantalla. Su versión suave, **Acento Suave** (`#EEF0FF` claro / `#212237` oscuro), es el fondo de la ficha activa y el halo de foco: nunca el acento sólido usado como fondo de área grande.

### Secondary (estados funcionales)
- **Esmeralda** (`#0E9F6E` claro / `#34D399` oscuro): confirmación y estado positivo — insignia "ok", borde de un ticket de cocina recién llegado. Su variante suave (`#E7F7F0` / `#0F2A22`) es el fondo de esa insignia, nunca del acento primario.
- **Ámbar** (`#B7791F` claro / `#F0B429` oscuro): aviso — algo pendiente sin ser error todavía, un ticket que empieza a demorarse, una advertencia de configuración a medio terminar. Suave: `#FDF6E7` / `#2C2313`.
- **Carmín** (`#DC2626` claro / `#F87171` oscuro): peligro — acción destructiva, ticket muy demorado (con fondo rosado completo, no solo borde), error. Suave: `#FDEEEE` / `#2C1618`.

### Neutral
- **Lienzo** (`#ECEEF6` claro / `#0A0C14` oscuro): el fondo de toda la aplicación, detrás de cualquier panel.
- **Panel** (`#FFFFFF` claro / `#12151F` oscuro): la superficie de secciones, tarjetas, diálogos y campos.
- **Panel Hundido** (`#F7F8FC` claro / `#171B27` oscuro): el estado de hover de una fila o un botón sutil — un paso más oscuro que el panel, nunca un color propio.
- **Línea** (`#DCE0EE` claro / `#232838` oscuro) y **Línea Fuerte** (`#C6CBDF` / `#323848`): el divisor de 1px que reemplaza al borde de tarjeta, y su variante más marcada para el borde de un campo o botón secundario.
- **Tinta** (`#101322` / `#EDEFF7`), **Tinta Media** (`#585E7A` / `#A0A7C0`), **Tinta Tenue** (`#9AA0B8` / `#6E7690`): los tres pasos de texto, de título a placeholder.
- **Tinta Inversa** (`#FFFFFF` claro / `#0A0C14` oscuro): el texto o ícono que va *encima* de un relleno sólido de acento. Nunca "blanco" a secas — en oscuro el acento se aclara, y blanco encima dejaría de leerse.

### Named Rules
**La Regla del Acento Raro.** El índigo sólido se usa como fondo de área en como mucho un elemento por pantalla (el botón principal, o la ficha activa del menú). Todo lo demás que necesite destacar usa su variante suave como fondo con el acento como texto — nunca dos rellenos sólidos de acento compitiendo en la misma vista.

**La Regla del Papel Ciego al Tema.** Ningún color de este sistema — ni los neutros, ni el acento, ni los tres de estado — cruza a `.doc` y sus variantes (comanda, ticket, corte). Ahí todo es negro sobre blanco fijo, siempre.

## Typography

**Headline/Label Font:** Space Grotesk (con `sans-serif` de respaldo)
**Body Font:** Plus Jakarta Sans (con `system-ui, sans-serif` de respaldo)

**Character:** Space Grotesk es la fuente de lo que hay que leer de un vistazo — títulos, cifras de dinero, etiquetas cortas de sección — con su ligera geometría técnica dándole peso a lo que importa sin subir el tamaño. Plus Jakarta Sans lleva todo el resto: cuerpo, controles, hints. Ninguna de las dos es decorativa; las dos se cargan por CDN sin paso de compilación.

### Hierarchy
- **Headline** (600, 19px, interlineado 1.25, `-0.011em`): título de pantalla (`pageHeader`), Space Grotesk. Es el tamaño más grande que existe en el sistema — no hay una escala "display": esto es una herramienta de trabajo, no una portada.
- **Label** (600, 13px, `0.01em`, versalitas por `text-transform: uppercase`): el encabezado eyebrow de una sección (`.seccion > header h2`), en Tinta Media. Comparte fuente con Headline aunque sea pequeño: Space Grotesk marca "esto se escanea", no "esto es grande".
- **Numeric** (500, 14px, tabular): cualquier cifra de dinero o cantidad, con `font-variant-numeric: tabular-nums` (clase `.num`) para que las columnas de una tabla no bailen al cambiar de valor.
- **Body** (400, 14px, interlineado 1.45): texto de párrafo y contenido general.
- **Body Dense** (500, 13.5px, interlineado 1.3): la talla de casi todos los controles — botones, pestañas, filas de lista — donde la densidad importa más que el aire.
- **Hint** (400, 12–13px, Tinta Tenue): texto de ayuda bajo un campo o un encabezado de sección.

### Named Rules
**La Regla de Lectura Rápida.** Space Grotesk aparece solo donde algo se lee "de reojo": títulos, cifras, etiquetas de sección. Todo lo que se lee en frase corrida es Plus Jakarta Sans. Mezclar las dos en el mismo rol rompería la señal.

## Layout

La aplicación es una sola columna de contenido dentro de un cascarón de dos zonas: una barra lateral de navegación (`rail`) y el área de vista. En escritorio grande la barra mide 212px con etiquetas de texto; en escritorio angosto se colapsa a 60px de solo ícono; en móvil desaparece del todo y su rol lo toma una barra inferior fija — la navegación baja al pulgar en vez de subir a un menú de hamburguesa.

El contenido principal tiene un tope de ancho de **1380px**, a propósito: una fila de tabla de 1900px de ancho obligaría a barrer la pantalla con la vista para relacionar la primera columna con la última en un reporte. La densidad de la aplicación es alta — tipografía de 14px base, filas de 34px — salvo en la pantalla de venta, donde el mostrador se opera de pie y con el dedo: ahí los objetivos táctiles suben a 44px (`.boton-grande`, `.producto`).

Los campos de formulario usan 16px de tamaño de fuente por defecto (evita que iOS haga zoom automático al enfocar) y bajan a 13.5px desde el breakpoint `sm` (640px), donde ese riesgo ya no existe y la densidad vuelve a mandar.

## Elevation & Depth

El sistema es plano en reposo. La sombra (`--sombra`, definida pero sin uso propio: en la práctica la elevación real usa las utilidades `shadow-lg`/`shadow-xl` de Tailwind) aparece únicamente en lo que de verdad flota sobre el resto de la interfaz — un toast, un menú, un diálogo — y **siempre acompañada de un borde de 1px** (`border-[--linea]`), nunca sola: sobre un lienzo gris-azulado de bajo contraste, una sombra sin borde se lee ambigua, no como profundidad.

En móvil, un diálogo no flota centrado: entra como hoja inferior (`rounded-t-2xl`, pegada al borde inferior de la pantalla) y en escritorio pasa a estar centrado con las cuatro esquinas redondeadas. Es el mismo componente, dos posiciones según el espacio disponible para el pulgar.

### Shadow Vocabulary
- **Flotante** (Tailwind `shadow-xl`, siempre con `border border-[--linea]`): diálogos, menús desplegables, paneles laterales deslizantes.
- **Aviso momentáneo** (Tailwind `shadow-lg`): el toast, que además lleva su propio fondo sólido (nunca `--panel`) porque tiene que leerse sobre cualquier pantalla que haya debajo.

### Named Rules
**La Regla de la Sombra Acompañada.** Ninguna sombra aparece sin un borde de 1px al lado. Es lo que distingue "esto flota" de "esto tiene una mancha debajo".

## Shapes

Dos radios, sin escala intermedia: **10px** (`--r`) para todo control — botón, campo, insignia, ítem de navegación — y **16px** (`--r-g`) para todo bloque — sección, tarjeta, diálogo. La regla es mecánica: si es algo que se toca, 10px; si es un contenedor que agrupa controles, 16px. El único borde asimétrico del sistema es la hoja inferior en móvil (esquinas superiores redondeadas, inferiores a cero, porque nace pegada al borde de la pantalla).

Los tickets de cocina llevan además un borde izquierdo de 3px cuyo color es la demora (`--ok`/`--aviso`/`--peligro`): la única silueta del sistema que codifica información en su propio contorno, no solo en su color de fondo.

## Components

### Buttons
- **Shape:** radio de control (10px); `.boton-grande` (44px de alto) mantiene el mismo radio, solo crece el objetivo táctil.
- **Primary** (`.boton-principal`): fondo Tinta Índigo, texto Tinta Inversa, 34px de alto, 0–0.75rem de padding horizontal.
- **Secondary** (`.boton-secundario`): fondo Panel, texto Tinta Media, borde Línea Fuerte de 1px.
- **Danger** (`.boton-peligro`): fondo Panel, texto Carmín, borde carmín-200 de 1px; en hover pasa a fondo Carmín Suave.
- **Subtle** (`.boton-sutil`): sin fondo ni borde en reposo, texto Tinta Media; en hover gana fondo Panel Hundido.
- **Hover / Focus:** el primario aclara con `filter: brightness(1.08)` en vez de cambiar de color (mantiene el mismo tono en los dos temas sin declarar un segundo hex); el foco visible en cualquier control es un anillo de 2px en Tinta Índigo con 1px de separación.
- **Disabled:** opacidad 0.4 y cursor `not-allowed`, sin cambio de color — la forma dice "inactivo" sin inventar un cuarto tono neutro.

### Badges (insignias)
- **Style:** texto Label (13px→11.5px aquí, 600, sin mayúsculas en este caso), fondo suave + texto del color del rol; radio de 4px, más chico que el radio de control porque es texto inline, no un objetivo táctil.
- **Tones:** `neutral` (Panel Hundido / Tinta Media), `info` (variante suave del acento), `warn` (Ámbar Suave / Ámbar), `ok` (Esmeralda Suave / Esmeralda), `danger` (Carmín Suave / Carmín).

### Cards / Containers (`.seccion`)
- **Corner Style:** radio de bloque (16px).
- **Background:** Panel sobre Lienzo, con borde Línea de 1px — reemplaza a la tarjeta con sombra: es la unidad de agrupación por defecto, no un objeto que flota.
- **Header:** Label en mayúsculas (13px, Tinta Media) con un divisor Línea debajo; el contenido va en `.cuerpo` (1rem de relleno) o en `.lista`, cuyas filas (`.fila`) se separan por línea y se resaltan con Panel Hundido al pasar el cursor.
- **Shadow Strategy:** ninguna — ver Elevation & Depth. Una `.seccion` nunca flota.

### Inputs / Fields (`.campo`)
- **Style:** fondo Panel, borde Línea Fuerte de 1px, radio de control.
- **Focus:** borde Tinta Índigo + halo de 3px en Acento Suave (`box-shadow`), sin animación — el cambio es instantáneo para no sumar demora en el mostrador.
- **Placeholder:** Tinta Tenue.

### Kitchen Tickets (`.ticket`) — componente de firma
- **Style:** tarjeta (única superficie que sí flota como objeto suelto, porque es literalmente un objeto que se mueve entre columnas del tablero), borde izquierdo de 3px codificado por demora: Esmeralda (fresco), Ámbar (atento), Carmín + fondo Carmín Suave completo (tarde). Es la única vez que el sistema usa color de fondo de área además de acento puntual, porque un ticket muy demorado tiene que notarse sin leer el borde.

### Navigation (`rail` / barra inferior)
- **Style:** fondo Panel sobre borde Línea a la derecha (desktop) o arriba (barra inferior móvil). El ítem activo se marca con fondo Acento Suave, texto Índigo y peso 600 — nunca con un indicador aparte (línea, punto): el propio fondo es la marca.

### Print Documents (`.doc`) — fuera del sistema de tema
- **Style:** monoespaciada (`ui-monospace`), siempre negro sobre blanco, ancho fijado por el perfil de impresión de la sucursal (48mm o 72mm). Un ticket muy demorado en pantalla no tiene equivalente aquí — el papel no transmite urgencia, solo registra la venta.

## Do's and Don'ts

### Do:
- **Do** usar Tinta Índigo sólida como fondo de área en un único elemento por pantalla; todo lo demás que necesite destacar lleva su variante suave.
- **Do** acompañar cualquier sombra con un borde de 1px en Línea — nunca una sombra sola.
- **Do** usar Space Grotesk para títulos, cifras y etiquetas cortas de sección; Plus Jakarta Sans para todo lo demás.
- **Do** mantener el radio de control (10px) en cualquier cosa que se toque y el de bloque (16px) en cualquier cosa que agrupe.
- **Do** subir los objetivos táctiles a 44px solo en la pantalla de venta (piso, de pie, con el dedo); en el resto manda la densidad de 34px.
- **Do** mantener el papel impreso (`.doc`) negro sobre blanco fijo, ajeno al tema claro/oscuro.

### Don't:
- **Don't** introducir un segundo color de acento de marca — el sistema tiene uno, y su rareza es la señal.
- **Don't** usar naranja, rojo cálido o energía de mascota/"burbuja" propia de una app de delivery al consumidor: esto opera un negocio, no lo vende a un comensal.
- **Don't** usar gris beige o el aspecto de terminal de punto de venta de los noventa — la frialdad índigo y la tipografía técnica son deliberadas frente a ese anti-referente.
- **Don't** envolver una fila o una sección en una tarjeta con sombra: la línea fina es la separación por defecto; la tarjeta con elevación se reserva para lo que de verdad flota o se mueve (un diálogo, un ticket de cocina).
- **Don't** dejar un `--tinta-inversa` fijo en blanco: en tema oscuro el acento se aclara, y el texto encima tiene que oscurecerse con él.
