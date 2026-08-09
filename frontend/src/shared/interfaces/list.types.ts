/**
 * Professional List Management Types
 * Based on Angular BaseListService architecture
 */

import type { CrudMode } from '@/shared/enums/CrudMode'
import type { IEntity } from '@/shared/interfaces/Entity'

export interface IEntityTableRowProps<T> {
    tableOnly?: boolean
    standalone?: boolean
    entity: T
    canEdit: boolean
    canDelete?: boolean
    canView?: boolean
    /** Flags globales: NO pasarlos en filas nuevas — provocan re-render de toda la tabla */
    isLoading?: boolean
    isDeleting?: boolean
    isSaving?: boolean
    onEdit: (_entity: T) => void
    onDelete: (_entity: T) => void
    onDetail?: (_entity: T) => void
}

export interface IEntityFormModalProps<T> {
    isOpen: boolean
    mode: CrudMode
    entity?: T | null
    onClose: () => void
    onSuccess?: (_entity: T) => void
}

export const checkMemoListProps = <T>(prevProps: Readonly<IEntityTableRowProps<T>>, nextProps: Readonly<IEntityTableRowProps<T>>) => {
    return (
        prevProps.entity === nextProps.entity &&
        prevProps.canEdit === nextProps.canEdit &&
        prevProps.canDelete === nextProps.canDelete &&
        prevProps.canView === nextProps.canView &&
        prevProps.isLoading === nextProps.isLoading &&
        prevProps.isDeleting === nextProps.isDeleting &&
        prevProps.isSaving === nextProps.isSaving
    )
}

// Core search params structure (from Angular)
export interface ISearchParams {
    id?: string
    scopes?: Scope[]
    filters?: Filter[]
    search?: Search | null
    sort?: Sort[]
    page?: number
    limit?: number
    aggregates?: Aggregate[]
    includes?: Include[]
    user_id?: string | null
    view?: string
    active?: string | boolean
    /** Custom top-level param: billing owner user ids for ChargeController::applyOwnerFilter */
    owner_ids?: string[]
}

export interface DateFilterForm extends IEntity {
    date_start: string
    date_end: string
}

export interface Scope {
    name: string
    parameters?: string[]
}

export interface Filter {
    field: string
    operator: FilterOperator
    value: unknown
    type?: string
}

export type FilterOperator =
    | '='
    | '!='
    | '>'
    | '>='
    | '<'
    | '<='
    | 'like'
    | 'not_like'
    | 'in'
    | 'not_in'
    | 'between'
    | 'is_null'
    | 'is_not_null'

export interface Search {
    value: string | null
    case_sensitive?: boolean
}

export interface Sort {
    field: string
    direction?: 'asc' | 'desc'
}

export interface Aggregate {
    relation: string
    type?: string
    filters?: Filter[]
}

export interface Include {
    relation: string
    filters?: Filter[]
}

export interface IPaginationMeta {
    current_page: number
    from: number | null
    last_page: number
    per_page: number
    to: number | null
    total: number
    path?: string
    first_page_url?: string
    last_page_url?: string
    next_page_url?: string | null
    prev_page_url?: string | null
}

// Pagination state
export interface IPagination {
    page: number
    limit: number
    total: number
    pages: number
    per_page: number
    to?: number | null
    from?: number | null
}
