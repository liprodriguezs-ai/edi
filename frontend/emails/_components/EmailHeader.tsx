import { Img, Section } from '@react-email/components'
import React from 'react'
import { emailTheme } from '../_theme'
import { getEmailAssetsBaseUrl } from '../_utils/emailAssetsBaseUrl'

interface EmailHeaderProps {
  logoUrl?: string
  logoAlt?: string
  productName?: string
}

const defaultLogoUrl = `${getEmailAssetsBaseUrl()}/static/logo.png`

export const EmailHeader: React.FC<EmailHeaderProps> = ({
  logoUrl = defaultLogoUrl,
  logoAlt = emailTheme.productName,
  productName = emailTheme.productName,
}) => (
  <Section
    className="py-5 px-6"
    style={{
      backgroundColor: emailTheme.bgCard,
      borderBottomWidth: '1px',
      borderBottomStyle: 'solid',
      borderBottomColor: emailTheme.border,
    }}
  >
    <table width="100%" cellPadding={0} cellSpacing={0} role="presentation">
      <tbody>
        <tr>
          <td align="left" valign="middle">
            <Img
              src={logoUrl}
              alt={logoAlt}
              width={140}
              height={40}
              className="block"
              style={{ maxHeight: '40px', width: 'auto', objectFit: 'contain' }}
            />
          </td>
          <td align="right" valign="middle">
            <span
              style={{
                fontSize: '13px',
                fontWeight: 500,
                color: emailTheme.muted,
                letterSpacing: '0.02em',
              }}
            >
              {productName}
            </span>
          </td>
        </tr>
      </tbody>
    </table>
  </Section>
)

export default EmailHeader
