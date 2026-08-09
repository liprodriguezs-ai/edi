# Residia — Landing

Landing page de **Residia**, el SaaS de administración de organizacións y
organizaciones (cobranza, tesorería, morosidad, mesa directiva, transparencia).

Construida a partir del handoff de diseño de Claude Design (variante
**"Residia — Landing Bento"**, dirección _A · Salvia_) y reproducida de forma
fiel en Astro + Tailwind, componentizada por sección.

> Nota de marca: el repositorio principal documenta el producto como _EDI_;
> el diseño entregado para esta landing usa la marca **Residia**. La landing
> respeta el diseño. Si la marca definitiva es otra, se cambia en
> `src/data/site.ts` (un solo lugar).

## Stack

| Herramienta | Versión | Rol |
|---|---|---|
| Astro | ^7.0.3 | Framework de sitio estático |
| Tailwind CSS | ^4.3.1 | Estilos (vía `@tailwindcss/vite`) |
| @astrojs/sitemap | ^3.7.3 | `sitemap-index.xml` automático |
| TypeScript | ^5.9 | Tipado estricto (sin `any`) |

### Node

Astro 7 requiere **Node ≥ 22.12**. Hay un `.nvmrc` (Node 22):

```bash
fnm use      # o: nvm use
```

> **Compatibilidad Vite (histórico):** cuando la landing corría sobre Astro 6
> (Vite 7) había un choque con `@tailwindcss/vite` 4.3 (Vite 8) y se intentó
> pinear Vite con un `overrides` en `package.json`. Ese conflicto **ya no
> existe**: Astro 7 exige Vite 8 y `@tailwindcss/vite` lo acepta, así que ambos
> convergen en una sola versión. El override se eliminó en 2026-08 porque era
> peligroso: pnpm nunca lo aplicó (pnpm ≥ 10 lee overrides desde
> `pnpm-workspace.yaml`), pero npm sí lo honra, y forzar Vite 7 bajo Astro 7
> rompe el build. **Usa siempre pnpm en este repo, nunca `npm install`.**
> Para verificar el árbol: `pnpm why vite` debe reportar una sola versión 8.x.

## Comandos

```bash
npm install        # instalar dependencias
npm run dev        # servidor de desarrollo (http://localhost:4321)
npm run build      # astro check + build estático a dist/
npm run preview    # previsualizar el build de dist/
```

## Variables de entorno

| Variable | Default | Rol |
|---|---|---|
| `PUBLIC_APP_URL` | `https://app.edi.example.com` | URL de la app a donde apuntan los CTAs de "empezar/iniciar" (nav, hero y CTA final). Se consume en `src/data/site.ts`; si no se define, usa el default. |
| `PUBLIC_API_BASE_URL` | `http://localhost:8000/api` | Base de la API pública del backend que consume el **Cotizador** (`src/components/PricingQuoter.astro`): `GET /billing/catalog` y `POST /billing/quote`. Si no se define, usa el default de desarrollo local. |

Crear un `.env` (gitignoreado) en la raíz de `landing/` para sobrescribir, por
ejemplo en staging:

```bash
PUBLIC_APP_URL=https://app.edi.example.com
PUBLIC_API_BASE_URL=https://api.edi.example.com/api
```

Ver `.env.example` para la lista completa de variables.

## Estructura

```
src/
├── data/site.ts          # FUENTE ÚNICA de todos los copys (editable en un lugar)
├── styles/global.css     # tokens de diseño (@theme), base, reveal, skip-link, focus
├── layouts/
│   └── BaseLayout.astro   # shell HTML, <head>, landmarks, scripts (reveal + contadores)
├── components/
│   ├── BaseHead.astro     # SEO: title/description, canonical, OG, Twitter, JSON-LD
│   ├── Nav.astro          # nav flotante tipo píldora
│   ├── Hero.astro         # hero centrado + product shot
│   ├── AppWindow.astro    # mock del panel (role="img" — ilustrativo)
│   ├── TrustStrip.astro   # organizaciónes que confían
│   ├── SectionHead.astro  # encabezado de sección reutilizable
│   ├── Bento.astro        # grid bento (6 columnas)
│   ├── BentoArt.astro     # mini-UIs de cada tarjeta (unión discriminada tipada)
│   ├── StatusPill.astro   # pill de estado financiero (color + texto, nunca solo color)
│   ├── ResultsBand.astro  # banda oscura con contadores animados
│   ├── Quote.astro        # testimonio (figure/blockquote)
│   ├── CTA.astro          # tarjeta CTA con degradado verde
│   ├── SiteFooter.astro   # pie
│   └── Button.astro       # botón/enlace con variantes
└── pages/index.astro      # composición de la landing
public/
├── og.png                 # imagen Open Graph 1200×630 (de marca)
├── favicon.svg
└── robots.txt
```

## Sistema de diseño

Tokens en `src/styles/global.css` dentro de `@theme`, así Tailwind genera las
utilidades (`bg-paper`, `text-ink`, `font-serif`, `rounded-card`, `shadow-card`…)
y a la vez quedan disponibles como variables CSS. Escala de espaciado de 8px
mapeada 1:1 a la escala numérica de Tailwind (16=4, 24=6, 32=8, 48=12, 64=16,
96=24, 128=32).

- **Tipografía:** Hanken Grotesk (toda la UI) + Cormorant Garamond (display/wordmark).
- **Paleta:** neutrales cálidos de papel + verde salvia + estados semánticos.

## Accesibilidad (WCAG 2.1 AA)

Auditado con **axe-core 4.10**: 0 violaciones. Incluye:

- HTML semántico + landmarks (`header`/`nav`/`main`/`footer`), skip-link,
  jerarquía de encabezados (1×`h1`, secciones con `h2`, tarjetas con `h3`).
- Foco visible (`:focus-visible`) on-brand; nunca se elimina el `outline`.
- El estado financiero se comunica con **color + texto** (nunca solo color).
- Animaciones respetan `prefers-reduced-motion`; el contenido es legible sin JS.
- El mock del panel se expone como una sola imagen (`role="img"` + `aria-label`).

**Desviaciones deliberadas vs. el prototipo** (para cumplir AA 4.5:1):

- `--ink-faint` pasó de `#8A8C82` (2.83:1) a `#686A60` (~4.57:1).
- Texto de pill _ámbar_ oscurecido a `#785B17`.
- Avatar de acento y badge "PDF" usan `sage-deep` en vez de `sage` (blanco 5.85:1).
- Se quitó la opacidad de los nombres del trust strip (subía a 4.31:1).

## SEO

- `<title>` + meta description self-contained, canonical, `robots`.
- Open Graph + Twitter Card con imagen 1200×630.
- JSON-LD: `Organization` + `WebSite` + `SoftwareApplication`.
- `sitemap-index.xml` (build) y `robots.txt` que no bloquea crawlers de IA.

> Antes de publicar: confirmar el dominio canónico en `astro.config.mjs` (`site`)
> y sustituir las métricas ilustrativas (98%, 12 hrs, −38%) por datos auditados.
