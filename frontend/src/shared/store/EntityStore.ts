import type { IEntity } from '@/shared/interfaces/Entity'
import type { IPagination, IPaginationMeta } from '@/shared/interfaces/list.types'
import { create } from 'zustand'
import { devtools } from 'zustand/middleware'

export type EntityId = string | number

/** Resultado completo de un fetch de lista, aplicado en un único set() */
export interface IListResult<T> {
    entities: T[]
    /** undefined = conservar el meta actual del store */
    meta?: IPaginationMeta | null
    pagination: Partial<IPagination>
    notFound: boolean
}

export interface IEntityStore<T extends IEntity> {
    // Entities
    entities: T[]
    selectId: EntityId | null
    currentEntity: T | null

    // State flags
    isLoading: boolean
    isSaving: boolean
    isDeleting: boolean
    notFound: boolean
    initial: boolean

    // Pagination
    pagination: IPagination

    // Meta
    meta: IPaginationMeta | null

    // Methods - exactly like NgRx
    putInitial: (_initial: boolean) => void
    putSelectId: (_selectId: EntityId | null) => void
    putMeta: (_meta: IPaginationMeta | null) => void
    putPagination: (_pagination: Partial<IPagination>) => void
    putLoading: (_isLoading: boolean) => void
    putSaving: (_isSaving: boolean) => void
    putDeleting: (_isDeleting: boolean) => void
    putNotFound: (_notFound: boolean) => void
    setCurrentEntity: (_entity: T | null) => void

    // Entity operations - exactly like NgRx
    addEntity: (_entity: T) => void
    addEntities: (_entities: T[]) => void
    setEntity: (_entity: T) => void
    setEntities: (_entities: T[]) => void
    setAllEntities: (_entities: T[]) => void
    setListResult: (_result: IListResult<T>) => void
    updateEntity: (_entity: Partial<T> | T) => void
    updateEntities: (_ids: EntityId[], _changes: Partial<T>) => void
    updateAllEntities: (_changes: Partial<T>) => void
    removeEntity: (_id: EntityId) => void
    removeEntities: (_ids: EntityId[]) => void
    removeAllEntities: () => void

    // Reset
    reset: () => void
}

const initialPaginationState: IPagination = {
    page: 1,
    limit: 20,
    total: 0,
    pages: 0,
    per_page: 20,
    to: null,
    from: null
}

/**
 * Creates an entity store exactly like NgRx signals store
 */
export function createEntityStore<T extends IEntity>(name: string) {
    return create<IEntityStore<T>>()(
        devtools(
            (set) => ({
                // Initial state
                entities: [],
                selectId: null,
                currentEntity: null,
                isLoading: false,
                isSaving: false,
                isDeleting: false,
                notFound: false,
                initial: false,
                pagination: initialPaginationState,
                meta: null,

                // State setters - exactly like patchState in NgRx
                putInitial: (initial) => set({ initial }),
                putSelectId: (selectId) => set({ selectId }),
                putMeta: (meta) => set({ meta }),
                putPagination: (pagination) => set(state => ({
                    pagination: { ...state.pagination, ...pagination }
                })),
                putLoading: (isLoading) => set({ isLoading }),
                putSaving: (isSaving) => set({ isSaving }),
                putDeleting: (isDeleting) => set({ isDeleting }),
                putNotFound: (notFound) => set({ notFound }),
                setCurrentEntity: (entity) => set({ currentEntity: entity }),

                // Entity operations - exactly like NgRx entity adapter
                addEntity: (entity) => set(state => {
                    // Check if entity already exists
                    const exists = state.entities.some(e => e.id === entity.id)
                    if (exists) {
                        // If exists, update it instead
                        return {
                            entities: state.entities.map(e =>
                                e.id === entity.id ? entity : e
                            )
                        }
                    }
                    // If new, add to the beginning
                    return {
                        entities: [entity, ...state.entities],
                        pagination: {
                            ...state.pagination,
                            total: state.pagination.total + 1
                        }
                    }
                }),

                addEntities: (entities) => set(state => ({
                    entities: [...entities, ...state.entities]
                })),

                setEntity: (entity) => set(state => {
                    const index = state.entities.findIndex(e => e.id === entity.id)
                    if (index >= 0) {
                        const newEntities = [...state.entities]
                        newEntities[index] = entity
                        return { entities: newEntities }
                    }
                    return { entities: [entity, ...state.entities] }
                }),

                setEntities: (entities) => set(state => {
                    const entityMap = new Map(state.entities.map(e => [e.id, e]))
                    entities.forEach(entity => entityMap.set(entity.id, entity))
                    return { entities: Array.from(entityMap.values()) }
                }),

                setAllEntities: (entities) => set({ entities }),

                setListResult: ({ entities, meta, pagination, notFound }) => set(state => ({
                    entities,
                    meta: meta === undefined ? state.meta : meta,
                    pagination: { ...state.pagination, ...pagination },
                    notFound
                })),

                updateEntity: (entity) => set(state => ({
                    entities: state.entities.map(e =>
                        e.id === entity.id ? { ...e, ...entity } : e
                    )
                })),

                updateEntities: (ids, changes) => set(state => ({
                    entities: state.entities.map(e =>
                        ids.includes(e.id) ? { ...e, ...changes } : e
                    )
                })),

                updateAllEntities: (changes) => set(state => ({
                    entities: state.entities.map(e => ({ ...e, ...changes }))
                })),

                removeEntity: (id) => set(state => ({
                    entities: state.entities.filter(e => e.id !== id),
                    selectId: state.selectId === id ? null : state.selectId,
                    pagination: {
                        ...state.pagination,
                        total: Math.max(0, state.pagination.total - 1)
                    }
                })),

                removeEntities: (ids) => set(state => {
                    const idSet = new Set(ids)
                    const filtered = state.entities.filter(e => !idSet.has(e.id))
                    return {
                        entities: filtered,
                        selectId: idSet.has(state.selectId as EntityId) ? null : state.selectId,
                        pagination: {
                            ...state.pagination,
                            total: Math.max(0, state.pagination.total - (state.entities.length - filtered.length))
                        }
                    }
                }),

                removeAllEntities: () => set({
                    entities: [],
                    selectId: null
                }),

                // Reset
                reset: () => set({
                    entities: [],
                    selectId: null,
                    currentEntity: null,
                    isLoading: false,
                    isSaving: false,
                    isDeleting: false,
                    notFound: false,
                    initial: false,
                    pagination: initialPaginationState,
                    meta: null
                })
            }),
            { name, enabled: import.meta.env.DEV }
        )
    )
}
