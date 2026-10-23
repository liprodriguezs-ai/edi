import type { IScreeningFinding } from '@/modules/screening/schema'
import { findingCopy } from '@/modules/screening/utils/findingCopy'
import { AlertCircle } from 'lucide-react'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'

interface FindingsListProps {
  findings: IScreeningFinding[] | undefined
}

/** REQ-SUI-008/009 — clinical trace findings, rendered from `finding_type.key` + context (D12 copy map). */
export function FindingsList({ findings }: FindingsListProps) {
  if (!findings || findings.length === 0) {
    return <InlineEmpty>Sin hallazgos.</InlineEmpty>
  }

  return (
    <ul className="space-y-2">
      {findings.map((finding) => (
        <li key={finding.id} className="flex items-start gap-2 text-sm">
          <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
          <span>{findingCopy(finding.finding_type?.key, finding.context)}</span>
        </li>
      ))}
    </ul>
  )
}
