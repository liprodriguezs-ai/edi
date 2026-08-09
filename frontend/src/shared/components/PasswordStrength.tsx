import { cn } from '@/lib/utils'
import { useMemo } from 'react'

export type PasswordStrengthLevel = 0 | 1 | 2 | 3 | 4 | 5

const SEGMENTS = 5
const LABELS: Record<PasswordStrengthLevel, string> = {
  0: 'Muy débil',
  1: 'Débil',
  2: 'Aceptable',
  3: 'Buena',
  4: 'Fuerte',
  5: 'Muy fuerte',
}

function computeStrength(password: string): PasswordStrengthLevel {
  if (!password.length) return 0
  let score = 0
  if (password.length >= 8) score++
  if (password.length >= 12) score++
  if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++
  if (/\d/.test(password)) score++
  if (/[^a-zA-Z0-9]/.test(password)) score++
  return Math.min(score, 5) as PasswordStrengthLevel
}

export interface PasswordStrengthProps {
  /** Current password value. */
  value: string
  /** Id for the strength label (for aria-describedby from the input). */
  id?: string
  /** Optional class for the root element. */
  className?: string
  /** Hide the text label, show only the bar. */
  showLabel?: boolean
}

/**
 * Global password strength indicator: segmented bar + optional text label.
 * Use below any password input (signup, reset, accept invitation, user form).
 */
export function PasswordStrength({
  value,
  id = 'password-strength',
  className,
  showLabel = true,
}: PasswordStrengthProps) {
  const level = useMemo(() => computeStrength(value), [value])

  const segmentClasses = (index: number) => {
    const filled = index < level
    if (!filled) return 'bg-muted'
    if (level <= 1) return 'bg-destructive'
    if (level <= 3) return 'bg-amber-500'
    return 'bg-primary'
  }

  return (
    <div
      id={id}
      role="status"
      aria-live="polite"
      aria-label={`Fortaleza: ${LABELS[level]}`}
      className={cn('space-y-1.5', className)}
    >
      <div
        className="flex gap-0.5"
        aria-hidden
      >
        {Array.from({ length: SEGMENTS }, (_, i) => (
          <div
            key={i}
            className={cn(
              'h-1 flex-1 rounded-full transition-colors duration-200',
              segmentClasses(i)
            )}
          />
        ))}
      </div>
      {showLabel && value.length > 0 && (
        <p className="text-xs text-muted-foreground">
          Fortaleza: <span className="font-medium text-foreground">{LABELS[level]}</span>
        </p>
      )}
    </div>
  )
}
