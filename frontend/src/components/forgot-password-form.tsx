import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import {
  Field,
  FieldDescription,
  FieldGroup,
  FieldLabel
} from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { forgotPasswordRequest } from '@/shared/auth/authService'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import z from 'zod'

const forgotSchema = z.object({
  email: z.string().email({ message: 'Ingresa un email válido' }),
})

type ForgotFormData = z.infer<typeof forgotSchema>

export function ForgotPasswordForm() {
  const [isSubmitting, setIsSubmitting] = useState(false)

  const form = useForm<ForgotFormData>({
    resolver: zodResolver(forgotSchema),
    defaultValues: { email: '' },
  })

  const onSubmit = form.handleSubmit(async (data) => {
    try {
      setIsSubmitting(true)
      await forgotPasswordRequest(data.email)
      toast.success({
        title: 'Correo enviado',
        description:
          'Si existe una cuenta con ese correo, recibirás un enlace para restablecer tu contraseña.',
      })
      form.reset()
    } catch (error) {
      toast.error({
        title: 'Error',
        description: error instanceof Error ? error.message : 'No se pudo enviar el enlace.',
      })
    } finally {
      setIsSubmitting(false)
    }
  })

  return (
    <Card className="overflow-hidden p-0">
      <CardContent className="p-6 md:p-8">
        <form
          name="forgot-password"
          onSubmit={onSubmit}
          autoComplete="on"
          className="space-y-6"
        >
          <FieldGroup>
            <div className="flex flex-col items-center gap-2 text-center">
              <h1 className="text-2xl font-bold">Restablecer contraseña</h1>
              <p className="text-muted-foreground text-balance">
                Indica tu correo y te enviaremos un enlace para crear una nueva contraseña.
              </p>
            </div>
            <Field>
              <FieldLabel htmlFor="forgot-email">Email</FieldLabel>
              <Input
                id="forgot-email"
                type="email"
                autoComplete="email"
                placeholder="m@example.com"
                {...form.register('email')}
                disabled={isSubmitting}
                required
              />
            </Field>
            <Button type="submit" disabled={isSubmitting} className="w-full">
              {isSubmitting ? 'Enviando…' : 'Enviar enlace'}
            </Button>
            <FieldDescription className="text-center">
              <Link to="/login" className="underline underline-offset-2 hover:text-primary">
                Volver al inicio de sesión
              </Link>
            </FieldDescription>
          </FieldGroup>
        </form>
      </CardContent>
    </Card>
  )
}
