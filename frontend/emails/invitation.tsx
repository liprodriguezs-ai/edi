import { Section, Text } from '@react-email/components'
import React from 'react'
import ButtonBase from './_components/ButtonBase'
import EmailLayout from './_components/EmailLayout'
import { BladeTrans } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

interface InvitationEmailProps {
  acceptUrl: string
}

export const InvitationEmail = () => (
  <EmailLayout previewText="Invitación a tu cuenta de colaborador">
    <Section
      style={{
        paddingTop: '40px',
        paddingBottom: '24px',
        textAlign: 'center',
      }}
    >
      <Text
        className="m-0"
        style={{
          fontSize: '22px',
          fontWeight: 700,
          color: emailTheme.heading,
          letterSpacing: '-0.02em',
          lineHeight: '30px',
        }}
      >
        <BladeTrans translationKey="invitation.title">
          Invitación a tu cuenta de colaborador
        </BladeTrans>
      </Text>
      <Text
        className="m-0"
        style={{
          fontSize: '16px',
          color: emailTheme.body,
          lineHeight: '24px',
          marginTop: '16px',
        }}
      >
        <BladeTrans translationKey="invitation.intro">
          Has sido invitado a la plataforma para consultar tu expediente e información laboral.
        </BladeTrans>
      </Text>
      <Text
        className="m-0"
        style={{
          fontSize: '15px',
          color: emailTheme.muted,
          lineHeight: '22px',
          marginTop: '8px',
        }}
      >
        <BladeTrans translationKey="invitation.accept_instructions">
          Completa tu cuenta eligiendo una contraseña para poder iniciar sesión y consultar tu información.
        </BladeTrans>
      </Text>
    </Section>
    <Section style={{ paddingBottom: '48px', textAlign: 'center' }}>
      <ButtonBase href={'{{ $acceptUrl }}'}>
        <BladeTrans translationKey="invitation.cta">Completar mi cuenta</BladeTrans>
      </ButtonBase>
    </Section>
  </EmailLayout>
)

InvitationEmail.PreviewProps = {
  acceptUrl: 'https://app.example.com/auth/accept-invitation/xxx',
} as InvitationEmailProps

export default InvitationEmail
