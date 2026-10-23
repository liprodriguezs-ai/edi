import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { prettyPrintCondition } from '@/modules/instrument/components/conditionPrettyPrinter'
import { useGlobalScoringRules } from '@/modules/instrument/hooks/useInstrumentQueries'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { ResultColorPill } from '@/shared/components/atoms/ResultColorPill'

export function GlobalRulesTab({ versionId }: { versionId: string }) {
  const { data: rules, isLoading } = useGlobalScoringRules(versionId)

  if (isLoading) return <InlineLoading name='instrument-global-rules' />
  if (!rules || rules.length === 0) return <InlineEmpty>Sin reglas globales.</InlineEmpty>

  const sorted = rules
    .slice()
    .sort((a, b) => (a.age_group?.ordinal ?? 0) - (b.age_group?.ordinal ?? 0) || a.sequence - b.sequence)

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Grupo</TableHead>
          <TableHead>Secuencia</TableHead>
          <TableHead>Condición</TableHead>
          <TableHead>Resultado</TableHead>
          <TableHead>Mensaje</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {sorted.map((rule) => (
          <TableRow key={rule.id}>
            <TableCell>{rule.age_group?.code}</TableCell>
            <TableCell>{rule.sequence}</TableCell>
            <TableCell className="max-w-md text-sm">{prettyPrintCondition(rule.condition)}</TableCell>
            <TableCell>
              <ResultColorPill colorKey={rule.result_color?.key} />
            </TableCell>
            <TableCell>
              <p className="text-sm font-medium">{rule.message?.headline}</p>
              <ul className="mt-1 list-inside list-disc text-xs text-muted-foreground">
                {rule.message?.referral_items.map((bullet, index) => <li key={index}>{bullet}</li>)}
              </ul>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}
