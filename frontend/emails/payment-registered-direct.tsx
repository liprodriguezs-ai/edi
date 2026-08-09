import { Hr, Section, Text } from '@react-email/components'
import React from 'react'
import ButtonBase from './_components/ButtonBase'
import EmailLayout from './_components/EmailLayout'
import { Blade, BladeTrans } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

interface PaymentRegisteredDirectEmailProps {
  residentName?: string
  receiptNumber?: string
  amount?: string
  paidAt?: string
  unitLabel?: string
  url?: string
}

/**
 * Comprobante de pago registrado directamente por el administrador (al residente).
 *
 * Variables Blade inyectadas por el backend (FLAT snake_case):
 *   $resident_name   → nombre del residente
 *   $receipt_number  → folio digital del recibo (ej. "R-2026-000124")
 *   $amount          → monto formateado (ej. "$1,500.00 MXN")
 *   $paid_at         → fecha en que se registró el pago
 *   $unit_label      → identificador de la unidad (ej. "Casa 12 · Torre A")
 *   $url             → enlace para ver el recibo en el portal
 */
const detailRows = [
  {
    key: 'receipt',
    label: 'Folio digital',
    variable: 'receipt_number',
    valueFallback: 'R-2026-000124',
  },
  {
    key: 'amount',
    label: 'Monto registrado',
    variable: 'amount',
    valueFallback: '$1,500.00 MXN',
  },
  {
    key: 'paid_at',
    label: 'Fecha de registro',
    variable: 'paid_at',
    valueFallback: '27 jun 2026, 11:30',
  },
  {
    key: 'unit',
    label: 'Unidad',
    variable: 'unit_label',
    valueFallback: 'Casa 12 · Torre A',
  },
]

export const PaymentRegisteredDirectEmail = ({
  residentName = 'Vecino',
  amount = '$1,500.00 MXN',
}: PaymentRegisteredDirectEmailProps) => (
  <EmailLayout previewText='Se registró un pago a tu nombre'>
    {/* Check badge */}
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
                        backgroundColor: emailTheme.primary,
                        color: '#ffffff',
                        fontSize: '30px',
                        lineHeight: '56px',
                        textAlign: 'center',
                      }}
                    >
                      &#10003;
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
        <BladeTrans translationKey='payment_registered_direct.title'>
          Se registró tu pago
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
        <BladeTrans translationKey='payment_registered_direct.greeting'>Hola</BladeTrans>{' '}
        <Blade variable='resident_name' fallback={residentName} />,{' '}
        <BladeTrans translationKey='payment_registered_direct.intro'>
          tu administrador registró un pago a tu nombre. Este correo es tu comprobante oficial.
        </BladeTrans>
      </Text>
    </Section>

    {/* Amount hero */}
    <Section
      style={{
        marginTop: '12px',
        marginBottom: '8px',
        padding: '24px',
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
        <BladeTrans translationKey='payment_registered_direct.amount_label'>
          Monto registrado
        </BladeTrans>
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
      <table cellPadding={0} cellSpacing={0} role='presentation' style={{ margin: '12px auto 0' }}>
        <tbody>
          <tr>
            <td
              style={{
                backgroundColor: emailTheme.primaryLight,
                borderRadius: '9999px',
                padding: '4px 12px',
                fontSize: '12px',
                fontWeight: 600,
                color: emailTheme.primaryDark,
              }}
            >
              &#10003;{' '}
              <BladeTrans translationKey='payment_registered_direct.status'>Registrado</BladeTrans>
            </td>
          </tr>
        </tbody>
      </table>
    </Section>

    {/* Receipt details */}
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
        <BladeTrans translationKey='payment_registered_direct.cta'>Ver recibo</BladeTrans>
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

    {/* Security note */}
    <Section style={{ paddingBottom: '24px' }}>
      <Text
        className='m-0'
        style={{ fontSize: '13px', color: emailTheme.muted, lineHeight: '20px' }}
      >
        <BladeTrans translationKey='payment_registered_direct.security'>
          Conserva este correo como comprobante del pago registrado. Si no reconoces esta operación,
          contacta a la administración de tu condominio de inmediato.
        </BladeTrans>
      </Text>
    </Section>
  </EmailLayout>
)

PaymentRegisteredDirectEmail.PreviewProps = {
  residentName: 'Carlos Mendoza',
  receiptNumber: 'R-2026-000124',
  amount: '$1,500.00 MXN',
  paidAt: '27 jun 2026, 11:30',
  unitLabel: 'Casa 12 · Torre A',
  url: 'https://app.example.com/portal/recibos',
} as PaymentRegisteredDirectEmailProps

export default PaymentRegisteredDirectEmail
