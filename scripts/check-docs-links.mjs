#!/usr/bin/env node
/**
 * Verifica que TODO enlace del registro de la app apunte a una página real del
 * manual.
 *
 * El problema que resuelve: los enlaces de una interfaz hacia su documentación
 * se pudren en silencio. Alguien reorganiza el manual, el enlace queda
 * apuntando a un 404, y nadie se entera hasta que lo reporta un usuario. Aquí
 * eso falla de inmediato.
 *
 *   node scripts/check-docs-links.mjs
 *
 * Sale con código 1 si hay enlaces rotos. El hook `Stop` del repo lo corre solo
 * cuando se toca el registro o el contenido del manual.
 */

import { readFile, readdir, access } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const REGISTRY = join(ROOT, 'frontend/src/shared/docs/docsRegistry.ts');
const DOCS_DIR = join(ROOT, 'landing/src/content/docs/docs');

const exists = (p) => access(p).then(() => true).catch(() => false);

/** Rutas `/docs/...` que aparecen como valor en el registro. */
async function urlsFromRegistry() {
  const src = await readFile(REGISTRY, 'utf8');
  // Solo los literales de cadena que empiezan con /docs — ignora comentarios
  // porque estos usan `kivo7.com/docs` sin comilla inicial.
  const found = new Map();
  const re = /^\s*(?:'([^']+)'|"([^"]+)"|([A-Za-z_][\w-]*))\s*:\s*'(\/docs[^']*)'/gm;
  let m;
  while ((m = re.exec(src)) !== null) {
    const key = m[1] ?? m[2] ?? m[3];
    found.set(key, m[4]);
  }
  // La portada se declara aparte
  const home = src.match(/DOCS_HOME\s*=\s*'([^']+)'/);
  if (home) found.set('(respaldo DOCS_HOME)', home[1]);
  return found;
}

/**
 * Slug de un encabezado, imitando a `github-slugger` (el que usa Starlight):
 * minúsculas, sin puntuación, espacios por guiones, acentos conservados.
 * Un encabezado repetido recibe sufijo `-1`, `-2`, … igual que en el sitio.
 */
function headingSlug(text) {
  return text
    .trim()
    .toLowerCase()
    .replace(/`([^`]*)`/g, '$1') // código en línea
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1') // enlaces
    .replace(/[*_~]/g, '') // negrita, cursiva, tachado
    .replace(/[^\p{L}\p{N}\s-]/gu, '') // resto de puntuación
    .replace(/\s+/g, '-')
    .replace(/^-+|-+$/g, '')
}

/**
 * Páginas del manual y los encabezados de cada una.
 * Devuelve `Map<url, Set<slug>>` — la clave sirve como "la página existe" y el
 * valor permite validar las anclas.
 */
async function pagesOnDisk() {
  const out = new Map();

  async function walk(dir, prefix) {
    let items;
    try {
      items = await readdir(dir, { withFileTypes: true });
    } catch {
      return;
    }
    for (const it of items) {
      if (it.isDirectory()) {
        await walk(join(dir, it.name), `${prefix}/${it.name}`);
        continue;
      }
      if (!/\.mdx?$/.test(it.name)) continue;

      const slug = it.name.replace(/\.mdx?$/, '');
      const url = slug === 'index' ? prefix || '/docs' : `${prefix}/${slug}`;

      const src = await readFile(join(dir, it.name), 'utf8');
      const anchors = new Set();
      const seen = new Map();
      let inFence = false;

      for (const line of src.split('\n')) {
        // Ignorar encabezados dentro de bloques de código
        if (/^\s*(```|~~~)/.test(line)) {
          inFence = !inFence;
          continue;
        }
        if (inFence) continue;

        const m = line.match(/^(#{2,4})\s+(.+)$/);
        if (!m) continue;

        const base = headingSlug(m[2]);
        if (!base) continue;
        const n = seen.get(base) ?? 0;
        seen.set(base, n + 1);
        anchors.add(n === 0 ? base : `${base}-${n}`);
      }

      out.set(url, anchors);
    }
  }

  await walk(DOCS_DIR, '/docs');
  return out;
}

