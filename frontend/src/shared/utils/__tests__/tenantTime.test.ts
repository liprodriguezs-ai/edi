import { describe, expect, it } from 'vitest'
import {
  civilDaysBetween,
  addCivilDays,
  civilWeekBounds,
  civilDateToWallDate,
  compareInstants,
  formatDateOnly,
  formatTenantInstant,
  formatTenantTime,
  instantToTenantCivilDate,
  instantToTenantWallDate,
  isInstantAtOrAfter,
  isValidTimeZone,
  resolveTenantTimeZone,
  tenantCivilRangeToUtc,
  tenantCurrentMonth,
  tenantDatePreset,
  tenantDateTimeLocalToUtc,
  tenantToday,
  tenantWallDateToUtc,
  toCivilDate,
  unixSecondsToInstant,
  utcToTenantDateTimeLocal,
  wallDateToCivilDate,
  wallDateToDateTimeLocal,
} from '@/shared/utils/tenantTime'

const MX = 'America/Mexico_City'

describe('isValidTimeZone / resolveTenantTimeZone', () => {
  it('acepta zonas IANA y rechaza basura', () => {
    expect(isValidTimeZone(MX)).toBe(true)
    expect(isValidTimeZone('Mars/Olympus')).toBe(false)
    expect(isValidTimeZone('')).toBe(false)
  })

  it('usa el fallback cuando la zona falta o es inválida', () => {
    expect(resolveTenantTimeZone(MX, 'UTC')).toBe(MX)
    expect(resolveTenantTimeZone(null, 'UTC')).toBe('UTC')
    expect(resolveTenantTimeZone('Mars/Olympus', 'UTC')).toBe('UTC')
  })
})

describe('tenantToday / tenantCurrentMonth', () => {
  it('resuelve el día y mes civil del tenant en la frontera de mes', () => {
    const now = '2026-11-01T03:00:00Z'
    expect(tenantToday(MX, now)).toBe('2026-10-31')
    expect(tenantCurrentMonth(MX, now)).toBe('2026-10')
    expect(tenantToday('UTC', now)).toBe('2026-11-01')
    expect(tenantCurrentMonth('UTC', now)).toBe('2026-11')
  })
})

describe('addCivilDays', () => {
  it('suma días calendario, no 24 h', () => {
    expect(addCivilDays('2026-10-31', 1)).toBe('2026-11-01')
    expect(addCivilDays('2026-10-25', 7)).toBe('2026-11-01')
    expect(addCivilDays('2026-03-01', -1)).toBe('2026-02-28')
  })
})

describe('toCivilDate', () => {
  it('acepta YYYY-MM-DD y medianoche Z; rechaza instantes con hora', () => {
    expect(toCivilDate('2026-10-21')).toBe('2026-10-21')
    expect(toCivilDate('2026-10-21T00:00:00.000000Z')).toBe('2026-10-21')
    expect(toCivilDate('2026-10-21T03:00:00.000000Z')).toBeNull()
    expect(toCivilDate('nope')).toBeNull()
  })
})

describe('formatDateOnly', () => {
  it('formatea una fecha civil en español sin corrimiento', () => {
    expect(formatDateOnly('2026-10-21')).toBe('oct 21, 2026')
  })
})

describe('formatTenantInstant', () => {
  it('convierte un instante UTC a la zona del tenant', () => {
    expect(formatTenantInstant('2026-10-21T03:00:00.000000Z', MX)).toBe('oct 20, 2026 21:00')
    expect(formatTenantInstant('2026-10-21T03:00:00.000000Z', MX, 'mediumDate')).toBe(
      'oct 20, 2026'
    )
    expect(formatTenantInstant('2026-10-21T03:00:00.000000Z', 'Asia/Tokyo')).toBe(
      'oct 21, 2026 12:00'
    )
  })

  it('interpreta un datetime sin offset como UTC (almacenamiento UTC)', () => {
    expect(formatTenantInstant('2026-10-21 03:00:00', MX)).toBe('oct 20, 2026 21:00')
  })
})

describe('utcToTenantDateTimeLocal / tenantDateTimeLocalToUtc', () => {
  it('ida y vuelta entre instante UTC y hora de pared del tenant', () => {
    expect(utcToTenantDateTimeLocal('2026-10-21T03:00:00Z', MX)).toBe('2026-10-20T21:00')
    expect(tenantDateTimeLocalToUtc('2026-10-20T21:00', MX)).toEqual({
      ok: true,
      value: '2026-10-21T03:00:00Z',
    })
  })

  it('rechaza horas inexistentes (hueco DST) y ambiguas (pliegue DST)', () => {
    // America/New_York: 2026-03-08 02:30 no existe; 2026-11-01 01:30 ocurre dos veces.
    expect(tenantDateTimeLocalToUtc('2026-03-08T02:30', 'America/New_York')).toEqual({
      ok: false,
      error: 'invalid_wall_time',
    })
    expect(tenantDateTimeLocalToUtc('2026-11-01T01:30', 'America/New_York')).toEqual({
      ok: false,
      error: 'invalid_wall_time',
    })
  })

  it('rechaza entradas y zonas inválidas', () => {
    expect(tenantDateTimeLocalToUtc('ayer', MX)).toEqual({ ok: false, error: 'invalid_datetime' })
    expect(tenantDateTimeLocalToUtc('2026-10-20T21:00', 'Mars/Olympus')).toEqual({
      ok: false,
      error: 'invalid_timezone',
    })
  })
})

