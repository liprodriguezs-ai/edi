import { CalendarClock } from 'lucide-react'
import { useState } from 'react'
import { Callout } from '@/shared/components/atoms/Callout'
import { StatusPill, type PillTone } from '@/shared/components/atoms/StatusPill'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import type {
  ConcreteLifecycleMilestones,
  LifecycleMilestones,
  LifecycleStage,
} from '@/shared/utils/chargeLifecycle'

/**
 * Línea de tiempo horizontal del ciclo de vida de un cargo: Nace → Por vencer →
 * Vence → Moroso. El color refuerza la etapa pero NUNCA es el único indicador:
 * cada nodo lleva etiqueta y fecha textual, y la etapa activa muestra un pill.
 *
 * - `parametric`: hitos calculados a partir de periodicidad + settings (concepto/config).
 * - `concrete`:   hitos a partir de fechas reales del lote/cargo.
 * - `manual`:     cobros eventuales sin fechas automáticas → aviso informativo.
 *
 * Hitos que caen en la MISMA fecha (p. ej. gracia=0 → Vence == Moroso) se funden
 * en un único nodo con etiqueta combinada ("Vence / Moroso") en todos los modos.
 *
 * Con `collapsible`, por defecto se muestra un resumen de una línea
 * ("Nace … · Vence …") con un toggle "Ver detalle" que despliega la línea de
 * tiempo completa.
 */
type TimelineMode =
  | { mode: 'parametric'; milestones: LifecycleMilestones; activeStage?: LifecycleStage }
  | { mode: 'concrete'; milestones: ConcreteLifecycleMilestones; activeStage?: LifecycleStage }
  | { mode: 'manual' }

type ChargeLifecycleTimelineProps = TimelineMode & {
  className?: string
  /** Si es true, arranca colapsado en un resumen de una línea (default false = expandido). */
  collapsible?: boolean
}

interface TimelineNode {
  key: string
  label: string
  date: string | null
  tone: PillTone
  /** Etapa de la FSM que este nodo representa (los nodos "nace"/"vence" comparten etapa). */
  stage: LifecycleStage
  /** Etapas fundidas en este nodo (incluye la propia); usado para marcar activo. */
  stages: LifecycleStage[]
}

const TONE_DOT: Record<PillTone, string> = {
  g: 'bg-[var(--g)]',
  y: 'bg-[var(--y)]',
  r: 'bg-[var(--r)]',
  b: 'bg-[var(--b)]',
}

const TONE_RING: Record<PillTone, string> = {
  g: 'ring-[var(--g)]',
  y: 'ring-[var(--y)]',
  r: 'ring-[var(--r)]',
  b: 'ring-[var(--b)]',
}

const STAGE_LABEL: Record<LifecycleStage, string> = {
  pending: 'Por vencer',
  due_soon: 'Por vencer',
  overdue: 'Moroso',
}

function buildNodes(
  milestones: LifecycleMilestones | ConcreteLifecycleMilestones,
): TimelineNode[] {
  const raw: TimelineNode[] = [
    { key: 'generation', label: 'Nace', date: milestones.generationDate, tone: 'b', stage: 'pending', stages: ['pending'] },
    { key: 'due_soon', label: 'Por vencer', date: milestones.dueSoonDate, tone: 'y', stage: 'due_soon', stages: ['due_soon'] },
    { key: 'due', label: 'Vence', date: milestones.dueDate, tone: 'y', stage: 'due_soon', stages: ['due_soon'] },
    { key: 'overdue', label: 'Moroso', date: milestones.overdueDate, tone: 'r', stage: 'overdue', stages: ['overdue'] },
  ]

  // Funde hitos adyacentes que caen en la misma fecha en un solo nodo: la
  // etiqueta se combina ("Vence / Moroso") y el nodo hereda el tono/etapa del
  // hito más avanzado (el último), conservando todas las etapas para el activo.
  return raw.reduce<TimelineNode[]>((merged, node) => {
    const previous = merged[merged.length - 1]
    if (previous && previous.date != null && previous.date === node.date) {
      previous.label = `${previous.label} / ${node.label}`
      previous.key = `${previous.key}+${node.key}`
      previous.tone = node.tone
      previous.stage = node.stage
      previous.stages = [...previous.stages, ...node.stages]
      return merged
    }
    merged.push({ ...node })
    return merged
  }, [])
}

