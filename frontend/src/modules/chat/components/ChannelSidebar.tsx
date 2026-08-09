import { displayChannelName } from '@/modules/chat/helpers/channelDisplay'
import { CHAT_CHANNEL_TYPE, type IChatChannel } from '@/modules/chat/schema'
import { Eye, Hash, Lock, UserRound } from 'lucide-react'

interface ChannelSidebarProps {
  channels: IChatChannel[]
  isLoading: boolean
  activeId: string | null
  currentUserId?: string
  onSelect: (_id: string) => void
}

function ChannelIcon({ channel }: { channel: IChatChannel }) {
  if (channel.chat_channel_type_id === CHAT_CHANNEL_TYPE.DIRECT) return <UserRound className="size-3.5 flex-none" />
  if (channel.is_system) return <Lock className="size-3.5 flex-none" />
  return <Hash className="size-3.5 flex-none" />
}

/** Lista lateral de canales del chat del condominio. */
export function ChannelSidebar({ channels, isLoading, activeId, currentUserId, onSelect }: ChannelSidebarProps) {
  return (
    <div className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
      <div className="border-b border-[var(--line-2)] px-4 py-3">
        <h3 className="text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]">Canales</h3>
      </div>
      <div className="max-h-[60vh] overflow-y-auto p-1.5">
        {isLoading ? (
          <p className="px-3 py-4 text-[13px] text-[var(--ink-3)]">Cargando…</p>
        ) : channels.length === 0 ? (
          <p className="px-3 py-4 text-[13px] text-[var(--ink-3)]">Sin canales.</p>
        ) : (
          channels.map((channel) => (
            <button
              key={channel.id}
              type="button"
              onClick={() => onSelect(channel.id)}
              className={`flex w-full items-center gap-2 rounded-[9px] px-3 py-2 text-left text-[13.5px] transition-colors ${
                channel.id === activeId
                  ? 'bg-[var(--green-bg)] font-semibold text-[var(--green-deep)]'
                  : 'text-[var(--ink-2)] hover:bg-[var(--surface-2)]'
              }`}
            >
              <ChannelIcon channel={channel} />
              <span className="truncate">{displayChannelName(channel, currentUserId)}</span>
              {channel.can_write === false ? (
                <Eye className="ml-auto size-3.5 flex-none text-[var(--ink-3)]" aria-label="Solo lectura" />
              ) : null}
            </button>
          ))
        )}
      </div>
    </div>
  )
}
