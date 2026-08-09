import {
  buildAnnouncementFormDefaults,
  buildAnnouncementTargetSelector,
  type AnnouncementFormValues,
  type IAnnouncement,
} from '@/modules/announcement/schema'
import { describe, expect, it } from 'vitest'

/** Base form values for tests — includes the alcance (target_*) fields. */
function values(overrides: Partial<AnnouncementFormValues>): AnnouncementFormValues {
  return {
    title: 'Aviso',
    content_html: '<p>Contenido del aviso</p>',
    content: 'Contenido del aviso',
    announcement_type_id: 'type-1',
    announcement_priority_id: 'prio-1',
    channel_push: true,
    channel_board: true,
    channel_email: false,
    expires_at: '',
    target_type: 'all',
    target_unit_type_ids: [],
    target_tower_ids: [],
    target_section_ids: [],
    target_feature_ids: [],
    target_floor: '',
    target_unit_id: undefined,
    target_unit_ids: [],
    ...overrides,
  }
}

// ── buildAnnouncementTargetSelector ───────────────────────────────────────────

describe('buildAnnouncementTargetSelector', () => {
  it('returns null for all, board and with_vehicles (no selector needed)', () => {
    expect(buildAnnouncementTargetSelector(values({ target_type: 'all' }))).toBeNull()
    expect(buildAnnouncementTargetSelector(values({ target_type: 'board' }))).toBeNull()
    expect(buildAnnouncementTargetSelector(values({ target_type: 'with_vehicles' }))).toBeNull()
  })

  it('maps by_type to { unit_type_ids } (single and multiple)', () => {
    expect(
      buildAnnouncementTargetSelector(
        values({ target_type: 'by_type', target_unit_type_ids: ['ut-1'] })
      )
    ).toEqual({ unit_type_ids: ['ut-1'] })
    expect(
      buildAnnouncementTargetSelector(
        values({ target_type: 'by_type', target_unit_type_ids: ['ut-1', 'ut-2'] })
      )
    ).toEqual({ unit_type_ids: ['ut-1', 'ut-2'] })
  })

  it('maps by_tower to { tower_ids }', () => {
    expect(
      buildAnnouncementTargetSelector(
        values({ target_type: 'by_tower', target_tower_ids: ['t1', 't2'] })
      )
    ).toEqual({ tower_ids: ['t1', 't2'] })
  })

  it('maps by_section to { section_ids }', () => {
    expect(
      buildAnnouncementTargetSelector(
        values({ target_type: 'by_section', target_section_ids: ['s1', 's2'] })
      )
    ).toEqual({ section_ids: ['s1', 's2'] })
  })

  it('maps by_feature to { unit_feature_ids }', () => {
    expect(
      buildAnnouncementTargetSelector(
        values({ target_type: 'by_feature', target_feature_ids: ['f1', 'f2'] })
      )
    ).toEqual({ unit_feature_ids: ['f1', 'f2'] })
  })

  it('maps manual to { unit_ids } — UNITS, resolved to residents server-side', () => {
    expect(
      buildAnnouncementTargetSelector(
        values({ target_type: 'manual', target_unit_ids: ['u1', 'u2'] })
      )
    ).toEqual({ unit_ids: ['u1', 'u2'] })
  })

  it('maps single_unit to { unit_id } (legacy, hidden in UI)', () => {
    expect(
      buildAnnouncementTargetSelector(
        values({ target_type: 'single_unit', target_unit_id: 'u-abc' })
      )
    ).toEqual({ unit_id: 'u-abc' })
  })
})

// ── buildAnnouncementFormDefaults ─────────────────────────────────────────────

