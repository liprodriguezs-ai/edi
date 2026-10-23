import { Button } from '@/components/ui/button'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Modal } from '@/shared/components/Modal'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { SearchSelect } from '@/shared/components/SearchSelect'
import {
  caregiverFullName,
  type ChildCaregiverFormValues,
  type IChildCaregiver,
} from '@/modules/caregiver/schema'
import { CaregiverFormModal } from '@/modules/caregiver/components/CaregiverFormModal'
import { CaregiverResource } from '@/modules/caregiver/services/CaregiverResource'
import { ChildCaregiverResource } from '@/modules/caregiver/services/ChildCaregiverResource'
import { CrudMode } from '@/shared/enums/CrudMode'
import { CatalogType, useLazyCatalogs, type ICatalogItem } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { Plus, Unlink } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'

interface ChildCaregiverPanelProps {
  childId: string
}

/**
 * REQ-CH-005 / REQ-CH-006 — vínculos cuidador↔niño del expediente. El primer
 * vínculo se vuelve primario solo (servidor); acá solo se puede PROMOVER un
 * secundario a primario o desvincular, y ambos casos surfacean el 422 del
 * servidor si la invariante lo bloquea (ej. desvincular al primario cuando
 * hay otros vínculos).
 */
export function ChildCaregiverPanel({ childId }: ChildCaregiverPanelProps) {
  const [links, setLinks] = useState<IChildCaregiver[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [isLinkDialogOpen, setIsLinkDialogOpen] = useState(false)
  const [caregiverFormOpen, setCaregiverFormOpen] = useState(false)
  const [selectedCaregiverId, setSelectedCaregiverId] = useState<string | null>(null)
  const [selectedRelationshipId, setSelectedRelationshipId] = useState<string | null>(null)
  const [linkToRemove, setLinkToRemove] = useState<IChildCaregiver | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  const { catalogs: relationshipCatalogs, isLoading: isLoadingRelationships, enable: enableRelationships } =
    useLazyCatalogs({ [CatalogType.CAREGIVER_RELATIONSHIP]: { _sort: 'name' } })

  const loadLinks = useCallback(async () => {
    setIsLoading(true)
    try {
      const response = await ChildCaregiverResource.search({
        filters: [{ field: 'child_id', operator: '=', value: childId }],
        includes: [{ relation: 'caregiver' }, { relation: 'caregiver_relationship' }],
      })
      setLinks(response.data)
    } catch {
      toast.error('No se pudieron cargar los cuidadores')
    } finally {
      setIsLoading(false)
    }
  }, [childId])

  useEffect(() => {
    void loadLinks()
  }, [loadLinks])

  const openLinkDialog = useCallback(() => {
    setSelectedCaregiverId(null)
    setSelectedRelationshipId(null)
    setIsLinkDialogOpen(true)
    enableRelationships()
  }, [enableRelationships])

  const handleCaregiverCreated = useCallback((caregiver: { id: string }) => {
    setSelectedCaregiverId(caregiver.id)
    setCaregiverFormOpen(false)
    setIsLinkDialogOpen(true)
  }, [])

  const handleConfirmLink = useCallback(async () => {
    if (!selectedCaregiverId || !selectedRelationshipId) {
      toast.error('Selecciona un cuidador y un parentesco')
      return
    }
    setIsSubmitting(true)
    try {
      await ChildCaregiverResource.create({
        child_id: childId,
        caregiver_id: selectedCaregiverId,
        caregiver_relationship_id: selectedRelationshipId,
      })
      toast.success('Cuidador vinculado')
      setIsLinkDialogOpen(false)
      await loadLinks()
    } catch (err) {
      if (err instanceof ApiError) {
        const { title, description } = formatApiErrorMessage(err)
        toast.error(title, description)
      } else {
        toast.error('No se pudo vincular el cuidador')
      }
    } finally {
      setIsSubmitting(false)
    }
  }, [childId, selectedCaregiverId, selectedRelationshipId, loadLinks])

  /** REQ-CH-006 — promueve un vínculo secundario a primario. */
  const handleSetPrimary = useCallback(
    async (link: IChildCaregiver) => {
      try {
        // Partial PATCH-style update: the backend's ChildCaregiverUpdateRequest
        // accepts `is_primary` alone (all its rules are `sometimes`).
        await ChildCaregiverResource.update(link.id, { is_primary: true } as ChildCaregiverFormValues)
        toast.success('Cuidador primario actualizado')
        await loadLinks()
      } catch (err) {
        if (err instanceof ApiError) {
          const { title, description } = formatApiErrorMessage(err)
          toast.error(title, description)
        } else {
          toast.error('No se pudo actualizar el cuidador primario')
        }
      }
    },
    [loadLinks]
  )

  const handleConfirmRemove = useCallback(async () => {
    if (!linkToRemove) return
    try {
      await ChildCaregiverResource.remove(linkToRemove.id)
      toast.success('Cuidador desvinculado')
      setLinkToRemove(null)
      await loadLinks()
    } catch (err) {
      if (err instanceof ApiError) {
        const { title, description } = formatApiErrorMessage(err)
        toast.error(title, description)
      } else {
        toast.error('No se pudo desvincular el cuidador')
      }
    }
  }, [linkToRemove, loadLinks])

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold">Cuidadores</h3>
        <Button size="sm" onClick={openLinkDialog} className="flex items-center gap-1.5">
          <Plus className="h-4 w-4" />
          Vincular cuidador
        </Button>
      </div>

      {isLoading ? (
        <InlineLoading name="child-caregiver-panel" />
      ) : links.length === 0 ? (
        <InlineEmpty>Este niño aún no tiene cuidadores vinculados.</InlineEmpty>
      ) : (
        <ul className="divide-y divide-border border border-border">
          {links.map((link) => (
            <li
              key={link.id}
              className="flex items-center justify-between px-4 py-3"
            >
              <div className="flex items-center gap-2">
                <span className="font-medium">
                  {link.caregiver ? caregiverFullName(link.caregiver) : '—'}
                </span>
                <span className="text-xs text-muted-foreground">
                  {link.caregiver_relationship?.name ?? '—'}
                </span>
                {link.is_primary && (
                  <StatusPill tone="b">Primario</StatusPill>
                )}
              </div>
              <div className="flex items-center gap-1">
                {!link.is_primary && (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => void handleSetPrimary(link)}
                    aria-label={`Hacer primario a ${link.caregiver ? caregiverFullName(link.caregiver) : ''}`}
                  >
                    Hacer primario
                  </Button>
                )}
                <Button
                  variant="ghost"
                  size="icon"
                  className="size-8 text-destructive hover:text-destructive"
                  onClick={() => setLinkToRemove(link)}
                  aria-label="Desvincular"
                >
                  <Unlink className="size-4" />
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}

      <Modal
        isOpen={isLinkDialogOpen}
        onClose={() => setIsLinkDialogOpen(false)}
        title="Vincular cuidador"
        footer={
          <>
            <Button variant="outline" onClick={() => setIsLinkDialogOpen(false)} disabled={isSubmitting}>
              Cancelar
            </Button>
            <Button onClick={() => void handleConfirmLink()} loading={isSubmitting}>
              {isSubmitting ? 'Vinculando…' : 'Vincular'}
            </Button>
          </>
        }
      >
        <FieldGroup>
          <Field>
            <div className="flex items-center justify-between">
              <FieldLabel htmlFor="link-caregiver_id" required>
                Cuidador
              </FieldLabel>
              <Button
                type="button"
                variant="link"
                size="sm"
                onClick={() => {
                  setIsLinkDialogOpen(false)
                  setCaregiverFormOpen(true)
                }}
              >
                Crear nuevo
              </Button>
            </div>
            <SearchSelect<ICatalogItem>
              id="link-caregiver_id"
              value={selectedCaregiverId}
              onChange={(v) => setSelectedCaregiverId((v as string | null) ?? null)}
              loadOptions={async (term) => {
                const response = await CaregiverResource.search({
                  search: { value: term },
                  limit: 20,
                })
                return response.data.map((c) => ({ id: c.id, name: caregiverFullName(c) }))
              }}
              placeholder="Buscar cuidador…"
            />
          </Field>
          <Field>
            <FieldLabel htmlFor="link-relationship_id" required>
              Parentesco
            </FieldLabel>
            <SearchSelect<ICatalogItem>
              id="link-relationship_id"
              value={selectedRelationshipId}
              onChange={(v) => setSelectedRelationshipId((v as string | null) ?? null)}
              options={relationshipCatalogs[CatalogType.CAREGIVER_RELATIONSHIP] ?? []}
              isLoading={isLoadingRelationships}
              placeholder="Seleccionar…"
            />
          </Field>
        </FieldGroup>
      </Modal>

      <CaregiverFormModal
        isOpen={caregiverFormOpen}
        mode={CrudMode.CREATE}
        caregiver={null}
        onClose={() => setCaregiverFormOpen(false)}
        onSaved={handleCaregiverCreated}
      />

      <ConfirmDialog
        isOpen={Boolean(linkToRemove)}
        variant="delete"
        entityName={linkToRemove?.caregiver ? caregiverFullName(linkToRemove.caregiver) : undefined}
        onConfirm={handleConfirmRemove}
        onCancel={() => setLinkToRemove(null)}
      />
    </div>
  )
}
