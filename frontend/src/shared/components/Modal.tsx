import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import type { JSX, PropsWithChildren, ReactNode } from 'react'

type ModalSize = 'sm' | 'md' | 'lg' | 'xl' | 'fullscreen'

interface ModalProps {
  isOpen: boolean
  title: ReactNode
  description?: ReactNode
  onClose: () => void
  footer?: ReactNode
  size?: ModalSize
}

/**
 * Modal component built on top of shadcn Sheet (right-side drawer).
 * Provides accessible side panels with proper focus management.
 */
export const Modal = ({
  isOpen,
  onClose,
  title,
  description,
  footer,
  size = 'md',
  children,
}: PropsWithChildren<ModalProps>): JSX.Element => {
  const getSizeClasses = () => {
    switch (size) {
      case 'sm':
        return 'sm:max-w-sm'
      case 'md':
        return 'sm:max-w-md'
      case 'lg':
        return 'sm:max-w-lg'
      case 'xl':
        return 'sm:max-w-2xl'
      case 'fullscreen':
        return 'sm:max-w-none w-full'
      default:
        return 'sm:max-w-md'
    }
  }

  return (
    <Sheet open={isOpen} onOpenChange={(open: boolean) => !open && onClose()}>
      <SheetContent
        side='right'
        className={`flex flex-col gap-0 p-0 w-full max-w-[100vw] pb-[env(safe-area-inset-bottom)] ${getSizeClasses()}`}
        {...(!description ? { 'aria-describedby': undefined } : {})}
      >
        <SheetHeader className='shrink-0 px-6 pt-6 pb-2'>
          <SheetTitle>{title}</SheetTitle>
          {description && <SheetDescription>{description}</SheetDescription>}
        </SheetHeader>
        <div className='flex-1 min-h-0 overflow-y-auto px-6 py-2'>{children}</div>
        {footer && (
          <SheetFooter className='shrink-0 px-6 pb-6 pt-4 border-t border-border'>
            {footer}
          </SheetFooter>
        )}
      </SheetContent>
    </Sheet>
  )
}
