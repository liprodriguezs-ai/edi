import { Section, Text } from '@react-email/components'
import React from 'react'
import ButtonBase from './_components/ButtonBase'
import EmailLayout from './_components/EmailLayout'
import { Blade } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

export const LoyaltyRewardEarnedEmail = () => (
  <EmailLayout previewText="Has ganado una recompensa!">
    <Section style={{ paddingTop: '32px', paddingBottom: '16px', textAlign: 'center' }}>
      <Text className="m-0" style={{ fontSize: '40px', lineHeight: '48px' }}>
        &#127881;
      </Text>
      <Text
        className="m-0"
        style={{ fontSize: '22px', fontWeight: 700, color: emailTheme.heading, letterSpacing: '-0.02em', lineHeight: '30px', marginTop: '8px' }}
      >
        Premio ganado!
      </Text>
      <Text
        className="m-0"
        style={{ fontSize: '16px', color: emailTheme.body, lineHeight: '24px', marginTop: '16px' }}
      >
        Felicidades <Blade variable="memberName" fallback="Miembro" />, tus visitas han dado fruto.
      </Text>
    </Section>

    <Section style={{ paddingBottom: '16px' }}>
      <table width="100%" cellPadding={0} cellSpacing={0}>
        <tbody>
          <tr>
            <td style={{
              backgroundColor: '#fef3c7',
              border: '2px solid #f59e0b',
              borderRadius: '12px',
              padding: '24px',
              textAlign: 'center',
            }}>
              <Text className="m-0" style={{ fontSize: '12px', color: '#92400e', textTransform: 'uppercase', letterSpacing: '1px' }}>
                Tu recompensa
              </Text>
              <Text className="m-0" style={{ fontSize: '22px', fontWeight: 700, color: '#92400e', marginTop: '4px' }}>
                <Blade variable="rewardName" fallback="Cafe Gratis" />
              </Text>
            </td>
          </tr>
        </tbody>
      </table>
    </Section>

    <Section style={{ paddingBottom: '16px' }}>
      <Text className="m-0" style={{ fontSize: '15px', color: emailTheme.body, lineHeight: '22px', textAlign: 'center' }}>
        Presenta tu codigo de miembro en tu proxima visita para canjear tu premio.
        Sigue acumulando visitas para ganar mas!
      </Text>
    </Section>

    <Section style={{ paddingBottom: '40px', textAlign: 'center' }}>
      <ButtonBase href={'{{ $profileUrl }}'}>
        Ver mis premios
      </ButtonBase>
    </Section>
  </EmailLayout>
)

LoyaltyRewardEarnedEmail.PreviewProps = {
  memberName: 'Juan Garcia',
  rewardName: 'Cafe Gratis',
  profileUrl: 'https://app.example.com/loyalty/profile/abc123',
}

export default LoyaltyRewardEarnedEmail
