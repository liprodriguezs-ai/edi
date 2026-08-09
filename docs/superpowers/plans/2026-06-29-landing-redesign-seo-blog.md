# Landing Kivo7 — Rediseño + SEO + Blog — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rediseñar la landing de Kivo7 (más impacto, mismo ADN visual), reordenar en una narrativa de venta de 12 bloques, agregar 4 secciones nuevas, extender el SEO con structured data y activar un blog con 3 posts.

**Architecture:** Astro 7 estático + Tailwind v4 (CSS-native `@theme`) en el submódulo `landing/`. Todo el copy vive en `src/data/site.ts` (tipado). Componentes `.astro` presentacionales que consumen ese data. Motion en vanilla JS gated por `prefers-reduced-motion` (patrón ya existente). Blog vía Astro Content Collections (markdown). SEO extiende `BaseHead.astro` con JSON-LD por página.

**Tech Stack:** Astro 7.0.3, Tailwind 4.3.1, `@astrojs/sitemap`, TypeScript strict, pnpm, Node 22.

## Global Constraints

- **Sin commits.** Todos los cambios quedan en el working tree de la rama actual. Los "checkpoints" reemplazan a los commits.
- **Mantener paleta y tipografías.** Tokens en `src/styles/global.css` `@theme` (paper `#eceae4`, ink `#14181a`, sage `#6e7e6a`, etc.). Fuentes Cormorant Garamond (serif) + Hanken Grotesk (sans). No agregar fuentes ni colores nuevos.
- **Marca = Kivo7, dominio canónico = `https://kivo7.com`, app = `https://app.kivo7.com`.**
- **TypeScript strict, prohibido `any`.** El build corre `astro check && astro build`.
- **No tocar** el override `overrides.vite: ^7.3.2` en `package.json` (previene crash doble-Vite).
- **No tocar** `frontend/` ni `backend/`. Solo `landing/`.
- **UI en español (MX).** Reusar componentes existentes: `SectionHead.astro`, `Button.astro`, `StatusPill.astro`, `BaseLayout.astro`.
- **Accesibilidad:** todo motion detrás de `prefers-reduced-motion: reduce`; contraste AA sobre franjas oscuras; foco visible; interactivos navegables por teclado.
- **Defaults de contenido aprobados:** precios placeholder marcados "por confirmar"; stats ilustrativas con disclaimer; TrustStrip/Quote ilustrativos; `twitter:site`/`sameAs` omitidos hasta tener handles.
- **Ciclo de verificación por tarea** (sustituye al TDD/commit): `pnpm astro check` (tipos) → `pnpm build` cuando aplique → chequeo visual en `pnpm dev` (http://localhost:4321).

---

## File Structure

```
landing/
├── astro.config.mjs                 # MOD: site → kivo7.com; sitemap serialize (lastmod)
├── public/robots.txt                # MOD: Sitemap → kivo7.com
├── src/
│   ├── data/site.ts                 # MOD: +comparison, +howItWorks, +pricing, +faq, +blog meta
│   ├── content/
│   │   ├── config.ts                # NEW: collection 'blog' (schema Zod)
│   │   └── blog/
│   │       ├── cuota-mantenimiento-fraccionamiento.md   # NEW
│   │       ├── morosidad-condominios-estrategias.md      # NEW
│   │       └── excel-vs-software-administracion.md       # NEW
│   ├── pages/
│   │   ├── index.astro              # MOD: reordenar a 12 bloques
│   │   └── blog/
│   │       ├── index.astro          # NEW: listado
│   │       └── [slug].astro         # NEW: post + Article JSON-LD
│   ├── components/
│   │   ├── BaseHead.astro           # MOD: props para FAQPage/Breadcrumb/Article; OG por página
│   │   ├── Hero.astro               # MOD: rediseño Dir. A (capas + parallax)
│   │   ├── Nav.astro                # MOD: +link Blog
│   │   ├── SiteFooter.astro         # MOD: +link Blog, legal real
│   │   ├── ComparisonExcel.astro    # NEW
│   │   ├── HowItWorks.astro         # NEW
│   │   ├── Pricing.astro            # NEW
│   │   ├── Faq.astro                # NEW
│   │   ├── BlogCard.astro           # NEW
│   │   └── PostMeta.astro           # NEW (autor + fecha + lectura)
│   └── styles/global.css            # MOD: utilidades de profundidad/parallax si hacen falta
```

---

## Phase 0 — Fundaciones (marca, dominio, data model)

### Task 1: Alinear marca/dominio + sitemap

**Files:**
- Modify: `landing/astro.config.mjs`
- Modify: `landing/public/robots.txt`
- Modify: `landing/src/data/site.ts` (constantes de URL de CTA si están hardcodeadas en componentes — verificar)

**Interfaces:**
- Produces: `site` canónico = `https://kivo7.com` (lo consume `BaseHead.astro` para canonical/OG, y `@astrojs/sitemap` para las URLs).

- [ ] **Step 1: Cambiar el dominio canónico**

En `astro.config.mjs`, setear `site: 'https://kivo7.com'`. Configurar el sitemap con `lastmod` y prioridades:

```js
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
// ...
export default defineConfig({
  site: 'https://kivo7.com',
  integrations: [
    sitemap({
      serialize(item) {
        item.changefreq = item.url === 'https://kivo7.com/' ? 'weekly' : 'monthly';
        item.priority = item.url === 'https://kivo7.com/' ? 1.0 : 0.7;
        item.lastmod = new Date().toISOString();
        return item;
      },
    }),
  ],
  // ...resto sin cambios (passthroughImageService, tailwind vite plugin)
});
```

- [ ] **Step 2: Actualizar robots.txt**

En `public/robots.txt`, cambiar la línea `Sitemap:` a `Sitemap: https://kivo7.com/sitemap-index.xml`.

- [ ] **Step 3: Verificar CTAs**

Grep por `app.kivo7.com` y `residia.mx` en `src/`. Confirmar que los CTA ya apuntan a `https://app.kivo7.com` (el spec lo da por hecho). Si hay algún `residia.mx` hardcodeado en componentes/data, cambiarlo a `kivo7.com`.

```bash
grep -rn "residia.mx\|app.kivo7.com" landing/src landing/public
```

- [ ] **Step 4: Checkpoint**

Run: `cd landing && pnpm astro check` → Esperado: 0 errores. Luego `pnpm build` → `dist/sitemap-0.xml` debe mostrar URLs con `https://kivo7.com` y con `<lastmod>`.

---

### Task 2: Extender el modelo de datos `site.ts`

**Files:**
- Modify: `landing/src/data/site.ts`

**Interfaces:**
- Produces: objetos tipados `comparison`, `howItWorks`, `pricing`, `faq` y `blogMeta`, consumidos por los componentes nuevos y por `BaseHead`.

- [ ] **Step 1: Definir los tipos y el contenido**

Agregar al final de `site.ts` (respetando el estilo tipado existente). Usar exactamente estas formas (las tareas siguientes dependen de estos nombres):

```ts
// ---- Comparativa vs Excel (#4) ----
export interface ComparisonRow { before: string; after: string; }
export const comparison: {
  eyebrow: string; title: string;
  beforeLabel: string; afterLabel: string;
  rows: ComparisonRow[];
} = {
  eyebrow: 'El cambio',
  title: 'Dejá el Excel. En serio.',
  beforeLabel: 'Con Excel',
  afterLabel: `Con ${BRAND}`,
  rows: [
    { before: 'Fórmulas que se rompen y nadie sabe por qué', after: 'Cobranza y saldos calculados solos' },
    { before: 'Pagos que se pierden entre WhatsApps', after: 'Cada pago con folio y comprobante' },
    { before: 'Cierre de mes a mano, 12 horas', after: 'Cierre de mes automático, en minutos' },
    { before: 'Cero transparencia para los vecinos', after: 'Estado de cuenta visible para todos' },
  ],
};

// ---- Cómo funciona (#5) ----
export interface HowStep { n: string; title: string; body: string; }
export const howItWorks: { eyebrow: string; title: string; lede: string; steps: HowStep[] } = {
  eyebrow: 'Cómo funciona',
  title: 'Tu condominio deja el Excel en una semana.',
  lede: 'Tres pasos. Sin migraciones eternas ni curva de aprendizaje.',
  steps: [
    { n: '01', title: 'Creás el clúster', body: 'Alta y configuración guiada del fraccionamiento o condominio en minutos.' },
    { n: '02', title: 'Cargás unidades y residentes', body: 'Torres, secciones y unidades. Vinculás a cada propietario o inquilino.' },
    { n: '03', title: 'Cobrás y reportás solo', body: 'Generás cargos, registrás pagos con FIFO y el estado de cuenta se arma solo.' },
  ],
};

// ---- Precios (#9) — PLACEHOLDER: montos por confirmar ----
export interface PricingTier {
  name: string; priceLabel: string; period: string;
  highlight: boolean; features: string[]; cta: string; href: string;
  note?: string;
}
export const pricing: { eyebrow: string; title: string; lede: string; tiers: PricingTier[]; disclaimer: string } = {
  eyebrow: 'Precios',
  title: 'Un precio simple por clúster.',
  lede: 'Probá gratis 14 días. Sin tarjeta.',
  disclaimer: 'Montos ilustrativos — por confirmar.',
  tiers: [
    { name: 'Inicial', priceLabel: '$—', period: '/mes', highlight: false,
      features: ['Hasta 50 unidades', 'Cobranza y tesorería', 'Estado de cuenta para residentes', 'Soporte por email'],
      cta: 'Empezar gratis', href: 'https://app.kivo7.com', note: 'por confirmar' },
    { name: 'Profesional', priceLabel: '$—', period: '/mes', highlight: true,
      features: ['Unidades ilimitadas', 'Todo lo de Inicial', 'Mesa directiva y roles', 'Reportes y transparencia', 'Soporte prioritario'],
      cta: 'Empezar gratis', href: 'https://app.kivo7.com', note: 'por confirmar' },
    { name: 'Clúster', priceLabel: 'A medida', period: '', highlight: false,
      features: ['Varios fraccionamientos', 'Onboarding asistido', 'Integraciones', 'Account manager'],
      cta: 'Hablar con ventas', href: 'https://app.kivo7.com' },
  ],
};

// ---- FAQ (#10) ----
export interface FaqItem { q: string; a: string; }
export const faq: { eyebrow: string; title: string; items: FaqItem[] } = {
  eyebrow: 'Preguntas frecuentes',
  title: 'Lo que todos preguntan.',
  items: [
    { q: '¿Sirve para fraccionamiento y para condominio?', a: 'Sí. Kivo7 maneja clústeres horizontales (casas), verticales (departamentos) y mixtos.' },
    { q: '¿Cómo migro mi Excel?', a: 'Cargás tus unidades y residentes una vez. No necesitás fórmulas: la cobranza y los saldos se calculan automáticamente.' },
    { q: '¿Los vecinos ven su estado de cuenta?', a: 'Sí. Cada residente ve sus cargos, pagos y saldo con total transparencia.' },
    { q: '¿Maneja indiviso (ownership_share)?', a: 'Sí. Podés cobrar montos proporcionales al porcentaje de copropiedad cuando lo necesites.' },
    { q: '¿Qué métodos de pago registra?', a: 'Efectivo, transferencia, depósito y cheque, cada uno con folio y comprobante.' },
    { q: '¿Es seguro?', a: 'Cada clúster tiene sus datos aislados y auditados. Nadie ve la información de otro.' },
  ],
};

// ---- Blog meta ----
export const blogMeta = {
  title: 'Blog',
  description: `Guías de administración de fraccionamientos y condominios, por ${BRAND}.`,
};
```

- [ ] **Step 2: Checkpoint**

Run: `cd landing && pnpm astro check` → Esperado: 0 errores de tipo.

---

## Phase 1 — Secciones nuevas de marketing

> Patrón común para las 4: componente `.astro` que importa su data de `site.ts`, usa `SectionHead.astro` para eyebrow+título cuando aplique, clases Tailwind de la paleta (`bg-paper`, `text-ink`, `text-sage`, `border-line`, `font-serif`, `rounded-card`), y `.reveal`/`.reveal-d1`/`.reveal-d2` para el motion (ya gated en `BaseLayout`). Verificación de cada tarea: `astro check` + render visual en `pnpm dev`.

### Task 3: `ComparisonExcel.astro` (sección #4, 1er momento oscuro)

**Files:**
- Create: `landing/src/components/ComparisonExcel.astro`

**Interfaces:**
- Consumes: `comparison` de `site.ts`.
- Produces: `<ComparisonExcel />` usado en `index.astro`.

- [ ] **Step 1: Crear el componente**

Split de 2 columnas dentro de `.wrap`. Columna "Con Excel": fondo `bg-paper-2`, ítems con ✕ en terracota (`text-bad`). Columna `Con Kivo7`: **franja oscura** (`bg-ink` con gradiente a `#1d2426`), ítems con ✓ en salvia. Itera `comparison.rows`. Header con `SectionHead` (eyebrow + título). Aplicar `.reveal` a cada fila con stagger (`.reveal-d1`, `.reveal-d2`). Texto sobre oscuro en `text-paper`/`#e9ece6` (contraste AA). Responsive: 1 columna en mobile.

- [ ] **Step 2: Checkpoint** — `pnpm astro check` (0 errores) + visual: las dos columnas se ven, la oscura contrasta, reveals andan, y con `prefers-reduced-motion` el contenido aparece sin animar.

---

### Task 4: `HowItWorks.astro` (sección #5)

**Files:**
- Create: `landing/src/components/HowItWorks.astro`

**Interfaces:**
- Consumes: `howItWorks` de `site.ts`.
- Produces: `<HowItWorks />`.

- [ ] **Step 1: Crear el componente**

`SectionHead` con `howItWorks.eyebrow` + `howItWorks.title` + `howItWorks.lede`. Debajo, grid de 3 pasos (1 col mobile / 3 col desktop). Cada paso: número grande en `font-serif` (`step.n`), título (`font-serif`), body (`text-ink-soft`). Línea/conector sutil entre pasos en desktop (borde `border-line`). `.reveal` con stagger por paso.

- [ ] **Step 2: Checkpoint** — `pnpm astro check` + visual de los 3 pasos responsive.

---

### Task 5: `Pricing.astro` (sección #9)

**Files:**
- Create: `landing/src/components/Pricing.astro`

**Interfaces:**
- Consumes: `pricing` de `site.ts`.
- Produces: `<Pricing />`. El JSON-LD `Offer` se mantiene en `BaseHead` (Task 10) — no duplicar aquí.

- [ ] **Step 1: Crear el componente**

`SectionHead` con título + lede. Grid de 3 tiers (1 col mobile). Cada tier: card `bg-card border-line rounded-card`; el `highlight: true` lleva borde salvia + badge "Recomendado" + leve elevación (`shadow`). Mostrar `name`, `priceLabel` grande en `font-serif` + `period`, lista de `features` con check salvia, y `Button` (`href = tier.href`, variante solid para highlight / ghost para el resto). Si `tier.note`, mostrar "por confirmar" en `text-ink-faint`. Disclaimer general (`pricing.disclaimer`) abajo en chico.

- [ ] **Step 2: Checkpoint** — `pnpm astro check` + visual: 3 planes, el highlight resalta, montos placeholder visibles con su nota.

---

### Task 6: `Faq.astro` (sección #10, acordeón accesible)

**Files:**
- Create: `landing/src/components/Faq.astro`

**Interfaces:**
- Consumes: `faq` de `site.ts`.
- Produces: `<Faq />`. Las preguntas alimentan el `FAQPage` JSON-LD (Task 10), que lee el mismo `faq` import.

- [ ] **Step 1: Crear el acordeón con `<details>`/`<summary>`**

Usar `<details>` nativo (accesible por teclado sin JS). Cada item: `<details class="...border-line...">` con `<summary>` (pregunta, `font-serif`/medium, cursor pointer, ícono chevron que rota con `[open]`) y el body (`faq.items[].a`, `text-ink-soft`). `SectionHead` arriba. Asegurar foco visible en el `<summary>`. Opcional: un toque de JS gated para animar la apertura — si no, dejar nativo (preferible, YAGNI).

- [ ] **Step 2: Checkpoint** — `pnpm astro check` + visual: abre/cierra con click y con Enter/Espacio; foco visible; chevron rota.

---

## Phase 2 — Hero, reorden e impacto

### Task 7: Rediseño del Hero (Dir. A — editorial en capas)

**Files:**
- Modify: `landing/src/components/Hero.astro`
- Modify (si hace falta): `landing/src/components/AppWindow.astro`
- Modify (si se agregan utilidades/JS): `landing/src/styles/global.css`, `landing/src/layouts/BaseLayout.astro`

**Interfaces:**
- Consumes: data de hero ya existente en `site.ts` (badge, H1, lede, CTAs, proofs) + `hero-dashboard.png`.
- Produces: Hero con layout split asimétrico.

- [ ] **Step 1: Reestructurar a split asimétrico**

Layout 2 columnas en desktop (`grid`, ~`1.05fr / 1fr`), 1 columna apilada en mobile. Izquierda: badge eyebrow, H1 `font-serif` (subir un escalón de tamaño para más impacto), lede, 2 CTAs (`Button` solid + ghost), 3 micro-proofs con check salvia. Derecha: **stack de capas** — la `AppWindow` (browser chrome + `hero-dashboard.png`) como capa principal, más 1–2 tarjetas/chips flotantes offset detrás/delante con `box-shadow` suave y un chip de dato (`+ $48,200`, `98% al día`). Mantener accesibilidad del `alt` de la imagen.

- [ ] **Step 2: Parallax leve gated**

Agregar parallax sutil a las capas de la derecha: en scroll, `translateY` pequeño (factor distinto por capa) vía vanilla JS con `requestAnimationFrame`, **envuelto en** `if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches)`. Seguir el patrón del JS de reveal/countup ya existente en `BaseLayout.astro`. Sin librerías.

- [ ] **Step 3: Checkpoint** — `pnpm astro check` + visual: split se ve premium, capas con profundidad, parallax suave en scroll; con reduced-motion las capas quedan estáticas y todo visible. Responsive mobile OK.

---

### Task 8: Reordenar `index.astro` a 12 bloques + Nav/Footer

**Files:**
- Modify: `landing/src/pages/index.astro`
- Modify: `landing/src/components/Nav.astro`
- Modify: `landing/src/components/SiteFooter.astro`

**Interfaces:**
- Consumes: todos los componentes (existentes + nuevos Tasks 3–6).
- Produces: la home en el orden final.

- [ ] **Step 1: Componer el orden final**

`index.astro`:
```astro
<BaseLayout>
  <Nav />
  <Hero />
  <TrustStrip />
  <ComparisonExcel />
  <HowItWorks />
  <Bento />
  <ResultsBand />
  <Quote />
  <Pricing />
  <Faq />
  <CTA />
  <SiteFooter />
</BaseLayout>
```
Agregar los imports de los 4 componentes nuevos.

- [ ] **Step 2: Nav + Footer**

`Nav.astro`: agregar link "Blog" → `/blog` (junto a Plataforma/Transparencia/Resultados). `SiteFooter.astro`: agregar link "Blog" → `/blog`; revisar los links legales (hoy Privacidad/Términos van a `#`) — dejarlos a `#` con marca de pendiente o apuntarlos a rutas reales si existen (si no existen, mantener `#` y anotar). Marcar TrustStrip/Quote como ilustrativos si hay un lugar natural (caption pequeño) — opcional, no romper diseño.

- [ ] **Step 3: Checkpoint** — `pnpm build` completo → home renderiza las 12 secciones en orden, sin errores. Nav y Footer muestran "Blog".

---

### Task 9: Pulido del sistema de impacto (ritmo de contraste + reveals)

**Files:**
- Modify (según haga falta): `landing/src/styles/global.css`, secciones nuevas.

- [ ] **Step 1: Verificar el ritmo "Equilibrado"**

Confirmar 3 momentos oscuros y bien espaciados: ComparisonExcel (#4), ResultsBand (#7, ya oscuro), CTA (#11, ya oscuro). El resto en paper. Si dos oscuros quedan muy juntos, ajustar. Asegurar que las secciones nuevas tengan `.reveal` con stagger consistente con el resto.

- [ ] **Step 2: Checkpoint** — visual full-page scroll: el contraste da ritmo sin saturar; transiciones suaves; reduced-motion limpio.

---

## Phase 3 — SEO structured data

### Task 10: Extender `BaseHead.astro` (FAQPage, Breadcrumb, Article, OG por página)

**Files:**
- Modify: `landing/src/components/BaseHead.astro`

**Interfaces:**
- Consumes: nuevas props opcionales + `faq` de `site.ts`.
- Produces: JSON-LD condicional por página. Lo consumen `index.astro` (FAQ) y `blog/[slug].astro` (Article + Breadcrumb).

- [ ] **Step 1: Agregar props opcionales a `BaseHead`**

Extender el frontmatter para aceptar (todas opcionales, sin romper el uso actual):
```ts
interface Props {
  title?: string; description?: string; image?: string;   // OG por página
  includeFaq?: boolean;                                    // inyecta FAQPage desde `faq`
  article?: { title: string; description: string; datePublished: string; dateModified?: string; author: string; image?: string; };
  breadcrumb?: { name: string; url: string }[];
}
```
- OG/Twitter: usar `title`/`description`/`image` de props si vienen; si no, los globales de `site.ts` (comportamiento actual).
- Si `includeFaq`, agregar un `<script type="application/ld+json">` con `@type: FAQPage` mapeando `faq.items` → `mainEntity[].acceptedAnswer`.
- Si `article`, agregar `BlogPosting`/`Article` JSON-LD.
- Si `breadcrumb`, agregar `BreadcrumbList`.
- Mantener el `@graph` existente (Organization + WebSite + SoftwareApplication). `twitter:site`/`sameAs`: **omitidos** (sin handles) — dejar comentario `// TODO: agregar cuando haya handles`.

- [ ] **Step 2: Activar FAQ en la home**

En `index.astro` (o en el `BaseLayout`/head correspondiente), pasar `includeFaq={true}` para que la home emita el `FAQPage`.

- [ ] **Step 3: Checkpoint** — `pnpm build`; inspeccionar el `dist/index.html`: el JSON-LD `FAQPage` está presente y bien formado (las 6 Q/A). Validar mentalmente contra el formato de Rich Results.

---

## Phase 4 — Blog

### Task 11: Content collection + 3 posts

**Files:**
- Create: `landing/src/content/config.ts`
- Create: `landing/src/content/blog/cuota-mantenimiento-fraccionamiento.md`
- Create: `landing/src/content/blog/morosidad-condominios-estrategias.md`
- Create: `landing/src/content/blog/excel-vs-software-administracion.md`

**Interfaces:**
- Produces: collection `blog` con entries tipados (consumida por las páginas del blog).

- [ ] **Step 1: Definir la collection**

```ts
// src/content/config.ts
import { defineCollection, z } from 'astro:content';

const blog = defineCollection({
  type: 'content',
  schema: z.object({
    title: z.string(),
    description: z.string(),
    pubDate: z.coerce.date(),
    updatedDate: z.coerce.date().optional(),
    author: z.string().default('Equipo Kivo7'),
    tags: z.array(z.string()).default([]),
    heroImage: z.string().optional(),
    draft: z.boolean().default(false),
  }),
});

export const collections = { blog };
```

- [ ] **Step 2: Escribir los 3 posts**

Cada `.md` con frontmatter (`title`, `description` SEO ~155 chars, `pubDate`, `author`, `tags`) y cuerpo real de 600–900 palabras, en español MX, con H2/H3, listas y un CTA suave a la app al final. Temas y slugs:
1. `cuota-mantenimiento-fraccionamiento.md` — "Cómo calcular la cuota de mantenimiento de un fraccionamiento (con ejemplos)". Tags: `['cobranza','administración']`.
2. `morosidad-condominios-estrategias.md` — "Morosidad en condominios: 7 estrategias para cobrar las cuotas a tiempo". Tags: `['morosidad','cobranza']`.
3. `excel-vs-software-administracion.md` — "Excel vs. software de administración de condominios: cuándo conviene dar el salto". Tags: `['excel','software']`. Linkea a `/` (la landing) en el cierre.

- [ ] **Step 3: Checkpoint** — `pnpm astro check` reconoce la collection y valida el frontmatter de los 3 posts sin errores.

---

### Task 12: Listado del blog `/blog`

**Files:**
- Create: `landing/src/pages/blog/index.astro`
- Create: `landing/src/components/BlogCard.astro`

**Interfaces:**
- Consumes: `getCollection('blog')`, `blogMeta`, `Nav`, `SiteFooter`, `BaseLayout`.
- Produces: página `/blog`.

- [ ] **Step 1: `BlogCard.astro`**

Card on-brand (`bg-card border-line rounded-card`): título `font-serif`, `description` `text-ink-soft`, fecha formateada (`pubDate`), tags como chips salvia suaves. Toda la card linkea a `/blog/{slug}`. Hover con leve elevación.

- [ ] **Step 2: `blog/index.astro`**

```astro
---
import BaseLayout from '@/layouts/BaseLayout.astro';
import Nav from '@/components/Nav.astro';
import SiteFooter from '@/components/SiteFooter.astro';
import BlogCard from '@/components/BlogCard.astro';
import { blogMeta } from '@/data/site';
import { getCollection } from 'astro:content';

const posts = (await getCollection('blog', ({ data }) => !data.draft))
  .sort((a, b) => b.data.pubDate.valueOf() - a.data.pubDate.valueOf());
---
<BaseLayout title={`${blogMeta.title} · Kivo7`} description={blogMeta.description}>
  <Nav />
  <main class="wrap" style="padding-top: 7rem;">
    <header class="reveal">
      <p class="eyebrow">{blogMeta.title}</p>
      <h1 class="font-serif">Administrar tu clúster, sin misterios.</h1>
    </header>
    <div class="grid"> {posts.map((p) => <BlogCard post={p} />)} </div>
  </main>
  <SiteFooter />
</BaseLayout>
```
(Ajustar clases/grid a las utilidades reales del proyecto; reusar `eyebrow` si existe o `SectionHead`.)

- [ ] **Step 3: Checkpoint** — `pnpm build` genera `/blog/index.html` con las 3 cards ordenadas por fecha desc.

---

### Task 13: Página de post `/blog/[slug]`

**Files:**
- Create: `landing/src/pages/blog/[slug].astro`
- Create: `landing/src/components/PostMeta.astro`

**Interfaces:**
- Consumes: `getCollection('blog')`, `BaseHead` (props `article` + `breadcrumb`), `render()` del entry.
- Produces: páginas `/blog/{slug}` con Article + Breadcrumb JSON-LD.

- [ ] **Step 1: `PostMeta.astro`** — autor + fecha formateada + tiempo de lectura estimado (palabras/200). Estilo `text-ink-faint`, chico.

- [ ] **Step 2: `blog/[slug].astro`**

```astro
---
import BaseLayout from '@/layouts/BaseLayout.astro';
import Nav from '@/components/Nav.astro';
import SiteFooter from '@/components/SiteFooter.astro';
import PostMeta from '@/components/PostMeta.astro';
import { getCollection } from 'astro:content';

export async function getStaticPaths() {
  const posts = await getCollection('blog', ({ data }) => !data.draft);
  return posts.map((post) => ({ params: { slug: post.slug }, props: { post } }));
}
const { post } = Astro.props;
const { Content } = await post.render();
const url = `https://kivo7.com/blog/${post.slug}`;
---
<BaseLayout
  title={`${post.data.title} · Kivo7`}
  description={post.data.description}
  article={{
    title: post.data.title,
    description: post.data.description,
    datePublished: post.data.pubDate.toISOString(),
    dateModified: (post.data.updatedDate ?? post.data.pubDate).toISOString(),
    author: post.data.author,
    image: post.data.heroImage,
  }}
  breadcrumb={[
    { name: 'Inicio', url: 'https://kivo7.com/' },
    { name: 'Blog', url: 'https://kivo7.com/blog' },
    { name: post.data.title, url },
  ]}
>
  <Nav />
  <main class="wrap" style="padding-top: 7rem; max-width: 720px;">
    <a href="/blog" class="eyebrow">← Blog</a>
    <h1 class="font-serif">{post.data.title}</h1>
    <PostMeta author={post.data.author} pubDate={post.data.pubDate} body={post.body} />
    <article class="prose"> <Content /> </article>
  </main>
  <SiteFooter />
</BaseLayout>
```
> Nota: `BaseLayout` debe forwardear `title/description/article/breadcrumb` a `BaseHead`. Verificar la firma de `BaseLayout` y extender sus props si hace falta (mismas props opcionales del Task 10).

- [ ] **Step 3: Estilos `prose`** — agregar a `global.css` reglas mínimas para el contenido del artículo (h2/h3 `font-serif`, párrafos Hanken con interlineado cómodo, listas, links salvia, `max-width` de lectura). Coherente con la paleta.

- [ ] **Step 4: Checkpoint** — `pnpm build` genera `/blog/{slug}.html` para los 3 posts; el HTML incluye `Article` y `BreadcrumbList` JSON-LD; el contenido se ve legible.

---

## Phase 5 — Verificación final

### Task 14: Build, SEO, accesibilidad y responsive

**Files:** ninguno (solo verificación).

- [ ] **Step 1: Build limpio** — `cd landing && pnpm build` (corre `astro check && astro build`) → 0 errores de tipo, build OK.

- [ ] **Step 2: Sitemap** — `dist/sitemap-0.xml` incluye `https://kivo7.com/`, `/blog/` y las 3 URLs de posts, con `<lastmod>`.

- [ ] **Step 3: Structured data** — inspeccionar `dist/index.html` (FAQPage + el @graph) y un `dist/blog/*.html` (Article + Breadcrumb). Bien formados.

- [ ] **Step 4: Lighthouse** (chrome-devtools MCP, `pnpm preview` o `dev`) sobre home y un post: SEO/Best-Practices/Accessibility altos, CLS ~0, LCP razonable. Anotar hallazgos.

- [ ] **Step 5: Accesibilidad** — `prefers-reduced-motion: reduce` → sin animaciones, todo visible. Navegación por teclado del Nav y del FAQ (`<details>` + Enter/Espacio). Foco visible. Contraste AA en franjas oscuras.

- [ ] **Step 6: Responsive** — mobile/tablet/desktop: Hero apila bien, Comparativa y Pricing pasan a 1 columna, blog cards fluyen. Sin overflow horizontal.

- [ ] **Step 7: Reporte** — resumen de qué quedó, qué falta (las 5 decisiones abiertas pendientes de datos reales), y screenshots/notas de Lighthouse.

---

## Self-Review (cobertura del spec)

- §3 IA 12 bloques → Tasks 8 (orden) + 3–6 (secciones nuevas). ✔
- §4 sistema de impacto → Tasks 7 (hero) + 9 (ritmo) + reveals en cada sección. ✔
- §5 secciones nuevas → Tasks 3–6. ✔
- §6 copy/marca → Tasks 1 (marca) + 2 (copy en data). ✔
- §7.1 SEO structured data → Task 10. ✔
- §7.2 sitemap → Task 1. ✔
- §7.3 blog → Tasks 11–13. ✔
- §8 decisiones abiertas → defaults aplicados (placeholders marcados, ilustrativos, omitir social). ✔
- §10 verificación → Task 14. ✔

Sin placeholders de plan (los "$—"/"por confirmar" son contenido intencional aprobado, no TODOs de plan). Tipos consistentes (`comparison`, `howItWorks`, `pricing`, `faq`, props de `BaseHead`).
