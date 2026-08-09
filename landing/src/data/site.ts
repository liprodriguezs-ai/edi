/**
 * Single source of truth for every piece of marketing copy on the landing.
 * Centralised so the "copys que venden" can be tuned in one place and so the
 * sections stay data-driven (the foundation for any future programmatic pages).
 *
 * NOTE: figures like 98%, 12 hrs, −38% are illustrative claims from the design
 * brief. Swap them for audited client data before a public launch.
 */

import type { ImageMetadata } from 'astro';
import cobranzaImg from '../assets/system/card-cobranza.webp';
import tesoreriaImg from '../assets/system/card-tesoreria.webp';
import morosidadImg from '../assets/system/card-morosidad.webp';
import transparenciaImg from '../assets/system/card-transparencia.webp';
import mesaImg from '../assets/system/card-mesa.webp';
import reportesImg from '../assets/system/card-reportes.webp';
import residentesImg from '../assets/system/card-residentes.webp';

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
  /** Emphasised (italic sage) fragment that closes the headline. */
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
  /** Unit rendered in italic sage after the number. */
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
const BRAND = 'EDI';

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
  title: `${BRAND} — Plataforma integral para organizaciones modernos`,
  description: `${BRAND}: plataforma integral para administrar organizaciones y organizaciones en México. Cobranza, tesorería y transparencia en un solo lugar. Deja el Excel.`,
  // Dominio del producto (infraestructura). Derivado de APP_URL / PUBLIC_APP_URL.
  appHost: APP_HOST,
  sameAs: [],
};

// Anclas root-relative (`/#id`) para que funcionen también desde /blog y los posts.
export const nav: NavLink[] = [
  { href: '/#plataforma', label: 'Plataforma' },
  { href: '/#precios', label: 'Precios' },
  { href: '/#transparencia', label: 'Transparencia' },
  { href: '/docs', label: 'Documentación' },
  { href: '/blog', label: 'Blog' },
];

export const navCta: NavLink = { href: APP_URL, label: 'Pruébalo gratis 30 días' };

export const hero: Hero = {
  badge: 'Plataforma integral para organizaciones modernos',
  titleLead: 'La administración de tu organización,',
  titleEm: 'en orden.',
  ledeLead:
    'Cobranza al día, tesorería que cuadra y una comunidad que confía en los números.',
  ledeStrong: 'Tu organización deja el Excel en una semana.',
  ctaPrimary: { href: APP_URL, label: 'Pruébalo gratis 30 días' },
  ctaSecondary: { href: '#plataforma', label: 'Ver la plataforma' },
  proofs: [
    { text: 'Hoy no pagas nada' },
    { text: 'Migramos tus saldos' },
    { text: 'Soporte en español' },
  ],
};

export const trustEyebrow = `Confían en ${BRAND}`;
export const trustNames: string[] = [
  'Loma Chica',
  'Torre Polanco',
  'Villa Serena',
  'Altos del Bosque',
  'Residencial Niebla',
];

export const platformHead = {
  eyebrow: 'La plataforma integral',
  titleLead: 'Todo lo que un administrador necesita.',
  titleEm: 'Nada que lo distraiga.',
  body: 'Cobranza, tesorería, transparencia y más — cada cosa en su lugar, funcionando todos los días.',
};

export const bento: BentoCard[] = [
  {
    tag: 'Cobranza',
    title: 'Cobra sin perseguir a nadie',
    body: 'Cada vecino sabe cuánto debe y cada pago se registra en segundos — completo, parcial o adelantado. Tú solo ves cómo baja la morosidad.',
    span: 3,
    image: cobranzaImg,
    imageAlt:
      'Tablero de cobranza de EDI: tasa de cobro, antigüedad de cartera y las unidades con mayor saldo pendiente.',
  },
  {
    tag: 'Tesorería',
    title: 'Cierra el mes en minutos, no en madrugadas',
    body: 'Cada peso queda con su comprobante, su categoría y quién lo autorizó. Cuando llega el cierre, ya está hecho.',
    span: 3,
    delay: 1,
    image: tesoreriaImg,
    imageAlt:
      'Tablero de tesorería de EDI: evolución del saldo y entradas contra salidas de los últimos doce meses.',
  },
  {
    tag: 'Morosidad',
    title: 'El adeudo, visible antes de que crezca',
    body: 'Cada unidad con saldo vencido, su antigüedad y el monto exacto — para actuar antes de que se vuelva impagable.',
    span: 2,
    image: morosidadImg,
    imageAlt:
      'Control de morosidad de EDI: unidades con adeudo vencido, su antigüedad y el monto pendiente de cada una.',
  },
  {
    tag: 'Transparencia',
    title: 'El dinero de todos, a la vista de todos',
    body: 'Ingresos, egresos y utilidad del mes en un estado de resultados que cualquier vecino entiende.',
    span: 2,
    delay: 1,
    id: 'transparencia',
    image: transparenciaImg,
    imageAlt:
      'Estado de resultados de EDI: ingresos, egresos y utilidad neta mensual de los últimos doce meses.',
  },
  {
    tag: 'Mesa directiva',
    title: 'La mesa cambia. El orden se queda.',
    body: 'Cada periodo entrega cuentas claras al siguiente.',
    span: 2,
    delay: 2,
    image: mesaImg,
    imageAlt:
      'Mesa directiva en EDI: periodo vigente con presidente, tesorero, secretario, vocal y comité de vigilancia.',
  },
  {
    tag: 'Reportes',
    title: 'Llega a la asamblea con la respuesta lista',
    body: 'Estado de resultados, morosidad y flujo de efectivo — generados en un clic, no armados a mano la noche anterior.',
    span: 4,
    image: reportesImg,
    imageAlt:
      'Centro de reportes de EDI: morosidad, estado de resultados, flujo de efectivo y más, exportables a PDF y Excel.',
  },
  {
    tag: 'Residentes',
    title: 'Cada vecino, con su cuenta clara',
    body: 'Su estado de cuenta, cargos y pagos, siempre a la mano desde el celular.',
    span: 2,
    delay: 1,
    image: residentesImg,
    imageAlt:
      'Estado de cuenta del residente en EDI: cada cargo mensual con lo esperado, lo pagado y su estado al corriente.',
  },
];

