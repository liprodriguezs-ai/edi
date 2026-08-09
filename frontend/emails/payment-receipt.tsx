import { Hr, Section, Text } from '@react-email/components'
import React from 'react'
import ButtonBase from './_components/ButtonBase'
import EmailLayout from './_components/EmailLayout'
import { Blade, BladeTrans } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

interface PaymentReceiptEmailProps {
  residentName?: string
  receiptNumber?: string
  amount?: string
  paidAt?: string
  method?: string
  unit?: string
  concept?: string
  portalUrl?: string
}

/**
 * Recibo de pago confirmado (módulo Collections).
 *
 * Variables Blade esperadas del backend al exportar (BLADE_OUTPUT=true):
 *   $payment->receipt_number         → folio digital (R-YYYY-NNNNNN)
 *   $payment->formatted_amount       → monto ya formateado por el Money cast ("$1,500.00 MXN")
 *   $payment->paid_at_formatted      → fecha de pago formateada
 *   $payment->method_label           → método legible (Transferencia, Efectivo, ...)
 *   $unit->full_identifier           → "Casa 12 · Torre A"
 *   $chargeConcept->name             → concepto cobrado
 *   $url                             → enlace al recibo en el portal
 */

const detailRows = [
  {
    key: 'folio',
    labelKey: 'payment_receipt.folio',
    labelFallback: 'Folio digital',
    variable: 'payment->receipt_number',
    valueFallback: 'R-2026-000123',
  },
  {
    key: 'date',
    labelKey: 'payment_receipt.date',
    labelFallback: 'Fecha de pago',
    variable: 'payment->paid_at_formatted',
    valueFallback: '26 jun 2026, 14:32',
  },
  {
    key: 'method',
    labelKey: 'payment_receipt.method',
    labelFallback: 'Método de pago',
    variable: 'payment->method_label',
    valueFallback: 'Transferencia',
  },
  {
    key: 'unit',
    labelKey: 'payment_receipt.unit',
    labelFallback: 'Unidad',
    variable: 'unit->full_identifier',
    valueFallback: 'Casa 12 · Torre A',
  },
  {
    key: 'concept',
    labelKey: 'payment_receipt.concept',
    labelFallback: 'Concepto',
    variable: 'chargeConcept->name',
    valueFallback: 'Mantenimiento — Junio 2026',
  },
]

export const PaymentReceiptEmail = ({
  residentName = 'Vecino',
  receiptNumber = 'R-2026-000123',
  amount = '$1,500.00 MXN',
  portalUrl = 'https://app.example.com/portal/recibos',
}: PaymentReceiptEmailProps) => (
  <EmailLayout previewText={`Pago confirmado — Recibo ${receiptNumber}`}>
    {/* Check badge */}
    <Section style={{ paddingTop: '32px', paddingBottom: '8px' }}>
      <table
        width="100%"
        cellPadding={0}
        cellSpacing={0}
        role="presentation"
      >
        <tbody>
          <tr>
            <td align="center">
              <table cellPadding={0} cellSpacing={0} role="presentation">
                <tbody>
                  <tr>
                    <td
                      align="center"
                      valign="middle"
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
        className="m-0 text-center"
        style={{
          fontSize: '22px',
          fontWeight: 700,
          color: emailTheme.heading,
          letterSpacing: '-0.02em',
          lineHeight: '30px',
        }}
      >
        <BladeTrans translationKey="payment_receipt.title">
          Pago confirmado
        </BladeTrans>
      </Text>
      <Text
        className="text-center m-0"
        style={{
          fontSize: '15px',
          color: emailTheme.body,
          lineHeight: '22px',
          marginTop: '12px',
          paddingLeft: '8px',
          paddingRight: '8px',
        }}
      >
        <BladeTrans translationKey="payment_receipt.greeting">Hola</BladeTrans>{' '}
        <Blade variable="user->name" fallback={residentName} />,{' '}
        <BladeTrans translationKey="payment_receipt.intro">
          recibimos tu pago correctamente. Este correo es tu comprobante oficial.
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
        className="m-0"
        style={{
          fontSize: '11px',
          fontWeight: 600,
          letterSpacing: '0.08em',
          textTransform: 'uppercase',
          color: emailTheme.muted,
        }}
      >
        <BladeTrans translationKey="payment_receipt.amount_label">
          Monto pagado
        </BladeTrans>
      </Text>
      <Text
        className="m-0"
        style={{
          fontSize: '34px',
          fontWeight: 700,
          color: emailTheme.heading,
          letterSpacing: '-0.02em',
          lineHeight: '40px',
          marginTop: '6px',
        }}
      >
        <Blade variable="payment->formatted_amount" fallback={amount} />
      </Text>
      <table
        cellPadding={0}
        cellSpacing={0}
        role="presentation"
        style={{ margin: '12px auto 0' }}
      >
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
              <BladeTrans translationKey="payment_receipt.status">
                Confirmado
              </BladeTrans>
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
      <table width="100%" cellPadding={0} cellSpacing={0} role="presentation">
        <tbody>
          {detailRows.map((row, index) => (
            <tr key={row.key}>
              <td
                align="left"
                valign="top"
                style={{
                  fontSize: '14px',
                  color: emailTheme.muted,
                  paddingTop: '14px',
                  paddingBottom: '14px',
                  borderBottom:
                    index < detailRows.length - 1
                      ? `1px solid ${emailTheme.borderSoft}`
                      : 'none',
                  whiteSpace: 'nowrap',
                }}
              >
                <BladeTrans translationKey={row.labelKey}>
                  {row.labelFallback}
                </BladeTrans>
              </td>
              <td
                align="right"
                valign="top"
                style={{
                  fontSize: '14px',
                  fontWeight: 600,
                  color: emailTheme.heading,
                  paddingTop: '14px',
                  paddingBottom: '14px',
                  paddingLeft: '16px',
                  borderBottom:
                    index < detailRows.length - 1
                      ? `1px solid ${emailTheme.borderSoft}`
                      : 'none',
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
    <Section
      style={{ paddingTop: '28px', paddingBottom: '20px', textAlign: 'center' }}
    >
      <ButtonBase href={portalUrl}>
        <BladeTrans translationKey="payment_receipt.cta">
          Ver recibo en el portal
        </BladeTrans>
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
        className="m-0"
        style={{ fontSize: '13px', color: emailTheme.muted, lineHeight: '20px' }}
      >
        <BladeTrans translationKey="payment_receipt.security">
          Conserva este correo como comprobante de pago. Si no reconoces esta
          operación, contacta a la administración de tu condominio de inmediato.
        </BladeTrans>
      </Text>
    </Section>
  </EmailLayout>
)

PaymentReceiptEmail.PreviewProps = {
  residentName: 'María González',
  receiptNumber: 'R-2026-000123',
  amount: '$1,500.00 MXN',
  paidAt: '26 jun 2026, 14:32',
  method: 'Transferencia',
  unit: 'Casa 12 · Torre A',
  concept: 'Mantenimiento — Junio 2026',
  portalUrl: 'https://app.example.com/portal/recibos',
} as PaymentReceiptEmailProps

export default PaymentReceiptEmail
