import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useAreaScoringRules } from '@/modules/instrument/hooks/useInstrumentQueries'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'

const SOURCE_LABELS: Record<string, string> = {
  legacy_js: 'Legado (funcionesResultados)',
  legacy_js_commented: 'Legado (bloque comentado, no usado)',
  extrapolated: 'Extrapolado (no usado)',
  oracle_confirmed: 'Confirmado por oráculo',
  manual: 'Manual',
}

export function AreaRulesTab({ versionId }: { versionId: string }) {
  const { data: rules, isLoading } = useAreaScoringRules(versionId)

  if (isLoading) return <InlineLoading name='instrument-area-rules' />
  if (!rules || rules.length === 0) return <InlineEmpty>Sin reglas por eje.</InlineEmpty>

  const sorted = rules
    .slice()
    .sort((a, b) => (a.age_group?.ordinal ?? 0) - (b.age_group?.ordinal ?? 0) || a.development_area!.code.localeCompare(b.development_area!.code))

  return (
    <Table className="border border-border">
      <TableHeader>
        <TableRow>
          <TableHead>Grupo</TableHead>
          <TableHead>Eje</TableHead>
          <TableHead className="text-right font-mono tabular-nums">Reactivos</TableHead>
          <TableHead className="text-right font-mono tabular-nums">Umbral rojo</TableHead>
          <TableHead className="text-right font-mono tabular-nums">Umbral verde</TableHead>
          <TableHead>Permite amarillo</TableHead>
          <TableHead>Origen</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {sorted.map((rule) => (
          <TableRow key={rule.id}>
            <TableCell className="font-mono">{rule.age_group?.code}</TableCell>
            <TableCell className="font-mono">{rule.development_area?.code}</TableCell>
            <TableCell className="text-right font-mono tabular-nums">{rule.item_count}</TableCell>
            <TableCell className="text-right font-mono tabular-nums">{rule.red_threshold}</TableCell>
            <TableCell className="text-right font-mono tabular-nums">{rule.green_threshold ?? '—'}</TableCell>
            <TableCell>{rule.allows_yellow ? 'Sí' : 'No'}</TableCell>
            <TableCell>
              <Badge variant="outline">{SOURCE_LABELS[rule.source] ?? rule.source}</Badge>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}
