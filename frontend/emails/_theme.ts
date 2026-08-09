/**
 * EDI — Email theme
 *
 * Change colors and brand name here. Values are in hex for maximum
 * email client support (no oklch/CSS variables in email).
 *
 * These match the spirit of src/index.css (primary hue ~155 green/teal).
 */

export const emailTheme = {
  /** Product name used in header and footer */
  productName: 'EDI',

  /** Primary brand (buttons, links, accents). Aligned with app primary ~155 hue. */
  primary: '#0d9488',
  /** Darker primary for primary buttons (more contrast) */
  primaryDark: '#0f766e',
  /** Very light primary for backgrounds (e.g. security note, step cards) */
  primaryLight: '#ccfbf1',
  /** Border for primary-light areas */
  primaryBorder: '#99f6e4',

  /** Main heading text */
  heading: '#0f172a',
  /** Body text */
  body: '#475569',
  /** Muted / secondary text */
  muted: '#64748b',

  /** Email outer background (body) */
  bgOuter: '#f1f5f9',
  /** Card / container background */
  bgCard: '#ffffff',
  /** Subtle surface (e.g. step blocks, feature cards) */
  bgSurface: '#f8fafc',
  /** Border for cards and surfaces */
  border: '#e2e8f0',
  /** Softer border (dividers, footer) */
  borderSoft: '#f1f5f9',

  /** Footer background: same hue as primary, very light */
  footerBg: '#f0fdfa',
  /** Footer text */
  footerText: '#475569',
  /** Footer link color */
  footerLink: '#0d9488',
} as const

export type EmailTheme = typeof emailTheme
