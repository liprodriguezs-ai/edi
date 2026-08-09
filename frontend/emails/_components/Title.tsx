import { Heading } from '@react-email/components'
import React from 'react'

function Title({
  as = 'h1',
  children
}: {
  as?: 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6',
  children?: React.ReactNode
}) {
  return (
      <Heading as={as} className="text-2xl font-bold text-center m-0 py-8">
        {children}
      </Heading>
  );
}

export default Title;