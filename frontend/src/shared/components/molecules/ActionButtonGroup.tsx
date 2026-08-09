import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import {
  Edit,
  Eye,
  MinusSquare,
  MoreHorizontal,
  PlusSquare,
  Printer,
  RotateCcw,
  Trash2,
} from 'lucide-react'
import type { ReactNode } from 'react'

/** Order spacing for default actions; use 0–9 for custom options so they appear first. */
export const ACTION_ORDER = {
  RESTORE: 10,
  DETAIL: 20,
  EDIT: 30,
  PRINT: 40,
  REMOVE: 50,
} as const

export interface ActionOption {
  key: string
  label: string
  icon: ReactNode
  order: number
  onClick: () => void
  variant?: 'default' | 'destructive'
}

export interface ActionButtonGroupProps {
  isSelectable?: boolean
  isSelected?: boolean
  edit?: boolean
  mobile?: boolean
  print?: boolean
  detail?: boolean
  dropdown?: boolean
  remove?: boolean
  restore?: boolean
  onEdit?: () => void
  onDetail?: () => void
  onPrint?: () => void
  onRestore?: () => void
  onRemove?: () => void
  onSelect?: () => void
  /** Custom options; use order < 10 to show before default actions (restore, detail, edit, print, remove). */
  extraOptions?: ActionOption[]
  children?: ReactNode
  className?: string
  disabled?: boolean
}

interface ActionConfig {
  key: string
  icon: ReactNode
  dropdownIcon: ReactNode
  label: string
  handler?: () => void
  visible: boolean
  order: number
  variant?: 'default' | 'destructive'
}

const ICON_INLINE = 'h-8 w-8 md:h-6 md:w-6'
const ICON_DROPDOWN = 'mr-2 h-5 w-5'

const HOVER_VISIBLE_COUNT = 2

function stopAndRun(e: React.MouseEvent, action?: () => void) {
  e.stopPropagation()
  action?.()
}

function buildActions(props: ActionButtonGroupProps): ActionConfig[] {
  const {
    restore,
    detail,
    edit,
    print,
    remove,
    onRestore,
    onDetail,
    onEdit,
    onPrint,
    onRemove,
    extraOptions = [],
  } = props

  const builtIns: ActionConfig[] = [
    {
      key: 'restore',
      icon: <RotateCcw className={ICON_INLINE} />,
      dropdownIcon: <RotateCcw className={ICON_DROPDOWN} />,
      label: 'Restaurar',
      handler: onRestore,
      visible: Boolean(restore && onRestore),
      order: ACTION_ORDER.RESTORE,
    },
    {
      key: 'detail',
      icon: <Eye className={ICON_INLINE} />,
      dropdownIcon: <Eye className={ICON_DROPDOWN} />,
      label: 'Ver detalles',
      handler: onDetail,
      visible: Boolean(detail && onDetail),
      order: ACTION_ORDER.DETAIL,
    },
    {
      key: 'edit',
      icon: <Edit className={ICON_INLINE} />,
      dropdownIcon: <Edit className={ICON_DROPDOWN} />,
      label: 'Editar',
      handler: onEdit,
      visible: Boolean(edit && onEdit),
      order: ACTION_ORDER.EDIT,
    },
    {
      key: 'print',
      icon: <Printer className={ICON_INLINE} />,
      dropdownIcon: <Printer className={ICON_DROPDOWN} />,
      label: 'Imprimir',
      handler: onPrint,
      visible: Boolean(print && onPrint),
      order: ACTION_ORDER.PRINT,
    },
    {
      key: 'remove',
      icon: <Trash2 className={ICON_INLINE} />,
      dropdownIcon: <Trash2 className={ICON_DROPDOWN} />,
      label: 'Eliminar',
      handler: onRemove,
      visible: Boolean(remove && onRemove),
      order: ACTION_ORDER.REMOVE,
      variant: 'destructive',
    },
  ]

  const fromExtra: ActionConfig[] = extraOptions.map((opt) => ({
    key: opt.key,
    icon: opt.icon,
    dropdownIcon: opt.icon,
    label: opt.label,
    handler: opt.onClick,
    visible: true,
    order: opt.order,
    variant: opt.variant,
  }))

  const all = [...fromExtra, ...builtIns]
    .filter((a) => a.visible && a.handler)
    .sort((a, b) => a.order - b.order)

  return all
}

function DropdownActions({
  actions,
  disabled,
}: {
  actions: ActionConfig[]
  disabled: boolean
}) {
  return (
    <>
      {actions.map((action) => (
        <DropdownMenuItem
          key={action.key}
          onClick={(e) => stopAndRun(e, action.handler)}
          disabled={disabled}
          variant={action.variant}
          className={
            action.variant === 'destructive'
              ? 'text-destructive focus:text-destructive'
              : undefined
          }
        >
          {action.dropdownIcon}
          <span>{action.label}</span>
        </DropdownMenuItem>
      ))}
    </>
  )
}

function ActionIconButton({
  action,
  disabled,
  showTooltip,
  className,
}: {
  action: ActionConfig
  disabled: boolean
  showTooltip?: boolean
  className?: string
}) {
  const colorClass =
    action.variant === 'destructive'
      ? 'text-destructive hover:text-destructive/90 hover:bg-destructive/10'
      : 'text-primary hover:text-primary/90 hover:bg-primary/10'

  const btn = (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      onClick={(e) => stopAndRun(e, action.handler)}
      title={action.label}
      disabled={disabled}
      aria-label={action.label}
      className={cn(
        'h-8 w-8 shrink-0 p-0 transition-all duration-200',
        colorClass,
        className
      )}
    >
      {action.icon}
    </Button>
  )

  if (showTooltip) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>{btn}</TooltipTrigger>
        <TooltipContent side="left">{action.label}</TooltipContent>
      </Tooltip>
    )
  }

  return btn
}