export const results: ResultStat[] = [
  {
    value: 98,
    unit: '%',
    captionStrong: 'de cobranza al corriente',
    caption: `en organizaciones con 6+ meses en ${BRAND}.`,
  },
  {
    value: 12,
    unit: 'hrs',
    captionStrong: 'recuperadas al mes',
    caption: 'por tesorero — el cierre pasa de noches a minutos.',
  },
  {
    value: 0,
    captionStrong: 'hojas de Excel.',
    caption: 'Ni libretas, ni capturas de WhatsApp, ni dudas.',
  },
];

// Testimonios ilustrativos (nombres ficticios) — reemplazar por reales antes del launch.
export const testimonials: Testimonial[] = [
  {
    quote:
      'Dejé tres libretas y dos hojas de Excel. Ahora la asamblea ve el reporte y nadie pregunta a dónde se fue el dinero.',
    name: 'Alberto González',
    role: 'Tesorero · Loma Chica, 77 unidades',
  },
  {
    quote:
      'La morosidad bajó del 40% al 8% en cuatro meses. Los recordatorios automáticos hicieron el trabajo que yo no alcanzaba a hacer.',
    name: 'María Fernanda Ruiz',
    role: 'Administradora · Altos del Bosque, 120 unidades',
  },
  {
    quote:
      'Cada vecino ve su estado de cuenta desde el celular. Se acabaron las llamadas preguntando cuánto deben.',
    name: 'Jorge Mendoza',
    role: 'Presidente · Torre Polanco, 64 deptos',
  },
  {
    quote: 'El cierre de mes pasó de un fin de semana entero a quince minutos. En serio.',
    name: 'Claudia Herrera',
    role: 'Tesorera · Villa Serena, 52 unidades',
  },
  {
    quote:
      'Antes el dinero del organización era un misterio. Hoy cualquier vecino ve en qué se gasta cada peso, y eso bajó la tensión en las asambleas.',
    name: 'Ricardo Beltrán',
    role: 'Presidente · Residencial Niebla, 88 unidades',
  },
  {
    quote:
      'Migrar fue más fácil de lo que pensaba. Cargué mis unidades una tarde y al día siguiente ya estaba cobrando con folios.',
    name: 'Diana Castro',
    role: 'Administradora · Privada del Roble, 41 casas',
  },
];

export const cta: Cta = {
  eyebrow: 'Empieza este mes',
  titleLead: 'Tu comunidad,',
  titleEm: 'más organizada y segura.',
  bodyLead:
    'Alta guiada: damos de alta tu organización, migramos tus saldos y capacitamos a la mesa.',
  bodyStrong: 'En una semana, el Excel es historia.',
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
  { href: '/#transparencia', label: 'Transparencia' },
  { href: '/privacidad', label: 'Privacidad' },
  { href: '/terminos', label: 'Términos' },
];

export const footerLegal = `© 2026 ${BRAND} · Hecho en México · Comunidades más felices, organizadas y seguras.`;

// -------------------------------------------------------- new sections (2026-06) --

