import {
  IconBell,
  IconCreditCard,
  IconDotsVertical,
  IconLock,
  IconLogout,
} from '@tabler/icons-react'

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  useSidebar,
} from '@/components/ui/sidebar'
import { ChangePasswordModal } from '@/shared/components/ChangePasswordModal'
import { ProfilePhotoModal } from '@/shared/components/ProfilePhotoModal'
import { useSession } from '@/shared/auth/useSession'
import { useCloseMobileSidebar } from '@/shared/hooks/useCloseMobileSidebar'
import { useRoles } from '@/shared/hooks/useRoles'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { displayInitials } from '@/shared/utils/displayInitials'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'

type SessionUser = ReturnType<typeof useSession>['user']

/** Avatar (imagen o inicial) + nombre/correo; se reutiliza en el trigger y el menú. */
function UserIdentity({ user }: { user: SessionUser }) {
  return (
    <>
      <Avatar className='h-8 w-8 rounded-lg'>
        {user?.profile_image_url && (
          <AvatarImage src={user.profile_image_url} alt={user.name || ''} />
        )}
        <AvatarFallback className='rounded-lg'>{displayInitials(user?.name ?? '')}</AvatarFallback>
      </Avatar>
      <div className='grid flex-1 text-left text-sm leading-tight'>
        <span className='truncate font-medium'>{user?.name}</span>
        <span className='text-muted-foreground truncate text-xs'>{user?.email}</span>
      </div>
    </>
  )
}

export function NavUser() {
  const { isMobile } = useSidebar()
  const { logout, user } = useSession()
  const navigate = useNavigate()
  const isOwner = useRoles(ERoleUserSlug.ACCOUNT_OWNER)
  const [pwdOpen, setPwdOpen] = useState(false)
  const [photoOpen, setPhotoOpen] = useState(false)

  const closeMobileSidebar = useCloseMobileSidebar()

  const handleLogout = () => {
    logout()
  }

  /** En móvil el sidebar es un Sheet: si no lo cerramos, queda abierto sobre la ruta nueva. */
  const goTo = (path: string) => {
    closeMobileSidebar()
    navigate(path)
  }

  return (
    <SidebarMenu>
      <SidebarMenuItem>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <SidebarMenuButton
              size='lg'
              className='data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground'
            >
              <UserIdentity user={user} />
              <IconDotsVertical className='ml-auto size-4' />
            </SidebarMenuButton>
          </DropdownMenuTrigger>
          <DropdownMenuContent
            className='w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg'
            side={isMobile ? 'bottom' : 'right'}
            align='end'
            sideOffset={4}
          >
            <DropdownMenuLabel className='p-0 font-normal'>
              <div className='flex items-center gap-2 px-1 py-1.5 text-left text-sm'>
                <UserIdentity user={user} />
              </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
              {/* <DropdownMenuItem onClick={() => navigate('/company/me')}>
                <IconBuilding />
                Mi empresa
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => setPhotoOpen(true)}>
                <IconCamera />
                Editar foto
              </DropdownMenuItem> */}
              <DropdownMenuItem onClick={() => setPwdOpen(true)}>
                <IconLock />
                Cambiar contraseña
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => goTo('/notification-preferences')}>
                <IconBell />
                Notificaciones
              </DropdownMenuItem>
              {isOwner && (
                <DropdownMenuItem onClick={() => goTo('/subscription')}>
                  <IconCreditCard />
                  Suscripción
                </DropdownMenuItem>
              )}
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem onClick={handleLogout}>
              <IconLogout />
              Salir
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </SidebarMenuItem>

      <ProfilePhotoModal isOpen={photoOpen} onClose={() => setPhotoOpen(false)} />
      <ChangePasswordModal isOpen={pwdOpen} onClose={() => setPwdOpen(false)} />
    </SidebarMenu>
  )
}
