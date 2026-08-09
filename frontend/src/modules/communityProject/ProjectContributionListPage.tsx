import { ContributionsList } from '@/modules/communityProject/components/ContributionsList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'

/**
 * Aportaciones a proyectos comunitarios (§11) — vista SOLO para la mesa directiva.
 * La lista, búsqueda, filtros y acciones (aportar/reportar/declinar/revertir) viven en el
 * componente compartido `ContributionsList`, el mismo que usa el detalle del proyecto.
 */
export const ProjectContributionListPage = () => {
  return (
    <div className='space-y-6'>
      <ContributionsList
        showProjectColumn
        renderHeader={(exportButton) => (
          <PageHeader
            title='Aportaciones'
            description='Aportaciones por unidad a los proyectos comunitarios'
            actions={exportButton}
          />
        )}
      />
    </div>
  )
}
