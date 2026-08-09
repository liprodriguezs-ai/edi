import { Img, Link, Section, Text } from '@react-email/components'
import React from 'react'
import ButtonBase from './_components/ButtonBase'
import EmailLayout from './_components/EmailLayout'
import { Blade, BladeTrans } from './_components/LaravelBlade'
import { emailTheme } from './_theme'
import { getEmailAssetsBaseUrl } from './_utils/emailAssetsBaseUrl'

interface WelcomeEmailProps {
  name?: string
  startUrl?: string
  helpCenterUrl?: string
}

const featureBlockStyle = {
  padding: '16px 18px',
  backgroundColor: emailTheme.bgSurface,
  borderRadius: '8px',
  border: `1px solid ${emailTheme.border}`,
} as const

export const WelcomeEmail = ({
  name = 'Alex',
  helpCenterUrl = 'https://help.example.com',
}: WelcomeEmailProps) => (
  <EmailLayout previewText="Tu cuenta está lista. Añade tu primer colaborador y empieza a completar su expediente.">
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
    <Section style={{ paddingTop: '28px', paddingBottom: '12px' }}>
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
        <BladeTrans translationKey="welcome.title">Bienvenido</BladeTrans>
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
        <BladeTrans translationKey="welcome.greeting">Hola</BladeTrans>{' '}
        <Blade variable="user->name" fallback={name} />,{' '}
        <BladeTrans translationKey="welcome.intro">
          tu cuenta está lista. Desde el panel puedes añadir colaboradores y llevar
          el expediente de cada uno: documentos, checklist de cumplimiento y contratos.
        </BladeTrans>
      </Text>
    </Section>
    <Section style={{ paddingTop: '8px', paddingBottom: '8px' }}>
      <Text
        className="m-0"
        style={{
          fontSize: '13px',
          fontWeight: 600,
          color: emailTheme.muted,
          textTransform: 'uppercase',
          letterSpacing: '0.04em',
          marginBottom: '10px',
        }}
      >
        <BladeTrans translationKey="welcome.what_you_can_do">Qué puedes hacer</BladeTrans>
      </Text>
      <Section style={{ ...featureBlockStyle, marginBottom: '10px' }}>
        <Text
          className="m-0 mb-1"
          style={{
            fontSize: '14px',
            fontWeight: 600,
            color: emailTheme.heading,
          }}
        >
          <BladeTrans translationKey="welcome.feature1_title">
            Expediente por colaborador
          </BladeTrans>
        </Text>
        <Text
          className="m-0"
          style={{ fontSize: '14px', color: emailTheme.body, lineHeight: '22px' }}
        >
          <BladeTrans translationKey="welcome.feature1_desc">
            Todos los documentos de cada persona en un solo lugar.
          </BladeTrans>
        </Text>
      </Section>
      <Section style={featureBlockStyle}>
        <Text
          className="m-0 mb-1"
          style={{
            fontSize: '14px',
            fontWeight: 600,
            color: emailTheme.heading,
          }}
        >
          <BladeTrans translationKey="welcome.feature2_title">
            Cumplimiento documental
          </BladeTrans>
        </Text>
        <Text
          className="m-0"
          style={{ fontSize: '14px', color: emailTheme.body, lineHeight: '22px' }}
        >
          <BladeTrans translationKey="welcome.feature2_desc">
            Checklist de lo que falta: completo, incompleto o crítico por colaborador.
          </BladeTrans>
        </Text>
      </Section>
    </Section>
    <Section style={{ paddingTop: '28px', paddingBottom: '28px', textAlign: 'center' }}>
      <ButtonBase href={'{{ $url }}'}>
        <BladeTrans translationKey="welcome.cta">Añadir primer colaborador</BladeTrans>
      </ButtonBase>
    </Section>
    <Section style={{ paddingBottom: '24px', textAlign: 'center' }}>
      <Text className="m-0" style={{ fontSize: '14px', color: emailTheme.muted }}>
        <BladeTrans translationKey="welcome.help">¿Necesitas ayuda?</BladeTrans>{' '}
        <Link
          href={helpCenterUrl}
          style={{
            color: emailTheme.primary,
            textDecoration: 'none',
            fontWeight: 500,
          }}
        >
          <BladeTrans translationKey="welcome.help_link">
            Centro de ayuda
          </BladeTrans>
        </Link>
      </Text>
    </Section>
  </EmailLayout>
)

WelcomeEmail.PreviewProps = {
  name: 'Alex',
  startUrl: 'https://app.example.com/dashboard',
  helpCenterUrl: 'https://help.example.com',
} as WelcomeEmailProps

export default WelcomeEmail
