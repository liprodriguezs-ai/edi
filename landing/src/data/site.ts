/**
 * Single source of truth for every piece of marketing copy on the landing.
 * Centralised so the "copys que venden" can be tuned in one place and so the
 * sections stay data-driven (the foundation for any future programmatic pages).
 *
 * NOTE: las cifras de `results` son datos DEL INSTRUMENTO (15 grupos, 8 áreas,
 * 451 reactivos), no promesas de resultado — verificables y por eso seguras.
 * Lo que sí falta reemplazar antes de publicar: `testimonials` y `trustNames`
 * son ficticios, y los precios de `pricing` están marcados `proposed` porque el
 * sujeto de cuota todavía no se define (ver la deuda conocida en AGENTS.md).
 *
 * TODO capturas: los 7 .webp de `bento` siguen siendo del producto anterior
 * (administración de condominios). Hay que recapturarlos desde la app EDI; los
 * `imageAlt` ya describen la pantalla que debe ir en cada tarjeta.
 */

import type { ImageMetadata } from 'astro';
import capturaImg from '../assets/system/card-cobranza.webp';
import resultadoImg from '../assets/system/card-tesoreria.webp';
import alternaImg from '../assets/system/card-morosidad.webp';
import seguimientoImg from '../assets/system/card-transparencia.webp';
import estimulacionImg from '../assets/system/card-mesa.webp';
import reportesImg from '../assets/system/card-reportes.webp';
import expedienteImg from '../assets/system/card-residentes.webp';

export type FinancialStatus = 'ok' | 'warn' | 'bad';

export interface SiteMeta {
  /** Brand name / wordmark. */
  name: string;
  /** Used in <title> after the page title. */
  shortTitle: string;
  /** Page <title>. */
  title: string;
  /** Meta description (≤160 chars, self-contained answer for snippets/AI). */
  description: string;
  /** App subdomain shown in the product mock. */
  appHost: string;
  /** Social handle / org links for schema.org sameAs. */
  sameAs: string[];
}

export interface NavLink {
  href: string;
  label: string;
}

export interface MicroProof {
  /** Short reassurance shown with a check mark. */
  text: string;
}

export interface Hero {
  badge: string;
  /** Headline before the emphasised fragment. */
  titleLead: string;
  /** Emphasised (italic brand) fragment that closes the headline. */
  titleEm: string;
  /** Lede with an emphasised closing sentence. */
  ledeLead: string;
  ledeStrong: string;
  ctaPrimary: NavLink;
  ctaSecondary: NavLink;
  proofs: MicroProof[];
}

export interface BentoCard {
  /** Eyebrow tag (module name). */
  tag: string;
  title: string;
  body?: string;
  /** Column span on the 6-col desktop grid. */
  span: 2 | 3 | 4;
  /** Stagger group for the reveal animation. */
  delay?: 1 | 2;
  /** Optional anchor id. */
  id?: string;
  /** Real product screenshot (astro:assets optimises it at build). */
  image: ImageMetadata;
  /** Spoken description of the screenshot for assistive tech. */
  imageAlt: string;
}

export interface ResultStat {
  /** Numeric value to count up to. */
  value: number;
  /** Visible prefix when the raw number alone is ambiguous (e.g. "$"). */
  prefix?: string;
  /** Unit rendered in italic brand after the number. */
  unit?: string;
  captionStrong: string;
  caption: string;
}

export interface Testimonial {
  quote: string;
  name: string;
  role: string;
}

export interface Cta {
  eyebrow: string;
  titleLead: string;
  titleEm: string;
  bodyLead: string;
  bodyStrong: string;
  primary: NavLink;
  proofs: MicroProof[];
}

// ---------------------------------------------------------------- content --

/** Nombre de marca del producto. Fuente única — cámbialo aquí y se propaga a toda la landing. */
const BRAND = 'Prueba EDI';

/**
 * URL de la app donde aterrizan los CTAs de "empezar/iniciar".
 * Configurable por entorno (`PUBLIC_APP_URL`); fallback a producción.
 */
const APP_URL: string = import.meta.env.PUBLIC_APP_URL ?? 'https://app.edi.example.com';

