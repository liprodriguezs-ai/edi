import { Section, Text } from '@react-email/components'
import React from 'react'
import EmailLayout from './_components/EmailLayout'
import { Blade } from './_components/LaravelBlade'
import { emailTheme } from './_theme'

interface AmenityReservationSurchargeAddedEmailProps {
  amenityName?: string
  reservationDate?: string
  amount?: string
  conceptName?: string
  description?: string
}

/**
 * Cargo adicional aplicado a una reservación de amenidad (al residente).
 *
 * Variables Blade inyectadas por el backend (FLAT snake_case). El monto llega
 * YA FORMATEADO como string (TenantMoney::format) — se interpola verbatim.
 * $description puede llegar como cadena vacía (campo opcional del cargo) — se
 * interpola vía <Blade>, que renderiza vacío en ese caso, nunca un placeholder
 * crudo:
 *   $amenity_name     → nombre de la amenidad reservada
 *   $reservation_date → fecha de la reservación (dd/mm/yyyy)
 *   $amount           → monto del cargo (ej. "$250.00")
 *   $concept_name     → concepto de cobro del cargo (ej. "Daños e incidentes")
 *   $description      → nota del administrador (puede ser vacía)
 */
export const AmenityReservationSurchargeAddedEmail = ({
  amenityName = 'Alberca',
  reservationDate = '25/07/2026',
  amount = '$250.00',
  conceptName = 'Daños e incidentes',
  description = 'Vidrio roto.',
}: AmenityReservationSurchargeAddedEmailProps) => (
  <EmailLayout previewText='Se aplicó un cargo adicional a tu reservación'>
    {/* Warning badge */}
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
                        backgroundColor: '#fef3c7',
                        border: '2px solid #fcd34d',
                        color: '#92400e',
                        fontSize: '26px',
                        lineHeight: '56px',
                        textAlign: 'center',
                      }}
                    >
                      &#33;
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
        Cargo adicional aplicado
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
        Se aplicó un cargo adicional a tu reservación de{' '}
        <Blade variable='amenity_name' fallback={amenityName} /> (
        <Blade variable='reservation_date' fallback={reservationDate} />
        ).
      </Text>
    </Section>

    {/* Amount block */}
    <Section
      style={{
        marginTop: '12px',
        marginBottom: '8px',
        padding: '20px 24px',
        backgroundColor: '#fffbeb',
        borderRadius: '12px',
        border: '1px solid #fde68a',
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
        Monto del cargo
      </Text>
      <Text
        className='m-0'
        style={{
          fontSize: '34px',
          fontWeight: 700,
          color: '#92400e',
          letterSpacing: '-0.02em',
          lineHeight: '40px',
          marginTop: '6px',
        }}
      >
        <Blade variable='amount' fallback={amount} />
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
              Concepto
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
              <Blade variable='concept_name' fallback={conceptName} />
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

    {/* Note — description may be empty; Blade renders it safely either way */}
    <Section
      style={{
        marginTop: '16px',
        padding: '16px 20px',
        backgroundColor: emailTheme.bgSurface,
        borderRadius: '12px',
        border: `1px solid ${emailTheme.border}`,
      }}
    >
      <Text
        className='m-0'
        style={{
          fontSize: '11px',
          fontWeight: 700,
          letterSpacing: '0.08em',
          textTransform: 'uppercase',
          color: emailTheme.muted,
          marginBottom: '8px',
        }}
      >
        Nota de la administración
      </Text>
      <Text
        className='m-0'
        style={{
          fontSize: '15px',
          color: emailTheme.body,
          lineHeight: '22px',
          marginTop: '8px',
        }}
      >
        <Blade variable='description' fallback={description} />
      </Text>
    </Section>

    {/* Footer note */}
    <Section style={{ paddingTop: '24px', paddingBottom: '24px' }}>
      <Text
        className='m-0'
        style={{ fontSize: '13px', color: emailTheme.muted, lineHeight: '20px' }}
      >
        Si tienes dudas sobre este cargo, comunícate directamente con la administración de tu
        condominio para más información.
      </Text>
    </Section>
  </EmailLayout>
)

AmenityReservationSurchargeAddedEmail.PreviewProps = {
  amenityName: 'Alberca',
  reservationDate: '25/07/2026',
  amount: '$250.00',
  conceptName: 'Daños e incidentes',
  description: 'Vidrio roto.',
} as AmenityReservationSurchargeAddedEmailProps

export default AmenityReservationSurchargeAddedEmail
