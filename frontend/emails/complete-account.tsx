import { Section, Text } from '@react-email/components'
import React from 'react'
import ButtonBase from './_components/ButtonBase'
import EmailLayout from './_components/EmailLayout'
import { Blade, BladeTrans } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

interface CompleteAccountEmailProps {
  name?: string
  completeUrl?: string
}

const steps = [
  {
    key: 'step1',
    titleKey: 'complete_account.step1_title',
    titleFallback: '1. Confirmar datos',
    descKey: 'complete_account.step1_desc',
    descFallback: 'Verifica tu información personal y de empresa.',
  },
  {
    key: 'step2',
    titleKey: 'complete_account.step2_title',
    titleFallback: '2. Configurar preferencias',
    descKey: 'complete_account.step2_desc',
    descFallback: 'Ajusta notificaciones y opciones de cuenta.',
  },
  {
    key: 'step3',
    titleKey: 'complete_account.step3_title',
    titleFallback: '3. Invitar al equipo',
    descKey: 'complete_account.step3_desc',
    descFallback: 'Añade colaboradores cuando quieras.',
  },
]

export const CompleteAccountEmail = ({
  name = 'Usuario',
  completeUrl = 'https://app.example.com/complete-account',
}: CompleteAccountEmailProps) => (
  <EmailLayout previewText="¡Estás a un paso! Completa tu cuenta de Kivo7">
    <Section style={{ paddingTop: '32px', paddingBottom: '16px' }}>
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
        <BladeTrans translationKey="complete_account.title">
          ¡Estás a un paso de comenzar!
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
        <BladeTrans translationKey="complete_account.greeting">Hola</BladeTrans>,{' '}
        <Blade variable="user->name" fallback={name} />.{' '}
        <BladeTrans translationKey="complete_account.intro">
          Tu cuenta está casi lista. Solo necesitamos unos detalles finales para que
          puedas empezar a potenciar tus ventas y gestionar tus clientes como un
          profesional.
        </BladeTrans>
      </Text>
    </Section>
    <Section style={{ paddingBottom: '8px' }}>
      <Text
        className="m-0 mb-3"
        style={{
          fontSize: '14px',
          fontWeight: 600,
          color: emailTheme.heading,
        }}
      >
        <BladeTrans translationKey="complete_account.steps_heading">
          Pasos restantes para activar tu cuenta:
        </BladeTrans>
      </Text>
      {steps.map((step) => (
        <Section
          key={step.key}
          style={{
            padding: '16px',
            backgroundColor: emailTheme.bgSurface,
            borderRadius: '8px',
            border: `1px solid ${emailTheme.border}`,
            marginBottom: '12px',
          }}
        >
          <Text
            className="m-0 mb-1"
            style={{
              fontSize: '14px',
              fontWeight: 600,
              color: emailTheme.heading,
            }}
          >
            <BladeTrans translationKey={step.titleKey}>
              {step.titleFallback}
            </BladeTrans>
          </Text>
          <Text
            className="m-0"
            style={{ fontSize: '14px', color: emailTheme.body }}
          >
            <BladeTrans translationKey={step.descKey}>
              {step.descFallback}
            </BladeTrans>
          </Text>
        </Section>
      ))}
    </Section>
    <Section style={{ paddingTop: '24px', paddingBottom: '24px', textAlign: 'center' }}>
      <ButtonBase href={completeUrl} >
        <BladeTrans translationKey="complete_account.cta">
          Completar mi cuenta
        </BladeTrans>
      </ButtonBase>
    </Section>
    <Section style={{ paddingBottom: '24px' }}>
      <Text
        className="m-0"
        style={{ fontSize: '14px', color: emailTheme.muted }}
      >
        <BladeTrans translationKey="complete_account.help">
          ¿Necesitas ayuda? Nuestro equipo de soporte está disponible 24/7.
        </BladeTrans>
      </Text>
    </Section>
  </EmailLayout>
)

CompleteAccountEmail.PreviewProps = {
  name: 'Usuario',
  completeUrl: 'https://app.example.com/complete-account',
} as CompleteAccountEmailProps

export default CompleteAccountEmail
