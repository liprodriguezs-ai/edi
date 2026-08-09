import { APP_CONFIG } from '@/shared/config/appConfig'
import { cn } from '@/lib/utils'

const TEXT_SIZE = {
  sm: 'text-lg',
  md: 'text-[22px]',
  lg: 'text-2xl',
} as const

type WordmarkSize = keyof typeof TEXT_SIZE

/**
 * Sello de marca del producto: isotipo Kivo7 + wordmark en serif (Cormorant).
 * El nombre sale de `APP_CONFIG.brandName` (fuente única), así que renombrar la
 * marca no toca este componente. Átomo reutilizado en login, registro y el header
 * de selección de plan. Debe renderizarse dentro del scope `.salvia` para tomar la
 * fuente de marca. En tono `light` (fondos oscuros) el isotipo va sobre una placa
 * blanca para conservar el contraste del trazo verde.
 */
export function BrandWordmark({
  size = 'md',
  tone = 'dark',
  className,
}: {
  size?: WordmarkSize
  tone?: 'light' | 'dark'
  className?: string
}) {
  return (
    <span className={cn('flex items-center gap-2.5', className)}>
      <span
        aria-hidden
        className={cn(
          'grid h-[34px] w-[34px] flex-none place-items-center',
          tone === 'light' && 'rounded-[10px] bg-white'
        )}
      >
        <img
          src='/icons/kivo7-mark.svg'
          alt=''
          className={cn('w-auto', tone === 'light' ? 'h-[26px]' : 'h-[32px]')}
        />
      </span>
      <span
        className={cn(
          'font-brand font-semibold tracking-[0.005em]',
          TEXT_SIZE[size],
          tone === 'light' ? 'text-white' : 'text-foreground'
        )}
      >
        {APP_CONFIG.brandName}
      </span>
    </span>
  )
}
