import { ResidentInvitationResource } from '@/modules/residentInvitation/services/ResidentInvitationResource'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

/**
 * Pending invitations for a single unit, keyed by unitId (mirrors the embedded
 * query pattern of UnitDetailPage — no shared global store, so multiple units on
 * MyUnitPage never collide).
 */
export function useUnitInvitations(unitId: string, enabled = true) {
  const queryClient = useQueryClient()
  const queryKey = ['unit', 'invitations', unitId] as const

  const query = useQuery({
    queryKey,
    queryFn: () => ResidentInvitationResource.listByUnit(unitId),
    enabled: enabled && Boolean(unitId),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey })

  const resend = useMutation({
    mutationFn: (id: string) => ResidentInvitationResource.resend(id),
    onSuccess: invalidate,
  })

  const revoke = useMutation({
    mutationFn: (id: string) => ResidentInvitationResource.revoke(id),
    onSuccess: invalidate,
  })

  return { ...query, invalidate, resend, revoke }
}
