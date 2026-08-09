import { Img, Section, Text } from '@react-email/components'
import React from 'react'
import ButtonBase from './_components/ButtonBase'
import EmailLayout from './_components/EmailLayout'
import { Blade, BladeTrans } from './_components/LaravelBlade'
import { emailTheme } from './_theme'
import { getEmailAssetsBaseUrl } from './_utils/emailAssetsBaseUrl'

interface OwnerTransferredEmailProps {
  name?: string
  dashboardUrl?: string
}

export const OwnerTransferredEmail = ({
  name = 'Alex',
  dashboardUrl: _dashboardUrl = 'https://app.example.com/dashboard',
}: OwnerTransferredEmailProps) => (
  <EmailLayout previewText="Te han asignado como titular de la cuenta en Kivo7.">
    <Section style={{ paddingTop: '8px', paddingBottom: '4px' }}>
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
    <Section style={{ paddingTop: '28px', paddingBottom: '28px' }}>
      <Text
        className="m-0 text-center"
        style={{
          fontSize: '22px',
          fontWeight: 700,
          color: emailTheme.heading,
          letterSpacing: '-0.02em',
          lineHeight: '30px',
        }}
      >
        <BladeTrans translationKey="owner_transferred.title">
          Eres titular de la cuenta
        </BladeTrans>
      </Text>
      <Text
        className="text-center m-0"
        style={{
          fontSize: '16px',
          color: emailTheme.body,
          lineHeight: '24px',
          marginTop: '14px',
          paddingLeft: '8px',
          paddingRight: '8px',
        }}
      >
        <BladeTrans translationKey="owner_transferred.greeting">Hola</BladeTrans>{' '}
        <Blade variable="user->name" fallback={name} />,{' '}
        <BladeTrans translationKey="owner_transferred.body">
          te han asignado como titular de esta cuenta. Desde el panel puedes
          gestionar la suscripción, el equipo y la configuración.
        </BladeTrans>
      </Text>
    </Section>
    <Section style={{ paddingTop: '28px', paddingBottom: '28px', textAlign: 'center' }}>
      <ButtonBase href={'{{ $url }}'}>
        <BladeTrans translationKey="owner_transferred.cta">Ir al panel</BladeTrans>
      </ButtonBase>
    </Section>
  </EmailLayout>
)

OwnerTransferredEmail.PreviewProps = {
  name: 'Alex',
  dashboardUrl: 'https://app.example.com/dashboard',
} as OwnerTransferredEmailProps

export default OwnerTransferredEmail
