import { ArrowLeft, SearchX, TriangleAlert } from 'lucide-react'
import { Link } from 'react-router-dom'

import { Button } from '@/components/ui/button'
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from '@/components/ui/empty'

export interface IEntityNotFoundProps {
  /** 'notFound' (default): the record does not exist. 'error': load failure with retry. */
  variant?: 'notFound' | 'error'
  title?: string
  description?: string
  /** Href for the back button (react-router Link). */
  backTo?: string
  backLabel?: string
  /** Alternative to backTo when the page navigates programmatically. */
  onBack?: () => void
  /** Only rendered for variant 'error'. */
  onRetry?: () => void
}

const DEFAULT_COPY = {
  notFound: {
    title: 'No se encontró el registro',
    description: 'El registro no existe o no tienes acceso a él.',
  },
  error: {
    title: 'No se pudo cargar la información',
    description: 'Ocurrió un error al obtener los datos. Intenta de nuevo.',
  },
} as const

export function EntityNotFound({
  variant = 'notFound',
  title,
  description,
  backTo,
  backLabel = 'Volver',
  onBack,
  onRetry,
}: IEntityNotFoundProps) {
  const copy = DEFAULT_COPY[variant]
  const Icon = variant === 'notFound' ? SearchX : TriangleAlert

  return (
    <Empty className='py-16'>
      <EmptyHeader>
        <EmptyMedia variant='icon'>
          <Icon aria-hidden='true' />
        </EmptyMedia>
        <EmptyTitle>{title ?? copy.title}</EmptyTitle>
        <EmptyDescription>{description ?? copy.description}</EmptyDescription>
      </EmptyHeader>
      <EmptyContent>
        <div className='flex flex-wrap items-center justify-center gap-2'>
          {variant === 'error' && onRetry && (
            <Button variant='outline' onClick={onRetry}>
              Reintentar
            </Button>
          )}
          {backTo && (
            <Button variant='outline' asChild>
              <Link to={backTo}>
                <ArrowLeft aria-hidden='true' />
                {backLabel}
              </Link>
            </Button>
          )}
          {!backTo && onBack && (
            <Button variant='outline' onClick={onBack}>
              <ArrowLeft aria-hidden='true' />
              {backLabel}
            </Button>
          )}
        </div>
      </EmptyContent>
    </Empty>
  )
}
