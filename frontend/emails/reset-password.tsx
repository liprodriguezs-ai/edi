import { Img, Link, Section, Text } from '@react-email/components'
import React from 'react'
import ButtonBase from './_components/ButtonBase'
import EmailLayout from './_components/EmailLayout'
import { BladeTrans, LaravelBlade } from './_components/LaravelBlade'
import { emailTheme } from './_theme'
import { getEmailAssetsBaseUrl } from './_utils/emailAssetsBaseUrl'

interface ResetPasswordEmailProps {
  name?: string
  resetUrl?: string
  /** Expiration in minutes (e.g. 60, 1440). Rendered as Blade {{ $count }} in production. */
  expiresInMinutes?: number
}

export const ResetPasswordEmail = ({
  name: _name = 'Usuario',
  resetUrl = 'https://app.example.com/reset-password?token=xxx',
  expiresInMinutes = 60,
}: ResetPasswordEmailProps) => (
  <EmailLayout previewText="Restablece tu contraseña de EDI">
    <Section style={{ paddingTop: '8px', paddingBottom: '8px' }}>
      <Img
        src={`${getEmailAssetsBaseUrl()}/static/banner.jpg`}
        alt=""
        width={560}
        style={{
          width: '100%',
          maxWidth: '560px',
          height: 'auto',
          borderRadius: '8px',
          display: 'block',
          margin: '0 auto',
        }}
      />
    </Section>
    <Section style={{ paddingTop: '32px', paddingBottom: '16px' }}>
      <Text
        className="m-0 text-center"
        style={{
          fontSize: '20px',
          fontWeight: 700,
          color: emailTheme.heading,
          letterSpacing: '-0.02em',
          lineHeight: '28px',
        }}
      >
        <BladeTrans translationKey="reset_password.title">
          Restablecer tu contraseña
        </BladeTrans>
      </Text>
      <Text
        className="text-center m-0"
        style={{
          fontSize: '15px',
          color: emailTheme.body,
          lineHeight: '22px',
          marginTop: '12px',
        }}
      >
        <BladeTrans translationKey="reset_password.intro">
          Recibimos una solicitud para restablecer la contraseña de tu cuenta. Haz
          clic en el botón de abajo para elegir una nueva contraseña segura.
        </BladeTrans>
      </Text>
    </Section>
    <Section style={{ paddingTop: '24px', paddingBottom: '24px', textAlign: 'center' }}>
      <ButtonBase href={'{{ $url }}'}>
        <BladeTrans translationKey="reset_password.cta">
          Restablecer contraseña
        </BladeTrans>
      </ButtonBase>
    </Section>
    <Section
      style={{
        paddingTop: '0',
        paddingBottom: '16px',
      }}
    >
      <Text
        className="m-0"
        style={{
          fontSize: '13px',
          color: emailTheme.muted,
          lineHeight: '20px',
        }}
      >
        <BladeTrans translationKey="reset_password.fallback_intro">
          Si el botón no funciona, copia y pega este enlace en tu navegador:
        </BladeTrans>
      </Text>
      <Text
        className="m-0"
        style={{
          fontSize: '13px',
          marginTop: '8px',
          wordBreak: 'break-all' as const,
        }}
      >
        <Link
          href={'{{ $url }}'}
          title="Abrir enlace de restablecimiento de contraseña"
          style={{
            color: emailTheme.primary,
            textDecoration: 'underline',
          }}
        >
          <LaravelBlade variable="url" html={false}>
            {resetUrl}
          </LaravelBlade>
        </Link>
      </Text>
    </Section>
    <Section
      style={{
        padding: '16px',
        backgroundColor: emailTheme.primaryLight,
        borderRadius: '8px',
        border: `1px solid ${emailTheme.primaryBorder}`,
      }}
    >
      <Text
        className="m-0"
        style={{
          fontSize: '13px',
          color: emailTheme.primaryDark,
          lineHeight: '20px',
        }}
      >
        <BladeTrans translationKey="reset_password.security_note">
          Si no solicitaste este cambio, puedes ignorar este correo. Tu contraseña
          actual no cambiará. Este enlace expira en{' '}
          <LaravelBlade variable="count" html={false}>
            {expiresInMinutes}
          </LaravelBlade>{' '}
          minutos.
        </BladeTrans>
      </Text>
    </Section>
    <Section style={{ paddingTop: '24px' }} />
  </EmailLayout>
)

ResetPasswordEmail.PreviewProps = {
  name: 'Usuario',
  resetUrl: 'https://app.example.com/reset-password?token=xxx',
  expiresInMinutes: 60,
} as ResetPasswordEmailProps

export default ResetPasswordEmail
