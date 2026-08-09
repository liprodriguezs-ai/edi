import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { cn } from '@/lib/utils'
import { displayInitials } from '@/shared/utils/displayInitials'

interface ProfileIdentityProps {
  name: string
  subtitle?: string
  imageUrl?: string | null
  avatarSize?: 'sm' | 'md' | 'lg'
  nameTag?: 'h1' | 'h2' | 'h3' | 'p'
  className?: string
  number?: string
}

const sizeClasses = {
  sm: { avatar: 'size-10 text-base', name: 'text-base' },
  md: { avatar: 'size-12 text-lg', name: 'text-xl' },
  lg: { avatar: 'h-24 w-24 text-2xl', name: 'text-lg' },
} as const

export function ProfileIdentity({
  name,
  number,
  subtitle,
  imageUrl,
  avatarSize = 'md',
  nameTag: NameTag = 'h1',
  className,
}: ProfileIdentityProps) {
  const sizes = sizeClasses[avatarSize]

  return (
    <div className={cn('flex items-center gap-3', className)}>
      <Avatar className={sizes.avatar}>
        {imageUrl && <AvatarImage src={imageUrl} alt={name} />}
        <AvatarFallback className="bg-primary/10 text-primary font-semibold">
          {displayInitials(name)}
        </AvatarFallback>
      </Avatar>

      <div className="min-w-0">
        
        <NameTag className={cn('font-semibold truncate', sizes.name)}>{name}</NameTag>
        {number && (
          <p className="text-sm text-muted-foreground truncate">Numero de colaborador: {number}</p>
        )}
        {subtitle && (
          <p className="text-sm text-muted-foreground truncate">{subtitle}</p>
        )}
      </div>
    </div>
  )
}
