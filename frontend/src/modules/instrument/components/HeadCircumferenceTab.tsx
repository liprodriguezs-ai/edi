import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useHeadCircumferenceReferences } from '@/modules/instrument/hooks/useInstrumentQueries'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'

const SEX_LABELS: Record<string, string> = {
  male: 'Masculino',
  female: 'Femenino',
}

/** mm are stored as exact integers (source cm x 10); shown as cm with one decimal. */
function mmToCm(mm: number): string {
  return (mm / 10).toFixed(1)
}

export function HeadCircumferenceTab({ versionId }: { versionId: string }) {
  const { data: references, isLoading } = useHeadCircumferenceReferences(versionId)

  if (isLoading) return <InlineLoading name='instrument-head-circumference' />
  if (!references || references.length === 0) return <InlineEmpty>Sin datos de referencia.</InlineEmpty>

  const sorted = references
    .slice()
    .sort((a, b) => (a.sex?.key ?? '').localeCompare(b.sex?.key ?? '') || a.age_months - b.age_months)

  return (
    <Table className="border border-border">
      <TableHeader>
        <TableRow>
          <TableHead>Sexo</TableHead>
          <TableHead className="text-right font-mono tabular-nums">Edad (meses)</TableHead>
          <TableHead className="text-right font-mono tabular-nums">Mínimo (cm)</TableHead>
          <TableHead className="text-right font-mono tabular-nums">Máximo (cm)</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {sorted.map((reference) => (
          <TableRow key={reference.id}>
            <TableCell>{SEX_LABELS[reference.sex?.key ?? ''] ?? reference.sex?.key}</TableCell>
            <TableCell className="text-right font-mono tabular-nums">{reference.age_months}</TableCell>
            <TableCell className="text-right font-mono tabular-nums">{mmToCm(reference.min_circumference_mm)}</TableCell>
            <TableCell className="text-right font-mono tabular-nums">{mmToCm(reference.max_circumference_mm)}</TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}
