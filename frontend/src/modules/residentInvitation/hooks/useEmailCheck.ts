import { ResidentInvitationResource } from '@/modules/residentInvitation/services/ResidentInvitationResource'
import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/**
 * Debounced (300ms) non-blocking email check used while typing in the invite
 * modal. It never blocks the submit — it only surfaces a warning when the email
 * already belongs to another unit of the same cluster.
 */
export function useEmailCheck(email: string, unitId: string) {
  const [debounced, setDebounced] = useState('')

  useEffect(() => {
    const handle = setTimeout(() => setDebounced(email.trim()), 300)
    return () => clearTimeout(handle)
  }, [email])

  const isValidEmail = EMAIL_RE.test(debounced)

  return useQuery({
    queryKey: ['invitation-email-check', debounced, unitId],
    queryFn: () => ResidentInvitationResource.checkEmail(debounced, unitId),
    enabled: isValidEmail && Boolean(unitId),
    staleTime: 30_000,
  })
}
