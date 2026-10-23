import { defaultAgendaCenter } from '@/modules/appointment/utils/defaultAgendaCenter'
import { describe, expect, it } from 'vitest'

describe('defaultAgendaCenter', () => {
  const practitioners = [
    { user_id: 'u-1', health_center_id: 'c-1' },
    { user_id: 'u-2', health_center_id: 'c-2' },
  ]

  it('usa el centro del profesional que inicia sesión', () => {
    expect(defaultAgendaCenter(practitioners, 'u-2')).toBe('c-2')
  })

  it('usa el único centro cuando todos trabajan en el mismo', () => {
    expect(defaultAgendaCenter([{ user_id: 'u-1', health_center_id: 'c-1' }, { user_id: 'u-3', health_center_id: 'c-1' }], 'x')).toBe('c-1')
  })

  it('muestra todos los centros cuando no hay forma de decidir', () => {
    expect(defaultAgendaCenter(practitioners, 'recepcion')).toBeNull()
    expect(defaultAgendaCenter([], null)).toBeNull()
  })
})
