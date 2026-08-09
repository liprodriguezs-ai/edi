import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Sparkles } from 'lucide-react'
import { useNavigate } from 'react-router-dom'

interface UpgradeRequiredDialogProps {
  isOpen: boolean
  unitLimit: number
  onClose: () => void
}

export function UpgradeRequiredDialog({ isOpen, unitLimit, onClose }: UpgradeRequiredDialogProps) {
  const navigate = useNavigate()

  function handleUpgrade() {
    onClose()
    // `/subscription` es la pantalla real de facturación (MultiItemSubscriptionManager,
    // App.tsx). La ruta `/change-plan` que había aquí NUNCA existió en el router: el
    // diálogo caía al fallback y terminaba en `/onboarding`. No se notaba porque el
    // diálogo tampoco abría (UnitForm comparaba contra un código que el backend no emite).
    navigate('/subscription')
  }

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-md" aria-describedby="upgrade-dialog-description">
        <DialogHeader>
          <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-primary/10">
            <Sparkles className="h-6 w-6 text-primary" />
          </div>
          <DialogTitle>Alcanzaste el límite de tu plan</DialogTitle>
        </DialogHeader>

        <p id="upgrade-dialog-description" className="text-sm text-muted-foreground">
          Tu plan permite hasta <strong>{unitLimit}</strong> unidades y ya las registraste todas.
          Mejora tu plan para seguir sumando viviendas a tu condominio — no perderás nada de lo que ya
          cargaste.
        </p>

        <DialogFooter className="mt-2 flex-col gap-2 sm:flex-row">
          <Button type="button" variant="outline" onClick={onClose} className="min-w-[100px]">
            Ahora no
          </Button>
          <Button type="button" onClick={handleUpgrade} className="min-w-[140px]">
            Mejorar mi plan
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
