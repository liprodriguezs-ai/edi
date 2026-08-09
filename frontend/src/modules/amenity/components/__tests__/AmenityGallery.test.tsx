/**
 * Smoke tests para AmenityGallery: lista fotos existentes y sube una nueva
 * contra `/amenity/{id}/media` (mockeado).
 */
import { AmenityGallery } from '@/modules/amenity/components/AmenityGallery'
import { AmenityResource } from '@/modules/amenity/services/AmenityResource'
import type { IMedia } from '@/shared/interfaces/Entity'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/amenity/services/AmenityResource', () => ({
  AmenityResource: {
    uploadMedia: vi.fn().mockResolvedValue({ id: 'media-new' }),
    deleteMedia: vi.fn().mockResolvedValue(undefined),
  },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

const existingMedia: IMedia = {
  id: 'media-1',
  collection_name: 'gallery',
  name: 'foto-alberca',
  file_name: 'foto-alberca.jpg',
  mime_type: 'image/jpeg',
  disk: 'public',
  conversions_disk: 'public',
  size: 1024,
  manipulations: [],
  custom_properties: [],
  generated_conversions: [],
  responsive_images: [],
  order_column: 1,
  created_at: '',
  updated_at: '',
  model_type: 'Amenity',
  model_id: 'amenity-1',
  original_url: 'https://example.test/foto-alberca.jpg',
  preview_url: 'https://example.test/foto-alberca-preview.jpg',
}

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

describe('AmenityGallery', () => {
  beforeEach(() => vi.clearAllMocks())

  it('renders existing gallery photos', () => {
    render(<AmenityGallery amenityId='amenity-1' media={[existingMedia]} disabled={false} />, {
      wrapper: makeWrapper(),
    })

    expect(screen.getByAltText('foto-alberca')).toBeInTheDocument()
  })

  it('shows the empty state when there are no photos', () => {
    render(<AmenityGallery amenityId='amenity-1' media={[]} disabled={false} />, {
      wrapper: makeWrapper(),
    })

    expect(screen.getByText('Sin fotos todavía.')).toBeInTheDocument()
  })

  it('uploads a new photo when a file is picked', async () => {
    render(<AmenityGallery amenityId='amenity-1' media={[]} disabled={false} />, {
      wrapper: makeWrapper(),
    })

    const file = new File(['data'], 'nueva-foto.jpg', { type: 'image/jpeg' })
    const input = document.querySelector('input[type="file"]') as HTMLInputElement
    fireEvent.change(input, { target: { files: [file] } })

    await waitFor(() => expect(AmenityResource.uploadMedia).toHaveBeenCalledWith('amenity-1', file))
  })
})
