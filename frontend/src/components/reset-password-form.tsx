import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import {
  Field,
  FieldDescription,
  FieldGroup,
  FieldLabel,
} from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { PasswordStrength } from '@/shared/components/PasswordStrength'
import {
  PASSWORD_MIN_LENGTH,
  PASSWORD_REGEX,
  PASSWORD_REQUIREMENTS_TEXT,
} from '@/shared/constants/passwordRequirements'
import {
  resetPasswordRequest,
  verifyResetTokenRequest,
} from '@/shared/auth/authService'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { Eye, EyeOff, Info } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate, useParams } from 'react-router-dom'
import z from 'zod'

const resetSchema = z
  .object({
    password: z
      .string()
      .min(PASSWORD_MIN_LENGTH, { message: `Mínimo ${PASSWORD_MIN_LENGTH} caracteres` })
      .regex(PASSWORD_REGEX, { message: 'Debe incluir mayúscula, minúscula y número' }),
    password_confirmation: z.string().min(1, { message: 'Confirma la contraseña' }),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'Las contraseñas no coinciden',
    path: ['password_confirmation'],
  })

type ResetFormData = z.infer<typeof resetSchema>

function ResetPasswordFormFields({
  form,
  onSubmit,
  showPassword,
  setShowPassword,
  showPasswordConfirm,
  setShowPasswordConfirm,
}: {
  form: ReturnType<typeof useForm<ResetFormData>>
  onSubmit: (_e: React.FormEvent<HTMLFormElement>) => void
  showPassword: boolean
  setShowPassword: React.Dispatch<React.SetStateAction<boolean>>
  showPasswordConfirm: boolean
  setShowPasswordConfirm: React.Dispatch<React.SetStateAction<boolean>>
}) {
  return (
    <form
      name="reset-password"
      onSubmit={onSubmit}
      autoComplete="on"
      className="space-y-6"
    >
      <FieldGroup>
        <div className="flex flex-col items-center gap-2 text-center">
          <h1 className="text-2xl font-bold">Nueva contraseña</h1>
          <p className="text-muted-foreground text-balance">
            Elige una contraseña segura para tu cuenta.
          </p>
        </div>
        <Field>
          <div className="flex items-center gap-1.5">
            <FieldLabel htmlFor="new-password">Nueva contraseña</FieldLabel>
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  className="inline-flex text-muted-foreground hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded"
                  aria-label="Requisitos de contraseña"
                >
                  <Info className="h-4 w-4" />
                </button>
              </TooltipTrigger>
              <TooltipContent side="top" className="max-w-xs">
                {PASSWORD_REQUIREMENTS_TEXT}
              </TooltipContent>
            </Tooltip>
          </div>
          <div className="relative">
            <Input
              id="new-password"
              type={showPassword ? 'text' : 'password'}
              autoComplete="new-password"
              placeholder="Mín. 8 caracteres, mayúscula, minúscula y número"
              className="pr-10"
              aria-invalid={!!form.formState.errors.password}
              aria-describedby={
                form.formState.errors.password
                  ? 'reset-password-error'
                  : 'reset-password-strength reset-password-hint'
              }
              {...form.register('password')}
              disabled={form.formState.isSubmitting}
              required
            />
            <button
              type="button"
              onClick={() => setShowPassword((p) => !p)}
              className="absolute right-2 top-1/2 -translate-y-1/2 rounded text-muted-foreground hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
            >
              {showPassword ? (
                <EyeOff className="h-4 w-4" />
              ) : (
                <Eye className="h-4 w-4" />
              )}
            </button>
          </div>
          <PasswordStrength
            value={form.watch('password')}
            id="reset-password-strength"
            showLabel
          />
          <FieldDescription id="reset-password-hint">
            Mín. 8 caracteres, mayúscula, minúscula y número.
          </FieldDescription>
          {form.formState.errors.password && (
            <FieldDescription
              id="reset-password-error"
              className="text-destructive"
              role="alert"
            >
              {form.formState.errors.password.message}
            </FieldDescription>
          )}
        </Field>
        <Field>
          <FieldLabel htmlFor="new-password-confirm">Confirmar contraseña</FieldLabel>
          <div className="relative">
            <Input
              id="new-password-confirm"
              type={showPasswordConfirm ? 'text' : 'password'}
              autoComplete="new-password"
              placeholder="Repite la contraseña"
              className="pr-10"
              aria-invalid={!!form.formState.errors.password_confirmation}
              aria-describedby={
                form.formState.errors.password_confirmation
                  ? 'reset-password-confirm-error'
                  : undefined
              }
              {...form.register('password_confirmation')}
              disabled={form.formState.isSubmitting}
              required
            />
            <button
              type="button"
              onClick={() => setShowPasswordConfirm((p) => !p)}
              className="absolute right-2 top-1/2 -translate-y-1/2 rounded text-muted-foreground hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              aria-label={
                showPasswordConfirm ? 'Ocultar contraseña' : 'Mostrar contraseña'
              }
            >
              {showPasswordConfirm ? (
                <EyeOff className="h-4 w-4" />
              ) : (
                <Eye className="h-4 w-4" />
              )}
            </button>
          </div>
          {form.formState.errors.password_confirmation && (
            <FieldDescription
              id="reset-password-confirm-error"
              className="text-destructive"
              role="alert"
            >
              {form.formState.errors.password_confirmation.message}
            </FieldDescription>
          )}
        </Field>
        <Button
          type="submit"
          disabled={form.formState.isSubmitting}
          className="w-full"
        >
          {form.formState.isSubmitting ? 'Guardando…' : 'Restablecer contraseña'}
        </Button>
        <FieldDescription className="text-center">
          <Link to="/login" className="underline underline-offset-2 hover:text-primary">
            Volver al inicio de sesión
          </Link>
        </FieldDescription>
      </FieldGroup>
    </form>
  )
}

