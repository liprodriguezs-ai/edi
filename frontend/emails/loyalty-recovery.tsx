import { Section, Text } from '@react-email/components'
import React from 'react'
import ButtonBase from './_components/ButtonBase'
import Card from './_components/Card'
import EmailLayout from './_components/EmailLayout'
import { Blade } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

export const LoyaltyRecoveryEmail = () => (
  <EmailLayout previewText="Recupera tu tarjeta de fidelidad">
    <Section style={{ paddingTop: '32px', paddingBottom: '16px', textAlign: 'center' }}>
      <Text
        className="m-0"
        style={{ fontSize: '22px', fontWeight: 700, color: emailTheme.heading, letterSpacing: '-0.02em', lineHeight: '30px' }}
      >
        Recupera tu tarjeta
      </Text>
      <Text
        className="m-0"
        style={{ fontSize: '16px', color: emailTheme.body, lineHeight: '24px', marginTop: '16px' }}
      >
        Hola <Blade variable="memberName" fallback="Miembro" />, recibimos tu solicitud de recuperacion.
        Aqui estan los datos de tu cuenta:
      </Text>
    </Section>

    <Section style={{ paddingBottom: '16px' }}>
      <Card>
        <table width="100%" cellPadding={0} cellSpacing={0}>
          <tbody>
            <tr>
              <td style={{ padding: '6px 0', fontSize: '14px', color: emailTheme.muted }}>Codigo:</td>
              <td style={{ padding: '6px 0', fontSize: '14px', fontWeight: 600, textAlign: 'right', fontFamily: 'monospace' }}>
                <Blade variable="memberCode" fallback="LY-ABC123" />
              </td>
            </tr>
            <tr>
              <td style={{ padding: '6px 0', fontSize: '14px', color: emailTheme.muted }}>Visitas actuales:</td>
              <td style={{ padding: '6px 0', fontSize: '14px', fontWeight: 600, textAlign: 'right' }}>
                <Blade variable="currentVisits" fallback="5" />
              </td>
            </tr>
          </tbody>
        </table>
      </Card>
    </Section>

    <Section style={{ paddingBottom: '40px', textAlign: 'center' }}>
      <ButtonBase href={'{{ $profileUrl }}'}>
        Ver mi perfil
      </ButtonBase>
    </Section>
  </EmailLayout>
)

LoyaltyRecoveryEmail.PreviewProps = {
  memberName: 'Juan Garcia',
  memberCode: 'LY-A7X9K2',
  profileUrl: 'https://app.example.com/loyalty/profile/abc123',
  currentVisits: 5,
}

export default LoyaltyRecoveryEmail
