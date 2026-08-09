import { cn } from '@/lib/utils'
import { toast } from '@/shared/hooks/useToast'
import { Check, Copy, Eye, EyeOff } from 'lucide-react'
import { useCallback, useState } from 'react'

interface ObfuscatedTextProps {
  value: string | null | undefined
  fallback?: string
  className?: string
  maskChar?: string
}

export const ObfuscatedText = ({
  value,
  fallback = '—',
  className,
  maskChar = '•',
}: ObfuscatedTextProps) => {
  const [visible, setVisible] = useState(false)
  const [copied, setCopied] = useState(false)

  const hasValue = value != null && value.length > 0

  const handleCopy = useCallback(async () => {
    if (!hasValue) return
    try {
      await navigator.clipboard.writeText(value)
      setCopied(true)
      toast.success('Copiado al portapapeles')
      setTimeout(() => setCopied(false), 2000)
    } catch {
      toast.error('No se pudo copiar')
    }
  }, [value, hasValue])

  if (!hasValue) {
    return <span className="text-muted-foreground">{fallback}</span>
  }

  const masked = maskChar.repeat(Math.min(value.length, 16))

  return (
    <span className={cn('inline-flex items-center gap-1.5', className)}>
      <span className="select-none font-mono text-sm">
        {visible ? value : masked}
      </span>

      <button
        type="button"
        onClick={() => setVisible((prev) => !prev)}
        className="inline-flex size-6 shrink-0 items-center justify-center rounded-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        title={visible ? 'Ocultar' : 'Mostrar'}
      >
        {visible ? <EyeOff className="size-3.5" /> : <Eye className="size-3.5" />}
      </button>

      <button
        type="button"
        onClick={handleCopy}
        className="inline-flex size-6 shrink-0 items-center justify-center rounded-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        title="Copiar"
      >
        {copied ? (
          <Check className="size-3.5 text-green-600" />
        ) : (
          <Copy className="size-3.5" />
        )}
      </button>
    </span>
  )
}