/** Resumen de una línea para el modo colapsado: "Nace … · Vence …" (+ Moroso si difiere). */
function CollapsedSummary({
  milestones,
}: {
  milestones: LifecycleMilestones | ConcreteLifecycleMilestones
}) {
  const nace = displayFormatDate(milestones.generationDate, 'mediumDate')
  const vence = displayFormatDate(milestones.dueDate, 'mediumDate')
  const showMoroso = milestones.overdueDate !== milestones.dueDate

  return (
    <span className="text-[12px] leading-snug text-[var(--ink-3)]">
      Nace <span className="font-medium text-foreground tabular-nums">{nace}</span>
      {' · '}
      Vence <span className="font-medium text-foreground tabular-nums">{vence}</span>
      {showMoroso && (
        <>
          {' · '}
          Moroso{' '}
          <span className="font-medium text-foreground tabular-nums">
            {displayFormatDate(milestones.overdueDate, 'mediumDate')}
          </span>
        </>
      )}
    </span>
  )
}

function Timeline({
  milestones,
  activeStage,
  className = '',
}: {
  milestones: LifecycleMilestones | ConcreteLifecycleMilestones
  activeStage?: LifecycleStage
  className?: string
}) {
  const nodes = buildNodes(milestones)

  return (
    <ol
      aria-label="Ciclo de vida del cobro"
      className={`flex items-start justify-between gap-1 ${className}`}
    >
      {nodes.map((node, index) => {
        const isActive = activeStage != null && node.stages.includes(activeStage)
        const isFirst = index === 0
        return (
          <li
            key={node.key}
            aria-current={isActive ? 'step' : undefined}
            className="relative flex flex-1 flex-col items-center text-center"
          >
            {/* Línea conectora hacia el nodo anterior (no en el primero) */}
            {!isFirst && (
              <span
                aria-hidden
                className={`absolute right-1/2 top-[7px] -z-10 h-[2px] w-full ${TONE_DOT[node.tone]} opacity-40`}
              />
            )}
            <span
              aria-hidden
              className={`h-3.5 w-3.5 flex-none rounded-full ${TONE_DOT[node.tone]} ${
                isActive ? `ring-2 ring-offset-2 ring-offset-card ${TONE_RING[node.tone]}` : ''
              }`}
            />
            <span className="mt-2 text-[11.5px] font-semibold leading-tight text-foreground">
              {node.label}
            </span>
            <time
              dateTime={node.date ?? undefined}
              className="mt-0.5 text-[11px] leading-tight text-[var(--ink-3)] tabular-nums"
            >
              {node.date ? displayFormatDate(node.date, 'mediumDate') : '—'}
            </time>
            {isActive && (
              <span className="mt-1.5">
                <StatusPill tone={node.tone}>{STAGE_LABEL[activeStage]}</StatusPill>
              </span>
            )}
          </li>
        )
      })}
    </ol>
  )
}

export function ChargeLifecycleTimeline(props: ChargeLifecycleTimelineProps) {
  const { className = '', collapsible = false } = props
  const [expanded, setExpanded] = useState(false)

  if (props.mode === 'manual') {
    if (collapsible) {
      return (
        <span className={`text-[12px] leading-snug text-[var(--ink-3)] ${className}`}>
          Manual — defines las fechas al crear el cobro
        </span>
      )
    }
    return (
      <Callout icon={CalendarClock} className={className}>
        Para cobros eventuales, tú defines las fechas al crear el cobro.
      </Callout>
    )
  }

  if (!collapsible) {
    return (
      <Timeline
        milestones={props.milestones}
        activeStage={props.activeStage}
        className={className}
      />
    )
  }

  return (
    <div className={`space-y-2 ${className}`}>
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <CollapsedSummary milestones={props.milestones} />
        <button
          type="button"
          onClick={() => setExpanded((prev) => !prev)}
          className="text-[11px] font-medium text-[var(--green-deep)] underline-offset-2 hover:underline"
          aria-expanded={expanded}
        >
          {expanded ? 'Ocultar detalle' : 'Ver detalle'}
        </button>
      </div>
      {expanded && (
        <Timeline milestones={props.milestones} activeStage={props.activeStage} />
      )}
    </div>
  )
}
