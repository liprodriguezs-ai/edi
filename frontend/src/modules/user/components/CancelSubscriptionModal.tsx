import { Button } from '@/components/ui/button'
import { Modal } from '@/shared/components/Modal'
import { AlertTriangle, Heart, Loader2, Shield } from 'lucide-react'
import { useCallback, useState } from 'react'

type CancelStep = 'persuade' | 'confirm'

interface CancelSubscriptionModalProps {
  isOpen: boolean
  isCanceling: boolean
  /** Cancellation is always at period end (the cluster keeps access until the paid period ends). */
  onCancel: () => void
  onClose: () => void
  planName?: string
}

export function CancelSubscriptionModal({
  isOpen,
  isCanceling,
  onCancel,
  onClose,
  planName,
}: CancelSubscriptionModalProps) {
  const [step, setStep] = useState<CancelStep>('persuade')

  const handleClose = useCallback(() => {
    setStep('persuade')
    onClose()
  }, [onClose])

  const stepTitles: Record<CancelStep, string> = {
    persuade: 'Cancelar suscripción',
    confirm: 'Confirmar cancelación',
  }

  return (
    <Modal isOpen={isOpen} onClose={handleClose} title={stepTitles[step]} size="sm">
      <div className="py-4">
        {step === 'persuade' && (
          <div className="space-y-6">
            <div className="flex flex-col items-center text-center">
              <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/30">
                <Heart className="h-7 w-7 text-amber-600 dark:text-amber-400" />
              </div>
              <h3 className="text-lg font-semibold">¿Seguro que quieres irte?</h3>
              <p className="mt-2 text-sm text-muted-foreground">
                Tu plan <strong>{planName}</strong> incluye funciones premium que perderás al
                cancelar. Nuestro equipo está disponible para ayudarte si algo no funciona como
                esperas.
              </p>
            </div>

            <div className="space-y-3 rounded-lg border bg-muted/50 p-4">
              <div className="flex items-start gap-3">
                <Shield className="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                <div>
                  <p className="text-sm font-medium">Conserva tus beneficios</p>
                  <p className="text-xs text-muted-foreground">
                    Al cancelar perderás acceso a todas las funciones de tu plan actual.
                  </p>
                </div>
              </div>
            </div>

            <div className="flex flex-col gap-3">
              <Button onClick={handleClose} className="w-full">
                Mantener mi plan
              </Button>
              <Button
                variant="ghost"
                className="w-full text-muted-foreground"
                onClick={() => setStep('confirm')}
              >
                Continuar con la cancelación
              </Button>
            </div>
          </div>
        )}

        {step === 'confirm' && (
          <div className="space-y-6">
            <div className="flex flex-col items-center text-center">
              <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-destructive/10">
                <AlertTriangle className="h-7 w-7 text-destructive" />
              </div>
              <p className="text-sm text-muted-foreground">
                Tu plan se mantendrá activo hasta el final del ciclo actual. Después se desactivará
                automáticamente. Puedes reactivarlo antes de esa fecha.
              </p>
            </div>

            <div className="flex flex-col gap-3">
              <Button
                variant="destructive"
                className="w-full"
                disabled={isCanceling}
                onClick={onCancel}
              >
                {isCanceling ? (
                  <>
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    Cancelando...
                  </>
                ) : (
                  'Confirmar cancelación al final del ciclo'
                )}
              </Button>
              <Button
                variant="ghost"
                className="w-full"
                disabled={isCanceling}
                onClick={() => setStep('persuade')}
              >
                Volver
              </Button>
            </div>
          </div>
        )}
      </div>
    </Modal>
  )
}
