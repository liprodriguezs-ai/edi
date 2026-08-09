import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogMedia,
  AlertDialogTitle
} from '@/components/ui/alert-dialog'
import type { JSX, ReactNode } from 'react'
import { Trash2 } from 'lucide-react'

export type ConfirmDialogVariant = 'delete' | 'default'

interface ConfirmDialogProps {
  isOpen: boolean
  /** Semantic variant: 'delete' shows destructive styling and copy; 'default' is generic confirm */
  variant?: ConfirmDialogVariant
  title?: ReactNode
  description?: ReactNode
  /** For variant='delete': e.g. "Empresa X" → "¿Eliminar Empresa X? Esta acción no se puede deshacer." */
  entityName?: string
  confirmLabel?: string
  cancelLabel?: string
  isLoading?: boolean
  onConfirm: () => void
  onCancel: () => void
  /** Override confirm button style; ignored when variant='delete' (uses destructive) */
  colorButtonConfirm?: string
  colorButtonCancel?: string
}

const DELETE_DEFAULTS = {
  title: 'Eliminar registro',
  confirmLabel: 'Eliminar',
  loadingLabel: 'Eliminando...',
  descriptionNoName: 'Esta acción no se puede deshacer. ¿Deseas continuar?',
  descriptionWithName: (name: string) =>
    `¿Eliminar "${name}"? Esta acción no se puede deshacer.`
} as const

const DEFAULT_DEFAULTS = {
  title: 'Confirmar',
  description: '¿Estás seguro de que quieres continuar?',
  confirmLabel: 'Confirmar',
  loadingLabel: 'Procesando...'
} as const

function resolveCopy(
  variant: ConfirmDialogVariant,
  overrides: {
    title?: ReactNode
    description?: ReactNode
    entityName?: string
    confirmLabel?: string
  }
): { title: ReactNode; description: ReactNode; confirmLabel: string; loadingLabel: string } {
  const isDelete = variant === 'delete'
  const title = overrides.title ?? (isDelete ? DELETE_DEFAULTS.title : DEFAULT_DEFAULTS.title)
  const description =
    overrides.description ??
    (isDelete
      ? overrides.entityName
        ? DELETE_DEFAULTS.descriptionWithName(overrides.entityName)
        : DELETE_DEFAULTS.descriptionNoName
      : DEFAULT_DEFAULTS.description)
  const confirmLabel =
    overrides.confirmLabel ??
    (isDelete ? DELETE_DEFAULTS.confirmLabel : DEFAULT_DEFAULTS.confirmLabel)
  const loadingLabel = isDelete ? DELETE_DEFAULTS.loadingLabel : DEFAULT_DEFAULTS.loadingLabel
  return { title, description, confirmLabel, loadingLabel }
}

/**
 * ConfirmDialog built on Radix UI AlertDialog.
 * Use variant="delete" for delete confirmations (destructive button, icon, clearer copy).
 */
export const ConfirmDialog = ({
  isOpen,
  variant = 'default',
  title,
  description,
  entityName,
  confirmLabel,
  cancelLabel = 'Cancelar',
  isLoading = false,
  onConfirm,
  onCancel,
  colorButtonConfirm = 'bg-primary hover:bg-primary/90'
}: ConfirmDialogProps): JSX.Element => {
  const isDelete = variant === 'delete'
  const { title: resolvedTitle, description: resolvedDescription, confirmLabel: resolvedConfirmLabel, loadingLabel } =
    resolveCopy(variant, { title, description, entityName, confirmLabel })

  return (
    <AlertDialog open={isOpen} onOpenChange={(open: boolean) => !open && onCancel()}>
      <AlertDialogContent>
        <AlertDialogHeader>
          {isDelete && (
            <AlertDialogMedia className="bg-destructive/10 text-destructive">
              <Trash2 className="size-8" aria-hidden />
            </AlertDialogMedia>
          )}
          <AlertDialogTitle>{resolvedTitle}</AlertDialogTitle>
          <AlertDialogDescription>{resolvedDescription}</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel onClick={onCancel} disabled={isLoading}>
            {cancelLabel}
          </AlertDialogCancel>
          <AlertDialogAction
            onClick={onConfirm}
            disabled={isLoading}
            variant={isDelete ? 'destructive' : undefined}
            className={!isDelete ? colorButtonConfirm : undefined}
          >
            {isLoading ? loadingLabel : resolvedConfirmLabel}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
