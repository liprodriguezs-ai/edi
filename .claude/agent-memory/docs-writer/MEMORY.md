# docs-writer — MEMORY (índice)

> Conocimiento acumulado del manual de producto publicado en `kivo7.com/docs`.
> Este archivo es un ÍNDICE: una línea por entrada, el detalle en `topics/*.md`.
> Agrega entradas fechadas; corrige (no borres) las equivocadas con una nota
> fechada. Es una foto del momento — verifica contra el disco antes de afirmar.

## Hechos de carga (se quedan aquí, inline)

- El manual vive en `landing/src/content/docs/docs/**` (subdirectorio doble a
  propósito: hace que las URLs salgan bajo `/docs`).
- La guía de estilo es `landing/DOCS-STYLE-GUIDE.md` y **manda sobre todo lo demás**.
- Alcance: **solo manual de producto para usuario final**. Arquitectura, API y
  despliegue están explícitamente fuera.
- Idioma: español de México, de tú. Nunca voseo.
- Todos los ejemplos usan el condominio ficticio **Residencial Las Palmas**
  (48 casas, Guadalajara, cuota $1,500.00 MXN, Secciones Norte y Sur; personas:
  Laura Méndez administradora, Roberto Cruz tesorero, Ana Salgado residente de la
  Casa 12, Miguel Torres vigilante).
- Regla de oro: los módulos explican; Roles, FAQ y Solución de problemas enrutan.
- La operación interna de super admin va en `docs/operacion-interna.md` (raíz del
  superproyecto), **sin publicar**.

## Trampas del sitio (romperlas rompe el build o la landing)

- Nunca meter `global.css` en el `customCss` de Starlight.
- Nunca crear `landing/src/content/docs/index.md` (chocaría con la home).
- Al escribir el `.mdx` real, **borrar el `.md` de esqueleto**: un slug duplicado
  no da error, el build se queda con uno en silencio.
- `title` y `description` del frontmatter siempre entre comillas dobles.
- No quitar `prefetch: false` de `astro.config.mjs`.

## Archivos de detalle

- [Defectos encontrados al documentar](./topics/defectos-encontrados.md) — 6 bugs reales detectados leyendo el código, documentados con advertencia
- [Mapa página → código fuente](./topics/mapa-pagina-codigo.md) — qué archivo respalda cada página del manual
- [Enlaces app → manual](./topics/enlaces-app-a-manual.md) — el botón de ayuda de la barra superior, el registro y el validador que evita enlaces rotos
- [El indiviso salió de la UI](./topics/indiviso-retirado-de-la-ui.md) — 2026-08-06: campo quitado de toda la interfaz y borrado del manual; la captura `unidades.webp` quedó desactualizada
- [Calle y número interior en la Unidad](./topics/calle-y-numero-interior-en-unidad.md) — 2026-08-07: dos campos opcionales de dirección; no cambian la identidad ni el identificador, y la importación masiva aún no los acepta
- [Alta con plan y tarjeta obligatorios](./topics/alta-con-plan-y-tarjeta.md) — 2026-08-07: 30 días de prueba con tarjeta, cobro el día 31, pausa sin gracia si falla; strings prohibidos y página nueva `cuenta/acceso-en-pausa`
- ["Condominio", nunca "clúster"](./topics/terminologia-condominio-no-cluster.md) — 2026-08-08: la palabra quedó prohibida en el manual y en la app; `grep -i cl[uú]ster src/content/docs` debe dar 0

## Estado del manual

- **2026-08-06** — **Manual COMPLETO: las 106 páginas escritas, cero esqueletos.**
  Portada · Primeros pasos (5) · Estructura (11) · Cobranza (14) · Tesorería (4) ·
  Mesa y personal (5) · Comunicación (6) · Accesos (6) · Paquetería (5) ·
  Amenidades (8) · Proyectos (4) · Incidencias (4) · Reportes (5) ·
  Configuración (9) · Cuenta (4) · Roles (12) · Ayuda (3).
  Verificado: build con 0 errores, 96 enlaces internos sin rotos, buscador
  Pagefind devolviendo el resultado correcto, sin desbordamiento horizontal en
  móvil, `lang="es-MX"`, y el landing intacto (0 variables `--sl-color`, 0
  `rel="prefetch"`).
- **2026-08-06 (tarde)** — **14 capturas insertadas.** Tomadas con Playwright
  sobre el entorno de dev, convertidas a WebP con `cwebp -q 80 -resize 1440 0`
  (todas <70 KB, 529 KB en total), en `landing/src/assets/docs/*.webp`. Cada una
  con alt descriptivo; el build les pone width/height y `loading="lazy"`.
  El condominio de dev se renombró a **Residencial Las Palmas** para que las
  capturas coincidan con los ejemplos del texto.
- **2026-08-06 (noche)** — `tesoreria/cuentas-bancarias` actualizada con el campo
  **Número de tarjeta** (opcional, solo tipo **Cuenta bancaria**, 13–19 dígitos,
  enmascarado a `•••• 1234` en la lista y en la descarga). Nueva ancla
  `#el-número-de-tarjeta`. Ojo: el tipo se llama literalmente **Cuenta bancaria**
  en la interfaz, no "Banco".

- **2026-08-07** — `estructura/unidades` actualizada con **Calle** y **Número
  interior** (opcionales, en el bloque **Identificación**). Nueva ancla
  `#calle-y-número-interior`. Build con 0 errores, 74 enlaces app→docs válidos,
  landing intacto.

  Gotchas al capturar, para la próxima:
  - El entorno de dev corre en **`192.168.100.215:5189`** (front) y **`:8089`**
    (API) — la IP cambia con DHCP, verificar antes con `ipconfig getifaddr en0`.
  - Hay que **esperar a que desaparezca el texto "Cargando…"** antes de disparar
    la captura, o sale el esqueleto.
  - **`/package` no se puede abrir por URL directa** en el dev server: Vite lo
    resuelve como `package.json` y devuelve el JSON. Hay que llegar navegando
    por el menú.
  - `/dashboard` redirige a `/onboarding` mientras el onboarding esté incompleto.

- **2026-08-07 (tarde)** — **Manual alineado con el alta nueva (plan + tarjeta
  obligatorios, 30 días).** 8 páginas tocadas + 1 nueva (`cuenta/acceso-en-pausa`,
  ahora 107 páginas). Build con 0 errores (115 HTML), 12,178 enlaces internos sin
  rotos, 74 enlaces app→docs válidos, landing intacto (0 `--sl-color`), y el grep
  de strings prohibidos (`sin tarjeta` / `14 días`) devuelve vacío en el manual.
  Detalle en [Alta con plan y tarjeta](./topics/alta-con-plan-y-tarjeta.md).
