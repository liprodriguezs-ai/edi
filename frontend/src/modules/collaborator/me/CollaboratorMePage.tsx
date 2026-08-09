import { Button } from '@/components/ui/button'
import { DossierStatusCard } from '@/modules/collaborator/me/DossierStatusCard'
import { MyDataCard } from '@/modules/collaborator/me/MyDataCard'
import { MyDocumentsCard } from '@/modules/collaborator/me/MyDocumentsCard'
import { useCollaboratorMe } from '@/modules/collaborator/me/useCollaboratorMe'
import { memo } from 'react'
import { useNavigate } from 'react-router-dom'

function CollaboratorMePageInner() {
  const { collaborator, isLoading, error, isNotFound } = useCollaboratorMe()
  const navigate = useNavigate()

  if (error && !isLoading) {
    return (
      <div className="flex flex-col items-center justify-center gap-4 py-12">
        <p className="text-center text-muted-foreground">
          {isNotFound
            ? 'No tienes un expediente de colaborador asociado.'
            : error}
        </p>
        {isNotFound && (
          <Button variant="outline" onClick={() => navigate('/')}>
            Ir al inicio
          </Button>
        )}
      </div>
    )
  }

  return (
    <div className="space-y-8">
      <header>
        <h1 className="text-2xl font-bold tracking-tight">Mi expediente</h1>
        <p className="mt-1 text-muted-foreground">
          Revisa el estado de tu expediente, datos personales y documentos.
        </p>
      </header>

      <div className="grid gap-6 lg:grid-cols-2">
        <DossierStatusCard collaborator={collaborator} isLoading={isLoading} />
      </div>

      <MyDataCard collaborator={collaborator} isLoading={isLoading} />
      <MyDocumentsCard collaborator={collaborator} isLoading={isLoading} />
    </div>
  )
}

export const CollaboratorMePage = memo(CollaboratorMePageInner)
