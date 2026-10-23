import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'
import { render, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApplyModeFields } from './ApplyModeFields'

vi.mock('@/modules/screening/services/ScreeningResource', () => ({
  ScreeningResource: {
    ageGroups: vi.fn(),
  },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { error: vi.fn(), success: vi.fn(), info: vi.fn(), warning: vi.fn() },
}))

beforeEach(() => {
  vi.mocked(ScreeningResource.ageGroups).mockReset()
  vi.mocked(ScreeningResource.ageGroups).mockResolvedValue([])
})

describe('ApplyModeFields', () => {
  it('loads the groups when it opens already in "a elección" mode', async () => {
    render(
      <ApplyModeFields
        mode='chosen'
        onModeChange={vi.fn()}
        ageGroupId={null}
        onAgeGroupChange={vi.fn()}
      />
    )

    await waitFor(() => expect(vi.mocked(ScreeningResource.ageGroups)).toHaveBeenCalledTimes(1))
  })

  it('does not load the groups when it opens in age mode', () => {
    render(
      <ApplyModeFields
        mode='age'
        onModeChange={vi.fn()}
        ageGroupId={null}
        onAgeGroupChange={vi.fn()}
      />
    )

    expect(vi.mocked(ScreeningResource.ageGroups)).not.toHaveBeenCalled()
  })
})
