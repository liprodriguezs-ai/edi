import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { ResultColorPill } from '@/shared/components/atoms/ResultColorPill'
import type { IScreening } from '@/modules/screening/schema'

interface GlobalResultCardProps {
  screening: IScreening
}

/** REQ-SUI-008 — color, Spanish message, referral bullets, counters — all server-owned. */
export function GlobalResultCard({ screening }: GlobalResultCardProps) {
  if (!screening.result_color) {
    return (
      <Card>
        <CardContent className="pt-6">
          <InlineEmpty>Aún sin calificar.</InlineEmpty>
        </CardContent>
      </Card>
    )
  }

  const message = screening.global_result_message

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-3">
        <CardTitle className="text-base">Resultado global</CardTitle>
        <ResultColorPill colorKey={screening.result_color.key} />
      </CardHeader>
      <CardContent className="space-y-3">
        {message?.headline && <p className="text-sm">{message.headline}</p>}

        {message?.referral_items && message.referral_items.length > 0 && (
          <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
            {message.referral_items.map((item) => (
              <li key={item}>{item}</li>
            ))}
          </ul>
        )}

        <div className="flex flex-wrap gap-4 pt-2 text-sm">
          <span>
            <span className="text-muted-foreground">Signos de alerta: </span>
            {screening.alert_sign_count ?? '—'}
          </span>
          <span>
            <span className="text-muted-foreground">Factores de riesgo: </span>
            {screening.risk_factor_count ?? '—'}
          </span>
        </div>
      </CardContent>
    </Card>
  )
}
