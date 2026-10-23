import { Badge } from '@/components/ui/badge'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible'
import { useScreeningItemsByGroup } from '@/modules/instrument/hooks/useInstrumentQueries'
import type { IAgeGroup } from '@/modules/instrument/schema'
import { ChevronDown } from 'lucide-react'
import { useState } from 'react'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { ResultColorPill } from '@/shared/components/atoms/ResultColorPill'
import { resultColorName } from '@/shared/utils/resultColor'

interface GroupItemsAccordionProps {
  group: IAgeGroup
}

/** Renders a `<br>`-marked question (verbatim source data, Q6) as line breaks. */
function QuestionText({ question }: { question: string }) {
  const lines = question.split(/<br\s*\/?>/i)
  return (
    <>
      {lines.map((line, index) => (
        <span key={index}>
          {line.trim()}
          {index < lines.length - 1 && <br />}
        </span>
      ))}
    </>
  )
}

function GroupPanel({ group }: GroupItemsAccordionProps) {
  const { data: items, isLoading } = useScreeningItemsByGroup(group.id)

  if (isLoading) {
    return <InlineLoading name='instrument-group-items' className='p-4' />
  }

  if (!items || items.length === 0) {
    return <InlineEmpty className='p-4'>Este grupo no tiene reactivos.</InlineEmpty>
  }

  return (
    <ul className="divide-y divide-border border-t border-border">
      {items.map((item) => (
        <li key={item.id} className="px-4 py-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="font-mono text-xs text-muted-foreground">{item.code}</span>
            <span className="font-mono text-xs text-muted-foreground">
              {item.development_area?.code} · {item.application_position?.key}
            </span>
          </div>
          <p className="mt-1 text-sm">
            <QuestionText question={item.question} />
          </p>
          <div className="mt-2 flex flex-wrap gap-2">
            {item.options
              ?.slice()
              .sort((a, b) => a.sort_order - b.sort_order)
              .map((option) => (
                <ResultColorPill
                  key={option.id}
                  colorKey={option.result_color?.key}
                  label={`${option.label} → ${resultColorName(option.result_color?.key)}`}
                />
              ))}
          </div>
        </li>
      ))}
    </ul>
  )
}

export function GroupItemsAccordion({ group }: GroupItemsAccordionProps) {
  const [isOpen, setIsOpen] = useState(false)

  return (
    <Collapsible open={isOpen} onOpenChange={setIsOpen}>
      <CollapsibleTrigger className="flex w-full items-center justify-between p-4 text-left">
        <span className="font-medium">
          {group.code} — {group.name}
          {group.is_alternate && (
            <Badge variant="secondary" className="ml-2">
              Alterno
            </Badge>
          )}
        </span>
        <span className="flex items-center gap-2 text-sm text-muted-foreground">
          {!group.is_alternate && group.min_age_days !== null && (
            <span>
              {group.min_age_days}–{group.max_age_days} días
            </span>
          )}
          <ChevronDown className={`h-4 w-4 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
        </span>
      </CollapsibleTrigger>
      <CollapsibleContent>{isOpen && <GroupPanel group={group} />}</CollapsibleContent>
    </Collapsible>
  )
}