describe('buildAnnouncementFormDefaults', () => {
  it('blanks for a new announcement: target=all, default channels, empty arrays', () => {
    const defaults = buildAnnouncementFormDefaults(null)
    expect(defaults.target_type).toBe('all')
    expect(defaults.channel_push).toBe(true)
    expect(defaults.channel_board).toBe(true)
    expect(defaults.channel_email).toBe(false)
    expect(defaults.target_tower_ids).toEqual([])
    expect(defaults.target_unit_type_ids).toEqual([])
    expect(defaults.target_feature_ids).toEqual([])
    expect(defaults.target_unit_ids).toEqual([])
  })

  it('hydrates a by_tower announcement selector into the flat tower ids', () => {
    const announcement = {
      id: 'a1',
      title: 'Torre A',
      target_type: 'by_tower',
      target_selector: { tower_ids: ['t1', 't2'] },
    } as unknown as IAnnouncement

    const defaults = buildAnnouncementFormDefaults(announcement)
    expect(defaults.target_type).toBe('by_tower')
    expect(defaults.target_tower_ids).toEqual(['t1', 't2'])
  })

  it('hydrates by_section and round-trips to the same selector', () => {
    const announcement = {
      id: 'a2',
      title: 'Sección Norte',
      target_type: 'by_section',
      target_selector: { section_ids: ['s1', 's2'] },
    } as unknown as IAnnouncement

    const defaults = buildAnnouncementFormDefaults(announcement)
    expect(defaults.target_section_ids).toEqual(['s1', 's2'])
    expect(buildAnnouncementTargetSelector(defaults)).toEqual({ section_ids: ['s1', 's2'] })
  })

  it('hydrates by_feature and round-trips (unit_feature_ids key)', () => {
    const announcement = {
      id: 'a3',
      title: 'Con alberca',
      target_type: 'by_feature',
      target_selector: { unit_feature_ids: ['f1', 'f2'] },
    } as unknown as IAnnouncement

    const defaults = buildAnnouncementFormDefaults(announcement)
    expect(defaults.target_feature_ids).toEqual(['f1', 'f2'])
    expect(buildAnnouncementTargetSelector(defaults)).toEqual({ unit_feature_ids: ['f1', 'f2'] })
  })

  it('round-trips manual selector → defaults → selector', () => {
    const announcement = {
      id: 'a4',
      title: 'Manual',
      target_type: 'manual',
      target_selector: { unit_ids: ['u1', 'u2'] },
    } as unknown as IAnnouncement

    const defaults = buildAnnouncementFormDefaults(announcement)
    expect(defaults.target_unit_ids).toEqual(['u1', 'u2'])
    expect(buildAnnouncementTargetSelector(defaults)).toEqual({ unit_ids: ['u1', 'u2'] })
  })

  it('hydrates a legacy scalar unit_type_ids string into a 1-element array', () => {
    const announcement = {
      id: 'a5',
      title: 'Legacy por tipo',
      target_type: 'by_type',
      target_selector: { unit_type_ids: 'ut-legacy' },
    } as unknown as IAnnouncement

    const defaults = buildAnnouncementFormDefaults(announcement)
    expect(defaults.target_unit_type_ids).toEqual(['ut-legacy'])
  })

  it('hydrates a board announcement (no selector) and round-trips to null', () => {
    const announcement = {
      id: 'a6',
      title: 'Solo mesa',
      target_type: 'board',
      target_selector: null,
    } as unknown as IAnnouncement

    const defaults = buildAnnouncementFormDefaults(announcement)
    expect(defaults.target_type).toBe('board')
    expect(buildAnnouncementTargetSelector(defaults)).toBeNull()
  })

  it('falls back to all when target_type is missing or unknown', () => {
    expect(
      buildAnnouncementFormDefaults({ id: 'a7', title: 'X' } as unknown as IAnnouncement)
        .target_type
    ).toBe('all')
    expect(
      buildAnnouncementFormDefaults({
        id: 'a8',
        title: 'Y',
        target_type: 'nope',
      } as unknown as IAnnouncement).target_type
    ).toBe('all')
  })

  it('trims an ISO expires_at timestamp to a YYYY-MM-DD date input value', () => {
    const announcement = {
      id: 'a9',
      title: 'Con vencimiento',
      expires_at: '2026-07-01T00:00:00.000000Z',
    } as unknown as IAnnouncement

    expect(buildAnnouncementFormDefaults(announcement).expires_at).toBe('2026-07-01')
  })

  it('preserves explicit channel flags from the announcement', () => {
    const announcement = {
      id: 'a10',
      title: 'Canales',
      channel_push: false,
      channel_board: false,
      channel_email: true,
    } as unknown as IAnnouncement

    const defaults = buildAnnouncementFormDefaults(announcement)
    expect(defaults.channel_push).toBe(false)
    expect(defaults.channel_board).toBe(false)
    expect(defaults.channel_email).toBe(true)
  })

  it('hydrates content_html for the rich-text editor (empty for a new announcement)', () => {
    expect(buildAnnouncementFormDefaults(null).content_html).toBe('')
    const announcement = {
      id: 'a11',
      title: 'Con formato',
      content_html: '<p><strong>Hola</strong></p>',
    } as unknown as IAnnouncement
    expect(buildAnnouncementFormDefaults(announcement).content_html).toBe(
      '<p><strong>Hola</strong></p>'
    )
  })
})