/** Comparativa "antes/después" — Excel vs la plataforma. Una fila = un dolor resuelto. */
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
  title: 'Deja el Excel. En serio.',
  beforeLabel: 'Con Excel',
  afterLabel: `Con ${BRAND}`,
  rows: [
    { before: 'Fórmulas que se rompen y nadie sabe por qué', after: 'Cobranza y saldos calculados solos' },
    { before: 'Pagos que se pierden entre WhatsApps', after: 'Cada pago con folio y comprobante' },
    { before: 'Cierre de mes a mano, 12 horas', after: 'Cierre de mes automático, en minutos' },
    { before: 'Cero transparencia para los vecinos', after: 'Estado de cuenta visible para todos' },
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
  title: 'Tu organización deja el Excel en una semana.',
  lede: 'Tres pasos. Sin migraciones eternas ni curva de aprendizaje.',
  steps: [
    { n: '01', title: 'Creas el organización', body: 'Alta y configuración guiada de tu organización o organización en minutos.' },
    { n: '02', title: 'Cargas unidades y residentes', body: 'Torres, secciones y unidades. Vinculas a cada propietario o inquilino.' },
    { n: '03', title: 'Cobras y reportas solo', body: 'Generas cargos, registras pagos con FIFO y el estado de cuenta se arma solo.' },
  ],
};

/**
 * Planes por banda de unidades. El precio depende SOLO del tamaño del organización:
 * todo el producto (incluidas paquetería y amenidades) va dentro del plan base,
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
  title: 'Elige el plan que mejor se adapta a tu organización.',
  // El precio mostrado es el MENSUAL. El 5% de descuento del pago anual vivía sólo
  // en las notas al pie, donde nadie lo lee: se sube aquí porque es la palanca que
  // adelanta flujo de caja y reduce cancelaciones, y nadie elige lo que no ve.
  lede: 'Precios mensuales en MXN con IVA incluido; el pago anual tiene 5% de descuento. Pruébalo 30 días gratis: el primer cargo llega hasta el día 31 y puedes cancelar antes sin pagar nada.',
  tiers: [
    {
      name: 'Básico',
      key: 'basico',
      range: '1 a 25 casas',
      price: '$450',
      highlight: false,
      cta: 'Empezar gratis',
      href: `${APP_URL}/register?plan=basico`,
      proposed: true,
    },
    {
      name: 'Inicial',
      key: 'inicial',
      range: '26 a 50 casas',
      price: '$650',
      highlight: false,
      cta: 'Empezar gratis',
      href: `${APP_URL}/register?plan=inicial`,
      proposed: true,
    },
    {
      name: 'Esencial',
      key: 'esencial',
      range: '51 a 100 casas',
      price: '$950',
      highlight: true,
      cta: 'Empezar gratis',
      href: `${APP_URL}/register?plan=esencial`,
    },
    {
      name: 'Avanzado',
      key: 'avanzado',
      range: '101 a 200 casas',
      price: '$1,550',
      highlight: false,
      cta: 'Empezar gratis',
      href: `${APP_URL}/register?plan=avanzado`,
    },
    {
      name: 'Premium',
      key: 'premium',
      range: '201 a 300 casas',
      price: '$2,100',
      highlight: false,
      cta: 'Empezar gratis',
      href: `${APP_URL}/register?plan=premium`,
    },
    {
      name: 'A medida',
      key: 'a_medida',
      range: 'Más de 300 casas',
      highlight: false,
      contactSales: true,
      blurb:
        'Armamos un plan a tu medida, con acompañamiento en la migración y un precio acorde al tamaño de tu comunidad.',
      cta: 'Contáctanos',
      href: `mailto:${CONTACT_EMAIL}?subject=${encodeURIComponent('Plan a medida para más de 300 casas')}`,
    },
  ],
  includesTitle: 'Todo incluido en cualquier plan',
  includes: [
    'Administración financiera (cobranza y pagos)',
    'Comunicación con residentes y avisos',
    'Control de accesos y visitantes',
    'Paquetería y recepción de envíos',
    'Reserva de amenidades y áreas comunes',
    'Reportes y dashboards en tiempo real',
    'App móvil para administradores y residentes',
    'Respaldo seguro y actualizaciones incluidas',
  ],
  notes: [
    'Precios en MXN con IVA incluido.',
    'Sin módulos extra: el precio depende sólo del tamaño de tu organización.',
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
    { q: '¿Sirve para organización y para organización?', a: 'Sí. EDI maneja organizaciones horizontales (casas), verticales (departamentos) y mixtos.' },
    { q: '¿Cómo migro mi Excel?', a: 'Cargas tus unidades y residentes una vez. No necesitas fórmulas: la cobranza y los saldos se calculan automáticamente.' },
    { q: '¿Los vecinos ven su estado de cuenta?', a: 'Sí. Cada residente ve sus cargos, pagos y saldo con total transparencia.' },
    { q: '¿Maneja indiviso (ownership share)?', a: 'Sí. Puedes cobrar montos proporcionales al porcentaje de copropiedad cuando lo necesites.' },
    { q: '¿Qué métodos de pago registra?', a: 'Efectivo, transferencia, depósito y cheque, cada uno con folio y comprobante.' },
    { q: '¿Es seguro?', a: 'Cada organización tiene sus datos aislados y auditados. Nadie ve la información de otro.' },
  ],
};

/** Metadatos del blog (título/descr del listado, base para SEO). */
export const blogMeta = {
  title: 'Blog',
  description: `Guías de administración de organizaciones y organizaciones, por ${BRAND}.`,
};
