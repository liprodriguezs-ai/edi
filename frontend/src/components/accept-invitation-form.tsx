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
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import {
  acceptInvitationConfirmRequest,
  acceptInvitationRequest,
  verifyInvitationTokenRequest,
  type VerifyInvitationTokenResponse,
} from '@/shared/auth/authService'
import { useSession } from '@/shared/auth/useSession'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { Eye, EyeOff, Info } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate, useParams } from 'react-router-dom'
import z from 'zod'

const acceptSchema = z
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

type AcceptFormData = z.infer<typeof acceptSchema>

const loginSchema = z.object({
  password: z.string().min(1, { message: 'Ingresa tu contraseña' }),
})

type LoginFormData = z.infer<typeof loginSchema>

/** Resumen de a qué unidad/clúster se está uniendo la persona (solo invitación de residente). */
function InvitationSummary({ info }: { info: VerifyInvitationTokenResponse }) {
  if (!info.cluster_name && !info.unit_label) return null
  return (
    <dl className="grid grid-cols-1 gap-1 rounded-[10px] border border-border bg-muted/40 px-4 py-3 text-sm">
      {info.cluster_name && (
        <div className="flex justify-between gap-3">
          <dt className="text-muted-foreground">Condominio</dt>
          <dd className="font-medium text-right">{info.cluster_name}</dd>
        </div>
      )}
      {info.unit_label && (
        <div className="flex justify-between gap-3">
          <dt className="text-muted-foreground">Unidad</dt>
          <dd className="font-medium text-right">{info.unit_label}</dd>
        </div>
      )}
      {info.relationship_name && (
        <div className="flex justify-between gap-3">
          <dt className="text-muted-foreground">Tu rol</dt>
          <dd className="font-medium text-right capitalize">{info.relationship_name}</dd>
        </div>
      )}
      {info.inviter_name && (
        <div className="flex justify-between gap-3">
          <dt className="text-muted-foreground">Te invitó</dt>
          <dd className="font-medium text-right">{info.inviter_name}</dd>
        </div>
      )}
    </dl>
  )
}

