import { Section, Text } from '@react-email/components'
import React from 'react'
import EmailLayout from './_components/EmailLayout'
import { Blade } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

interface AmenityReservationPaymentConfirmedEmailProps {
  amenityName?: string
  reservationDate?: string
  amountPaid?: string
  amountOutstanding?: string
}

/**
 * Pago de reservación de amenidad confirmado / liquidado (al residente).
 *
 * Variables Blade inyectadas por el backend (FLAT snake_case). Los montos
 * llegan YA FORMATEADOS como string (TenantMoney::format, ej. "$1,500.00") —
 * se interpolan verbatim, sin aritmética en la plantilla:
 *   $amenity_name        → nombre de la amenidad reservada
 *   $reservation_date    → fecha de la reservación (dd/mm/yyyy)
 *   $amount_paid         → monto liquidado (ej. "$1,500.00")
 *   $amount_outstanding  → saldo pendiente restante (ej. "$0.00" si se liquidó completo)
 */
export const AmenityReservationPaymentConfirmedEmail = ({
  amenityName = 'Alberca',
  reservationDate = '25/07/2026',
  amountPaid = '$1,500.00',
  amountOutstanding = '$0.00',
}: AmenityReservationPaymentConfirmedEmailProps) => (
  <EmailLayout previewText='Tu pago de reservación fue confirmado'>
    {/* Success badge */}
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

    {/* Title + body */}
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
        Reservación liquidada
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
        Tu pago para <Blade variable='amenity_name' fallback={amenityName} /> (
        <Blade variable='reservation_date' fallback={reservationDate} />) fue confirmado. Tu
        reservación está completamente liquidada.
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
        Monto liquidado
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
        <Blade variable='amount_paid' fallback={amountPaid} />
      </Text>
    </Section>

    {/* Detail rows */}
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
                borderBottom: `1px solid ${emailTheme.borderSoft}`,
                whiteSpace: 'nowrap',
              }}
            >
              Amenidad
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
                borderBottom: `1px solid ${emailTheme.borderSoft}`,
              }}
            >
              <Blade variable='amenity_name' fallback={amenityName} />
            </td>
          </tr>
          <tr>
            <td
              align='left'
              valign='top'
              style={{
                fontSize: '14px',
                color: emailTheme.muted,
                paddingTop: '14px',
                paddingBottom: '14px',
                borderBottom: `1px solid ${emailTheme.borderSoft}`,
                whiteSpace: 'nowrap',
              }}
            >
              Fecha
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
                borderBottom: `1px solid ${emailTheme.borderSoft}`,
              }}
            >
              <Blade variable='reservation_date' fallback={reservationDate} />
            </td>
          </tr>
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
              Saldo pendiente
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
              <Blade variable='amount_outstanding' fallback={amountOutstanding} />
            </td>
          </tr>
        </tbody>
      </table>
    </Section>

    {/* Footer note */}
    <Section style={{ paddingTop: '24px', paddingBottom: '24px' }}>
      <Text
        className='m-0'
        style={{ fontSize: '13px', color: emailTheme.muted, lineHeight: '20px' }}
      >
        Conserva este correo como comprobante de pago. Si no reconoces esta operación, contacta a la
        administración de tu condominio de inmediato.
      </Text>
    </Section>
  </EmailLayout>
)

AmenityReservationPaymentConfirmedEmail.PreviewProps = {
  amenityName: 'Alberca',
  reservationDate: '25/07/2026',
  amountPaid: '$1,500.00',
  amountOutstanding: '$0.00',
} as AmenityReservationPaymentConfirmedEmailProps

export default AmenityReservationPaymentConfirmedEmail
