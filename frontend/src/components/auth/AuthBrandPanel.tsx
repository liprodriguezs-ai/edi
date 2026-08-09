import { BrandWordmark } from '@/components/auth/BrandWordmark'

interface AuthBrandPanelProps {
  headline: string
  subtext: string
  quote: string
  author: string
  authorMeta: string
  authorInitials: string
}

/**
 * Lado de marca del layout split (login / registro): panel verde profundo
 * con wordmark, titular en serif, subtítulo y un testimonio. Oculto en mobile.
 */
export function AuthBrandPanel({
  headline,
  subtext,
  quote,
  author,
  authorMeta,
  authorInitials,
}: AuthBrandPanelProps) {
  return (
    <aside className="relative hidden flex-col overflow-hidden bg-[var(--nav)] px-14 py-12 text-white md:flex">
      {/* círculos decorativos sutiles (efecto de profundidad) */}
      <span
        aria-hidden
        className="pointer-events-none absolute -bottom-32 -right-32 h-[360px] w-[360px] rounded-full bg-white/[0.03]"
      />
      <span
        aria-hidden
        className="pointer-events-none absolute -top-20 right-10 h-[220px] w-[220px] rounded-full bg-white/[0.025]"
      />

      <BrandWordmark size="lg" tone="light" className="relative z-10" />

      <div className="relative z-10 my-auto max-w-[30ch]">
        <h2 className="font-brand text-[42px] font-medium leading-[1.1] tracking-[-0.01em]">
          {headline}
        </h2>
        <p className="mt-[18px] text-base leading-[1.6] text-[var(--nav-txt)]">{subtext}</p>
      </div>

      <figure className="relative z-10 border-t border-white/[0.12] pt-6">
        <blockquote className="font-brand text-[19px] italic leading-[1.4] text-white">
          {quote}
        </blockquote>
        <figcaption className="mt-3 flex items-center gap-[11px]">
          <span className="grid h-9 w-9 flex-none place-items-center rounded-full bg-[var(--brand)] text-xs font-semibold text-white">
            {authorInitials}
          </span>
          <span className="text-[13px] leading-tight not-italic">
            {author}
            <span className="block text-xs text-[var(--nav-txt)]">{authorMeta}</span>
          </span>
        </figcaption>
      </figure>
    </aside>
  )
}
