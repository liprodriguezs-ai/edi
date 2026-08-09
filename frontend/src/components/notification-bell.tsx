import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import type { INotificationUser } from '@/modules/notification/schema'
import { NotificationUserResource } from '@/modules/notification/services/NotificationUserResource'
import {
  decrementUnread,
  RECENT_KEY,
  resetUnread,
  UNREAD_COUNT_KEY,
} from '@/shared/notifications/unreadCounter'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, CheckCheck } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'

export function NotificationBell() {
  const [open, setOpen] = useState(false)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  // Initial value derived from the server (search meta.total); refetch on focus
  // reconciles any drift (the only resync for users without push). No polling.
  const { data: unreadCount = 0 } = useQuery({
    queryKey: UNREAD_COUNT_KEY,
    queryFn: NotificationUserResource.searchUnread,
    staleTime: 60 * 1000,
    refetchOnWindowFocus: true,
  })

  const { data: recent = [], isLoading } = useQuery({
    queryKey: RECENT_KEY,
    queryFn: NotificationUserResource.searchRecent,
    enabled: open,
    staleTime: 30 * 1000,
  })

  const markAllRead = useMutation({
    mutationFn: () => NotificationUserResource.markAllRead(),
    onSuccess: () => {
      queryClient.setQueryData(UNREAD_COUNT_KEY, resetUnread)
      queryClient.invalidateQueries({ queryKey: RECENT_KEY })
    },
  })

  const markOneRead = useMutation({
    mutationFn: (id: string) => NotificationUserResource.markRead(id),
    onSuccess: () => {
      queryClient.setQueryData(UNREAD_COUNT_KEY, decrementUnread)
      queryClient.invalidateQueries({ queryKey: RECENT_KEY })
    },
  })

  const handleItemClick = (item: INotificationUser) => {
    if (!item.is_read) {
      markOneRead.mutate(item.id)
    }
    const action = item.notification?.click_action
    if (action && action.startsWith('/')) {
      setOpen(false)
      navigate(action)
    }
  }

  return (
    <DropdownMenu open={open} onOpenChange={setOpen}>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          className="relative size-9 rounded-full border border-border bg-card"
          aria-label={
            unreadCount > 0
              ? `Notificaciones: ${unreadCount} sin leer`
              : 'Notificaciones'
          }
        >
          <Bell className="size-4 text-[var(--ink-2)]" />
          {unreadCount > 0 && (
            <span
              aria-hidden
              className="absolute right-[7px] top-[7px] size-2 rounded-full bg-[#C0533C] ring-2 ring-card"
            />
          )}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-[340px] p-0">
        <div className="flex items-center justify-between border-b border-[var(--line-2)] px-4 py-3">
          <span className="text-sm font-semibold">Notificaciones</span>
          {unreadCount > 0 && (
            <button
              type="button"
              onClick={() => markAllRead.mutate()}
              disabled={markAllRead.isPending}
              className="flex items-center gap-1 text-[12px] font-medium text-[var(--green-deep)] hover:underline disabled:opacity-50"
            >
              <CheckCheck className="size-3.5" />
              Marcar todas leídas
            </button>
          )}
        </div>
        <div className="max-h-[360px] overflow-y-auto">
          {isLoading ? (
            <p className="px-4 py-6 text-center text-[13px] text-[var(--ink-3)]">
              Cargando…
            </p>
          ) : recent.length === 0 ? (
            <p className="px-4 py-6 text-center text-[13px] text-[var(--ink-3)]">
              Sin notificaciones por ahora.
            </p>
          ) : (
            recent.map((item) => (
              <button
                key={item.id}
                type="button"
                onClick={() => handleItemClick(item)}
                className="flex w-full items-start gap-2.5 border-b border-[var(--line-2)] px-4 py-3 text-left transition-colors last:border-b-0 hover:bg-[var(--surface-2)]"
              >
                <span
                  aria-hidden
                  className={`mt-1.5 size-2 flex-none rounded-full ${item.is_read ? 'bg-transparent' : 'bg-[var(--green)]'}`}
                />
                {!item.is_read && <span className="sr-only">(sin leer)</span>}
                <span className="min-w-0">
                  <span className="block truncate text-[13px] font-semibold text-foreground">
                    {item.notification?.title ?? 'Notificación'}
                  </span>
                  {item.notification?.message && (
                    <span className="mt-0.5 line-clamp-2 block text-[12.5px] text-[var(--ink-3)]">
                      {item.notification.message}
                    </span>
                  )}
                  <span className="mt-1 block text-[11px] text-[var(--ink-4)]">
                    {displayFormatDate(item.created_at)}
                  </span>
                </span>
              </button>
            ))
          )}
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
