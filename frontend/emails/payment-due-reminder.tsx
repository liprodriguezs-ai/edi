import { Hr, Section, Text } from '@react-email/components'
import React from 'react'
import ButtonBase from './_components/ButtonBase'
import EmailLayout from './_components/EmailLayout'
import { Blade, BladeTrans } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

interface PaymentDueReminderEmailProps {
  residentName?: string
  conceptName?: string
  amount?: string
  dueDate?: string
  daysLeft?: string | number
  url?: string
}

/**
 * Recordatorio de cargo próximo a vencer (al residente).
 *
 * Variables Blade inyectadas por el backend (FLAT snake_case):
 *   $resident_name   → nombre del residente
 *   $concept_name    → nombre del concepto de cobro (ej. "Mantenimiento — Julio 2026")
 *   $amount          → monto formateado (ej. "$1,500.00 MXN")
 *   $due_date        → fecha de vencimiento formateada (ej. "30 jun 2026")
 *   $days_left       → días restantes antes del vencimiento (ej. "3")
 *   $url             → enlace para pagar en el portal
 */
const detailRows = [
  {
    key: 'concept',
    label: 'Concepto',
    variable: 'concept_name',
    valueFallback: 'Mantenimiento — Julio 2026',
  },
  {
    key: 'amount',
    label: 'Monto a pagar',
    variable: 'amount',
    valueFallback: '$1,500.00 MXN',
  },
  {
    key: 'due_date',
    label: 'Fecha de vencimiento',
    variable: 'due_date',
    valueFallback: '30 jun 2026',
  },
]

export const PaymentDueReminderEmail = ({
  residentName = 'Vecino',
  daysLeft = 3,
}: PaymentDueReminderEmailProps) => (
  <EmailLayout previewText='Tienes un cargo próximo a vencer'>
    {/* Clock / reminder badge */}
    <Section style={{ paddingTop: '32px', paddingBottom: '8px' }}>
      <table width='100%' cellPadding={0} cellSpacing={0} role='presentation'>
        <tbody>
          <tr>
            <td align='center'>
              <table cellPadding={0} cellSpacing={0} role='presentation'>
                <tbody>
                  <tr>
                    <td
                      align='center'
                      valign='middle'
                      style={{
                        width: '56px',
                        height: '56px',
                        borderRadius: '50%',
                        backgroundColor: emailTheme.footerBg,
                        border: `2px solid ${emailTheme.primary}`,
                        color: emailTheme.primary,
                        fontSize: '26px',
                        lineHeight: '56px',
                        textAlign: 'center',
                      }}
                    >
                      &#8987;
                    </td>
                  </tr>
                </tbody>
              </table>
            </td>
          </tr>
        </tbody>
      </table>
    </Section>

    {/* Title + greeting */}
    <Section style={{ paddingTop: '16px', paddingBottom: '8px' }}>
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
        <BladeTrans translationKey='payment_due_reminder.title'>Recordatorio de pago</BladeTrans>
      </Text>
      <Text
        className='text-center m-0'
        style={{
          fontSize: '15px',
          color: emailTheme.body,
          lineHeight: '22px',
          marginTop: '12px',
          paddingLeft: '8px',
          paddingRight: '8px',
        }}
      >
        <BladeTrans translationKey='payment_due_reminder.greeting'>Hola</BladeTrans>{' '}
        <Blade variable='resident_name' fallback={residentName} />,{' '}
        <BladeTrans translationKey='payment_due_reminder.intro'>
          te recordamos que tienes un cargo pendiente próximo a vencer.
        </BladeTrans>
      </Text>
    </Section>

    {/* Days-left highlight */}
    <Section
      style={{
        marginTop: '12px',
        marginBottom: '8px',
        padding: '20px 24px',
        backgroundColor: emailTheme.footerBg,
        borderRadius: '12px',
        border: `1px solid ${emailTheme.primaryBorder}`,
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
        <BladeTrans translationKey='payment_due_reminder.days_left_label'>
          Días restantes para pagar
        </BladeTrans>
      </Text>
      <Text
        className='m-0'
        style={{
          fontSize: '40px',
          fontWeight: 700,
          color: emailTheme.primary,
          letterSpacing: '-0.02em',
          lineHeight: '48px',
          marginTop: '4px',
        }}
      >
        <Blade variable='days_left' fallback={String(daysLeft)} />
      </Text>
      <Text
        className='m-0'
        style={{
          fontSize: '13px',
          color: emailTheme.muted,
          marginTop: '2px',
        }}
      >
        <BladeTrans translationKey='payment_due_reminder.days_unit'>día(s)</BladeTrans>
      </Text>
    </Section>

    {/* Charge details */}
    <Section
      style={{
        marginTop: '16px',
        padding: '8px 20px',
        backgroundColor: emailTheme.bgSurface,
        borderRadius: '12px',
        border: `1px solid ${emailTheme.border}`,
      }}
    >
      <table width='100%' cellPadding={0} cellSpacing={0} role='presentation'>
        <tbody>
          {detailRows.map((row, index) => (
            <tr key={row.key}>
              <td
                align='left'
                valign='top'
                style={{
                  fontSize: '14px',
                  color: emailTheme.muted,
                  paddingTop: '14px',
                  paddingBottom: '14px',
                  borderBottom:
                    index < detailRows.length - 1 ? `1px solid ${emailTheme.borderSoft}` : 'none',
                  whiteSpace: 'nowrap',
                }}
              >
                {row.label}
              </td>
              <td
                align='right'
                valign='top'
                style={{
                  fontSize: '14px',
                  fontWeight: 600,
                  color: emailTheme.heading,
                  paddingTop: '14px',
                  paddingBottom: '14px',
                  paddingLeft: '16px',
                  borderBottom:
                    index < detailRows.length - 1 ? `1px solid ${emailTheme.borderSoft}` : 'none',
                }}
              >
                <Blade variable={row.variable} fallback={row.valueFallback} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </Section>

    {/* CTA */}
    <Section style={{ paddingTop: '28px', paddingBottom: '20px', textAlign: 'center' }}>
      <ButtonBase href={'{{ $url }}'}>
        <BladeTrans translationKey='payment_due_reminder.cta'>Pagar ahora</BladeTrans>
      </ButtonBase>
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
        <BladeTrans translationKey='payment_due_reminder.footer_note'>
          Si ya realizaste tu pago, puedes ignorar este mensaje. Si tienes dudas, contacta a la
          administración de tu condominio.
        </BladeTrans>
      </Text>
    </Section>
  </EmailLayout>
)

PaymentDueReminderEmail.PreviewProps = {
  residentName: 'Carlos Mendoza',
  conceptName: 'Mantenimiento — Julio 2026',
  amount: '$1,500.00 MXN',
  dueDate: '30 jun 2026',
  daysLeft: 3,
  url: 'https://app.example.com/portal/pagar',
} as PaymentDueReminderEmailProps

export default PaymentDueReminderEmail
