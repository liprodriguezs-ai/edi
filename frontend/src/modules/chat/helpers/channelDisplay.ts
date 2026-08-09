import { CHAT_CHANNEL_TYPE, type IChatChannel } from '@/modules/chat/schema'

/** Un canal directo se muestra con el nombre del OTRO miembro, no su nombre técnico. */
export function displayChannelName(channel: IChatChannel, currentUserId?: string): string {
  if (channel.chat_channel_type_id !== CHAT_CHANNEL_TYPE.DIRECT) return channel.name
  const other = channel.members?.find((member) => member.user_id !== currentUserId)
  return other?.user?.name ?? 'Mensaje directo'
}