function InlineSingleAction({
  action,
  disabled,
}: {
  action: ActionConfig
  disabled: boolean
}) {
  return (
    <ActionIconButton action={action} disabled={disabled} showTooltip />
  )
}

function InlineMultipleActions({
  actions,
  disabled,
}: {
  actions: ActionConfig[]
  disabled: boolean
}) {
  const hoverVisible = actions.slice(0, HOVER_VISIBLE_COUNT)

  return (
    <div
      className="group relative inline-flex h-8 shrink-0 justify-end overflow-visible"
      role="group"
      aria-label="Acciones"
    >
      {/* Dos iconos: solo visibles cuando el cursor está sobre el grupo (3 puntos + iconos), no cuando está sobre el menú */}
      <div
        className={cn(
          'absolute right-full top-0 flex h-8 items-center gap-0 rounded-l-md border border-r-0 border-border bg-card shadow-sm',
          'opacity-0 transition-opacity duration-200',
          'group-hover:opacity-100',
          'pointer-events-none group-hover:pointer-events-auto'
        )}
      >
        {hoverVisible.map((action) => (
          <ActionIconButton
            key={action.key}
            action={action}
            disabled={disabled}
            showTooltip={false}
          />
        ))}
      </div>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className={cn(
              'h-8 w-8 shrink-0 p-0 text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground',
              'border border-transparent hover:border-border hover:bg-card hover:shadow-sm',
              'rounded-md group-hover:rounded-l-none group-hover:rounded-r-md'
            )}
            disabled={disabled}
            aria-label="Más opciones"
            aria-haspopup="menu"
          >
            <MoreHorizontal className="h-5 w-5" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
          align="end"
          side="bottom"
          sideOffset={0}
          className="bg-card border border-border shadow-lg rounded-md"
        >
          {actions.map((action) => (
            <DropdownMenuItem
              key={action.key}
              onClick={(e) => stopAndRun(e, action.handler)}
              disabled={disabled}
              variant={action.variant}
              className={
                action.variant === 'destructive'
                  ? 'text-destructive focus:text-destructive'
                  : undefined
              }
            >
              {action.dropdownIcon}
              <span>{action.label}</span>
            </DropdownMenuItem>
          ))}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}

function DropdownSelectAction({
  selected,
  onSelect,
  disabled,
}: {
  selected: boolean
  onSelect: () => void
  disabled: boolean
}) {
  const icon = selected ? (
    <MinusSquare className={cn(ICON_DROPDOWN, 'text-red-600')} />
  ) : (
    <PlusSquare className={cn(ICON_DROPDOWN, 'text-green-600')} />
  )

  return (
    <DropdownMenuItem onClick={(e) => stopAndRun(e, onSelect)} disabled={disabled}>
      {icon}
      <span>{selected ? 'Deseleccionar' : 'Seleccionar'}</span>
    </DropdownMenuItem>
  )
}

function InlineSelectAction({
  selected,
  onSelect,
  disabled,
}: {
  selected: boolean
  onSelect: () => void
  disabled: boolean
}) {
  const colorClass = selected
    ? 'text-red-600 hover:text-red-700 hover:bg-red-50'
    : 'text-green-600 hover:text-green-700 hover:bg-green-50'

  const selectIcon = selected ? (
    <MinusSquare className="h-5 w-5 md:h-8 md:w-8 text-red-600" />
  ) : (
    <PlusSquare className="h-5 w-5 md:h-8 md:w-8 text-green-600" />
  )

  return (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      onClick={(e) => stopAndRun(e, onSelect)}
      title={selected ? 'Deseleccionar' : 'Seleccionar'}
      aria-label={selected ? 'Deseleccionar' : 'Seleccionar'}
      disabled={disabled}
      className={cn('h-8 w-8 p-0', colorClass)}
    >
      {selectIcon}
    </Button>
  )
}

export const ActionButtonGroup = (props: ActionButtonGroupProps) => {
  const {
    isSelectable = false,
    isSelected = false,
    dropdown = false,
    onSelect,
    children,
    className,
    disabled = false,
  } = props

  const actions = buildActions(props)
  const hasActions = actions.length > 0

  // dropdown={true} → UX compacta: 3 puntos, hover muestra 2 iconos + puntos, click abre menú completo
  if (dropdown) {
    return (
      <div
        className={cn('flex items-center justify-end gap-1', className)}
        role="toolbar"
        aria-label="Acciones de fila"
      >
        {isSelectable && onSelect && (
          <InlineSelectAction
            selected={isSelected}
            onSelect={onSelect}
            disabled={disabled}
          />
        )}
        {hasActions &&
          (actions.length === 1 ? (
            <InlineSingleAction action={actions[0]} disabled={disabled} />
          ) : (
            <InlineMultipleActions actions={actions} disabled={disabled} />
          ))}
        {children}
      </div>
    )
  }

  // dropdown={false} → Solo trigger + menú con todas las opciones (sin hover de 2 iconos)
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="h-8 w-8 p-0 text-muted-foreground hover:text-foreground hover:bg-muted/50"
          disabled={disabled}
          aria-label="Abrir menú de acciones"
          aria-haspopup="menu"
          onClick={(e) => e.stopPropagation()}
        >
          <MoreHorizontal className="h-5 w-5" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent
        align="end"
        side="bottom"
        sideOffset={0}
        className="bg-card border border-border shadow-lg rounded-md"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex flex-col">
          {isSelectable && onSelect && (
            <DropdownSelectAction
              selected={isSelected}
              onSelect={onSelect}
              disabled={disabled}
            />
          )}
          {hasActions && <DropdownActions actions={actions} disabled={disabled} />}
          {children}
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
