import { Section, Text } from '@react-email/components'
import React from 'react'
import ButtonBase from './_components/ButtonBase'
import Card from './_components/Card'
import EmailLayout from './_components/EmailLayout'
import { Blade } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

export const LoyaltyWelcomeEmail = () => (
  <EmailLayout previewText="Bienvenido al programa de fidelidad">
    <Section style={{ paddingTop: '32px', paddingBottom: '16px', textAlign: 'center' }}>
      <Text
        className="m-0"
        style={{ fontSize: '22px', fontWeight: 700, color: emailTheme.heading, letterSpacing: '-0.02em', lineHeight: '30px' }}
      >
        Bienvenido al programa de fidelidad
      </Text>
      <Text
        className="m-0"
        style={{ fontSize: '16px', color: emailTheme.body, lineHeight: '24px', marginTop: '16px' }}
      >
        Hola <Blade variable="memberName" fallback="Miembro" />, ya eres parte de nuestro programa.
        Acumula visitas y gana premios increibles.
      </Text>
    </Section>

    <Section style={{ paddingBottom: '16px' }}>
      <Card>
        <Text className="m-0" style={{ fontSize: '12px', color: emailTheme.muted, textTransform: 'uppercase', letterSpacing: '1px', textAlign: 'center' }}>
          Tu codigo de miembro
        </Text>
        <Text className="m-0" style={{ fontSize: '28px', fontWeight: 700, color: emailTheme.heading, fontFamily: 'monospace', letterSpacing: '3px', textAlign: 'center', marginTop: '4px' }}>
          <Blade variable="memberCode" fallback="LY-ABC123" />
        </Text>
      </Card>
    </Section>

    <Section style={{ paddingBottom: '16px' }}>
      <Text className="m-0" style={{ fontSize: '15px', color: emailTheme.body, lineHeight: '22px', textAlign: 'center' }}>
        Presenta este codigo cada vez que nos visites para acumular sellos.
      </Text>
    </Section>

    <Section style={{ paddingBottom: '40px', textAlign: 'center' }}>
      <ButtonBase href={'{{ $profileUrl }}'}>
        Ver mi perfil
      </ButtonBase>
    </Section>
  </EmailLayout>
)

LoyaltyWelcomeEmail.PreviewProps = {
  memberName: 'Juan Garcia',
  memberCode: 'LY-A7X9K2',
  profileUrl: 'https://app.example.com/loyalty/profile/abc123',
}

export default LoyaltyWelcomeEmail
