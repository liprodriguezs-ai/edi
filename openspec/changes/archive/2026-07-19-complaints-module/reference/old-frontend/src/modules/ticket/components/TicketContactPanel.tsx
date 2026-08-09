import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import type { IClient } from '@/modules/client/schema'
import { displayInitials } from '@/shared/utils/displayInitials'
import { Mail, Phone, User } from 'lucide-react'

interface TicketContactPanelProps {
  client: IClient | undefined
}

export function TicketContactPanel({ client }: TicketContactPanelProps) {
  if (!client) {
    return (
      <div className="flex h-full flex-col items-center justify-center gap-3 p-6">
        <User className="size-10 text-muted-foreground/40" />
        <p className="text-sm text-muted-foreground">Sin cliente asignado</p>
      </div>
    )
  }

  const email = client.user?.email
  const phone = client.user?.phone

  return (
    <div className="flex flex-col p-5">
      {/* Section header */}
      <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-5">
        Informaci\u00f3n del contacto
      </span>

      {/* Avatar + Name centered */}
      <div className="flex flex-col items-center gap-3 mb-6">
        <Avatar className="h-14 w-14 ring-2 ring-primary/20">
          <AvatarFallback className="bg-primary/10 text-primary text-base font-medium">
            {displayInitials(client.name)}
          </AvatarFallback>
        </Avatar>
        <h3 className="text-center font-semibold text-foreground">{client.name}</h3>
      </div>

      {/* Contact info */}
      <div className="w-full space-y-3 rounded-lg bg-muted/30 p-3">
        {email && (
          <div className="flex items-center gap-2.5">
            <Mail className="size-4 shrink-0 text-muted-foreground" />
            <span className="truncate text-sm text-foreground/80">{email}</span>
          </div>
        )}

        {phone && (
          <div className="flex items-center gap-2.5">
            <Phone className="size-4 shrink-0 text-muted-foreground" />
            <span className="text-sm text-foreground/80">{phone}</span>
          </div>
        )}

        {!email && !phone && (
          <p className="text-center text-sm text-muted-foreground py-2">
            Sin datos de contacto
          </p>
        )}
      </div>
    </div>
  )
}
