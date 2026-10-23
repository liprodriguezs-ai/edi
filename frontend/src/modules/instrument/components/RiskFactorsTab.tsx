import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useRiskFactors } from '@/modules/instrument/hooks/useInstrumentQueries'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'

const AUTO_DERIVATION_LABELS: Record<string, string> = {
  gestational_age_lt_34_weeks: 'Edad gestacional < 34 semanas',
  birth_weight_lte_1500_g: 'Peso al nacer ≤ 1500 g',
  mother_age_lt_16_years: 'Madre menor de 16 años',
}

export function RiskFactorsTab({ versionId }: { versionId: string }) {
  const { data: factors, isLoading } = useRiskFactors(versionId)

  if (isLoading) return <InlineLoading name='instrument-risk-factors' />
  if (!factors || factors.length === 0) return <InlineEmpty>Sin factores de riesgo.</InlineEmpty>

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Código</TableHead>
          <TableHead>Pregunta</TableHead>
          <TableHead>Auto-derivación</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {factors.map((factor) => (
          <TableRow key={factor.id}>
            <TableCell className="font-mono text-xs">{factor.code}</TableCell>
            <TableCell className="text-sm">{factor.question}</TableCell>
            <TableCell>
              {factor.auto_derivation_key ? (
                <Badge variant="secondary">{AUTO_DERIVATION_LABELS[factor.auto_derivation_key] ?? factor.auto_derivation_key}</Badge>
              ) : (
                <span className="text-xs text-muted-foreground">Manual</span>
              )}
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}
