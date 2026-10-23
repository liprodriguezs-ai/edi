import { AuthBrandPanel } from '@/components/auth/AuthBrandPanel'
import { AuthSplitLayout } from '@/components/auth/AuthSplitLayout'
import { BrandWordmark } from '@/components/auth/BrandWordmark'
import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { useSession } from '@/shared/auth/useSession'
import { APP_CONFIG } from '@/shared/config/appConfig'
import { PASSWORD_MIN_LENGTH, PASSWORD_REGEX } from '@/shared/constants/passwordRequirements'
import { toast } from '@/shared/hooks/useToast'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { zodResolver } from '@hookform/resolvers/zod'
import { Building2, Eye, EyeOff, Lock, Mail, User } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import z from 'zod'

const signupSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  organizationName: z
    .string()
    .trim()
    .min(1, 'El nombre del centro es requerido')
    .max(255, 'Máximo 255 caracteres'),
  email: z.string().email('Ingresa un email válido'),
  password: z
    .string()
    .min(PASSWORD_MIN_LENGTH, `Mínimo ${PASSWORD_MIN_LENGTH} caracteres`)
    .regex(PASSWORD_REGEX, 'Debe incluir mayúscula, minúscula y número'),
})

type SignupFormData = z.infer<typeof signupSchema>

const INPUT_CLASS = 'h-11 rounded-none bg-card pl-[42px]'
const LABEL_CLASS = 'text-xs font-semibold text-[var(--ink-2)]'
const ICON_CLASS =
  'pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-[var(--ink-4)]'

