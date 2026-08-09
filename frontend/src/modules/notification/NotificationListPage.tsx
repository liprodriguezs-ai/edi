import { Button } from '@/components/ui/button'
import type { INotificationUser } from '@/modules/notification/schema'
import { NotificationResource } from '@/modules/notification/services/NotificationResource'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCheck } from 'lucide-react'
import { useNavigate } from 'react-router-dom'

/** Centro de notificaciones (§17.2) — historial completo del usuario. */
export const NotificationListPage = () => {
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  const { data: items = [], isLoading } = useQuery({
    queryKey: ['notifications', 'all'],
    queryFn: NotificationResource.search,
  })

  const markAll = useMutation({
    mutationFn: NotificationResource.markAllRead,
    onSuccess: () => {
      toast.success('Notificaciones marcadas como leídas')
      void queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
  })

  const markOne = useMutation({
    mutationFn: NotificationResource.markRead,
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })

  const handleClick = (item: INotificationUser) => {
    if (!item.is_read) markOne.mutate(item.id)
    const action = item.notification?.click_action
    if (action && action.startsWith('/')) navigate(action)
  }

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Notificaciones'
        description='Historial de notificaciones de tu cuenta'
        actions={
          <Button
            variant='outline'
            className='rounded-full'
            onClick={() => markAll.mutate()}
            disabled={markAll.isPending}
          >
            <CheckCheck className='size-4' />
            Marcar todas leídas
          </Button>
        }
      />

      <div className='overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'>
        {isLoading ? (
          <p className='px-4 py-10 text-center text-[13px] text-[var(--ink-3)]'>Cargando…</p>
        ) : items.length === 0 ? (
          <p className='px-4 py-10 text-center text-[13px] text-[var(--ink-3)]'>
            Sin notificaciones.
          </p>
        ) : (
          items.map((item) => (
            <button
              key={item.id}
              type='button'
              onClick={() => handleClick(item)}
              className='flex w-full items-start gap-3 border-b border-[var(--line-2)] px-4 py-3.5 text-left transition-colors last:border-b-0 hover:bg-[var(--surface-2)]'
            >
              <span
                aria-hidden
                className={`mt-1.5 size-2 flex-none rounded-full ${item.is_read ? 'bg-transparent' : 'bg-[var(--green)]'}`}
              />
              <span className='min-w-0 flex-1'>
                <span className='block text-[13.5px] font-semibold'>
                  {item.notification?.title ?? 'Notificación'}
                </span>
                {item.notification?.message && (
                  <span className='mt-0.5 block text-[12.5px] text-[var(--ink-3)]'>
                    {item.notification.message}
                  </span>
                )}
              </span>
              <span className='flex-none text-[11.5px] text-[var(--ink-4)]'>
                {displayFormatDate(item.created_at)}
              </span>
            </button>
          ))
        )}
      </div>
    </div>
  )
}
