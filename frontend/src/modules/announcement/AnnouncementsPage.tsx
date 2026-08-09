import { AnnouncementFeedPage } from '@/modules/announcement/AnnouncementFeedPage'
import { AnnouncementListPage } from '@/modules/announcement/AnnouncementListPage'
import { usePermissions } from '@/shared/hooks/usePermissions'

/**
 * Punto de entrada de Avisos (/announcement): quien gestiona avisos
 * (update.announcement) ve la lista de administración; el residente ve el feed.
 */
export const AnnouncementsPage = () => {
  const canManage = usePermissions('update.announcement')
  return canManage ? <AnnouncementListPage /> : <AnnouncementFeedPage />
}
