import { AuthBrandPanel } from '@/components/auth/AuthBrandPanel'
import { AuthSplitLayout } from '@/components/auth/AuthSplitLayout'
import { BrandWordmark } from '@/components/auth/BrandWordmark'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { useSession } from '@/shared/auth/useSession'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { Eye, EyeOff, Lock, Mail } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate } from 'react-router-dom'
import z from 'zod'

const loginSchema = z.object({
  email: z.string().email({ message: 'Ingresa un email válido' }),
  password: z.string().min(1, { message: 'La contraseña es requerida' }),
})

type LoginFormData = z.infer<typeof loginSchema>

const INPUT_CLASS = 'h-11 rounded-[9px] bg-card pl-[42px]'
const LABEL_CLASS = 'text-xs font-semibold text-[var(--ink-2)]'

export function LoginForm() {
  const navigate = useNavigate()
  const { login } = useSession()
  const [showPassword, setShowPassword] = useState(false)

  const loginForm = useForm<LoginFormData>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '' },
  })

  const handleLoginSubmit = loginForm.handleSubmit(async (data: LoginFormData) => {
    try {
      await login(data)
      navigate('/', { replace: true })
    } catch {
      toast.error({
        title: 'Error de autenticación',
        description: 'Credenciales inválidas. Revisa email y contraseña.',
      })
    }
  })

  return (
    <AuthSplitLayout
      brand={
        <AuthBrandPanel
          headline="La administración de tu condominio, en orden."
          subtext="Cobranza, tesorería y transparencia en un solo lugar. Bienvenido de vuelta."
          quote="Dejé tres libretas y dos hojas de Excel. Hoy la asamblea ve el reporte y nadie pregunta a dónde se fue el dinero."
          author="Alberto González"
          authorMeta="Tesorero · Loma Chica"
          authorInitials="AG"
        />
      }
    >
      <BrandWordmark className="mb-7 md:hidden" />

      <h1 className="text-[27px] font-semibold tracking-[-0.02em] text-foreground">
        Inicia sesión
      </h1>
      <p className="mt-1.5 text-sm text-[var(--ink-3)]">Entra a administrar tu condominio.</p>

      <form name="login" onSubmit={handleLoginSubmit} autoComplete="on" className="mt-7">
        <FieldGroup className="gap-4">
          <Field>
            <FieldLabel htmlFor="login-email" className={LABEL_CLASS}>
              Correo electrónico
            </FieldLabel>
            <div className="relative">
              <Mail
                aria-hidden
                className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-[var(--ink-4)]"
              />
              <Input
                id="login-email"
                type="email"
                autoComplete="username email"
                placeholder="tu@correo.mx"
                className={INPUT_CLASS}
                aria-invalid={!!loginForm.formState.errors.email}
                aria-describedby={
                  loginForm.formState.errors.email ? 'login-email-error' : undefined
                }
                {...loginForm.register('email')}
                required
              />
            </div>
            {loginForm.formState.errors.email && (
              <FieldDescription id="login-email-error" className="text-destructive" role="alert">
                {loginForm.formState.errors.email.message}
              </FieldDescription>
            )}
          </Field>

          <Field>
            <FieldLabel htmlFor="login-password" className={LABEL_CLASS}>
              Contraseña
            </FieldLabel>
            <div className="relative">
              <Lock
                aria-hidden
                className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-[var(--ink-4)]"
              />
              <Input
                id="login-password"
                type={showPassword ? 'text' : 'password'}
                autoComplete="current-password"
                placeholder="Introduce tu contraseña"
                className={`${INPUT_CLASS} pr-10`}
                aria-invalid={!!loginForm.formState.errors.password}
                aria-describedby={
                  loginForm.formState.errors.password ? 'login-password-error' : undefined
                }
                required
                {...loginForm.register('password')}
              />
              <button
                type="button"
                onClick={() => setShowPassword((prev) => !prev)}
                className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1.5 text-[var(--ink-4)] transition-colors hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
              >
                {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
              </button>
            </div>
            {loginForm.formState.errors.password && (
              <FieldDescription
                id="login-password-error"
                className="text-destructive"
                role="alert"
              >
                {loginForm.formState.errors.password.message}
              </FieldDescription>
            )}
          </Field>

          <div className="-mt-0.5 flex items-center justify-between">
            <label
              htmlFor="login-remember"
              className="flex items-center gap-2 text-[13px] text-[var(--ink-2)] cursor-pointer"
            >
              <Checkbox id="login-remember" defaultChecked />
              Recordarme
            </label>
            <Link
              to="/forgot-password"
              className="text-[13px] font-medium text-[var(--green)] underline-offset-2 hover:underline"
            >
              ¿Olvidaste tu contraseña?
            </Link>
          </div>

          <Button
            type="submit"
            className="h-12 w-full rounded-full text-[15px] font-semibold"
            disabled={loginForm.formState.isSubmitting}
          >
            {loginForm.formState.isSubmitting ? 'Entrando…' : 'Entrar al panel'}
          </Button>

          <p className="text-center text-[13.5px] text-[var(--ink-3)]">
            ¿Aún no tienes cuenta?{' '}
            <Link to="/register" className="font-semibold text-[var(--green)] hover:underline">
              Crea una
            </Link>
          </p>
        </FieldGroup>
      </form>
    </AuthSplitLayout>
  )
}
