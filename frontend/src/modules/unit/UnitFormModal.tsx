import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { UnitForm } from '@/modules/unit/UnitForm'
import { UpgradeRequiredDialog } from '@/modules/unit/components/UpgradeRequiredDialog'
import type { IUnit } from '@/modules/unit/schema'
import { useUnitStore } from '@/modules/unit/store/unitStore'
import { CrudMode } from '@/shared/enums/CrudMode'
import type { IEntityFormModalProps } from '@/shared/interfaces/list.types'
import { useCallback, useRef, useState } from 'react'

export const UnitFormModal = ({
  isOpen,
  mode,
  entity,
  onClose,
  onSuccess,
}: IEntityFormModalProps<IUnit>) => {
  const isSaving = useUnitStore((s) => s.isSaving)
  const submitRef = useRef<(() => void) | null>(null)

  const [upgradeDialogOpen, setUpgradeDialogOpen] = useState(false)
  const [unitLimitForUpgrade, setUnitLimitForUpgrade] = useState(0)

  const handleSuccess = (result: IUnit) => {
    onSuccess?.(result)
    onClose()
  }

  const handleUnitLimitReached = useCallback((unitLimit: number) => {
    setUnitLimitForUpgrade(unitLimit)
    setUpgradeDialogOpen(true)
  }, [])

  const handleUpgradeDialogClose = useCallback(() => {
    setUpgradeDialogOpen(false)
    onClose()
  }, [onClose])

  return (
    <>
      <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
        <SheetContent
          side='right'
          className='flex flex-col gap-0 p-0 w-full sm:max-w-2xl'
          aria-describedby={undefined}
        >
          <SheetHeader className='shrink-0 px-6 pt-6 pb-2'>
            <SheetTitle>{mode === CrudMode.EDIT ? 'Editar unidad' : 'Nueva unidad'}</SheetTitle>
          </SheetHeader>

          <div className='min-h-0 flex-1 overflow-y-auto px-6 py-2'>
            <UnitForm
              mode={mode}
              entity={entity || undefined}
              onSuccess={handleSuccess}
              onSubmitRef={submitRef}
              onUnitLimitReached={handleUnitLimitReached}
            />
          </div>

          <SheetFooter className='shrink-0 px-6 pb-6 pt-4 border-t border-border flex-row justify-end gap-2'>
            <Button
              type='button'
              variant='outline'
              onClick={onClose}
              disabled={isSaving}
              className='min-w-[80px]'
            >
              Cancelar
            </Button>
            <Button
              type='button'
              onClick={() => submitRef.current?.()}
              disabled={isSaving}
              className='min-w-[130px]'
            >
              {isSaving ? 'Guardando…' : mode === CrudMode.EDIT ? 'Actualizar' : 'Guardar unidad'}
            </Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>

      <UpgradeRequiredDialog
        isOpen={upgradeDialogOpen}
        unitLimit={unitLimitForUpgrade}
        onClose={handleUpgradeDialogClose}
      />
    </>
  )
}
