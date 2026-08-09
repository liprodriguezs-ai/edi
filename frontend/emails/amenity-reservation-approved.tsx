import { Section, Text } from '@react-email/components'
import React from 'react'
import EmailLayout from './_components/EmailLayout'
import { Blade } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

interface AmenityReservationApprovedEmailProps {
  amenityName?: string
  reservationDate?: string
}

/**
 * Reservación de amenidad aprobada (al residente).
 *
 * Variables Blade inyectadas por el backend (FLAT snake_case):
 *   $amenity_name     → nombre de la amenidad reservada
 *   $reservation_date → fecha de la reservación (dd/mm/yyyy)
 */
export const AmenityReservationApprovedEmail = ({
  amenityName = 'Alberca',
  reservationDate = '25/07/2026',
}: AmenityReservationApprovedEmailProps) => (
  <EmailLayout previewText='Tu reservación de amenidad fue aprobada'>
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
        Reservación aprobada
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
        Tu reservación de <Blade variable='amenity_name' fallback={amenityName} /> para el{' '}
        <Blade variable='reservation_date' fallback={reservationDate} /> fue aprobada.
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
              }}
            >
              <Blade variable='reservation_date' fallback={reservationDate} />
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
        Consulta todos los detalles de tu reservación desde el portal cuando lo necesites.
      </Text>
    </Section>
  </EmailLayout>
)

AmenityReservationApprovedEmail.PreviewProps = {
  amenityName: 'Alberca',
  reservationDate: '25/07/2026',
} as AmenityReservationApprovedEmailProps

export default AmenityReservationApprovedEmail