/** Crear cuenta nueva (contraseña + confirmación + fuerza). */
function NewAccountForm({
  info,
  onSubmit,
  form,
}: {
  info: VerifyInvitationTokenResponse
  onSubmit: (_e: React.FormEvent<HTMLFormElement>) => void
  form: ReturnType<typeof useForm<AcceptFormData>>
}) {
  const [showPassword, setShowPassword] = useState(false)
  const [showPasswordConfirm, setShowPasswordConfirm] = useState(false)
  const isResident = Boolean(info.cluster_name || info.unit_label)

  return (
    <form name="accept-invitation" onSubmit={onSubmit} autoComplete="on" className="space-y-6">
      <FieldGroup>
        <div className="flex flex-col items-center gap-2 text-center">
          <h1 className="text-2xl font-bold">
            {isResident && info.cluster_name ? `Te invitaron a ${info.cluster_name}` : 'Completar mi cuenta'}
          </h1>
          <p className="text-muted-foreground text-balance">
            {isResident && info.unit_label
              ? `Crea tu contraseña para unirte a ${info.unit_label}${info.relationship_name ? ` como ${info.relationship_name}` : ''}.`
              : 'Elige una contraseña segura para poder iniciar sesión y consultar tu información.'}
          </p>
          {info.email && (
            <p className="text-sm text-muted-foreground" role="status">
              Cuenta: <span className="font-medium text-foreground">{info.email}</span>
            </p>
          )}
        </div>

        <InvitationSummary info={info} />

        <Field>
          <div className="flex items-center gap-1.5">
            <FieldLabel htmlFor="accept-password">Contraseña</FieldLabel>
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
              id="accept-password"
              type={showPassword ? 'text' : 'password'}
              autoComplete="new-password"
              placeholder="Mín. 8 caracteres, mayúscula, minúscula y número"
              className="pr-10"
              aria-invalid={!!form.formState.errors.password}
              aria-describedby={
                form.formState.errors.password
                  ? 'accept-password-error'
                  : 'accept-password-strength accept-password-hint'
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
              {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            </button>
          </div>
          <PasswordStrength value={form.watch('password')} id="accept-password-strength" showLabel />
          <FieldDescription id="accept-password-hint">
            Mín. 8 caracteres, mayúscula, minúscula y número.
          </FieldDescription>
          {form.formState.errors.password && (
            <FieldDescription id="accept-password-error" className="text-destructive" role="alert">
              {form.formState.errors.password.message}
            </FieldDescription>
          )}
        </Field>

        <Field>
          <FieldLabel htmlFor="accept-password-confirm">Confirmar contraseña</FieldLabel>
          <div className="relative">
            <Input
              id="accept-password-confirm"
              type={showPasswordConfirm ? 'text' : 'password'}
              autoComplete="new-password"
              placeholder="Repite la contraseña"
              className="pr-10"
              aria-invalid={!!form.formState.errors.password_confirmation}
              aria-describedby={
                form.formState.errors.password_confirmation ? 'accept-password-confirm-error' : undefined
              }
              {...form.register('password_confirmation')}
              disabled={form.formState.isSubmitting}
              required
            />
            <button
              type="button"
              onClick={() => setShowPasswordConfirm((p) => !p)}
              className="absolute right-2 top-1/2 -translate-y-1/2 rounded text-muted-foreground hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              aria-label={showPasswordConfirm ? 'Ocultar contraseña' : 'Mostrar contraseña'}
            >
              {showPasswordConfirm ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            </button>
          </div>
          {form.formState.errors.password_confirmation && (
            <FieldDescription id="accept-password-confirm-error" className="text-destructive" role="alert">
              {form.formState.errors.password_confirmation.message}
            </FieldDescription>
          )}
        </Field>

        <Button type="submit" disabled={form.formState.isSubmitting} className="w-full">
          {form.formState.isSubmitting ? 'Guardando…' : isResident ? 'Crear mi cuenta y unirme' : 'Completar mi cuenta'}
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

/** Cuenta existente: la persona ya tiene cuenta, inicia sesión para unirse. */
function ExistingAccountForm({
  info,
  onSubmit,
  form,
}: {
  info: VerifyInvitationTokenResponse
  onSubmit: (_e: React.FormEvent<HTMLFormElement>) => void
  form: ReturnType<typeof useForm<LoginFormData>>
}) {
  const [showPassword, setShowPassword] = useState(false)

  return (
    <form name="join-with-account" onSubmit={onSubmit} autoComplete="on" className="space-y-6">
      <FieldGroup>
        <div className="flex flex-col items-center gap-2 text-center">
          <h1 className="text-2xl font-bold">
            {info.unit_label ? `Únete a ${info.unit_label}` : 'Únete a tu unidad'}
          </h1>
          <p className="text-muted-foreground text-balance">
            Tu correo <span className="font-medium text-foreground">{info.email}</span> ya tiene una
            cuenta. Inicia sesión para unirte
            {info.unit_label ? ` a ${info.unit_label}` : ''}
            {info.cluster_name ? ` en ${info.cluster_name}` : ''}.
          </p>
        </div>

        <InvitationSummary info={info} />

        <Field>
          <FieldLabel htmlFor="join-password">Contraseña</FieldLabel>
          <div className="relative">
            <Input
              id="join-password"
              type={showPassword ? 'text' : 'password'}
              autoComplete="current-password"
              placeholder="Tu contraseña actual"
              className="pr-10"
              aria-invalid={!!form.formState.errors.password}
              aria-describedby={form.formState.errors.password ? 'join-password-error' : undefined}
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
              {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            </button>
          </div>
          {form.formState.errors.password && (
            <FieldDescription id="join-password-error" className="text-destructive" role="alert">
              {form.formState.errors.password.message}
            </FieldDescription>
          )}
        </Field>

        <Button type="submit" disabled={form.formState.isSubmitting} className="w-full">
          {form.formState.isSubmitting ? 'Uniéndote…' : 'Iniciar sesión y unirme'}
        </Button>
        <FieldDescription className="text-center">
          <Link to="/forgot-password" className="underline underline-offset-2 hover:text-primary">
            ¿Olvidaste tu contraseña?
          </Link>
        </FieldDescription>
      </FieldGroup>
    </form>
  )
}

export function AcceptInvitationForm() {
  const { token } = useParams<{ token: string }>()
  const navigate = useNavigate()
  const { login } = useSession()
  const [tokenState, setTokenState] = useState<VerifyInvitationTokenResponse | null>(null)

  const newForm = useForm<AcceptFormData>({
    resolver: zodResolver(acceptSchema),
    defaultValues: { password: '', password_confirmation: '' },
  })

  const existingForm = useForm<LoginFormData>({
    resolver: zodResolver(loginSchema),
    defaultValues: { password: '' },
  })

  useEffect(() => {
    if (!token) {
      // Defer to a microtask so we never setState synchronously inside the effect.
      void Promise.resolve().then(() => setTokenState({ valid: false }))
      return
    }
    verifyInvitationTokenRequest(token)
      .then((res) => setTokenState(res))
      .catch(() => setTokenState({ valid: false }))
  }, [token])

  const onSubmitNew = newForm.handleSubmit(async (data) => {
    if (!token) return
    try {
      await acceptInvitationRequest({
        token,
        password: data.password,
        password_confirmation: data.password_confirmation,
      })

      // Auto-login so the person lands directly in their unit ("y unirme").
      try {
        if (tokenState?.email) {
          await login({ email: tokenState.email, password: data.password })
          toast.success({ title: '¡Listo!', description: 'Tu cuenta está activa y te uniste a tu unidad.' })
          navigate('/my-unit', { replace: true })
          return
        }
      } catch {
        // Fall back to manual login below.
      }

      toast.success({ title: 'Cuenta creada', description: 'Inicia sesión con tu correo y contraseña.' })
      navigate('/login', { replace: true })
    } catch (error) {
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'El enlace puede haber expirado.'
      )
    }
  })

  const onSubmitExisting = existingForm.handleSubmit(async (data) => {
    if (!token || !tokenState?.email) return
    try {
      await login({ email: tokenState.email, password: data.password })
      await acceptInvitationConfirmRequest({ token })
      toast.success({ title: '¡Listo!', description: 'Te uniste a tu unidad.' })
      navigate('/my-unit', { replace: true })
    } catch (error) {
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'Revisa tu contraseña e inténtalo de nuevo.'
      )
    }
  })

  if (tokenState === null) {
    return (
      <Card className="overflow-hidden p-0">
        <CardContent className="p-6 md:p-8">
          <p className="text-center text-muted-foreground" role="status">
            Comprobando enlace…
          </p>
        </CardContent>
      </Card>
    )
  }

  if (!tokenState.valid) {
    return (
      <Card className="overflow-hidden p-0">
        <CardContent className="p-6 md:p-8">
          <div className="space-y-4 text-center">
            <h1 className="text-xl font-bold">La invitación venció</h1>
            <p className="text-muted-foreground" role="alert">
              Este enlace ya no funciona. Las invitaciones vencen a los 7 días. Pídele a quien te invitó
              que te envíe una nueva.
            </p>
            <Button asChild variant="outline">
              <Link to="/login">Ir al inicio de sesión</Link>
            </Button>
          </div>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card className="overflow-hidden p-0">
      <CardContent className="p-6 md:p-8">
        {tokenState.account_exists ? (
          <ExistingAccountForm info={tokenState} form={existingForm} onSubmit={onSubmitExisting} />
        ) : (
          <NewAccountForm info={tokenState} form={newForm} onSubmit={onSubmitNew} />
        )}
      </CardContent>
    </Card>
  )
}
