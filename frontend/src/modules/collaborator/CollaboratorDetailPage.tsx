import { CollaboratorDetail } from '@/modules/collaborator/CollaboratorDetail'
import { Navigate, useParams } from 'react-router-dom'

export function CollaboratorDetailPage() {
  const { id } = useParams<{ id: string }>()
/*   const { setFullWidth } = useLayoutContext()

  useEffect(() => {
    setFullWidth(true)
    return () => setFullWidth(false)
  }, [setFullWidth])
 */
  if (!id) return <Navigate to="/collaborator" replace />

  return <CollaboratorDetail collaboratorId={id} />
}
