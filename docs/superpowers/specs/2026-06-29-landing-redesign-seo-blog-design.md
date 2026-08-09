# Rediseño de landing Kivo7 + SEO + Blog — Design Spec

**Fecha:** 2026-06-29
**Repo objetivo:** `landing/` (submódulo Astro, separado de `frontend/`)
**Rama:** la misma (sin commits, por pedido explícito del usuario)

---

## 1. Contexto y objetivo

La landing actual (Astro 7 estático + Tailwind v4 + pnpm) tiene un diseño distintivo —estética "premium paper" salvia + Cormorant Garamond— pero el usuario quiere:

1. **Rediseño más innovador y de ultracalidad** manteniendo la paleta y tipografías.
2. **Reordenar** la página con una narrativa que venda mejor (se permite remover lo actual).
3. **Copy más vendible y entendible.**
4. **Potenciar el SEO** (extender lo existente, no arrancar de cero).
5. **Enriquecer el sitemap** (ya está activo vía `@astrojs/sitemap`).
6. **Activar un blog** con 3 posts de ejemplo (greenfield).

**Hallazgo importante verificado en código:** el sitemap YA se genera y el SEO base YA es sólido (title, description, canonical, OG, Twitter card, JSON-LD con Organization + WebSite + SoftwareApplication, robots.txt). El trabajo de SEO es **extender**, no "activar desde cero". El blog sí es nuevo.

### No-objetivos (fuera de alcance)
- No migrar a CMS ni a SSR. Sigue siendo Astro estático.
- No agregar librería de animación pesada (seguimos con vanilla JS + IntersectionObserver, como ya hace el proyecto).
- No tocar `frontend/` ni `backend/`. Solo `landing/`.
- No commitear. Trabajamos en la misma rama, cambios en working tree.

---

## 2. Decisiones tomadas (validadas con el usuario en el companion visual)

| Decisión | Resultado |
|---|---|
| Marca / dominio | **Kivo7 + kivo7.com** canónico. `residia.mx` queda legacy/redirect. Afecta canonical, JSON-LD, OG, sitemap y CTAs. |
| Dirección del Hero | **A · Editorial en capas** — split asimétrico, producto como tarjetas flotantes apiladas, profundidad + parallax sutil. |
| Sistema visual de impacto | 4 herramientas: **Contraste** (franjas paper/tinta), **Profundidad** (capas + sombras + chips), **Movimiento** (reveals, parallax, count-ups, micro "Cargo→Pagado"), **Acento quirúrgico** (salvia disciplinado). |
| Intensidad de contraste | **Equilibrado** — 2–3 momentos oscuros (Comparativa, Stats/Results, Cierre). |
| Secciones nuevas | **Comparativa vs Excel, Cómo funciona (3 pasos), Precios/Planes, FAQ.** |

---

## 3. Arquitectura de información (orden final · 12 bloques)

Narrativa de conversión: **Gancho → Prueba social → Problema → Cómo → Qué → Prueba → Precio → Dudas → Cierre.**

| # | Sección | Estado | Notas |
|---|---|---|---|
| 1 | **Nav** | Actualizar | Agregar link "Blog". Mantener pill flotante. |
| 2 | **Hero** | Rediseño (Dir. A) | Split asimétrico + tarjetas flotantes + parallax. |
| 3 | **TrustStrip** | Mantener | Prueba social temprana. Reemplazar nombres ficticios por reales o quitar (ver §8 abierto). |
| 4 | **Comparativa vs Excel** | NUEVA | Antes (claro/terracota) vs Después (franja oscura/salvia). 1er momento oscuro. |
| 5 | **Cómo funciona** | NUEVA | 3 pasos: alta del clúster → cargá unidades/residentes → cobrá y reportá solo. |
| 6 | **Bento** | Mover (baja) | Plataforma / 7 features. Sin cambios estructurales mayores. |
| 7 | **ResultsBand** | Mantener | Stats con count-up. 2º momento oscuro (ya es banda oscura). |
| 8 | **Quote** | Mantener | Testimonio. Reemplazar por real o marcar como ilustrativo. |
| 9 | **Precios / Planes** | NUEVA | Tiers + Offer schema. Precios a confirmar (ver §8 abierto). |
| 10 | **FAQ** | NUEVA | Acordeón accesible + FAQPage schema (rich results). |
| 11 | **CTA** | Mantener | Empuje final. 3er momento oscuro (ya es gradiente forest). |
| 12 | **Footer** | Actualizar | Link Blog + legal real (Privacidad/Términos hoy van a `#`). |

---

## 4. Lenguaje visual (sistema de impacto · "Equilibrado")

Se mantiene 100% la paleta (`global.css` `@theme`) y tipografías. El "más impacto" se logra con:

