import { Hr, Section, Text } from '@react-email/components'
import React from 'react'
import ButtonBase from './_components/ButtonBase'
import EmailLayout from './_components/EmailLayout'
import { Blade, BladeTrans } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

interface PaymentRegisteredEmailProps {
  residentName?: string
  unitLabel?: string
  amount?: string
  method?: string
  paidAt?: string
  url?: string
}

/**
 * Notificación a la mesa directiva: un residente registró un pago que requiere revisión.
 * Nombre del archivo = event key con guiones: payment_registered → payment-registered.
 *
 * Variables Blade inyectadas por el backend (FLAT snake_case):
 *   $resident_name   → nombre del residente que registró el pago
 *   $unit_label      → identificador de la unidad (ej. "Casa 12 · Torre A")
 *   $amount          → monto del pago formateado (ej. "$2,400.00 MXN")
 *   $method          → método de pago legible (ej. "Transferencia bancaria")
 *   $paid_at         → fecha y hora en que se registró el pago
 *   $url             → enlace para revisar el pago en el panel
 */
const detailRows = [
  {
    key: 'resident',
    label: 'Residente',
    variable: 'resident_name',
    valueFallback: 'María González',
  },
  {
    key: 'unit',
    label: 'Unidad',
    variable: 'unit_label',
    valueFallback: 'Casa 12 · Torre A',
  },
  {
    key: 'amount',
    label: 'Monto',
    variable: 'amount',
    valueFallback: '$2,400.00 MXN',
  },
  {
    key: 'method',
    label: 'Método de pago',
    variable: 'method',
    valueFallback: 'Transferencia bancaria',
  },
  {
    key: 'paid_at',
    label: 'Fecha de registro',
    variable: 'paid_at',
    valueFallback: '27 jun 2026, 10:15',
  },
]

export const PaymentRegisteredEmail = ({
  amount = '$2,400.00 MXN',
}: PaymentRegisteredEmailProps) => (
  <EmailLayout previewText='Un residente registró un pago que requiere tu revisión'>
    {/* Review badge */}
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
                        backgroundColor: emailTheme.primaryLight,
                        border: `2px solid ${emailTheme.primaryBorder}`,
                        color: emailTheme.primaryDark,
                        fontSize: '24px',
                        lineHeight: '56px',
                        textAlign: 'center',
                      }}
                    >
                      &#128269;
                    </td>
                  </tr>
                </tbody>
              </table>
            </td>
          </tr>
        </tbody>
      </table>
    </Section>

    {/* Title + intro */}
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
        <BladeTrans translationKey='payment_registered.title'>
          Pago pendiente de revisión
        </BladeTrans>
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
        <BladeTrans translationKey='payment_registered.intro'>
          Un residente registró un pago que requiere su validación. Por favor, revise el comprobante
          adjunto en el portal y confirme o rechace el pago.
        </BladeTrans>
      </Text>
    </Section>

    {/* Amount hero */}
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
        <BladeTrans translationKey='payment_registered.amount_label'>Monto del pago</BladeTrans>
      </Text>
      <Text
        className='m-0'
        style={{
          fontSize: '34px',
          fontWeight: 700,
          color: emailTheme.heading,
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
                backgroundColor: '#fef9c3',
                borderRadius: '9999px',
                padding: '4px 12px',
                fontSize: '12px',
                fontWeight: 600,
                color: '#92400e',
              }}
            >
              &#9888;{' '}
              <BladeTrans translationKey='payment_registered.status'>Por revisar</BladeTrans>
            </td>
          </tr>
        </tbody>
      </table>
    </Section>

    {/* Payment details */}
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
        <BladeTrans translationKey='payment_registered.cta'>Revisar pago</BladeTrans>
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
        <BladeTrans translationKey='payment_registered.footer_note'>
          Este aviso se envía automáticamente cuando un residente registra un pago que requiere
          validación manual por parte de la mesa directiva.
        </BladeTrans>
      </Text>
    </Section>
  </EmailLayout>
)

PaymentRegisteredEmail.PreviewProps = {
  residentName: 'María González',
  unitLabel: 'Casa 12 · Torre A',
  amount: '$2,400.00 MXN',
  method: 'Transferencia bancaria',
  paidAt: '27 jun 2026, 10:15',
  url: 'https://app.example.com/panel/pagos',
} as PaymentRegisteredEmailProps

export default PaymentRegisteredEmail
