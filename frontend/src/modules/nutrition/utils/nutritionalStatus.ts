import type { PillTone } from '@/shared/components/atoms/StatusPill'

/**
 * Semáforo del estado nutricional (S5): rojo = riesgo (desnutrición grave o
 * moderada, obesidad, talla baja); ámbar = vigilancia (leve, sobrepeso,
 * talla ligeramente baja/alta, talla alta); verde = normal; neutro = sin
 * referencia.
 */
const TONES: Record<string, PillTone> = {
  severe_malnutrition: 'r',
  moderate_malnutrition: 'r',
  obesity: 'r',
  short_stature: 'r',
  mild_malnutrition: 'y',
  overweight: 'y',
  slightly_short_stature: 'y',
  slightly_tall_stature: 'y',
  tall_stature: 'y',
  normal_weight: 'g',
  normal_stature: 'g',
}

export function nutritionalStatusTone(key?: string | null): PillTone {
  if (!key) return 'n'
  return TONES[key] ?? 'n'
}

export const kgToGrams = (kg: number): number => Math.round(kg * 1000)

export const cmToMillimetres = (cm: number): number => Math.round(cm * 10)

export function formatWeightKg(grams: number | null | undefined): string {
  if (grams === null || grams === undefined) return '—'
  return `${(grams / 1000).toFixed(2)} kg`
}

export function formatHeightCm(millimetres: number | null | undefined): string {
  if (millimetres === null || millimetres === undefined) return '—'
  return `${(millimetres / 10).toFixed(1)} cm`
}