describe('tenantDatePreset', () => {
  // Domingo 1 nov 2026 03:00Z = sábado 31 oct 21:00 en CDMX.
  const now = '2026-11-01T03:00:00Z'

  it('calcula los rangos con el día civil del tenant', () => {
    expect(tenantDatePreset('today', MX, now)).toEqual({ from: '2026-10-31', to: '2026-10-31' })
    expect(tenantDatePreset('overdue', MX, now)).toEqual({ from: null, to: '2026-10-30' })
    expect(tenantDatePreset('this_week', MX, now)).toEqual({ from: '2026-10-26', to: '2026-11-01' })
    expect(tenantDatePreset('this_month', MX, now)).toEqual({
      from: '2026-10-01',
      to: '2026-10-31',
    })
  })
})

describe('comparación de instantes', () => {
  it('compara y ordena instantes por su epoch, no por texto', () => {
    expect(compareInstants('2026-10-21T03:00:00Z', '2026-10-20T22:00:00-06:00')).toBeLessThan(0)
    expect(isInstantAtOrAfter('2026-10-21T03:00:00Z', '2026-10-21T02:59:59Z')).toBe(true)
    expect(isInstantAtOrAfter('2026-10-21T03:00:00Z', '2026-10-21T03:00:01Z')).toBe(false)
  })

  it('convierte segundos unix a un instante ISO UTC', () => {
    expect(unixSecondsToInstant(1792551600)).toBe('2026-10-21T03:00:00Z')
  })
})

describe('fechas de pared para calendarios (agenda)', () => {
  it('expone la hora de pared del tenant en los componentes locales del Date', () => {
    // 2026-10-21T03:00Z es 20 oct 21:00 en Ciudad de México.
    const wall = instantToTenantWallDate('2026-10-21T03:00:00Z', MX)
    expect([wall.getFullYear(), wall.getMonth() + 1, wall.getDate(), wall.getHours(), wall.getMinutes()]).toEqual([
      2026, 10, 20, 21, 0,
    ])
  })

  it('regresa al mismo instante UTC desde la hora de pared', () => {
    const wall = instantToTenantWallDate('2026-10-21T16:30:00Z', MX)
    expect(tenantWallDateToUtc(wall, MX)).toEqual({ ok: true, value: '2026-10-21T16:30:00Z' })
  })

  it('convierte fechas civiles sin desplazarlas', () => {
    const wall = civilDateToWallDate('2026-10-20')
    expect(wallDateToCivilDate(wall)).toBe('2026-10-20')
    expect([wall.getHours(), wall.getMinutes()]).toEqual([0, 0])
  })

  it('serializa la fecha de pared para un input datetime-local', () => {
    expect(wallDateToDateTimeLocal(instantToTenantWallDate('2026-10-21T16:05:00Z', MX))).toBe('2026-10-21T10:05')
  })
})

describe('hora y rangos de la agenda', () => {
  it('formatea la hora en la zona del tenant', () => {
    expect(formatTenantTime('2026-10-21T03:05:00Z', MX)).toBe('21:05')
  })

  it('ubica el instante en el día civil del tenant', () => {
    expect(instantToTenantCivilDate('2026-10-21T03:05:00Z', MX)).toBe('2026-10-20')
  })

  it('convierte un rango de días civiles a límites UTC semiabiertos', () => {
    expect(tenantCivilRangeToUtc('2026-10-20', '2026-10-20', MX)).toEqual({
      start: '2026-10-20T06:00:00Z',
      end: '2026-10-21T06:00:00Z',
    })
  })
})

describe('civilWeekBounds', () => {
  it('devuelve la semana de lunes a domingo', () => {
    expect(civilWeekBounds('2026-10-21')).toEqual({ from: '2026-10-19', to: '2026-10-25' })
    expect(civilWeekBounds('2026-10-25')).toEqual({ from: '2026-10-19', to: '2026-10-25' })
  })
})

describe('civilDaysBetween', () => {
  it('counts whole civil days, crossing months and leap years', () => {
    expect(civilDaysBetween('2025-04-15', '2026-10-21')).toBe(554)
    expect(civilDaysBetween('2024-02-28', '2024-03-01')).toBe(2)
    expect(civilDaysBetween('2026-10-21', '2026-10-21')).toBe(0)
  })
})
