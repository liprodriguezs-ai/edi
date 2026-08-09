import { Section, Text } from '@react-email/components'
import React from 'react'
import EmailLayout from './_components/EmailLayout'
import { Blade } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

interface AmenityReservationPaymentRejectedEmailProps {
  amenityName?: string
  reservationDate?: string
  amount?: string
  reason?: string
}

/**
 * Pago de reservación de amenidad rechazado (al residente).
 *
 * Variables Blade inyectadas por el backend (FLAT snake_case). El monto llega
 * YA FORMATEADO como string (TenantMoney::format) — se interpola verbatim:
 *   $amenity_name     → nombre de la amenidad reservada
 *   $reservation_date → fecha de la reservación (dd/mm/yyyy)
 *   $amount           → monto del pago rechazado (ej. "$1,500.00")
 *   $reason           → motivo del rechazo ingresado por el tesorero
 */
export const AmenityReservationPaymentRejectedEmail = ({
  amenityName = 'Alberca',
  reservationDate = '25/07/2026',
  amount = '$1,500.00',
  reason = 'Comprobante ilegible.',
}: AmenityReservationPaymentRejectedEmailProps) => (
  <EmailLayout previewText='Tu pago de reservación no pudo validarse'>
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
        Pago no validado
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
        Tu pago para la reservación de <Blade variable='amenity_name' fallback={amenityName} /> (
        <Blade variable='reservation_date' fallback={reservationDate} />) no pudo ser validado por
        el tesorero. Puedes corregir la información y registrarlo nuevamente.
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
        Monto no procesado
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
              &#10007; No validado
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
        Motivo del rechazo
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

    {/* Footer note */}
    <Section style={{ paddingTop: '24px', paddingBottom: '24px' }}>
      <Text
        className='m-0'
        style={{ fontSize: '13px', color: emailTheme.muted, lineHeight: '20px' }}
      >
        Si tienes dudas sobre el motivo del rechazo, comunícate directamente con la administración
        de tu condominio para más información.
      </Text>
    </Section>
  </EmailLayout>
)

AmenityReservationPaymentRejectedEmail.PreviewProps = {
  amenityName: 'Alberca',
  reservationDate: '25/07/2026',
  amount: '$1,500.00',
  reason: 'Comprobante ilegible.',
} as AmenityReservationPaymentRejectedEmailProps

export default AmenityReservationPaymentRejectedEmail
