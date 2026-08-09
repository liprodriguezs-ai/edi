import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { CrudMode } from '@/shared/enums/CrudMode'
import type { IEntity } from '@/shared/interfaces/Entity'
import type { IEntityStore } from '@/shared/store/EntityStore'
import { useCallback } from 'react'
import { toast } from '@/shared/hooks/useToast'

export type CrudPayload<TForm> = TForm | FormData

/**
 * Store hook compatible: el bound store de Zustand (acepta selector y expone getState)
 * o una función simple que devuelve el estado.
 */
export interface BoundEntityStore<TEntity extends IEntity> {
  (): IEntityStore<TEntity>
  <U>(_selector: (_state: IEntityStore<TEntity>) => U): U
  getState?: () => IEntityStore<TEntity>
}

export interface UseBaseCrudConfig<TEntity extends IEntity, TForm = Partial<TEntity>> {
  store: BoundEntityStore<TEntity>
  resource: {
    save?: (_data: CrudPayload<TForm>) => Promise<TEntity | { data: TEntity }>
    update?: (_id: string, _data: CrudPayload<TForm>) => Promise<TEntity | { data: TEntity }>
    delete?: (_id: string) => Promise<void | string | unknown>
    restore?: (_id: string) => Promise<void | string | unknown>
  }
  mode?: CrudMode
  entityId?: string | number
  initialData?: Partial<TEntity> | null
  /**
   * Optional error interception. Return `true` to suppress the default generic toast
   * and handle the error yourself. The error is still re-thrown after this callback.
   */
  onError?: (_err: ApiError) => boolean
}

/**
 * Base CRUD Hook - All CRUD logic via composition
 * TEntity = API entity type (what the store holds)
 * TForm   = form payload type (what save/update receive). Defaults to Partial<TEntity>.
 *
 * Suscripción reactiva mínima: solo `isSaving`. Las acciones del store son estables
 * y el estado se lee fresco al momento de cada llamada vía getState().
 */
export function useBaseCrud<TEntity extends IEntity, TForm = Partial<TEntity>>(
  config: UseBaseCrudConfig<TEntity, TForm>
) {
  const { store: storeHook, resource, entityId, initialData, onError } = config
  const mode = config.mode || CrudMode.CREATE

  // Única suscripción reactiva del hook: lo único que los forms leen del store.
  const isSaving = storeHook((s) => s.isSaving)

  // Estado fresco + acciones estables, sin suscripción (legal fuera de render vía getState).
  const readStore = useCallback((): IEntityStore<TEntity> => {
    return storeHook.getState ? storeHook.getState() : storeHook()
  }, [storeHook])

  const cleanFormData = useCallback((params: FormData): FormData => {
    const cleaned = new FormData()

    params.forEach((value, key) => {
      if (key === 'id' && typeof value === 'string' && value.trim() === '') {
        return
      }

      if (typeof value === 'string') {
        const trimmed = value.trim()
        if (trimmed === '' || trimmed === 'undefined' || trimmed === 'null') {
          return
        }
      }

      cleaned.append(key, value)
    })

    return cleaned
  }, [])

  const cleanParams = useCallback(
    (params: CrudPayload<TForm>): CrudPayload<TForm> => {
      if (params instanceof FormData) {
        return cleanFormData(params) as CrudPayload<TForm>
      }

      const cleaned = { ...params } as Record<string, unknown>
      if ('id' in cleaned && !cleaned.id) {
        delete cleaned.id
      }
      return cleaned as CrudPayload<TForm>
    },
    [cleanFormData]
  )

  const onSave = useCallback(
    async (data?: CrudPayload<TForm>): Promise<TEntity | null> => {
      if (!resource.save) return null

      const store = readStore()
      store.putSaving(true)

      try {
        const saveData = data ?? ((initialData ?? {}) as CrudPayload<TForm>)
        const params = cleanParams(saveData)

        const response = await resource.save(params)

        const entity: TEntity =
          response && typeof response === 'object' && 'data' in response
            ? (response as { data: TEntity }).data
            : (response as TEntity)

        if (!entity?.id) {
          ;(entity as TEntity & { id: string }).id = Date.now().toString()
        }

        toast.success('Registro creado exitosamente')

        store.addEntity(entity)
        store.putNotFound(false)

        return entity
      } catch (err) {
        if (err instanceof ApiError) {
          const handled = onError ? onError(err) : false
          if (!handled) {
            const { title, description } = formatApiErrorMessage(err)
            toast.error(title, description)
          }
        } else {
          toast.error('No se pudo guardar. Inténtalo de nuevo.')
        }
        throw err
      } finally {
        readStore().putSaving(false)
      }
    },
    [resource, initialData, readStore, cleanParams, onError]
  )

  const onUpdate = useCallback(
    async (data?: CrudPayload<TForm>): Promise<TEntity | null> => {
      if (!resource.update) return null

      const store = readStore()
      const targetId = entityId || store.selectId

      if (!targetId) {
        console.error('No entityId found for update')
        return null
      }

      store.putSaving(true)

      try {
        const updateData = data ?? ((initialData ?? {}) as CrudPayload<TForm>)
        const params = cleanParams(updateData)

        const response = await resource.update(targetId as string, params)

        const entity: TEntity =
          response && typeof response === 'object' && 'data' in response
            ? (response as { data: TEntity }).data
            : (response as TEntity)

        const updatedEntity: TEntity = { ...entity, id: targetId } as TEntity

        store.updateEntity(updatedEntity)
        toast.success('Registro actualizado exitosamente')

        return updatedEntity
      } catch (err) {
        if (err instanceof ApiError) {
          const { title, description } = formatApiErrorMessage(err)
          toast.error(title, description)
        } else {
          toast.error('No se pudo actualizar. Inténtalo de nuevo.')
        }
        throw err
      } finally {
        readStore().putSaving(false)
      }
    },
    [resource, entityId, initialData, readStore, cleanParams]
  )

  const submit = useCallback(
    async (data?: CrudPayload<TForm>): Promise<TEntity | null> => {
      if (mode === CrudMode.EDIT) {
        return onUpdate(data)
      }
      return onSave(data)
    },
    [mode, onSave, onUpdate]
  )

  const onDelete = useCallback(
    async (id?: string | number): Promise<void> => {
      if (!resource.delete) return

      const store = readStore()
      const targetId = id || entityId || store.selectId
      if (!targetId) return

      store.putDeleting(true)

      try {
        await resource.delete(targetId as string)
        store.removeEntity(targetId)
      } catch (err) {
        if (err instanceof ApiError) {
          const handled = onError ? onError(err) : false
          if (!handled) {
            const { title, description } = formatApiErrorMessage(err)
            toast.error(title, description)
          }
        } else {
          toast.error('No se pudo eliminar. Inténtalo de nuevo.')
        }
        throw err
      } finally {
        readStore().putDeleting(false)
      }
    },
    [resource, entityId, readStore, onError]
  )

  // Compat: los forms leen `crud.store.isSaving` (reactivo). El resto del objeto expone
  // las acciones estables del store; el estado puntual se lee fresco en cada callback.
  const store: IEntityStore<TEntity> = { ...readStore(), isSaving }

  return {
    store,
    mode,
    onSave,
    onUpdate,
    submit,
    onDelete,
    cleanParams,
  }
}