/** Host sin esquema — se muestra en el mock del navegador (AppWindow). Derivado de APP_URL para no duplicar. */
const APP_HOST = APP_URL.replace(/^https?:\/\//, '');

export const meta: SiteMeta = {
  name: BRAND,
  shortTitle: BRAND,
  title: `${BRAND} — Evaluación del Desarrollo Infantil`,
  description: `${BRAND}: aplica el tamiz de Evaluación del Desarrollo Infantil en la consulta. El semáforo por área y el resultado los calcula el sistema, con las reglas del grupo de edad que corresponde.`,
  // Dominio del producto (infraestructura). Derivado de APP_URL / PUBLIC_APP_URL.
  appHost: APP_HOST,
  sameAs: [],
};

// Anclas root-relative (`/#id`) para que funcionen también desde /blog y los posts.
export const nav: NavLink[] = [
  { href: '/#plataforma', label: 'Plataforma' },
  { href: '/#precios', label: 'Precios' },
  { href: '/#seguimiento', label: 'Seguimiento' },
  { href: '/docs', label: 'Documentación' },
  { href: '/blog', label: 'Blog' },
];

export const navCta: NavLink = { href: APP_URL, label: 'Pruébalo gratis 30 días' };

export const hero: Hero = {
  badge: 'Tamiz de la Secretaría de Salud',
  titleLead: 'El tamiz EDI, resuelto',
  titleEm: 'en la consulta.',
  ledeLead:
    'Capturas los reactivos del grupo de edad que corresponde y el sistema devuelve el semáforo por área.',
  ledeStrong: 'Sin hojas sueltas y sin cuentas a mano.',
  ctaPrimary: { href: APP_URL, label: 'Pruébalo gratis 30 días' },
  ctaSecondary: { href: '#plataforma', label: 'Ver cómo funciona' },
  proofs: [
    { text: 'Hoy no pagas nada' },
    { text: 'Migramos tu historial' },
    { text: 'Soporte en español' },
  ],
};

export const trustEyebrow = `Confían en ${BRAND}`;
// Nombres ilustrativos — reemplazar por centros reales antes del launch.
export const trustNames: string[] = [
  'CS Xochimilco',
  'CS Milpa Alta',
  'Pediatría Del Valle',
  'CS Tláhuac',
  'Estancia Los Pinos',
];

export const platformHead = {
  eyebrow: 'La plataforma',
  titleLead: 'Todo lo que necesitas para tamizar.',
  titleEm: 'Nada que te distraiga del niño.',
  body: 'Aplicación, resultado, seguimiento y estimulación temprana, cada cosa en su lugar y con el instrumento tal como se publicó.',
};

export const bento: BentoCard[] = [
  {
    tag: 'Aplicación',
    title: 'El grupo de edad lo resuelve el sistema',
    body: 'Capturas nacimiento y semanas de gestación. El sistema ubica el grupo entre los 15 y aplica la edad corregida cuando el niño nació prematuro.',
    span: 3,
    image: capturaImg,
    imageAlt:
      'Captura de un tamiz en EDI: los reactivos del grupo de edad con sus dos opciones de respuesta.',
  },
  {
    tag: 'Resultado',
    title: 'El semáforo se calcula en el servidor',
    body: 'Nunca se acepta el color desde el navegador. Cada tamiz queda amarrado a la versión del instrumento con la que se resolvió, así que un resultado de hace dos años se recalcula con las reglas de entonces.',
    span: 3,
    delay: 1,
    image: resultadoImg,
    imageAlt:
      'Resultado de un tamiz en EDI: el semáforo por área y la clasificación global del niño.',
  },
  {
    tag: 'Evaluación alterna',
    title: 'La regla que en papel se olvida',
    body: 'Cuando un área sale completa en amarillo hay que volver al grupo anterior. El sistema abre esos reactivos solo, sin que tengas que acordarte a mitad de la consulta.',
    span: 2,
    image: alternaImg,
    imageAlt:
      'Evaluación alterna en EDI: el área en amarillo y los reactivos del grupo previo abiertos para ese eje.',
  },
  {
    tag: 'Seguimiento',
    title: 'Ningún rezago se queda sin cita',
    body: 'Los niños con rezago o riesgo quedan en una lista de trabajo, con lo que sigue en cada caso: sesión de estimulación o referencia a especialista.',
    span: 2,
    delay: 1,
    id: 'seguimiento',
    image: seguimientoImg,
    imageAlt:
      'Lista de seguimiento en EDI: niños con rezago o riesgo y la acción pendiente de cada uno.',
  },
  {
    tag: 'Estimulación',
    title: 'De la detección a la sesión',
    body: 'Armas los grupos y registras cada sesión contra el niño que la necesita.',
    span: 2,
    delay: 2,
    image: estimulacionImg,
    imageAlt:
      'Sesiones de estimulación temprana en EDI: grupos armados y asistencia de cada niño.',
  },
  {
    tag: 'Reportes',
    title: 'El informe del centro, sin armarlo a mano',
    body: 'Tamizajes del periodo, distribución del semáforo y en qué áreas falla más tu población. Exportable, no transcrito de una libreta.',
    span: 4,
    image: reportesImg,
    imageAlt:
      'Reportes de EDI: tamizajes por grupo de edad, distribución del semáforo y áreas con más rezago.',
  },
  {
    tag: 'Expediente',
    title: 'Cada niño, con su historia completa',
    body: 'Todos sus tamizajes, sus factores de riesgo y sus sesiones, en orden y a la mano.',
    span: 2,
    delay: 1,
    image: expedienteImg,
    imageAlt:
      'Expediente del niño en EDI: tamizajes aplicados, factores de riesgo biológico y sesiones registradas.',
  },
];

// Cifras del INSTRUMENTO, no promesas de resultado: son verificables contra la
// prueba publicada, así que no hay nada que auditar antes de un launch.
export const results: ResultStat[] = [
  {
    value: 15,
    captionStrong: 'grupos de edad',
    caption: 'de 1 a 71 meses, cada uno con sus propios umbrales.',
  },
  {
    value: 8,
    captionStrong: 'áreas evaluadas',
    caption: 'motriz, lenguaje, social y conocimiento, más las señales de alarma y de alerta.',
  },
  {
    value: 451,
    captionStrong: 'reactivos',
    caption: 'el instrumento completo, tal como se publicó.',
  },
];

// Testimonios ilustrativos (nombres ficticios) — reemplazar por reales antes del launch.
export const testimonials: Testimonial[] = [
  {
    quote:
      'Antes sumaba los reactivos a mano al final de la consulta y con prisa. Ahora cierro el tamiz y el semáforo ya está, con la edad corregida aplicada.',
    name: 'Dra. Lorena Ramírez',
    role: 'Pediatra · CS Xochimilco',
  },
  {
    quote:
      'La evaluación alterna se me pasaba. No por descuido: es que hay que acordarse justo cuando ya tienes al niño encima. El sistema abre el grupo anterior solo.',
    name: 'Dr. Iván Beltrán',
    role: 'Médico general · CS Milpa Alta',
  },
  {
    quote:
      'Lo que más cambió fue el seguimiento. Los niños con rezago quedan en una lista, no en una hoja que se traspapela.',
    name: 'Lic. Norma Villaseñor',
    role: 'Trabajo social · CS Tláhuac',
  },
  {
    quote:
      'Puedo abrir un tamiz de hace dos años y ver con qué reglas se resolvió. Eso en papel no existía.',
    name: 'Dra. Paulina Escobar',
    role: 'Coordinadora de pediatría · Pediatría Del Valle',
  },
  {
    quote:
      'El informe del trimestre lo sacaba a mano en dos tardes. Ahora lo exporto y me queda tiempo para revisarlo.',
    name: 'Dr. Arturo Nájera',
    role: 'Director · CS Iztapalapa',
  },
  {
    quote:
      'Cargamos el padrón de niños una tarde y al día siguiente ya estábamos tamizando con folio.',
    name: 'Enf. Rocío Palma',
    role: 'Enfermería · Estancia Los Pinos',
  },
];

export const cta: Cta = {
  eyebrow: 'Empieza esta semana',
  titleLead: 'Tu centro,',
  titleEm: 'tamizando bien.',
  bodyLead:
    'Alta guiada: damos de alta tu centro, cargamos tu padrón de niños y capacitamos a quien aplica.',
  bodyStrong: 'En una semana estás tamizando con el instrumento completo.',
  primary: { href: APP_URL, label: 'Pruébalo gratis 30 días' },
  proofs: [
    { text: 'Hoy no pagas nada' },
    { text: 'Cancela cuando quieras' },
    { text: 'Soporte en español' },
  ],
};

export const footerLinks: NavLink[] = [
  { href: '/#plataforma', label: 'Plataforma' },
  { href: '/#precios', label: 'Precios' },
  { href: '/docs', label: 'Documentación' },
  { href: '/blog', label: 'Blog' },
  { href: '/#seguimiento', label: 'Seguimiento' },
  { href: '/privacidad', label: 'Privacidad' },
  { href: '/terminos', label: 'Términos' },
];

export const footerLegal = `© 2026 ${BRAND} · Hecho en México · Para que ningún rezago se detecte tarde.`;

// -------------------------------------------------------- new sections (2026-06) --

/** Comparativa "antes/después" — papel vs la plataforma. Una fila = un dolor resuelto. */
export interface ComparisonRow {
  before: string;
  after: string;
}
export const comparison: {
  eyebrow: string;
  title: string;
  beforeLabel: string;
  afterLabel: string;
  rows: ComparisonRow[];
} = {
  eyebrow: 'El cambio',
  title: 'Deja el formato de papel.',
  beforeLabel: 'En papel',
  afterLabel: `Con ${BRAND}`,
  rows: [
    { before: 'Sumar los reactivos a mano al cerrar la consulta', after: 'El semáforo por área, calculado al guardar' },
    { before: 'Sacar la edad corregida con calculadora', after: 'La prematurez aplicada antes de elegir el grupo' },
    { before: 'Acordarte de la evaluación alterna', after: 'El grupo anterior se abre solo cuando toca' },
    { before: 'Formatos que se traspapelan entre consultas', after: 'El expediente del niño, siempre completo' },
  ],
};

/** Onboarding en 3 pasos. */
export interface HowStep {
  n: string;
  title: string;
  body: string;
}
export const howItWorks: { eyebrow: string; title: string; lede: string; steps: HowStep[] } = {
  eyebrow: 'Cómo funciona',
  title: 'Tu centro empieza a tamizar en una semana.',
  lede: 'Tres pasos. Sin migraciones eternas ni curva de aprendizaje.',
  steps: [
    { n: '01', title: 'Das de alta el centro', body: 'Alta guiada del centro y de quienes aplican el tamiz, en minutos.' },
    { n: '02', title: 'Cargas a los niños', body: 'Fecha de nacimiento, semanas de gestación y factores de riesgo biológico. Con eso basta.' },
    { n: '03', title: 'Aplicas y el sistema resuelve', body: 'Capturas los reactivos; el semáforo por área, el resultado global y el seguimiento salen solos.' },
  ],
};

/**
 * Planes por banda de volumen. El precio depende SOLO del volumen de tamizajes:
 * todo el producto (incluidos seguimiento y estimulación) va dentro del plan base,
 * no hay módulos vendibles por separado. Esencial / Avanzado / Premium con
 * precios REALES del infográfico; Básico / Inicial (1–50 casas) con precios
 * PROPUESTOS (`proposed: true`) — confirmar antes del launch.
 *
 * El último tier (`a_medida`) es venta consultiva: sin precio, con CTA de
 * contacto. Debe existir también en el camino interactivo que consume
 * `GET /billing/plan-catalog` (allí llega como `is_contact_sales: true`).
 */
export interface PricingTier {
  name: string;
  /** Key de plan v2 — se pasa como ?plan=<key> al CTA de registro. */
  key: string;
  /** Rango de casas que cubre, ej. "51 a 100 casas". */
  range: string;
  /** Precio mensual, ej. "$950". Ausente en el tier de venta consultiva. */
  price?: string;
  highlight: boolean;
  cta: string;
  href: string;
  /** true cuando el monto es una propuesta a confirmar (planes 1–50). */
  proposed?: boolean;
  /**
   * true = tier sin precio (más de 300 casas). Se renderiza como card
   * "Contáctanos" con CTA mailto en lugar del CTA de registro.
   */
  contactSales?: boolean;
  /** Copy del cuerpo — sólo para el tier de venta consultiva. */
  blurb?: string;
}
/** Correo de venta consultiva. Debe coincidir con `contact.email` del catálogo. */
const CONTACT_EMAIL = 'hola@edi.example.com';

export const pricing: {
  eyebrow: string;
  title: string;
  lede: string;
  tiers: PricingTier[];
  includesTitle: string;
  includes: string[];
  notes: string[];
  /** Correo de venta consultiva — espejo del `contact.email` del catálogo. */
  contactEmail: string;
} = {
  eyebrow: 'Precios',
  title: 'Elige el plan que le queda a tu centro.',
  // El precio mostrado es el MENSUAL. El 5% de descuento del pago anual vivía sólo
  // en las notas al pie, donde nadie lo lee: se sube aquí porque es la palanca que
  // adelanta flujo de caja y reduce cancelaciones, y nadie elige lo que no ve.
  lede: 'Precios mensuales en MXN con IVA incluido; el pago anual tiene 5% de descuento. Pruébalo 30 días gratis: el primer cargo llega hasta el día 31 y puedes cancelar antes sin pagar nada.',
  tiers: [
    {
      name: 'Básico',
      key: 'basico',
      range: 'Hasta 50 tamizajes al mes',
      price: '$450',
      highlight: false,
      cta: 'Empezar gratis',
      href: `${APP_URL}/register?plan=basico`,
      proposed: true,
    },
    {
      name: 'Inicial',
      key: 'inicial',
      range: '51 a 120 tamizajes al mes',
      price: '$650',
      highlight: false,
      cta: 'Empezar gratis',
      href: `${APP_URL}/register?plan=inicial`,
      proposed: true,
    },
    {
      name: 'Esencial',
      key: 'esencial',
      range: '121 a 300 tamizajes al mes',
      price: '$950',
      highlight: true,
      cta: 'Empezar gratis',
      href: `${APP_URL}/register?plan=esencial`,
      proposed: true,
    },
    {
      name: 'Avanzado',
      key: 'avanzado',
      range: '301 a 600 tamizajes al mes',
      price: '$1,550',
      highlight: false,
      cta: 'Empezar gratis',
      href: `${APP_URL}/register?plan=avanzado`,
      proposed: true,
    },
    {
      name: 'Premium',
      key: 'premium',
      range: '601 a 1,000 tamizajes al mes',
      price: '$2,100',
      highlight: false,
      cta: 'Empezar gratis',
      href: `${APP_URL}/register?plan=premium`,
      proposed: true,
    },
    {
      name: 'A medida',
      key: 'a_medida',
      range: 'Más de 1,000 tamizajes al mes',
      highlight: false,
      contactSales: true,
      blurb:
        'Armamos un plan a tu medida, con acompañamiento en la carga del padrón y un precio acorde al volumen de tu centro.',
      cta: 'Contáctanos',
      href: `mailto:${CONTACT_EMAIL}?subject=${encodeURIComponent('Plan a medida para más de 1,000 tamizajes al mes')}`,
    },
  ],
  includesTitle: 'Todo incluido en cualquier plan',
  includes: [
    'El instrumento completo: 15 grupos de edad y 451 reactivos',
    'Edad corregida por prematurez y evaluación alterna',
    'Expediente del niño con sus factores de riesgo',
    'Seguimiento de rezago y riesgo de retraso',
    'Sesiones de estimulación temprana',
    'Reportes del centro exportables',
    'Usuarios ilimitados para quienes aplican el tamiz',
    'Respaldo seguro y actualizaciones incluidas',
  ],
  notes: [
    'Precios en MXN con IVA incluido.',
    'Sin módulos extra: el precio depende sólo del volumen de tamizajes de tu centro.',
    'Contrato anual con opción a pago mensual o anual (5% de descuento en el pago anual).',
    'Implementación y capacitación incluidas.',
  ],
  contactEmail: CONTACT_EMAIL,
};

/** Preguntas frecuentes — alimentan también el FAQPage JSON-LD en BaseHead. */
export interface FaqItem {
  q: string;
  a: string;
}
export const faq: { eyebrow: string; title: string; items: FaqItem[] } = {
  eyebrow: 'Preguntas frecuentes',
  title: 'Lo que todos preguntan.',
  items: [
    { q: '¿Es el tamiz EDI oficial?', a: 'Sí. EDI aplica la prueba de Evaluación del Desarrollo Infantil de la Secretaría de Salud tal como está publicada, sin adaptarla ni reinterpretarla.' },
    { q: '¿Qué edades cubre?', a: 'De 1 a 71 meses, repartidos en los 15 grupos de edad del instrumento. Cada grupo tiene sus propios reactivos y sus propios umbrales.' },
    { q: '¿Maneja la edad corregida por prematurez?', a: 'Sí. Capturas las semanas de gestación y el sistema aplica la corrección antes de elegir el grupo, que es donde suele cambiar el resultado.' },
    { q: '¿Y la evaluación alterna?', a: 'Cuando un área sale completa en amarillo, el sistema abre los reactivos del grupo anterior para esa área. Es la regla que más se pierde cuando la prueba se aplica en papel.' },
    { q: '¿Quién calcula el resultado?', a: 'El servidor. Nunca se acepta el color desde el navegador, y cada tamiz guarda la versión del instrumento con la que se resolvió, así que se puede recalcular con las reglas de entonces.' },
    { q: '¿Es seguro?', a: 'Cada centro tiene sus datos aislados y auditados. Nadie ve la información de otro.' },
  ],
};

/** Metadatos del blog (título/descr del listado, base para SEO). */
export const blogMeta = {
  title: 'Blog',
  description: `Guías sobre tamizaje del desarrollo infantil y estimulación temprana, por ${BRAND}.`,
};
