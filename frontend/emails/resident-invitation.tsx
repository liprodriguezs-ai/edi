import { Section, Text } from '@react-email/components'
import React from 'react'
import ButtonBase from './_components/ButtonBase'
import EmailLayout from './_components/EmailLayout'
import { Blade } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

interface ResidentInvitationEmailProps {
  acceptUrl: string
  inviterName: string
  unitLabel: string
  clusterName: string
  relationshipName: string
  ttlDays: number
}

export const ResidentInvitationEmail = () => (
  <EmailLayout previewText="Te invitaron a unirte a tu unidad">
    <Section style={{ paddingTop: '40px', paddingBottom: '24px', textAlign: 'center' }}>
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
        Te invitaron a <Blade variable="clusterName" fallback="tu condominio" />
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
        <Blade variable="inviterName" fallback="Un residente" /> te invitó a unirte a{' '}
        <Blade variable="unitLabel" fallback="tu unidad" /> como{' '}
        <Blade variable="relationshipName" fallback="residente" />.
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
        Crea tu contraseña para activar tu cuenta y acceder a tu unidad. Esta invitación vence en{' '}
        <Blade variable="ttlDays" fallback="7" /> días.
      </Text>
    </Section>
    <Section style={{ paddingBottom: '16px', textAlign: 'center' }}>
      <ButtonBase href={'{{ $acceptUrl }}'}>Activar mi cuenta</ButtonBase>
    </Section>
    <Section style={{ paddingBottom: '40px', textAlign: 'center' }}>
      <Text
        className="m-0"
        style={{ fontSize: '13px', color: emailTheme.muted, lineHeight: '20px' }}
      >
        Si no esperabas esta invitación, puedes ignorar este correo.
      </Text>
    </Section>
  </EmailLayout>
)

ResidentInvitationEmail.PreviewProps = {
  acceptUrl: 'https://app.example.com/auth/accept-invitation/xxx',
  inviterName: 'Roberto Méndez',
  unitLabel: 'Casa 1',
  clusterName: 'Privada Los Encinos',
  relationshipName: 'Familiar residente',
  ttlDays: 7,
} as ResidentInvitationEmailProps

export default ResidentInvitationEmail
