import { Hr, Section, Text } from '@react-email/components'
import React from 'react'
import ButtonBase from './_components/ButtonBase'
import EmailLayout from './_components/EmailLayout'
import { Blade } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

interface AnnouncementPublishedEmailProps {
  title: string
  bodyText: string
  priorityLabel: string
  audienceLabel: string
  announcementUrl: string
}

/**
 * Nuevo aviso publicado por el condominio (a los residentes destinatarios).
 *
 * Variables Blade inyectadas por el backend (garantizadas NO nulas):
 *   $title            → título del aviso (encabezado)
 *   $bodyText         → extracto en texto plano del cuerpo (hasta 600 caracteres)
 *   $priorityLabel    → "Normal" / "Importante" / "Urgente"
 *   $audienceLabel    → alcance legible, ej. "Por torre: Torre A", "Todo el condominio"
 *   $announcementUrl  → URL absoluta a la pantalla de avisos (usada en el botón CTA)
 */
export const AnnouncementPublishedEmail = ({
  title = 'Mantenimiento programado de la alberca',
  bodyText = 'Les informamos que la alberca permanecerá cerrada el próximo lunes por labores de mantenimiento. El servicio se restablecerá al día siguiente. Agradecemos su comprensión.',
  priorityLabel = 'Importante',
  audienceLabel = 'Todo el condominio',
  announcementUrl: _announcementUrl = 'https://app.example.com/announcements',
}: AnnouncementPublishedEmailProps) => (
  <EmailLayout previewText='Nuevo aviso de tu condominio'>
    {/* Priority badge */}
    <Section style={{ paddingTop: '32px', paddingBottom: '4px', textAlign: 'center' }}>
      <table cellPadding={0} cellSpacing={0} role='presentation' style={{ margin: '0 auto' }}>
        <tbody>
          <tr>
            <td
              style={{
                backgroundColor: emailTheme.primaryLight,
                borderRadius: '9999px',
                padding: '4px 14px',
                fontSize: '11px',
                fontWeight: 600,
                letterSpacing: '0.08em',
                textTransform: 'uppercase',
                color: emailTheme.primaryDark,
              }}
            >
              <Blade variable='priorityLabel' fallback={priorityLabel} />
            </td>
          </tr>
        </tbody>
      </table>
    </Section>

    {/* Title */}
    <Section style={{ paddingTop: '12px', paddingBottom: '4px' }}>
      <Text
        className='m-0 text-center'
        style={{
          fontSize: '22px',
          fontWeight: 700,
          color: emailTheme.heading,
          letterSpacing: '-0.02em',
          lineHeight: '30px',
        }}
      >
        <Blade variable='title' fallback={title} />
      </Text>
    </Section>

    {/* Audience — the recipient must clearly see the scope */}
    <Section
      style={{
        marginTop: '16px',
        padding: '14px 20px',
        backgroundColor: emailTheme.bgSurface,
        borderRadius: '12px',
        border: `1px solid ${emailTheme.border}`,
        textAlign: 'center',
      }}
    >
      <Text
        className='m-0'
        style={{
          fontSize: '11px',
          fontWeight: 600,
          letterSpacing: '0.08em',
          textTransform: 'uppercase',
          color: emailTheme.muted,
        }}
      >
        Dirigido a
      </Text>
      <Text
        className='m-0'
        style={{
          fontSize: '15px',
          fontWeight: 600,
          color: emailTheme.heading,
          lineHeight: '22px',
          marginTop: '4px',
        }}
      >
        <Blade variable='audienceLabel' fallback={audienceLabel} />
      </Text>
    </Section>

    {/* Body excerpt */}
    <Section style={{ paddingTop: '20px', paddingBottom: '4px' }}>
      <Text
        className='m-0'
        style={{
          fontSize: '15px',
          color: emailTheme.body,
          lineHeight: '24px',
          whiteSpace: 'pre-line',
        }}
      >
        <Blade variable='bodyText' fallback={bodyText} />
      </Text>
    </Section>

    {/* CTA */}
    <Section style={{ paddingTop: '24px', paddingBottom: '20px', textAlign: 'center' }}>
      <ButtonBase href={'{{ $announcementUrl }}'}>Ver el aviso completo</ButtonBase>
    </Section>

    <Hr
      style={{
        border: 'none',
        borderTopWidth: '1px',
        borderTopStyle: 'solid',
        borderTopColor: emailTheme.borderSoft,
        margin: '4px 0 20px',
      }}
    />

    {/* Footer note */}
    <Section style={{ paddingBottom: '24px' }}>
      <Text
        className='m-0'
        style={{ fontSize: '13px', color: emailTheme.muted, lineHeight: '20px' }}
      >
        Recibes este correo porque formas parte de tu condominio en Kivo7. Consulta todos tus avisos
        desde el portal cuando lo necesites.
      </Text>
    </Section>
  </EmailLayout>
)

AnnouncementPublishedEmail.PreviewProps = {
  title: 'Mantenimiento programado de la alberca',
  bodyText:
    'Les informamos que la alberca permanecerá cerrada el próximo lunes por labores de mantenimiento. El servicio se restablecerá al día siguiente. Agradecemos su comprensión.',
  priorityLabel: 'Importante',
  audienceLabel: 'Por torre: Torre A',
  announcementUrl: 'https://app.example.com/announcements',
} as AnnouncementPublishedEmailProps

export default AnnouncementPublishedEmail
