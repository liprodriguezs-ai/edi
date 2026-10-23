import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible'
import { Field, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import type { ICatalogItem } from '@/shared/hooks/useCatalogs'
import { ChevronDown } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import type { UseFormRegisterReturn } from 'react-hook-form'

export function CollapsibleSection({
  title,
  defaultOpen = false,
  children,
}: {
  title: string
  defaultOpen?: boolean
  children: ReactNode
}) {
  const [open, setOpen] = useState(defaultOpen)
  return (
    <Collapsible open={open} onOpenChange={setOpen} className="border-t border-border">
      <CollapsibleTrigger asChild>
        <button
          type="button"
          className="flex w-full cursor-pointer items-center justify-between px-4 py-4 text-left"
        >
          <span className="text-sm font-semibold">{title}</span>
          <ChevronDown className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} />
        </button>
      </CollapsibleTrigger>
      <CollapsibleContent>
        <div className="px-4 pb-6">{children}</div>
      </CollapsibleContent>
    </Collapsible>
  )
}

/** Combobox de texto libre con sugerencias de catálogo (D3: la sesión guarda texto libre, nunca un FK). */
export function SuggestField({
  id,
  label,
  register,
  suggestions,
  disabled,
}: {
  id: string
  label: string
  register: UseFormRegisterReturn
  suggestions: ICatalogItem[]
  disabled: boolean
}) {
  const listId = `${id}-list`
  return (
    <Field className="col-span-12 sm:col-span-6">
      <FieldLabel htmlFor={id}>{label}</FieldLabel>
      <Input id={id} list={listId} disabled={disabled} placeholder="Texto libre…" {...register} />
      <datalist id={listId}>
        {suggestions.map((s) => (
          <option key={s.id} value={s.name} />
        ))}
      </datalist>
    </Field>
  )
}
