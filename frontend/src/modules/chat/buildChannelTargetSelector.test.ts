import {
  buildChannelFormDefaults,
  buildChannelTargetSelector,
  buildChannelWriteTargetSelector,
  type ChannelFormValues,
  type IChatChannel,
} from '@/modules/chat/schema'
import { describe, expect, it } from 'vitest'

/** Base form values for tests — no mode, includes both read and write target fields. */
function values(overrides: Partial<ChannelFormValues>): ChannelFormValues {
  return {
    name: 'Canal',
    chat_channel_type_id: 'type-1',
    icon: '',
    description: '',
    // READ target defaults
    target_type: 'all',
    target_unit_type_ids: [],
    target_tower_ids: [],
    target_section_ids: [],
    target_floors: [],
    target_unit_feature_ids: [],
    target_unit_id: undefined,
    target_unit_ids: [],
    // WRITE target defaults
    write_target_type: 'all',
    write_target_unit_type_ids: [],
    write_target_tower_ids: [],
    write_target_section_ids: [],
    write_target_floors: [],
    write_target_unit_feature_ids: [],
    write_target_unit_id: undefined,
    write_target_unit_ids: [],
    notify_on_post: true,
    ...overrides,
  }
}

// ── buildChannelTargetSelector (READ) ─────────────────────────────────────────

describe('buildChannelTargetSelector', () => {
  it('returns null for all and board (no selector needed)', () => {
    expect(buildChannelTargetSelector(values({ target_type: 'all' }))).toBeNull()
    expect(buildChannelTargetSelector(values({ target_type: 'board' }))).toBeNull()
  })

  it('maps by_type to { unit_type_ids } with a single type', () => {
    expect(
      buildChannelTargetSelector(values({ target_type: 'by_type', target_unit_type_ids: ['ut-1'] }))
    ).toEqual({ unit_type_ids: ['ut-1'] })
  })

  it('maps by_type to { unit_type_ids } with multiple types', () => {
    expect(
      buildChannelTargetSelector(
        values({ target_type: 'by_type', target_unit_type_ids: ['ut-1', 'ut-2', 'ut-3'] })
      )
    ).toEqual({ unit_type_ids: ['ut-1', 'ut-2', 'ut-3'] })
  })

  it('maps by_tower to { tower_ids }', () => {
    expect(
      buildChannelTargetSelector(
        values({ target_type: 'by_tower', target_tower_ids: ['t1', 't2'] })
      )
    ).toEqual({ tower_ids: ['t1', 't2'] })
  })

  it('maps by_section to { section_ids }', () => {
    expect(
      buildChannelTargetSelector(
        values({ target_type: 'by_section', target_section_ids: ['s1', 's2'] })
      )
    ).toEqual({ section_ids: ['s1', 's2'] })
  })

  it('returns null for with_vehicles (no selector needed)', () => {
    expect(buildChannelTargetSelector(values({ target_type: 'with_vehicles' }))).toBeNull()
  })

  it('maps by_floor to { tower_ids, floors }', () => {
    expect(
      buildChannelTargetSelector(
        values({ target_type: 'by_floor', target_tower_ids: ['t1'], target_floors: ['3', '4'] })
      )
    ).toEqual({ tower_ids: ['t1'], floors: ['3', '4'] })
  })

  it('maps by_feature to { unit_feature_ids }', () => {
    expect(
      buildChannelTargetSelector(
        values({ target_type: 'by_feature', target_unit_feature_ids: ['f1', 'f2'] })
      )
    ).toEqual({ unit_feature_ids: ['f1', 'f2'] })
  })

  it('maps single_unit to { unit_id }', () => {
    expect(
      buildChannelTargetSelector(values({ target_type: 'single_unit', target_unit_id: 'u-abc' }))
    ).toEqual({ unit_id: 'u-abc' })
  })

  it('maps manual to { unit_ids } — UNITS, not people', () => {
    expect(
      buildChannelTargetSelector(values({ target_type: 'manual', target_unit_ids: ['u1', 'u2'] }))
    ).toEqual({ unit_ids: ['u1', 'u2'] })
  })
})

// ── buildChannelWriteTargetSelector (WRITE) ───────────────────────────────────

