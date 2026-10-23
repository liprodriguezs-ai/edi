import { TenantForm } from '@/modules/tenant/TenantForm'
import { TenantProvisionForm } from '@/modules/tenant/TenantProvisionForm'
import type { ITenant } from '@/modules/tenant/schema'
import { TenantResource } from '@/modules/tenant/services/TenantResource'
import { CrudMode } from '@/shared/enums/CrudMode'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/hooks/useToast', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

const TENANT: ITenant = {
  id: 't-1',
  name: 'Centro de Salud Norte',
  status: 'activo',
  timezone: 'America/Cancun',
} as ITenant

describe('Zona horaria del tenant en los formularios', () => {
  afterEach(() => vi.restoreAllMocks())

  it('el form de edición muestra la zona y la envía al actualizar', async () => {
    const update = vi.spyOn(TenantResource, 'update').mockResolvedValue(TENANT)

    render(<TenantForm mode={CrudMode.EDIT} entity={TENANT} />)

    expect(
      screen.getByText('Las fechas y horas se mostrarán en esta zona horaria.')
    ).toBeInTheDocument()
    expect(screen.getByLabelText(/Zona horaria/)).toHaveTextContent('Sureste (Cancún)')

    fireEvent.click(screen.getByRole('button', { name: 'Actualizar' }))

    await waitFor(() => expect(update).toHaveBeenCalled())
    expect(update.mock.calls[0][1]).toMatchObject({ timezone: 'America/Cancun' })
  })

  it('el alta envía la zona por defecto America/Mexico_City', async () => {
    const provision = vi.spyOn(TenantResource, 'provision').mockResolvedValue(TENANT)

    render(
      <>
        <TenantProvisionForm formId='provision-form' />
        <button type='submit' form='provision-form'>
          Enviar
        </button>
      </>
    )

    expect(screen.getByLabelText(/Zona horaria/)).toHaveTextContent('Centro (Ciudad de México)')
    fireEvent.change(screen.getByLabelText(/Nombre/), { target: { value: 'Centro Sur' } })
    fireEvent.click(screen.getByRole('button', { name: 'Enviar' }))

    await waitFor(() => expect(provision).toHaveBeenCalled())
    expect(provision.mock.calls[0][0]).toMatchObject({
      name: 'Centro Sur',
      timezone: 'America/Mexico_City',
    })
  })
})
