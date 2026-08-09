import { Section, Text } from '@react-email/components'
import React from 'react'
import EmailLayout from './_components/EmailLayout'
import { Blade } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

interface AmenityReservationCreatedEmailProps {
  residentName?: string
  unitLabel?: string
  amenityName?: string
  reservationDate?: string
}

/**
 * Nueva reservación de amenidad (a la mesa directiva + administrador).
 *
 * Variables Blade inyectadas por el backend (FLAT snake_case, garantizadas no
 * nulas — ver ReservationService::notifyReservationCreated()):
 *   $resident_name    → nombre del residente que creó la reservación
 *   $unit_label       → identificador de la unidad (ej. "Casa 12 · Torre A")
 *   $amenity_name     → nombre de la amenidad reservada
 *   $reservation_date → fecha de la reservación (dd/mm/yyyy)
 */
const detailRows = [
  { key: 'unit', label: 'Unidad', variable: 'unit_label', valueFallback: 'Casa 12 · Torre A' },
  {
    key: 'resident',
    label: 'Residente',
    variable: 'resident_name',
    valueFallback: 'Carlos Mendoza',
  },
  { key: 'amenity', label: 'Amenidad', variable: 'amenity_name', valueFallback: 'Alberca' },
  { key: 'date', label: 'Fecha', variable: 'reservation_date', valueFallback: '25/07/2026' },
]

export const AmenityReservationCreatedEmail = () => (
  <EmailLayout previewText='Nueva reservación de amenidad en tu condominio'>
    {/* Title */}
    <Section style={{ paddingTop: '32px', paddingBottom: '8px' }}>
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
        Nueva reservación
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
        Un residente creó una nueva reservación de amenidad. Estos son los detalles:
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

    {/* Footer note */}
    <Section style={{ paddingTop: '24px', paddingBottom: '24px' }}>
      <Text
        className='m-0'
        style={{ fontSize: '13px', color: emailTheme.muted, lineHeight: '20px' }}
      >
        Recibes este correo porque tienes un rol de mesa directiva o administración en tu condominio
        de Kivo7.
      </Text>
    </Section>
  </EmailLayout>
)

AmenityReservationCreatedEmail.PreviewProps = {
  residentName: 'Carlos Mendoza',
  unitLabel: 'Casa 12 · Torre A',
  amenityName: 'Alberca',
  reservationDate: '25/07/2026',
} as AmenityReservationCreatedEmailProps

export default AmenityReservationCreatedEmail
