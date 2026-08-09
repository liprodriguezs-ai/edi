import { describe, expect, it, vi } from 'vitest'
import {
  displayFormatDate,
  formatDateForInput,
  formatLocalDateString,
  formatTimeHHmm,
  parseLocalDateString,
  todayLocalDateString,
} from '@/shared/utils/dateHelper'

// trial-countdown-utility: el helper de "días restantes" que vivía acá fue
// eliminado — esa lógica ahora la da la API (trial_days_left), nunca el
// frontend. Ver SubscriptionStateService::entitledNarrative() /
// CalendarDays::until() en el backend. Este archivo cubre los helpers que SÍ
// siguen siendo del frontend (formateo de fechas para mostrar/enviar, no
// cómputo de negocio).

describe('formatLocalDateString', () => {
  it('serializa un Date a yyyy-MM-dd usando sus componentes locales', () => {
    expect(formatLocalDateString(new Date(2026, 0, 5))).toBe('2026-01-05')
  })
})

describe('parseLocalDateString', () => {
  it('es el inverso de formatLocalDateString (medianoche local)', () => {
    const date = parseLocalDateString('2026-03-15')

    expect(date.getFullYear()).toBe(2026)
    expect(date.getMonth()).toBe(2)
    expect(date.getDate()).toBe(15)
  })
})

describe('todayLocalDateString', () => {
  it('devuelve la fecha local de hoy, no la UTC (evita el corrimiento de día)', () => {
    // 23:30 hora local del 5 de enero es 6 de enero en UTC (ej. tenant en
    // America/Mexico_City, UTC-6) — todayLocalDateString debe seguir
    // devolviendo el 5, no adelantarse un día.
    vi.setSystemTime(new Date(2026, 0, 5, 23, 30))

    expect(todayLocalDateString()).toBe('2026-01-05')

    vi.useRealTimers()
  })
})

describe('formatTimeHHmm', () => {
  it('trunca HH:mm:ss a HH:mm', () => {
    expect(formatTimeHHmm('10:00:00')).toBe('10:00')
  })

  it('devuelve el valor tal cual si no matchea el patrón esperado', () => {
    expect(formatTimeHHmm('no-es-una-hora')).toBe('no-es-una-hora')
  })

  it('devuelve string vacío para null/undefined', () => {
    expect(formatTimeHHmm(null)).toBe('')
    expect(formatTimeHHmm(undefined)).toBe('')
  })
})

describe('displayFormatDate', () => {
  it('formatea una fecha ISO en formato medium (con hora)', () => {
    expect(displayFormatDate('2026-01-15T10:30:00.000Z', 'medium')).toContain('2026')
  })

  it('formatea una fecha ISO en formato mediumDate (solo fecha, sin corrimiento UTC)', () => {
    // "2026-01-15T00:00:00.000Z" debe mostrar 15, no 14 (el corrimiento a
    // UTC-6 que este helper existe para evitar).
    expect(displayFormatDate('2026-01-15T00:00:00.000Z', 'mediumDate')).toContain('15')
  })

  it('devuelve string vacío para un valor nulo/indefinido', () => {
    expect(displayFormatDate(null)).toBe('')
    expect(displayFormatDate(undefined)).toBe('')
  })
})

describe('formatDateForInput', () => {
  it('extrae yyyy-MM-dd de una fecha ISO completa', () => {
    expect(formatDateForInput('2026-02-20T15:00:00.000Z')).toBe('2026-02-20')
  })

  it('devuelve string vacío para un valor vacío', () => {
    expect(formatDateForInput(undefined)).toBe('')
    expect(formatDateForInput('')).toBe('')
  })
})
