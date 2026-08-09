import {
  Body,
  Container,
  Head,
  Html,
  Preview,
  Tailwind,
} from '@react-email/components'
import React from 'react'
import { emailTheme } from '../_theme'
import EmailHeader from './EmailHeader'
import EmailFooter from './EmailFooter'

interface EmailLayoutProps {
  children: React.ReactNode
  previewText?: string
  fontFamily?: string
}

export const EmailLayout = ({
  children,
  previewText = '',
  fontFamily = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif',
}: EmailLayoutProps) => (
  <Html lang="es">
    <Head />
    {previewText ? <Preview>{previewText}</Preview> : null}
    <Tailwind
      config={{
        theme: {
          fontSize: {
            xs: ['11px', { lineHeight: '16px' }],
            sm: ['13px', { lineHeight: '18px' }],
            base: ['15px', { lineHeight: '22px' }],
            lg: ['16px', { lineHeight: '24px' }],
            xl: ['18px', { lineHeight: '26px' }],
            '2xl': ['22px', { lineHeight: '30px' }],
            '3xl': ['26px', { lineHeight: '32px' }],
          },
          spacing: {
            0: '0',
            1: '4px',
            2: '8px',
            3: '12px',
            4: '16px',
            5: '20px',
            6: '24px',
            8: '32px',
            10: '40px',
            12: '48px',
            16: '64px',
          },
          extend: {
            colors: {
              heading: emailTheme.heading,
              body: emailTheme.body,
              muted: emailTheme.muted,
              primary: emailTheme.primary,
              'primary-dark': emailTheme.primaryDark,
              surface: emailTheme.bgSurface,
              border: emailTheme.border,
            },
            fontFamily: { sans: [fontFamily] },
          },
        },
      }}
    >
      <Body
        className="my-auto mx-auto font-sans"
        style={{ backgroundColor: emailTheme.bgOuter }}
      >
        <Container
          className="mx-auto my-8 rounded-xl max-w-[600px]"
          style={{
            backgroundColor: emailTheme.bgCard,
            borderWidth: '1px',
            borderStyle: 'solid',
            borderColor: emailTheme.border,
            boxShadow: '0 1px 3px 0 rgba(0,0,0,0.06)',
          }}
        >
          <EmailHeader />
          <Container className="px-6 py-2" style={{ backgroundColor: emailTheme.bgCard }}>
            {children}
          </Container>
          <EmailFooter />
        </Container>
      </Body>
    </Tailwind>
  </Html>
)

export default EmailLayout