export function ResetPasswordForm() {
  const { token, email: encodedEmail } = useParams<{ token: string; email: string }>()
  const navigate = useNavigate()
  const [tokenValid, setTokenValid] = useState<boolean | null>(() =>
    !token || !encodedEmail ? false : null
  )
  const [showPassword, setShowPassword] = useState(false)
  const [showPasswordConfirm, setShowPasswordConfirm] = useState(false)

  const email = encodedEmail ? decodeURIComponent(encodedEmail) : ''
  const hasParams = Boolean(token && email)

  const form = useForm<ResetFormData>({
    resolver: zodResolver(resetSchema),
    defaultValues: { password: '', password_confirmation: '' },
  })

  useEffect(() => {
    if (!hasParams) return
    verifyResetTokenRequest(token!, email)
      .then((res) => setTokenValid(res.response))
      .catch(() => setTokenValid(false))
  }, [token, email, hasParams])

  const onSubmit = form.handleSubmit(async (data) => {
    if (!token || !email) return
    try {
      await resetPasswordRequest({
        token,
        email,
        password: data.password,
        password_confirmation: data.password_confirmation,
      })
      toast.success({
        title: 'Contraseña actualizada',
        description: 'Ya puedes iniciar sesión con tu nueva contraseña.',
      })
      navigate('/login', { replace: true })
    } catch {
      toast.error({
        title: 'Error',
        description:
          'No se pudo restablecer la contraseña. Intenta de nuevo o solicita un nuevo enlace.',
      })
    }
  })

  if (tokenValid === null) {
    return (
      <Card className="overflow-hidden p-0">
        <CardContent className="p-6 md:p-8">
          <p className="text-center text-muted-foreground">Comprobando enlace…</p>
        </CardContent>
      </Card>
    )
  }

  if (!tokenValid) {
    return (
      <Card className="overflow-hidden p-0">
        <CardContent className="p-6 md:p-8">
          <div className="space-y-4 text-center">
            <h1 className="text-xl font-bold">Enlace inválido o caducado</h1>
            <p className="text-muted-foreground">
              El enlace de restablecimiento no es válido o ha expirado. Solicita uno nuevo.
            </p>
            <Button asChild variant="outline">
              <Link to="/forgot-password">Solicitar nuevo enlace</Link>
            </Button>
          </div>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card className="overflow-hidden p-0">
      <CardContent className="p-6 md:p-8">
        <ResetPasswordFormFields
          form={form}
          onSubmit={onSubmit}
          showPassword={showPassword}
          setShowPassword={setShowPassword}
          showPasswordConfirm={showPasswordConfirm}
          setShowPasswordConfirm={setShowPasswordConfirm}
        />
      </CardContent>
    </Card>
  )
}
