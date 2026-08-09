import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { changePasswordRequest } from '@/shared/auth/authService'
import { useSession } from '@/shared/auth/useSession'
import { toast } from '@/shared/hooks/useToast'
import { useMutation } from '@tanstack/react-query'
import { Eye, EyeOff } from 'lucide-react'
import { useState } from 'react'

interface ChangePasswordModalProps {
  isOpen: boolean
  onClose: () => void
}

/**
 * Cambio de contraseña del usuario autenticado. El backend valida la contraseña
 * actual y revoca todas las sesiones, así que tras el cambio se cierra la sesión
 * y se vuelve al login.
 */
export function ChangePasswordModal({ isOpen, onClose }: ChangePasswordModalProps) {
  const { logout } = useSession()
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [show, setShow] = useState(false)

  // Reset transient state on the open transition using React's "adjust state on
  // prop change" pattern (during render, NOT in an effect) — see ScanQrModal.
  const [wasOpen, setWasOpen] = useState(isOpen)
  if (isOpen !== wasOpen) {
    setWasOpen(isOpen)
    if (isOpen) {
      setCurrent('')
      setNext('')
      setConfirm('')
      setShow(false)
    }
  }

  const tooShort = next.length > 0 && next.length < 8
  const mismatch = confirm.length > 0 && next !== confirm
  const canSubmit = current.length > 0 && next.length >= 8 && next === confirm

  const mutation = useMutation({
    mutationFn: () => changePasswordRequest({ password: current, new_password: next }),
    onSuccess: () => {
      toast.success('Contraseña actualizada — inicia sesión de nuevo')
      onClose()
      logout()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? formatApiErrorMessage(error)
          : 'No se pudo cambiar la contraseña'
      ),
  })

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className='max-w-sm' aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>Cambiar contraseña</DialogTitle>
        </DialogHeader>

        <FieldGroup>
          <Field>
            <FieldLabel htmlFor='cp-current' required>
              Contraseña actual
            </FieldLabel>
            <div className='relative'>
              <Input
                id='cp-current'
                type={show ? 'text' : 'password'}
                autoComplete='current-password'
                value={current}
                onChange={(e) => setCurrent(e.target.value)}
                disabled={mutation.isPending}
              />
              <button
                type='button'
                className='absolute right-2 top-1/2 -translate-y-1/2 text-[var(--ink-3)]'
                onClick={() => setShow((s) => !s)}
                aria-label={show ? 'Ocultar' : 'Mostrar'}
              >
                {show ? <EyeOff className='size-4' /> : <Eye className='size-4' />}
              </button>
            </div>
          </Field>
          <Field>
            <FieldLabel htmlFor='cp-next' required>
              Nueva contraseña
            </FieldLabel>
            <Input
              id='cp-next'
              type={show ? 'text' : 'password'}
              autoComplete='new-password'
              value={next}
              onChange={(e) => setNext(e.target.value)}
              disabled={mutation.isPending}
            />
            <FieldError>{tooShort ? 'Mínimo 8 caracteres' : undefined}</FieldError>
          </Field>
          <Field>
            <FieldLabel htmlFor='cp-confirm' required>
              Confirmar nueva contraseña
            </FieldLabel>
            <Input
              id='cp-confirm'
              type={show ? 'text' : 'password'}
              autoComplete='new-password'
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
              disabled={mutation.isPending}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && canSubmit) mutation.mutate()
              }}
            />
            <FieldError>{mismatch ? 'Las contraseñas no coinciden' : undefined}</FieldError>
          </Field>
        </FieldGroup>

        <DialogFooter>
          <Button variant='outline' onClick={onClose} disabled={mutation.isPending}>
            Cancelar
          </Button>
          <Button onClick={() => mutation.mutate()} disabled={!canSubmit || mutation.isPending}>
            {mutation.isPending ? 'Guardando…' : 'Cambiar contraseña'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
