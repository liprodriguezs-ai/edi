// @ts-check
import { defineConfig, passthroughImageService } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import starlight from '@astrojs/starlight';
import tailwindcss from '@tailwindcss/vite';

// Canonical production origin. Update if the marketing site ships on another host.
const SITE = 'https://edi.example.com';

/**
 * Grupo de la barra lateral de /docs poblado desde una carpeta de contenido.
 * @param {string} label   Etiqueta visible (en español, como el menú de la app).
 * @param {string} dir     Carpeta bajo `src/content/docs/docs/`.
 * @param {boolean} [collapsed=true] Si arranca plegado.
 */
const group = (label, dir, collapsed = true) => ({
  label,
  collapsed,
  items: [{ autogenerate: { directory: `docs/${dir}` } }],
});

// https://astro.build/config
export default defineConfig({
  site: SITE,
  trailingSlash: 'ignore',
  // Inline el CSS en el <head> en vez de servirlo como <link> render-blocking.
  build: {
    inlineStylesheets: 'always',
  },
  // Starlight hace `prefetch: config.prefetch ?? { prefetchAll: true }`, así que
  // sin esto activaría prefetch en TODA la landing (home, blog, legales) y
  // cambiaría su perfil de red. `false` no es nullish → sobrevive a ese `??`.
  prefetch: false,
  integrations: [
    starlight({
      title: 'Documentación',
      titleDelimiter: '·',
      description:
        'Manual de uso de EDI: cobranza, tesorería, accesos, paquetería y todo lo que necesitas para administrar tu fraccionamiento.',

      // Una sola raíz en español: sin prefijo /es en las URLs y <html lang="es-MX">.
      defaultLocale: 'root',
      locales: { root: { label: 'Español', lang: 'es-MX' } },

      // El 404 de la landing (src/pages/404.astro) manda. Sin esto Starlight
      // inyecta su propia ruta `404` y dependeríamos de la prioridad de rutas.
      disable404Route: true,

      favicon: '/favicon.svg',
      // Depende de `git log`; Cloudflare Pages clona en shallow → sería inexacto.
      lastUpdated: false,
      credits: false,
      pagination: true,

      // Tema de marca. fonts.css PRIMERO: define las @font-face que docs.css usa.
      // NUNCA agregar global.css aquí — es el único vector por el que Tailwind y
      // los tokens de la landing se colarían dentro de /docs.
      customCss: ['./src/styles/fonts.css', './src/styles/docs.css'],

      // Light-only, igual que la landing: un solo tema de resaltado, sin toggle.
      expressiveCode: {
        themes: ['github-light'],
        styleOverrides: {
          borderRadius: '10px',
          borderColor: '#D8D4C9',
          codeBackground: '#F6F5F1',
        },
      },

      components: {
        SiteTitle: './src/components/docs/SiteTitle.astro',
        ThemeSelect: './src/components/docs/Empty.astro',
        Footer: './src/components/docs/Footer.astro',
      },

      // Desde Starlight 0.39 un grupo con `label` DEBE llevar `items`, y el
      // `autogenerate` va adentro. El orden dentro de cada grupo lo decide el
      // `sidebar.order` del frontmatter de cada página.
      sidebar: [
        group('Primeros pasos', 'empezar', false),
        group('Agenda', 'agenda'),
        group('Expediente del niño', 'expediente'),
        group('Estructura del organización', 'estructura'),
        group('Cobranza', 'cobranza'),
        group('Tesorería', 'tesoreria'),
        group('Mesa directiva y personal', 'mesa-y-personal'),
        group('Comunicación', 'comunicacion'),
        group('Control de accesos', 'accesos'),
        group('Paquetería', 'paqueteria'),
        group('Amenidades y reservaciones', 'amenidades'),
        group('Proyectos comunitarios', 'proyectos'),
        group('Incidencias', 'incidencias'),
        group('Reportes y tableros', 'reportes'),
        group('Configuración del organización', 'configuracion'),
        group('Tu cuenta y suscripción', 'cuenta'),
        group('Roles y permisos', 'roles'),
        group('Ayuda', 'ayuda'),
      ],
    }),

    // El nuestro se queda: Starlight detecta la integración por nombre
    // (`@astrojs/sitemap`) y NO añade la suya, así que no hay duplicado.
    sitemap({
      // Stamp every entry with a build-time lastmod — the one hint search
      // engines actually consume (changefreq/priority are ignored today).
      serialize(item) {
        item.lastmod = new Date().toISOString();
        return item;
      },
    }),
  ],
  // Serve the product screenshots as-is. The default service needs Sharp; the
  // shots are already cropped/sized for the layout, so skip transcoding and the
  // extra dependency. Swap to the Sharp service if/when we want webp + srcset.
  image: {
    service: passthroughImageService(),
  },
  vite: {
    // No inlinear assets como data: URIs → las fuentes quedan como archivos
    // self-hosted cacheables (mejor caché + CSP más estricta).
    build: {
      assetsInlineLimit: 0,
      // Piso de compatibilidad EXPLÍCITO: Safari/iOS 15.4 (el umbral natural de
      // la landing — @layer, <dialog> y crypto.randomUUID coinciden ahí). Sin
      // esto el piso era safari16 por default de Vite 7, decidido por nadie.
      target: ['safari15.4', 'chrome100', 'firefox100', 'edge100'],
    },
    plugins: [tailwindcss()],
  },
});