export function SignupForm() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const { register: doRegister } = useSession()
  const [showPassword, setShowPassword] = useState(false)

  const form = useForm<SignupFormData>({
    resolver: zodResolver(signupSchema),
    defaultValues: { name: '', organizationName: '', email: '', password: '' },
  })

  const onSubmit = form.handleSubmit(async (data) => {
    try {
      const profile = await doRegister({
        name: data.name,
        organization_name: data.organizationName,
        email: data.email,
        password: data.password,
        password_confirmation: data.password,
      })
      // El registro ya no otorga ningún acceso local: el dueño de una organización
      // nuevo (rol account.owner) SIEMPRE va a /select-plan, tenga o no una key
      // de plan válida en la URL — mientras no elija un plan ni registre un
      // medio de pago la organización queda bloqueado, así que saltarse este paso
      // lo dejaría varado.
      if (profile.roles[0] === ERoleUserSlug.ACCOUNT_OWNER) {
        // La key viaja TAL CUAL: quien decide si existe es el catálogo, ya en
        // /select-plan (useBillingQuoter la busca con `plans.find(...)` y cae al
        // primer plan si no aparece). Validarla aquí contra una lista local
        // significaba mantener una copia del catálogo en el frontend.
        //
        // encodeURIComponent es obligatorio ahora que no hay lista blanca: sin él
        // una key con `&` inyectaría parámetros extra en nuestra propia URL.
        const planKey = searchParams.get('plan')
        navigate(planKey ? `/select-plan?plan=${encodeURIComponent(planKey)}` : '/select-plan', {
          replace: true,
        })
      } else {
        // Siempre pasamos por "/" (HomeRedirect): él decide si toca el
        // onboarding o la home del rol. Ir directo a roleRoutes se saltaba
        // el onboarding justo después del registro, que es cuando más importa.
        navigate('/', { replace: true })
      }
    } catch (error) {
      toast.error({
        title: 'Error al registrarse',
        description: error instanceof Error ? error.message : 'Revisa los datos e intenta de nuevo',
      })
    }
  })

  return (
    <AuthSplitLayout
      brand={
        <AuthBrandPanel
          headline='Empieza a tamizar esta semana.'
          subtext='Crea tu cuenta y da de alta tu centro en minutos. Pruébalo 30 días gratis.'
          quote='En una semana dejamos el formato de papel. Ya no se nos pasa la evaluación alterna.'
          author='Dr. Iván Beltrán'
          authorMeta='Médico general · CS Milpa Alta'
          authorInitials='IB'
        />
      }
    >
      <BrandWordmark className='mb-7 md:hidden' />

      {/* Ahora que dar tarjeta es obligatorio, el alta tiene dos pasos y conviene
          decirlo: no saber cuánto falta es de las principales causas de abandono. */}
      <p className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
        Paso 1 de 2
      </p>
      <h1 className='mt-3 text-[27px] font-semibold tracking-[-0.02em] text-foreground'>
        Crea tu cuenta
      </h1>
      <p className='mt-1.5 text-sm text-[var(--ink-3)]'>
        En el siguiente paso eliges tu plan. Hoy no pagas nada.
      </p>

      <form name='signup' onSubmit={onSubmit} autoComplete='on' className='mt-7'>
        <FieldGroup className='gap-4'>
          <Field>
            <FieldLabel htmlFor='signup-name' className={LABEL_CLASS}>
              Nombre completo
            </FieldLabel>
            <div className='relative'>
              <User aria-hidden className={ICON_CLASS} />
              <Input
                id='signup-name'
                type='text'
                autoComplete='name'
                placeholder='Lorena Ramírez'
                className={INPUT_CLASS}
                aria-invalid={!!form.formState.errors.name}
                aria-describedby={form.formState.errors.name ? 'signup-name-error' : undefined}
                {...form.register('name')}
              />
            </div>
            {form.formState.errors.name && (
              <FieldDescription id='signup-name-error' className='text-destructive' role='alert'>
                {form.formState.errors.name.message}
              </FieldDescription>
            )}
          </Field>

          <Field>
            <FieldLabel htmlFor='signup-organization-name' className={LABEL_CLASS}>
              Nombre del centro
            </FieldLabel>
            <div className='relative'>
              <Building2 aria-hidden className={ICON_CLASS} />
              <Input
                id='signup-organization-name'
                type='text'
                autoComplete='organization'
                placeholder='Centro de Salud Xochimilco'
                className={INPUT_CLASS}
                aria-invalid={!!form.formState.errors.organizationName}
                aria-describedby={
                  form.formState.errors.organizationName
                    ? 'signup-organization-name-error'
                    : 'signup-organization-name-hint'
                }
                {...form.register('organizationName')}
              />
            </div>
            {form.formState.errors.organizationName ? (
              <FieldDescription
                id='signup-organization-name-error'
                className='text-destructive'
                role='alert'
              >
                {form.formState.errors.organizationName.message}
              </FieldDescription>
            ) : (
              <FieldDescription id='signup-organization-name-hint'>
                Así lo verá tu equipo. Puedes cambiarlo después.
              </FieldDescription>
            )}
          </Field>

          <Field>
            <FieldLabel htmlFor='signup-email' className={LABEL_CLASS}>
              Correo electrónico
            </FieldLabel>
            <div className='relative'>
              <Mail aria-hidden className={ICON_CLASS} />
              <Input
                id='signup-email'
                type='email'
                autoComplete='email'
                placeholder='tu@correo.mx'
                className={INPUT_CLASS}
                aria-invalid={!!form.formState.errors.email}
                aria-describedby={form.formState.errors.email ? 'signup-email-error' : undefined}
                {...form.register('email')}
              />
            </div>
            {form.formState.errors.email && (
              <FieldDescription id='signup-email-error' className='text-destructive' role='alert'>
                {form.formState.errors.email.message}
              </FieldDescription>
            )}
          </Field>

          <Field>
            <FieldLabel htmlFor='signup-password' className={LABEL_CLASS}>
              Contraseña
            </FieldLabel>
            <div className='relative'>
              <Lock aria-hidden className={ICON_CLASS} />
              <Input
                id='signup-password'
                type={showPassword ? 'text' : 'password'}
                autoComplete='new-password'
                placeholder='Mínimo 8 caracteres'
                className={`${INPUT_CLASS} pr-10`}
                aria-invalid={!!form.formState.errors.password}
                aria-describedby={
                  form.formState.errors.password ? 'signup-password-error' : 'signup-password-hint'
                }
                {...form.register('password')}
              />
              <button
                type='button'
                onClick={() => setShowPassword((prev) => !prev)}
                className='absolute right-2 top-1/2 -translate-y-1/2 rounded p-1.5 text-[var(--ink-4)] transition-colors hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring'
                aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
              >
                {showPassword ? <EyeOff className='size-4' /> : <Eye className='size-4' />}
              </button>
            </div>
            {form.formState.errors.password ? (
              <FieldDescription
                id='signup-password-error'
                className='text-destructive'
                role='alert'
              >
                {form.formState.errors.password.message}
              </FieldDescription>
            ) : (
              <FieldDescription id='signup-password-hint'>
                Usa 8+ caracteres con números y mayúsculas.
              </FieldDescription>
            )}
          </Field>

          <Button
            type='submit'
            className='h-12 w-full rounded-none text-[15px] font-semibold'
            disabled={form.formState.isSubmitting}
          >
            {form.formState.isSubmitting ? 'Creando cuenta…' : 'Continuar'}
          </Button>

          <p className='text-center text-xs leading-relaxed text-[var(--ink-3)]'>
            Al continuar aceptas los{' '}
            <a
              href='https://edi.example.com/terminos'
              target='_blank'
              rel='noopener noreferrer'
              className='text-[var(--ink-2)] underline underline-offset-2'
            >
              Términos
            </a>{' '}
            y el{' '}
            <a
              href='https://edi.example.com/privacidad'
              target='_blank'
              rel='noopener noreferrer'
              className='text-[var(--ink-2)] underline underline-offset-2'
            >
              Aviso de privacidad
            </a>{' '}
            de {APP_CONFIG.brandName}.
          </p>

          <p className='text-center text-[13.5px] text-[var(--ink-3)]'>
            ¿Ya tienes cuenta?{' '}
            <Link to='/login' className='font-semibold text-[var(--green)] hover:underline'>
              Inicia sesión
            </Link>
          </p>
        </FieldGroup>
      </form>
    </AuthSplitLayout>
  )
}
