import { Hr, Link, Section, Text } from '@react-email/components'
import { BladeTrans } from './LaravelBlade'
import React from 'react'
import { emailTheme } from '../_theme'

interface SocialLink {
  name: string
  url: string
}

interface EmailFooterProps {
  companyName?: string
  companyAddress?: string
  companyWebsite?: string
  socialLinks?: SocialLink[]
}

function EmailFooter({
  companyName = emailTheme.productName,
  companyAddress = '',
  companyWebsite = '',
  socialLinks = [],
}: EmailFooterProps) {
  return (
    <Section
      className="px-6 py-6 text-center"
      style={{ backgroundColor: emailTheme.footerBg }}
    >
      <Text
        className="m-0 mb-2"
        style={{ fontSize: '13px', color: emailTheme.footerText }}
      >
        © {new Date().getFullYear()} {companyName} •{' '}
        <BladeTrans translationKey="all_rights_reserved">
          Todos los derechos reservados
        </BladeTrans>
      </Text>
      {companyAddress ? (
        <Text
          className="m-0 mb-2"
          style={{ fontSize: '12px', color: emailTheme.footerText }}
        >
          {companyAddress}
        </Text>
      ) : null}
      {socialLinks.length > 0 ? (
        <Text
          className="mb-2"
          style={{ fontSize: '12px', color: emailTheme.footerText }}
        >
          {socialLinks.map((link, index) => (
            <React.Fragment key={link.name}>
              <Link
                href={link.url}
                style={{ color: emailTheme.footerLink, textDecoration: 'none' }}
              >
                {link.name}
              </Link>
              {index < socialLinks.length - 1 ? (
                <span style={{ margin: '0 8px', color: emailTheme.footerText }}>•</span>
              ) : null}
            </React.Fragment>
          ))}
        </Text>
      ) : null}
      <Hr
        className="my-4"
        style={{
          border: 'none',
          borderTopWidth: '1px',
          borderTopStyle: 'solid',
          borderTopColor: emailTheme.borderSoft,
        }}
      />
      {companyWebsite ? (
        <Text className="m-0" style={{ fontSize: '12px' }}>
          <Link
            href={companyWebsite}
            style={{ color: emailTheme.footerLink, textDecoration: 'none' }}
          >
            {companyWebsite.replace(/^https?:\/\//, '')}
          </Link>
        </Text>
      ) : null}
    </Section>
  )
}

export default EmailFooter
