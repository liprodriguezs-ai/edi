import React from 'react'
import { Button } from '@react-email/components'
import { emailTheme } from '../_theme'

function ButtonBase({
  children,
  href,
  className,
  primary = true,
  ...props
}: {
  children?: React.ReactNode
  className?: string
  href?: string
  primary?: boolean
  [key: string]: unknown
}) {
  const baseStyle = {
    backgroundColor: primary ? emailTheme.primaryDark : 'transparent',
    color: '#ffffff',
    fontWeight: 600,
    paddingTop: '14px',
    paddingBottom: '14px',
    paddingLeft: '24px',
    paddingRight: '24px',
    borderRadius: '8px',
    boxSizing: 'border-box' as const,
    textDecoration: 'none',
    display: 'inline-block',
    fontSize: '15px',
  }
  if (!primary) {
    baseStyle.backgroundColor = emailTheme.bgSurface
    baseStyle.color = emailTheme.primaryDark
  }
  return (
    <Button href={href} style={baseStyle} className={className ?? 'box-border'} {...props}>
      {children}
    </Button>
  )
}

export default ButtonBase
