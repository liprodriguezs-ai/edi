import { useOnboardingChecklist } from '@/modules/onboarding/hooks/useOnboardingChecklist'
import { Link } from 'react-router-dom'

/**
 * Compact pill shown in the site header for administrator / account.owner.
 * Hidden once onboarding is complete or for non-admin roles.
 */
export function OnboardingProgressPill() {
  const { checklist, isLoading, isComplete, isAdmin } = useOnboardingChecklist()

  if (!isAdmin || isLoading || !checklist || isComplete) {
    return null
  }

  const { required_completed, required_total, progress_percentage } = checklist

  return (
    <Link
      to="/onboarding"
      className="hidden items-center gap-2 rounded-full border border-border bg-card px-3 py-1.5 text-[12px] text-[var(--ink-2)] transition-colors hover:border-[var(--green)]/50 hover:text-foreground sm:flex"
      aria-label={`Configuración: ${progress_percentage}% completado`}
    >
      <span className="tabular-nums font-medium text-foreground">
        {required_completed}/{required_total}
      </span>
      <div
        className="h-1.5 w-14 overflow-hidden rounded-full bg-muted"
        role="progressbar"
        aria-valuenow={progress_percentage}
        aria-valuemin={0}
        aria-valuemax={100}
      >
        <div
          className="h-full rounded-full bg-[var(--green)] transition-all duration-300"
          style={{ width: `${progress_percentage}%` }}
        />
      </div>
    </Link>
  )
}
