import React from 'react'
import { Text } from '@react-email/components'

function TextBase({
  children,
  className = 'text-base',
  ...props
}: {
  children?: React.ReactNode,
  className?: string,
  [key: string]: React.ReactNode | string | number | boolean | null | undefined
}) {
  return (
      <Text className={className} {...props}>
        {children}
      </Text>
  );
}

export default TextBase;