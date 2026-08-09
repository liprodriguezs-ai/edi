import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { useSectionEdits } from '@/modules/configuration/hooks/useSectionEdits'
import { sessionStore } from '@/shared/auth/sessionStore'
import type {
  GeneralConfig,
  LogoUploadResult,
} from '@/modules/configuration/services/ConfigurationResource'
import type { UseMutationResult } from '@tanstack/react-query'
import type { ChangeEvent } from 'react'

const CARD = 'rounded-[14px] border border-border bg-card p-5 shadow-sm'

/** Refleja el nuevo nombre del clúster en la sesión ya cargada (header, selector). */
const syncSessionClusterName = (clusterName: string | undefined) => {
  if (!clusterName) return

  const { profile, setProfile } = sessionStore.getState()
  if (!profile?.tenant || profile.tenant.name === clusterName) return

  setProfile({ ...profile, tenant: { ...profile.tenant, name: clusterName } })
}

/**
 * El selector de condominio del menú pinta el logo desde la sesión, que sólo se
 * recarga en el próximo /auth/me. Sin este parche el logo recién subido no se
 * vería hasta refrescar la página.
 */
const syncSessionLogoUrl = (logoUrl: string | null | undefined) => {
  if (!logoUrl) return

  const { profile, setProfile } = sessionStore.getState()
  if (!profile?.tenant) return

  setProfile({ ...profile, tenant: { ...profile.tenant, logo_url: logoUrl } })
}

interface Props {
  general: GeneralConfig | undefined
  canUpdate: boolean
  saveMutation: UseMutationResult<
    unknown,
    Error,
    { path: string; payload: Record<string, unknown> }
  >
  logoMutation: UseMutationResult<LogoUploadResult, Error, File>
}

export function GeneralConfigSection({ general, canUpdate, saveMutation, logoMutation }: Props) {
  const { form, setField } = useSectionEdits<GeneralConfig>(general)

  const onPickLogo = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    if (file) {
      logoMutation.mutate(file, {
        onSuccess: (result) => syncSessionLogoUrl(result?.data?.logo_url),
      })
    }
    event.target.value = ''
  }

  const textField = (
    key: keyof GeneralConfig,
    label: string,
    span = 'md:col-span-6',
    type: 'text' | 'email' | 'tel' = 'text'
  ) => (
    <Field className={`col-span-12 ${span}`}>
      <FieldLabel htmlFor={`general-${key}`}>{label}</FieldLabel>
      <Input
        id={`general-${key}`}
        type={type}
        value={(form[key] as string | null | undefined) ?? ''}
        onChange={(e) => setField(key, e.target.value as GeneralConfig[typeof key])}
        disabled={!canUpdate || saveMutation.isPending}
      />
    </Field>
  )

  const saveGeneral = () => {
    // Se omite si quedó vacío: el backend lo rechaza en blanco, y un tenant
    // legacy sin nombre no debe bloquear la edición del resto de la sección.
    const clusterName = form.cluster_name?.trim() || undefined

    saveMutation.mutate(
      {
        path: '/config/general',
        payload: {
          cluster_name: clusterName,
          legal_name: form.legal_name || null,
          rfc: form.rfc || null,
          fiscal_address: form.fiscal_address || null,
          phone: form.phone || null,
          email: form.email || null,
        },
      },
      // El nombre del clúster vive además en la sesión (profile.tenant.name), que
      // es de donde lo leen el header y el selector. Invalidar las queries de
      // configuración no la toca, así que la parcheamos acá o el nombre viejo
      // seguiría visible hasta el próximo login.
      { onSuccess: () => syncSessionClusterName(clusterName) }
    )
  }

  return (
    <div className={CARD}>
      <FieldGroup>
        {/* Marca: logo del condominio (se muestra en el menú lateral) */}
        <div className='grid grid-cols-12 gap-4'>
          <Field className='col-span-12 md:col-span-6'>
            <FieldLabel>Logo</FieldLabel>
            <div className='flex items-center gap-3'>
              <div className='grid size-16 flex-none place-items-center overflow-hidden rounded-[10px] border border-border bg-[var(--surface-2)]'>
                {form.logo_url ? (
                  <img src={form.logo_url} alt='Logo' className='size-full object-contain' />
                ) : (
                  <span className='text-[11px] text-[var(--ink-4)]'>Sin logo</span>
                )}
              </div>
              {canUpdate && (
                <label className='cursor-pointer rounded-full border border-border px-3.5 py-2 text-[13px] font-medium hover:border-[var(--ink-3)]'>
                  {logoMutation.isPending ? 'Subiendo…' : 'Cambiar logo'}
                  <input
                    type='file'
                    accept='image/*'
                    className='hidden'
                    onChange={onPickLogo}
                    disabled={logoMutation.isPending}
                  />
                </label>
              )}
            </div>
            <FieldDescription>
              Imagen cuadrada, máx. 5MB. Se muestra en el menú lateral.
            </FieldDescription>
          </Field>
        </div>

        <div className='grid grid-cols-12 gap-4'>
          <Field className='col-span-12'>
            <FieldLabel htmlFor='general-cluster_name'>Nombre del condominio</FieldLabel>
            <Input
              id='general-cluster_name'
              type='text'
              value={form.cluster_name ?? ''}
              onChange={(e) => setField('cluster_name', e.target.value)}
              disabled={!canUpdate || saveMutation.isPending}
            />
            <FieldDescription>Así lo verán los residentes en toda la aplicación.</FieldDescription>
          </Field>
          {textField('legal_name', 'Razón social')}
          {textField('rfc', 'RFC', 'md:col-span-6')}
          <Field className='col-span-12'>
            <FieldLabel htmlFor='general-fiscal_address'>Dirección fiscal</FieldLabel>
            <Textarea
              id='general-fiscal_address'
              rows={2}
              className='resize-none'
              value={form.fiscal_address ?? ''}
              onChange={(e) => setField('fiscal_address', e.target.value)}
              disabled={!canUpdate || saveMutation.isPending}
            />
          </Field>
          {textField('phone', 'Teléfono', 'md:col-span-6', 'tel')}
          {textField('email', 'Correo', 'md:col-span-6', 'email')}
        </div>
        {canUpdate && (
          <div className='flex justify-end border-t border-[var(--line-2)] pt-4'>
            <Button
              onClick={saveGeneral}
              disabled={saveMutation.isPending}
              className='min-w-[120px] rounded-full'
            >
              {saveMutation.isPending ? 'Guardando…' : 'Guardar'}
            </Button>
          </div>
        )}
      </FieldGroup>
    </div>
  )
}
