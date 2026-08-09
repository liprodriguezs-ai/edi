import { Section } from '@react-email/components'
import React from 'react'

function Card({
  children
}: {
  children?: React.ReactNode
}) {
  return (
      <Section className="border border-[#e0e0e0] border-solid rounded-lg my-10 px-8 py-4 bg-white shadow-sm">
        {children}
      </Section>
  );
}

export default Card;