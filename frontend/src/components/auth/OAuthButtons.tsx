type OAuthProvider = 'google' | 'apple'

const OAUTH_BTN =
  'flex flex-1 items-center justify-center gap-2.5 rounded-[9px] border border-border bg-card px-3 py-[11px] text-[13.5px] font-medium text-[var(--ink-2)] transition-colors hover:border-[var(--ink-3)] hover:bg-secondary'

function GoogleIcon() {
  return (
    <svg width="17" height="17" viewBox="0 0 18 18" aria-hidden>
      <path
        fill="#4285F4"
        d="M17.6 9.2c0-.6-.05-1.2-.16-1.7H9v3.4h4.8a4.1 4.1 0 0 1-1.8 2.7v2.2h2.9c1.7-1.6 2.7-3.9 2.7-6.6z"
      />
      <path
        fill="#34A853"
        d="M9 18c2.4 0 4.5-.8 6-2.2l-2.9-2.2c-.8.5-1.8.9-3.1.9-2.4 0-4.4-1.6-5.1-3.8H.8v2.3A9 9 0 0 0 9 18z"
      />
      <path fill="#FBBC05" d="M3.9 10.7a5.4 5.4 0 0 1 0-3.4V5H.8a9 9 0 0 0 0 8z" />
      <path
        fill="#EA4335"
        d="M9 3.6c1.3 0 2.5.5 3.4 1.3l2.6-2.6A9 9 0 0 0 .8 5l3.1 2.3C4.6 5.2 6.6 3.6 9 3.6z"
      />
    </svg>
  )
}

function AppleIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden>
      <path d="M11.2 8.5c0-1.6 1.3-2.4 1.4-2.4-.8-1.1-2-1.3-2.4-1.3-1-.1-2 .6-2.5.6s-1.3-.6-2.1-.6c-1.1 0-2.1.6-2.7 1.6-1.1 2-.3 4.9.8 6.5.5.8 1.2 1.7 2 1.6.8 0 1.1-.5 2.1-.5s1.2.5 2.1.5c.9 0 1.4-.8 1.9-1.6.6-.9.9-1.8.9-1.8s-1.7-.7-1.7-2.6zM9.6 3.6c.4-.5.7-1.3.6-2-.6 0-1.4.4-1.9 1-.4.4-.7 1.2-.6 1.9.7.1 1.4-.4 1.9-.9z" />
    </svg>
  )
}

/**
 * Botones de acceso social (Google / Apple) + divisor "o con tu correo".
 * Se renderizan fieles al diseño; como aún no hay backend OAuth, `onProvider`
 * decide qué hacer (en este proyecto: avisar "próximamente").
 */
export function OAuthButtons({
  onProvider,
  dividerLabel = 'o con tu correo',
}: {
  onProvider: (_provider: OAuthProvider) => void
  dividerLabel?: string
}) {
  return (
    <>
      <div className="flex gap-2.5">
        <button type="button" className={OAUTH_BTN} onClick={() => onProvider('google')}>
          <GoogleIcon />
          Google
        </button>
        <button type="button" className={OAUTH_BTN} onClick={() => onProvider('apple')}>
          <AppleIcon />
          Apple
        </button>
      </div>
      <div className="my-5 flex items-center gap-3.5 text-xs text-[var(--ink-4)]">
        <span className="h-px flex-1 bg-border" />
        {dividerLabel}
        <span className="h-px flex-1 bg-border" />
      </div>
    </>
  )
}

export type { OAuthProvider }