describe('buildChannelWriteTargetSelector', () => {
  it('returns null for all and board (no selector needed)', () => {
    expect(buildChannelWriteTargetSelector(values({ write_target_type: 'all' }))).toBeNull()
    expect(buildChannelWriteTargetSelector(values({ write_target_type: 'board' }))).toBeNull()
  })

  it('maps by_type to { unit_type_ids } with a single type', () => {
    expect(
      buildChannelWriteTargetSelector(
        values({ write_target_type: 'by_type', write_target_unit_type_ids: ['ut-1'] })
      )
    ).toEqual({ unit_type_ids: ['ut-1'] })
  })

  it('maps by_type to { unit_type_ids } with multiple types', () => {
    expect(
      buildChannelWriteTargetSelector(
        values({ write_target_type: 'by_type', write_target_unit_type_ids: ['ut-1', 'ut-2'] })
      )
    ).toEqual({ unit_type_ids: ['ut-1', 'ut-2'] })
  })

  it('maps by_tower to { tower_ids }', () => {
    expect(
      buildChannelWriteTargetSelector(
        values({ write_target_type: 'by_tower', write_target_tower_ids: ['t1', 't2'] })
      )
    ).toEqual({ tower_ids: ['t1', 't2'] })
  })

  it('maps by_section to { section_ids }', () => {
    expect(
      buildChannelWriteTargetSelector(
        values({ write_target_type: 'by_section', write_target_section_ids: ['s1', 's2'] })
      )
    ).toEqual({ section_ids: ['s1', 's2'] })
  })

  it('returns null for with_vehicles (no selector needed)', () => {
    expect(
      buildChannelWriteTargetSelector(values({ write_target_type: 'with_vehicles' }))
    ).toBeNull()
  })

  it('maps by_floor to { tower_ids, floors }', () => {
    expect(
      buildChannelWriteTargetSelector(
        values({
          write_target_type: 'by_floor',
          write_target_tower_ids: ['t1'],
          write_target_floors: ['3', '4'],
        })
      )
    ).toEqual({ tower_ids: ['t1'], floors: ['3', '4'] })
  })

  it('maps by_feature to { unit_feature_ids }', () => {
    expect(
      buildChannelWriteTargetSelector(
        values({ write_target_type: 'by_feature', write_target_unit_feature_ids: ['f1', 'f2'] })
      )
    ).toEqual({ unit_feature_ids: ['f1', 'f2'] })
  })

  it('maps single_unit to { unit_id }', () => {
    expect(
      buildChannelWriteTargetSelector(
        values({ write_target_type: 'single_unit', write_target_unit_id: 'u-abc' })
      )
    ).toEqual({ unit_id: 'u-abc' })
  })

  it('maps manual to { unit_ids } — UNITS, not people', () => {
    expect(
      buildChannelWriteTargetSelector(
        values({ write_target_type: 'manual', write_target_unit_ids: ['u1', 'u2'] })
      )
    ).toEqual({ unit_ids: ['u1', 'u2'] })
  })
})

// ── buildChannelFormDefaults ───────────────────────────────────────────────────

