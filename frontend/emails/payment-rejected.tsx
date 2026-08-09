import { Hr, Section, Text } from '@react-email/components'
import React from 'react'
import ButtonBase from './_components/ButtonBase'
import EmailLayout from './_components/EmailLayout'
import { Blade, BladeTrans } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

interface PaymentRejectedEmailProps {
  residentName?: string
  amount?: string
  reason?: string
  unitLabel?: string
  url?: string
}

/**
 * Notificación de pago rechazado (al residente).
 *
 * Variables Blade inyectadas por el backend (FLAT snake_case):
 *   $resident_name   → nombre del residente
 *   $amount          → monto del pago rechazado (ej. "$2,400.00 MXN")
 *   $reason          → motivo del rechazo ingresado por la mesa directiva
 *   $unit_label      → identificador de la unidad (ej. "Casa 12 · Torre A")
 *   $url             → enlace para registrar el pago nuevamente
 */
export const PaymentRejectedEmail = ({
  residentName = 'Vecino',
  amount = '$2,400.00 MXN',
  reason = 'El comprobante de transferencia no es legible. Por favor, adjunta una imagen clara.',
  unitLabel = 'Casa 12 · Torre A',
}: PaymentRejectedEmailProps) => (
  <EmailLayout previewText='Tu pago no pudo validarse — revisa el motivo'>
    {/* Rejection badge */}
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
                        backgroundColor: '#fee2e2',
                        border: '2px solid #fca5a5',
                        color: '#b91c1c',
                        fontSize: '28px',
                        lineHeight: '56px',
                        textAlign: 'center',
                      }}
                    >
                      &#10007;
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
        <BladeTrans translationKey='payment_rejected.title'>Pago no validado</BladeTrans>
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
        <BladeTrans translationKey='payment_rejected.greeting'>Hola</BladeTrans>{' '}
        <Blade variable='resident_name' fallback={residentName} />,{' '}
        <BladeTrans translationKey='payment_rejected.intro'>
          lamentamos informarte que tu pago no pudo ser validado por la mesa directiva. Puedes
          corregir la información y registrarlo nuevamente.
        </BladeTrans>
      </Text>
    </Section>

    {/* Amount + status */}
    <Section
      style={{
        marginTop: '12px',
        marginBottom: '8px',
        padding: '20px 24px',
        backgroundColor: '#fff7f7',
        borderRadius: '12px',
        border: '1px solid #fca5a5',
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
        <BladeTrans translationKey='payment_rejected.amount_label'>Monto no procesado</BladeTrans>
      </Text>
      <Text
        className='m-0'
        style={{
          fontSize: '34px',
          fontWeight: 700,
          color: '#b91c1c',
          letterSpacing: '-0.02em',
          lineHeight: '40px',
          marginTop: '6px',
        }}
      >
        <Blade variable='amount' fallback={amount} />
      </Text>
      <table cellPadding={0} cellSpacing={0} role='presentation' style={{ margin: '10px auto 0' }}>
        <tbody>
          <tr>
            <td
              style={{
                backgroundColor: '#fee2e2',
                borderRadius: '9999px',
                padding: '4px 12px',
                fontSize: '12px',
                fontWeight: 600,
                color: '#b91c1c',
              }}
            >
              &#10007; <BladeTrans translationKey='payment_rejected.status'>No validado</BladeTrans>
            </td>
          </tr>
        </tbody>
      </table>
    </Section>

    {/* Reason block — highlighted */}
    <Section
      style={{
        marginTop: '16px',
        padding: '20px',
        backgroundColor: '#fffbeb',
        borderRadius: '12px',
        border: '1px solid #fde68a',
        borderLeftWidth: '4px',
        borderLeftStyle: 'solid',
        borderLeftColor: '#f59e0b',
      }}
    >
      <Text
        className='m-0'
        style={{
          fontSize: '11px',
          fontWeight: 700,
          letterSpacing: '0.08em',
          textTransform: 'uppercase',
          color: '#92400e',
          marginBottom: '8px',
        }}
      >
        <BladeTrans translationKey='payment_rejected.reason_label'>Motivo del rechazo</BladeTrans>
      </Text>
      <Text
        className='m-0'
        style={{
          fontSize: '15px',
          color: '#78350f',
          lineHeight: '22px',
          marginTop: '8px',
          fontStyle: 'italic',
        }}
      >
        &#8220;
        <Blade variable='reason' fallback={reason} />
        &#8221;
      </Text>
    </Section>

    {/* Unit info */}
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
          <tr>
            <td
              align='left'
              valign='top'
              style={{
                fontSize: '14px',
                color: emailTheme.muted,
                paddingTop: '14px',
                paddingBottom: '14px',
                whiteSpace: 'nowrap',
              }}
            >
              <BladeTrans translationKey='payment_rejected.unit_label'>Unidad</BladeTrans>
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
              }}
            >
              <Blade variable='unit_label' fallback={unitLabel} />
            </td>
          </tr>
        </tbody>
      </table>
    </Section>

    {/* CTA */}
    <Section style={{ paddingTop: '28px', paddingBottom: '20px', textAlign: 'center' }}>
      <ButtonBase href={'{{ $url }}'}>
        <BladeTrans translationKey='payment_rejected.cta'>Registrar de nuevo</BladeTrans>
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

    {/* Help note */}
    <Section style={{ paddingBottom: '24px' }}>
      <Text
        className='m-0'
        style={{ fontSize: '13px', color: emailTheme.muted, lineHeight: '20px' }}
      >
        <BladeTrans translationKey='payment_rejected.footer_note'>
          Si tienes dudas sobre el motivo del rechazo, comunícate directamente con la administración
          de tu condominio para más información.
        </BladeTrans>
      </Text>
    </Section>
  </EmailLayout>
)

PaymentRejectedEmail.PreviewProps = {
  residentName: 'Carlos Mendoza',
  amount: '$2,400.00 MXN',
  reason: 'El comprobante de transferencia no es legible. Por favor, adjunta una imagen clara.',
  unitLabel: 'Casa 12 · Torre A',
  url: 'https://app.example.com/portal/pagar',
} as PaymentRejectedEmailProps

export default PaymentRejectedEmail
