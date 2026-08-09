import { AnnouncementResource } from '@/modules/announcement/services/AnnouncementResource'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

/**
 * Feed de avisos del residente (§12.6): avisos publicados, urgentes primero, con
 * estado de leído por usuario. GET /announcement/board + marcar como leído.
 */
export function useAnnouncementFeed() {
  const queryClient = useQueryClient()

  const { data: announcements = [], isLoading } = useQuery({
    queryKey: ['announcement', 'board'],
    queryFn: () => AnnouncementResource.board(),
  })

  const markReadMutation = useMutation({
    mutationFn: (id: string) => AnnouncementResource.markAsRead(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['announcement', 'board'] }),
  })

  return { announcements, isLoading, markReadMutation }
}