describe('buildChannelFormDefaults', () => {
  it('blanks for a new channel: read=all / write=all / notify on', () => {
    const defaults = buildChannelFormDefaults(null)
    expect(defaults.target_type).toBe('all')
    expect(defaults.write_target_type).toBe('all')
    expect(defaults.notify_on_post).toBe(true)
    expect(defaults.target_tower_ids).toEqual([])
    expect(defaults.target_unit_type_ids).toEqual([])
    expect(defaults.write_target_tower_ids).toEqual([])
    expect(defaults.write_target_unit_type_ids).toEqual([])
  })

  it('hydrates a by_tower channel selector into the flat tower ids', () => {
    const channel = {
      id: 'c1',
      name: 'Torre A',
      target_type: 'by_tower',
      target_selector: { tower_ids: ['t1', 't2'] },
      notify_on_post: false,
    } as unknown as IChatChannel

    const defaults = buildChannelFormDefaults(channel)
    expect(defaults.target_type).toBe('by_tower')
    expect(defaults.target_tower_ids).toEqual(['t1', 't2'])
    expect(defaults.notify_on_post).toBe(false)
  })

  it('hydrates a by_section channel selector and round-trips to the same selector', () => {
    const channel = {
      id: 'cs',
      name: 'Sección Norte',
      target_type: 'by_section',
      target_selector: { section_ids: ['s1', 's2'] },
    } as unknown as IChatChannel

    const defaults = buildChannelFormDefaults(channel)
    expect(defaults.target_type).toBe('by_section')
    expect(defaults.target_section_ids).toEqual(['s1', 's2'])
    expect(buildChannelTargetSelector(defaults)).toEqual({ section_ids: ['s1', 's2'] })
  })

  it('hydrates a by_floor selector into tower ids + floors array (legacy type)', () => {
    const channel = {
      id: 'cf',
      name: 'Pisos',
      target_type: 'by_floor',
      target_selector: { tower_ids: ['t1'], floors: ['3', '4'] },
    } as unknown as IChatChannel

    const defaults = buildChannelFormDefaults(channel)
    expect(defaults.target_tower_ids).toEqual(['t1'])
    expect(defaults.target_floors).toEqual(['3', '4'])
  })

  it('falls back to all when target_type is unknown (e.g. direct)', () => {
    const channel = { id: 'c2', name: 'Directo', target_type: 'direct' } as unknown as IChatChannel
    expect(buildChannelFormDefaults(channel).target_type).toBe('all')
  })

  it('round-trips manual selector → defaults → selector (units, not people)', () => {
    const channel = {
      id: 'c3',
      name: 'Manual',
      target_type: 'manual',
      target_selector: { unit_ids: ['u1', 'u2'] },
    } as unknown as IChatChannel
    const defaults = buildChannelFormDefaults(channel)
    expect(defaults.target_unit_ids).toEqual(['u1', 'u2'])
    expect(buildChannelTargetSelector(defaults)).toEqual({ unit_ids: ['u1', 'u2'] })
  })

  it('hydrates by_type with the FULL unit_type_ids array (multi) and round-trips', () => {
    const channel = {
      id: 'c4',
      name: 'Por tipo',
      target_type: 'by_type',
      target_selector: { unit_type_ids: ['ut-abc', 'ut-def'] },
    } as unknown as IChatChannel
    const defaults = buildChannelFormDefaults(channel)
    expect(defaults.target_unit_type_ids).toEqual(['ut-abc', 'ut-def'])
    expect(buildChannelTargetSelector(defaults)).toEqual({ unit_type_ids: ['ut-abc', 'ut-def'] })
  })

  it('hydrates a legacy scalar unit_type_id string into a 1-element array', () => {
    const channel = {
      id: 'c4b',
      name: 'Legacy por tipo',
      target_type: 'by_type',
      // selector guardado ANTES de la migración SINGLE→MULTI: string suelto.
      target_selector: { unit_type_ids: 'ut-legacy' },
    } as unknown as IChatChannel
    const defaults = buildChannelFormDefaults(channel)
    expect(defaults.target_unit_type_ids).toEqual(['ut-legacy'])
  })

  it('hydrates a board channel (no selector) and round-trips to null', () => {
    const channel = {
      id: 'cb',
      name: 'Mesa',
      target_type: 'board',
      target_selector: null,
    } as unknown as IChatChannel
    const defaults = buildChannelFormDefaults(channel)
    expect(defaults.target_type).toBe('board')
    expect(buildChannelTargetSelector(defaults)).toBeNull()
  })

  it('hydrates write_target from write_target_selector independently of read target', () => {
    const channel = {
      id: 'c5',
      name: 'Mix',
      target_type: 'all',
      target_selector: null,
      write_target_type: 'by_tower',
      write_target_selector: { tower_ids: ['w1', 'w2'] },
    } as unknown as IChatChannel
    const defaults = buildChannelFormDefaults(channel)
    expect(defaults.target_type).toBe('all')
    expect(defaults.write_target_type).toBe('by_tower')
    expect(defaults.write_target_tower_ids).toEqual(['w1', 'w2'])
  })

  it('hydrates write_target by_section independently and round-trips', () => {
    const channel = {
      id: 'cw',
      name: 'Write sección',
      write_target_type: 'by_section',
      write_target_selector: { section_ids: ['ws1'] },
    } as unknown as IChatChannel
    const defaults = buildChannelFormDefaults(channel)
    expect(defaults.write_target_type).toBe('by_section')
    expect(defaults.write_target_section_ids).toEqual(['ws1'])
    expect(buildChannelWriteTargetSelector(defaults)).toEqual({ section_ids: ['ws1'] })
  })

  it('falls back to all for write_target_type when unknown', () => {
    const channel = {
      id: 'c6',
      name: 'X',
      write_target_type: 'unknown_type',
    } as unknown as IChatChannel
    expect(buildChannelFormDefaults(channel).write_target_type).toBe('all')
  })

  it('round-trips write manual selector → defaults → write selector (units, not people)', () => {
    const channel = {
      id: 'c7',
      name: 'Write manual',
      write_target_type: 'manual',
      write_target_selector: { unit_ids: ['wu1', 'wu2'] },
    } as unknown as IChatChannel
    const defaults = buildChannelFormDefaults(channel)
    expect(defaults.write_target_unit_ids).toEqual(['wu1', 'wu2'])
    expect(buildChannelWriteTargetSelector(defaults)).toEqual({ unit_ids: ['wu1', 'wu2'] })
  })

  it('hydrates write_target_unit_type_ids as a full array (multi)', () => {
    const channel = {
      id: 'c8',
      name: 'Write por tipo',
      write_target_type: 'by_type',
      write_target_selector: { unit_type_ids: ['wt-1', 'wt-2'] },
    } as unknown as IChatChannel
    const defaults = buildChannelFormDefaults(channel)
    expect(defaults.write_target_unit_type_ids).toEqual(['wt-1', 'wt-2'])
    expect(buildChannelWriteTargetSelector(defaults)).toEqual({ unit_type_ids: ['wt-1', 'wt-2'] })
  })

  it('hydrates a legacy scalar write_target unit_type_id string into a 1-element array', () => {
    const channel = {
      id: 'c9',
      name: 'Write legacy por tipo',
      write_target_type: 'by_type',
      // selector guardado ANTES de la migración SINGLE→MULTI: string suelto.
      write_target_selector: { unit_type_ids: 'wt-legacy' },
    } as unknown as IChatChannel
    const defaults = buildChannelFormDefaults(channel)
    expect(defaults.write_target_unit_type_ids).toEqual(['wt-legacy'])
  })
})