- **Ritmo de contraste:** 3 momentos en tinta oscura (`#14181a`→`#1d2426`): Comparativa (#4), ResultsBand (#7, ya existe), CTA (#11, ya existe). El resto respira en paper.
- **Profundidad:** tarjetas en capas con `box-shadow` suave, chips flotantes con dato real (`+ $48,200`, `98% al día`, `Cargo → Pagado`). El Hero usa stack de 2–3 ventanas offset (Dir. A).
- **Movimiento (motion-gated, respeta `prefers-reduced-motion`):**
  - Reveals al scroll (ya existe `.reveal` + IntersectionObserver) → extender a las nuevas secciones con stagger.
  - Parallax leve en las tarjetas del Hero (translateY en scroll, vanilla JS, gated).
  - Count-ups (ya existe) en ResultsBand y, opcionalmente, en chips del Hero.
  - Micro-interacción "Cargo → Pagado": un chip que cambia de estado una vez al entrar en viewport (CSS/JS mínimo, gated).
- **Acento quirúrgico:** salvia solo en CTAs, datos clave y estados ok. Nunca de relleno.

**Accesibilidad (no negociable):** todo el motion detrás de `prefers-reduced-motion: reduce`; contraste AA en texto sobre franjas oscuras; foco visible; `skip-link` ya existe; acordeón FAQ navegable por teclado con `aria-expanded`/`aria-controls`.

---

## 5. Diseño de secciones nuevas

### 5.1 Comparativa vs Excel (`ComparisonExcel.astro`)
Split de 2 columnas. Izq "Con Excel" (paper-2, íconos ✕ terracota): fórmulas rotas, pagos perdidos en WhatsApp, cierre manual de 12h, cero transparencia. Der "Con Kivo7" (franja tinta, íconos ✓ salvia): cobranza automática, folio+comprobante por pago, cierre en minutos, estado de cuenta visible. Reveal con stagger. Datos = del dominio real (cobranza FIFO, folios `R-YYYY-NNNNNN`, transparencia).

### 5.2 Cómo funciona (`HowItWorks.astro`)
3 pasos numerados (serif grande), eyebrow + título de sección reutilizando `SectionHead.astro`. Promesa: "Tu condominio deja el Excel en una semana."
1. **Creás el clúster** — alta + configuración guiada.
2. **Cargás unidades y residentes** — torres/secciones/unidades, vínculos owner/renter.
3. **Cobrás y reportás solo** — conceptos, lotes de cargos, FIFO, estado de cuenta.

### 5.3 Precios / Planes (`Pricing.astro`)
Estructura propuesta (números a confirmar — §8). 3 tiers o pricing por unidad. Cada plan: nombre, precio, lista de features, CTA. Sincronizar con JSON-LD `Offer` (hoy `price: 0`, trial 14d). Toggle mensual/anual opcional (YAGNI: solo si hay precio anual real).

### 5.4 FAQ (`Faq.astro`)
Acordeón accesible (5–7 preguntas). Preguntas candidatas: ¿sirve para fraccionamiento y condominio?, ¿cómo migro mi Excel?, ¿los vecinos ven sus estados de cuenta?, ¿maneja indiviso/ownership_share?, ¿qué métodos de pago registra?, ¿es seguro/multi-tenant?, ¿cuánto cuesta? Cada par Q/A alimenta el `FAQPage` JSON-LD.

---

## 6. Copy (más vendible y entendible)

- **Marca:** `BRAND = 'Kivo7'` en `site.ts` (ya está). Alinear dominio a `kivo7.com`.
- **Headline Hero:** mantener el ángulo "La administración de tu fraccionamiento, en orden." o reforzar el dolor; A/B en el spec review.
- **Value prop núcleo:** "Dejá el Excel. Cobranza, tesorería y transparencia, automáticas."
- **Tono:** directo, vertical-específico (fraccionamientos/condominios MX), sin relleno. Verbos de acción.
- **Stats (98% / 12h / 0 Excel):** hoy marcados como ilustrativos en código. Mantener como ilustrativos con disclaimer o reemplazar por datos auditados (§8).
- Todo el copy vive en `src/data/site.ts` (tipado). Se extiende el modelo de datos para las 4 secciones nuevas + blog.

---

## 7. SEO, sitemap y blog (técnico)

### 7.1 SEO — extender lo existente (`BaseHead.astro` + `site.ts`)
- **Corregir inconsistencia de marca:** `astro.config.mjs` `site: 'https://kivo7.com'`; CTAs a `app.kivo7.com`; canonical/OG/JSON-LD/sitemap pasan a kivo7.com automáticamente.
- **Agregar structured data:**
  - `FAQPage` (de la sección FAQ).
  - `BreadcrumbList` (Home > Blog > Post en el blog).
  - `Article`/`BlogPosting` por post (headline, datePublished, author, image).
- **Completar:** `twitter:site`/`twitter:creator` (handle a confirmar), `meta.sameAs` con perfiles sociales reales, `application-name`.
- **OG por página:** que cada post genere su propia OG (title/description/image) en vez de la global.

### 7.2 Sitemap — enriquecer (`@astrojs/sitemap`, ya instalado)
- Configurar `lastmod` y `changefreq`/`priority` por tipo de página (home vs blog).
- El sitemap recoge automáticamente `/blog` y `/blog/[slug]` al existir como páginas. Verificar tras el build.
- `robots.txt`: actualizar la línea `Sitemap:` a kivo7.com.

### 7.3 Blog — greenfield (Astro Content Collections)
- `src/content/config.ts` — collection `blog` con schema Zod: `title`, `description`, `pubDate`, `updatedDate?`, `author`, `tags[]`, `heroImage?`, `draft?`.
- Contenido: `src/content/blog/*.md` (markdown plano; sin MDX salvo que se necesiten embeds → YAGNI).
- Páginas:
  - `src/pages/blog/index.astro` — listado (cards on-brand, paper + serif), reusa Nav/Footer/BaseLayout.
  - `src/pages/blog/[slug].astro` — `getStaticPaths()` + render del post, con `Article` JSON-LD, breadcrumb, autor, fecha, tiempo de lectura.
- **3 posts de ejemplo** (SEO-driven, vertical MX, ángulo programmatic/keyword):
  1. **"Cómo calcular la cuota de mantenimiento de un fraccionamiento (con ejemplos)"** — informacional, alto volumen.
  2. **"Morosidad en condominios: 7 estrategias para cobrar las cuotas a tiempo"** — pain-point, mid-funnel.
  3. **"Excel vs. software de administración de condominios: cuándo conviene dar el salto"** — comparativa, bottom-funnel (linkea a la landing).
- Estilo de tipografía de artículo (prose) coherente con la paleta: Hanken para body, Cormorant para títulos.

---

## 8. Decisiones abiertas (a confirmar en la revisión del spec)

Estas NO bloquean el diseño, pero necesito tu input antes de implementar (o me das luz verde a usar mi propuesta/placeholders marcados):

1. **Precios reales** — ¿qué planes y montos? (hoy el JSON-LD dice `price: 0` + trial 14d). Si no los tenés cerrados, armo la sección con estructura de 3 tiers y precios placeholder claramente marcados "por confirmar".
2. **Stats del ResultsBand** — ¿mantenemos 98% / 12h / 0 Excel como ilustrativos (con disclaimer) o tenés datos auditados?
3. **TrustStrip y Quote** — ¿reemplazamos los nombres/testimonio ficticios por reales, los quitamos, o los dejamos marcados como ilustrativos?
4. **Redes sociales / handle Twitter-X** — para `twitter:site` y `sameAs`. ¿Existen? Si no, los omito.
5. **Dominio app** — confirmo CTAs a `https://app.kivo7.com` (es lo que ya está). ✔/✖

---

## 9. Enfoque técnico e impacto en archivos

```
landing/
├── astro.config.mjs            # site → kivo7.com; sitemap lastmod/serialize
├── public/robots.txt           # Sitemap → kivo7.com
├── src/
│   ├── data/site.ts            # +comparison, +howItWorks, +pricing, +faq; BRAND ya = Kivo7
│   ├── content/
│   │   ├── config.ts           # NUEVO — collection 'blog' (schema Zod)
│   │   └── blog/               # NUEVO — 3 .md de ejemplo
│   ├── pages/
│   │   ├── index.astro         # reordenar composición a 12 bloques
│   │   └── blog/
│   │       ├── index.astro     # NUEVO — listado
│   │       └── [slug].astro    # NUEVO — post + Article JSON-LD
│   ├── components/
│   │   ├── BaseHead.astro      # +FAQPage, +Breadcrumb, +Article, +twitter:site, +sameAs
│   │   ├── Hero.astro          # rediseño Dir. A (capas + parallax)
│   │   ├── Nav.astro / SiteFooter.astro  # +link Blog, legal real
│   │   ├── ComparisonExcel.astro  # NUEVO
│   │   ├── HowItWorks.astro        # NUEVO
│   │   ├── Pricing.astro           # NUEVO
│   │   ├── Faq.astro               # NUEVO
│   │   └── BlogCard.astro / PostMeta.astro  # NUEVO (blog)
│   └── styles/global.css       # tokens existentes; +utilidades de profundidad/parallax si hace falta
```

- Mantener el **override de Vite** (`overrides.vite: ^7.3.2`) — no tocar.
- Respetar `astro check` (TS strict). Nada de `any`.
- Reusar `SectionHead`, `Button`, `StatusPill` existentes.

---

## 10. Verificación (cómo sabemos que quedó bien)

- `pnpm build` (`astro check && astro build`) pasa sin errores de tipo.
- `dist/sitemap-0.xml` incluye `/`, `/blog/` y las 3 URLs de posts.
- Validar JSON-LD (FAQPage, Article, Breadcrumb) con el Rich Results Test mentalmente / estructura correcta.
- Lighthouse local (chrome-devtools MCP) sobre la home y un post: Performance/SEO/Accessibility/Best-Practices altos; CLS ~0; LCP razonable.
- `prefers-reduced-motion` activado → sin animaciones, contenido visible.
- Navegación por teclado del FAQ y del Nav.
- Revisión visual responsive (mobile/tablet/desktop) con la landing corriendo en local.

---

## 11. Siguiente paso

Tras tu OK al spec → invocar **writing-plans** para el plan de implementación por fases, y ejecutar en la misma rama sin commitear. Reviewers sugeridos al cierre: `@react-frontend-reviewer` no aplica (es Astro), pero sí una pasada de accesibilidad/SEO y Lighthouse.