/** Enlaces `/docs/...` (con o sin ancla) escritos DENTRO del manual. */
async function linksInsideDocs() {
  const found = [];

  async function walk(dir, prefix) {
    let items;
    try {
      items = await readdir(dir, { withFileTypes: true });
    } catch {
      return;
    }
    for (const it of items) {
      if (it.isDirectory()) {
        await walk(join(dir, it.name), `${prefix}/${it.name}`);
        continue;
      }
      if (!/\.mdx?$/.test(it.name)) continue;

      const src = await readFile(join(dir, it.name), 'utf8');
      const re = /(?:href="|\]\()(\/docs[^")\s]*)/g;
      let m;
      while ((m = re.exec(src)) !== null) {
        found.push({ from: `${prefix}/${it.name}`.replace('/docs/', ''), url: m[1] });
      }
    }
  }

  await walk(DOCS_DIR, '/docs');
  return found;
}

/**
 * Comprueba un destino contra las páginas y sus encabezados.
 * Devuelve el motivo del fallo, o `null` si está bien.
 */
function checkTarget(raw, pages) {
  const [pathPart, anchor] = raw.split('#');
  const url = pathPart.replace(/\/$/, '') || '/docs';

  if (!pages.has(url)) return 'la página no existe';
  if (anchor && !pages.get(url).has(decodeURIComponent(anchor))) {
    return 'el encabezado no existe';
  }
  return null;
}

async function main() {
  if (!(await exists(REGISTRY))) {
    console.log('  · sin registro de enlaces (frontend/src/shared/docs/docsRegistry.ts) — nada que revisar');
    return 0;
  }
  if (!(await exists(DOCS_DIR))) {
    console.log('  · el submódulo landing/ no está checkouteado — no puedo verificar los enlaces');
    return 0;
  }

  const pages = await pagesOnDisk();
  const registry = await urlsFromRegistry();
  const internal = await linksInsideDocs();

  // --- 1. Registro de la app -------------------------------------------------
  const brokenRegistry = [];
  for (const [key, url] of registry) {
    const why = checkTarget(url, pages);
    if (why) brokenRegistry.push([key, url, why]);
  }

  // --- 2. Enlaces dentro del propio manual -----------------------------------
  // Se revisan las anclas además de la página: renombrar un encabezado es más
  // frecuente que renombrar una página, y deja el enlace apuntando al vacío.
  const brokenInternal = [];
  const seen = new Set();
  for (const { from, url } of internal) {
    const why = checkTarget(url, pages);
    if (!why) continue;
    const k = `${from}::${url}`;
    if (seen.has(k)) continue;
    seen.add(k);
    brokenInternal.push([from, url, why]);
  }

  const anchors = [...pages.values()].reduce((n, s) => n + s.size, 0);
  const withAnchor = internal.filter((l) => l.url.includes('#')).length;

  if (brokenRegistry.length === 0 && brokenInternal.length === 0) {
    console.log(
      `  ✓ ${registry.size} enlaces de la app y ${internal.length} internos del manual ` +
        `(${withAnchor} con ancla) — todos válidos`
    );
    console.log(`    ${pages.size} páginas · ${anchors} encabezados enlazables`);
    return 0;
  }

  if (brokenRegistry.length > 0) {
    console.error(`\n  ✗ ${brokenRegistry.length} enlace(s) de la APP apuntan al vacío:\n`);
    for (const [key, url, why] of brokenRegistry) {
      console.error(`      ${key.padEnd(28)} → ${url}`);
      console.error(`      ${' '.repeat(28)}   (${why})`);
    }
    console.error(`\n  Corrige frontend/src/shared/docs/docsRegistry.ts.`);
  }

  if (brokenInternal.length > 0) {
    console.error(`\n  ✗ ${brokenInternal.length} enlace(s) DENTRO del manual apuntan al vacío:\n`);
    for (const [from, url, why] of brokenInternal) {
      console.error(`      ${url}`);
      console.error(`          en ${from} — ${why}`);
    }
  }

  console.error(`\n  Crea lo que falte en landing/src/content/docs/docs/, o corrige el enlace.\n`);
  return 1;
}

process.exit(await main());
