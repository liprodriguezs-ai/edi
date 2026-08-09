// NOTE: `z` from astro:content emite un hint cosmético ts(6385) "z is deprecated"
// (re-export de zod del toolchain de Astro). No es error/warning ni afecta el
// build/runtime; importar desde astro:schema da el mismo hint. Import canónico.
import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';
import { docsLoader } from '@astrojs/starlight/loaders';
import { docsSchema } from '@astrojs/starlight/schema';

/**
 * Blog collection (Content Layer API). Markdown lives in src/content/blog/*.md.
 * Entry `id` = filename without extension → used as the /blog/[slug] route.
 */
const blog = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/blog' }),
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

/**
 * Docs collection (Starlight). El contenido vive en `src/content/docs/docs/**`
 * — el subdirectorio extra es a propósito: el slug de cada entrada es su ruta
 * relativa a `src/content/docs/`, así que `docs/cobranza/pagos.md` termina en
 * `/docs/cobranza/pagos`.
 *
 * NUNCA crear `src/content/docs/index.md`: su slug sería '' y chocaría con
 * `src/pages/index.astro` (la home de la landing).
 */
const docs = defineCollection({
  loader: docsLoader(),
  schema: docsSchema(),
});

export const collections = { blog, docs };
