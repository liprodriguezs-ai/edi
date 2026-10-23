import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useInstrumentVersions } from '@/modules/instrument/hooks/useInstrumentQueries'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useNavigate } from 'react-router-dom'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'

const AGE_MODE_LABELS: Record<string, string> = {
  thirty_day_month: 'Mes de 30 días (legado)',
  calendar: 'Calendario real',
}

export function InstrumentVersionListPage() {
  const navigate = useNavigate()
  const { data: versions, isLoading } = useInstrumentVersions()

  return (
    <div className="space-y-6">
      <PageHeader
        title="Instrumento EDI"
        description="Catálogo de versiones del instrumento (contenido versionado, inmutable una vez publicado)"
      />

      {isLoading ? (
        <TableLoadingState rows={3} />
      ) : !versions || versions.length === 0 ? (
        <InlineEmpty>No hay versiones sembradas.</InlineEmpty>
      ) : (
        <Table className="border border-border">
          <TableHeader>
            <TableRow>
              <TableHead>Clave</TableHead>
              <TableHead>Nombre</TableHead>
              <TableHead>Modo de edad</TableHead>
              <TableHead>Predeterminada</TableHead>
              <TableHead>Fecha de publicación</TableHead>
              <TableHead>Hash</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {versions.map((version) => (
              <TableRow
                key={version.id}
                className="cursor-pointer"
                onClick={() => navigate(`/instrument/${version.id}`)}
              >
                <TableCell className="font-mono font-medium">{version.key}</TableCell>
                <TableCell>{version.name}</TableCell>
                <TableCell>{AGE_MODE_LABELS[version.age_calculation_mode] ?? version.age_calculation_mode}</TableCell>
                <TableCell>
                  {version.is_default ? <StatusPill tone="b">Predeterminada</StatusPill> : null}
                </TableCell>
                <TableCell className="font-mono tabular-nums">{displayFormatDate(version.published_at, 'mediumDate')}</TableCell>
                <TableCell className="font-mono text-xs text-muted-foreground">
                  {version.content_hash ? version.content_hash.slice(0, 12) : '—'}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </div>
  )
}
