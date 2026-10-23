import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import type { IBankItem } from '@/modules/screening/schema'
import { lockedReasonCopy } from '@/modules/screening/utils/lockedReasonCopy'
import { Lock } from 'lucide-react'

interface ItemRowProps {
  item: IBankItem
  value: string | undefined
  onChange: (_screeningItemOptionId: string) => void
  isIncomplete?: boolean
}

/**
 * REQ-SUI-006/009 — dos opciones NEUTRAS, sin pistas de color en ninguna fase
 * (el cliente no puede derivar un resultado). Los reactivos bloqueados van en
 * solo-lectura con su razón estructurada.
 *
 * Diseño (dirección A): la fila es densa — pregunta a la izquierda, control
 * NO/SÍ COMPACTO a la derecha. Un binario no merece una barra a todo el ancho;
 * en una lista de 25 reactivos esa barra multiplicada era un muro. El toggle
 * compacto deja la pantalla como una lista clínica escaneable.
 */
export function ItemRow({ item, value, onChange, isIncomplete = false }: ItemRowProps) {
  return (
    <div
      data-incomplete={isIncomplete || undefined}
      className={`scroll-mt-24 px-4 py-3 transition-colors sm:px-5 ${isIncomplete ? 'bg-destructive/[0.04]' : ''}`}
    >
      <div className="flex items-center justify-between gap-4">
        <div className="min-w-0 flex-1">
          <p className="text-[14px] font-medium leading-snug text-foreground">{item.question}</p>
          {item.locked && <p className="mt-1 text-xs text-muted-foreground">{lockedReasonCopy(item.locked_reason)}</p>}
          {isIncomplete && (
            <p className="mt-1 text-xs font-medium text-destructive">Falta contestar este reactivo.</p>
          )}
        </div>

        {item.locked ? (
          <Lock className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
        ) : (
          <RadioGroup
            value={value ?? ''}
            onValueChange={onChange}
            className="flex shrink-0 border border-border"
            aria-label={item.question}
          >
            {inFixedOrder(item.options).map((option, idx) => {
              const id = `item-${item.screening_item_id}-${option.screening_item_option_id}`
              const checked = value === option.screening_item_option_id
              return (
                <div key={option.screening_item_option_id}>
                  <RadioGroupItem id={id} value={option.screening_item_option_id} className="peer sr-only" />
                  <label
                    htmlFor={id}
                    className={`flex h-9 min-w-[52px] cursor-pointer select-none items-center justify-center whitespace-nowrap px-4 text-[13px] transition-colors peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:-outline-offset-2 peer-focus-visible:outline-ring ${idx > 0 ? 'border-l border-border ' : ''}${
                      checked
                        ? 'bg-primary font-semibold text-primary-foreground'
                        : 'bg-card font-medium text-muted-foreground hover:bg-muted hover:text-foreground'
                    }`}
                  >
                    {option.label}
                  </label>
                </div>
              )
            })}
          </RadioGroup>
        )}
      </div>
    </div>
  )
}

/**
 * The instrument stores SI first for some items and NO first for others; a
 * fixed position avoids tapping the wrong answer. Display order only -- the
 * selected option id is what gets saved.
 */
const OPTION_POSITION: Record<string, number> = { SI: 0, NO: 1 }

function inFixedOrder(options: IBankItem['options']): IBankItem['options'] {
  const position = (label: string) => OPTION_POSITION[label.trim().toUpperCase()] ?? 2
  return [...options].sort((a, b) => position(a.label) - position(b.label))
}

