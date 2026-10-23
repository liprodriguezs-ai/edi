import { PageLoader } from '@/shared/components/PageLoader'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { AreaRulesTab } from '@/modules/instrument/components/AreaRulesTab'
import { GlobalRulesTab } from '@/modules/instrument/components/GlobalRulesTab'
import { GroupsTab } from '@/modules/instrument/components/GroupsTab'
import { HeadCircumferenceTab } from '@/modules/instrument/components/HeadCircumferenceTab'
import { RiskFactorsTab } from '@/modules/instrument/components/RiskFactorsTab'
import { useInstrumentVersion } from '@/modules/instrument/hooks/useInstrumentQueries'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { DescriptionItem, DescriptionList } from '@/shared/components/molecules/DescriptionList'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Navigate, useParams } from 'react-router-dom'

const AGE_MODE_LABELS: Record<string, string> = {
  thirty_day_month: 'Mes de 30 días (legado)',
  calendar: 'Calendario real',
}

export function InstrumentVersionDetailPage() {
  const { id } = useParams<{ id: string }>()
  const { data: version, isLoading } = useInstrumentVersion(id)

  if (!id) return <Navigate to="/instrument" replace />

  if (isLoading) {
    return <PageLoader />
  }

  if (!version) {
    return <EntityNotFound title="No se encontró la versión." backTo="/instrument" backLabel="Volver al instrumento" />
  }

  return (
    <div className="space-y-6">
      <PageHeader
        back={{ to: '/instrument', label: 'Volver al instrumento' }}
        title={version.name}
        description={`Clave: ${version.key} · Modo de edad: ${AGE_MODE_LABELS[version.age_calculation_mode] ?? version.age_calculation_mode}`}
        actions={version.is_default ? <StatusPill tone="b">Predeterminada</StatusPill> : undefined}
      />

      <DescriptionList columns={3}>
        <DescriptionItem label="Fecha de publicación" value={displayFormatDate(version.published_at, 'mediumDate')} mono />
        <DescriptionItem label="Hash de contenido" value={version.content_hash ? version.content_hash.slice(0, 16) : '—'} mono />
        <DescriptionItem label="Notas de origen" value={version.source_note || '—'} />
      </DescriptionList>

      <Tabs defaultValue="groups">
        <TabsList>
          <TabsTrigger value="groups">Grupos</TabsTrigger>
          <TabsTrigger value="area-rules">Reglas por eje</TabsTrigger>
          <TabsTrigger value="global-rules">Reglas globales</TabsTrigger>
          <TabsTrigger value="risk-factors">Factores de riesgo</TabsTrigger>
          <TabsTrigger value="head-circumference">Perímetro cefálico</TabsTrigger>
        </TabsList>

        <TabsContent value="groups">
          <GroupsTab versionId={version.id} />
        </TabsContent>
        <TabsContent value="area-rules">
          <AreaRulesTab versionId={version.id} />
        </TabsContent>
        <TabsContent value="global-rules">
          <GlobalRulesTab versionId={version.id} />
        </TabsContent>
        <TabsContent value="risk-factors">
          <RiskFactorsTab versionId={version.id} />
        </TabsContent>
        <TabsContent value="head-circumference">
          <HeadCircumferenceTab versionId={version.id} />
        </TabsContent>
      </Tabs>
    </div>
  )
}

