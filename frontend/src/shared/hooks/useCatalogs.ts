import { apiClient } from '@/shared/api/apiClient'
import type { IEntity } from '@/shared/interfaces/Entity'
import { useQuery } from '@tanstack/react-query'
import { useCallback, useMemo, useState } from 'react'

// Tipos para los catálogos — las claves viajan a POST /selects y el backend
// las resuelve via Str::studly + $mapperSelects (AngularController).

export enum CatalogType {
  // Plataforma
  COUNTRY = 'country',
  MUNICIPALITY = 'municipality',
  ROLE = 'role',
  COMPANY = 'company',
  PERMISSION = 'permission',
  TENANT_PAYMENT_TYPE = 'tenant_payment_type',
  SUBSCRIPTION_PLAN = 'subscription_plan',
  USER = 'user',
  COLLABORATOR = 'collaborator',
  // Housing
  TOWER = 'tower',
  SECTION = 'section',
  UNIT = 'unit',
  UNIT_TYPE = 'unit_type',
  UNIT_STATUS = 'unit_status',
  UNIT_RELATIONSHIP = 'unit_relationship',
  UNIT_FEATURE = 'unit_feature',
  // Mesa directiva y personal
  BOARD_ROLE = 'board_role',
  BOARD_PERIOD_STATUS = 'board_period_status',
  EMPLOYEE_POSITION = 'employee_position',
  PAY_FREQUENCY = 'pay_frequency',
  EMPLOYEE_SHIFT = 'employee_shift',
  // Cobranza
  CHARGE_CONCEPT = 'charge_concept',
  CHARGE_POLICY = 'charge_policy',
  CHARGE_STATUS = 'charge_status',
  PAYMENT_STATUS = 'payment_status',
  CHARGE_BATCH_STATUS = 'charge_batch_status',
  CHARGE_CONCEPT_CATEGORY = 'charge_concept_category',
  AMOUNT_MODE = 'amount_mode',
  PERIODICITY = 'periodicity',
  PAYMENT_METHOD = 'payment_method',
  SURCHARGE_MODE = 'surcharge_mode',
  // Tesorería
  BANK_ACCOUNT = 'bank_account',
  BANK_ACCOUNT_TYPE = 'bank_account_type',
  BANK_ACCOUNT_PURPOSE = 'bank_account_purpose',
  EXPENSE_CATEGORY = 'expense_category',
  EXPENSE_STATUS = 'expense_status',
  BENEFICIARY_TYPE = 'beneficiary_type',
  // Proveedores
  PROVIDER = 'provider',
  PROVIDER_TYPE = 'provider_type',
  PROVIDER_SERVICE_TYPE = 'provider_service_type',
  // Proyectos comunitarios
  COMMUNITY_PROJECT_STATUS = 'community_project_status',
  COMMUNITY_PROJECT_CATEGORY = 'community_project_category',
  PARTICIPATION_TYPE = 'participation_type',
  PROJECT_CONTRIBUTION_STATUS = 'project_contribution_status',
  // Avisos
  ANNOUNCEMENT_TYPE = 'announcement_type',
  ANNOUNCEMENT_PRIORITY = 'announcement_priority',
  ANNOUNCEMENT_STATUS = 'announcement_status',
  // Chat
  CHAT_CHANNEL_TYPE = 'chat_channel_type',
  // Accesos
  VISITOR_MOTIVE = 'visitor_motive',
  VISITOR_STATUS = 'visitor_status',
  QR_TYPE = 'qr_type',
  ACCESS_ENTRY_TYPE = 'access_entry_type',
  ACCESS_VERIFICATION = 'access_verification',
  BLACKLIST_TYPE = 'blacklist_type',
  // Paquetería
  PACKAGE_CARRIER = 'package_carrier',
  PACKAGE_TYPE = 'package_type',
  PACKAGE_STATUS = 'package_status',
  // Amenidades
  RESERVATION_MODE = 'reservation_mode',
  RESERVATION_STATUS = 'reservation_status',
  // Incidencias
  COMPLAINT_CATEGORY = 'complaint_category',
  COMPLAINT_PRIORITY = 'complaint_priority',
  COMPLAINT_STATUS = 'complaint_status',
}

export interface CatalogParams {
  _sort?: string
  _limit?: number
  _search?: string
  _filters?: Record<string, unknown>
  /** Relaciones a eager-loadear en /selects (ApiHandler `with`), separadas por coma. Requiere @Relation en el método de la relación. Ej: 'periodicity'. */
  _with?: string
  [key: string]: unknown
}

export type CatalogRequest = {
  [_key in CatalogType]?: CatalogParams
}

/**
 * A catalog row always has a display `name` (the value shown in selects), so
 * consumers can read `item.name` without casting `IEntity` (whose `name` is
 * optional).
 */
export interface ICatalogItem extends IEntity {
  name: string
}

export type CatalogResponse = {
  [_key in CatalogType]?: ICatalogItem[]
}

// Configuración de cache por tipo de catálogo

/**
 * Hook para obtener catálogos de forma dinámica y eficiente
 *
 * @example
 * ```tsx
 * // Obtener múltiples catálogos
 * const { catalogs, isLoading, refetch } = useCatalogs({
 *   [CatalogType.CYCLING_CLASS_LEVELS]: { _sort: 'name' },
 *   [CatalogType.INSTRUCTORS]: { _sort: '-name', _filters: { is_active: true } }
 * });
 *
 * // Usar los catálogos
 * const levels = catalogs[CatalogType.CYCLING_CLASS_LEVELS] || [];
 * const instructors = catalogs[CatalogType.INSTRUCTORS] || [];
 * ```
 */
export interface UseCatalogsOptions {
  /**
   * Gate para lazy-loading. Si es `false`, la query NO se dispara (no hay POST
   * /selects) hasta que pase a `true`. Default `true` (comportamiento legacy:
   * carga al montar el componente que llama el hook). Para diferir la carga
   * hasta que el usuario abra el dropdown/formulario, preferí `useLazyCatalogs`.
   */
  enabled?: boolean
}

export function useCatalogs(catalogRequest: CatalogRequest, options?: UseCatalogsOptions) {
  // Lazy gate: si `options.enabled === false`, no se dispara /selects hasta que pase a true.
  const isEnabled = (options?.enabled ?? true) && Object.keys(catalogRequest).length > 0

  // Una sola query que obtiene todos los catálogos de una vez
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['catalogs', catalogRequest],
    queryFn: async () => {
      const response = await apiClient.post('/selects', catalogRequest)
      return response.data?.data || response.data || {}
    },
    staleTime: 30 * 1000, // 30s: al remontar pinta desde cache y refetchea en background si está stale
    gcTime: 15 * 60 * 1000, // mantener cache entre montajes (evita refetch por apertura de modal)
    refetchOnWindowFocus: false,
    retry: 2,
    enabled: isEnabled,
  })

  // Procesar los catálogos recibidos
  const catalogs = useMemo(() => {
    const catalogData: Partial<CatalogResponse> = {}

    // Inicializar todos los catálogos solicitados como arrays vacíos
    Object.keys(catalogRequest).forEach((catalogType) => {
      catalogData[catalogType as CatalogType] = []
    })

    // Llenar con los datos recibidos
    if (data) {
      Object.entries(data).forEach(([catalogType, catalogItems]) => {
        if (Array.isArray(catalogItems)) {
          catalogData[catalogType as CatalogType] = catalogItems
        }
      })
    }

    return catalogData
  }, [data, catalogRequest])

  // Función para refrescar un catálogo específico (refetch completo)
  const refetchCatalog = useCallback(async () => {
    // Como es una sola llamada, refrescamos todo
    await refetch()
  }, [refetch])

  // Función para obtener un catálogo específico
  const getCatalog = useCallback(
    (catalogType: CatalogType): IEntity[] => {
      return catalogs[catalogType] || []
    },
    [catalogs]
  )

  // Función para buscar un item específico en un catálogo
  const findCatalogItem = useCallback(
    (catalogType: CatalogType, predicate: (_item: IEntity) => boolean): IEntity | undefined => {
      const catalog = getCatalog(catalogType)
      return catalog.find(predicate)
    },
    [getCatalog]
  )

  // Función para obtener un item por ID
  const getCatalogItemById = useCallback(
    (catalogType: CatalogType, id: string): IEntity | undefined => {
      return findCatalogItem(catalogType, (item) => item.id === id)
    },
    [findCatalogItem]
  )

  // Función para obtener un item por slug
  const getCatalogItemBySlug = useCallback(
    (catalogType: CatalogType, slug: string): IEntity | undefined => {
      return findCatalogItem(catalogType, (item) => item.slug === slug)
    },
    [findCatalogItem]
  )

  return {
    // Datos
    catalogs,

    // Estados
    isLoading,
    isError,
    error,

    // Acciones
    refetch,
    refetchCatalog,

    // Utilidades
    getCatalog,
    findCatalogItem,
    getCatalogItemById,
    getCatalogItemBySlug,

    // Información adicional
    catalogTypes: Object.keys(catalogRequest) as CatalogType[],
    isEmpty: Object.values(catalogs).every(
      (catalog) => !catalog || (Array.isArray(catalog) && catalog.length === 0)
    ),
  }
}

/**
 * Variante LAZY de {@link useCatalogs}: NO dispara `POST /selects` al montar.
 * La query queda inhabilitada hasta que llamás `enable()` (idempotente), pensada
 * para diferir la carga hasta que el usuario abra el dropdown/sección.
 *
 * Ahorra infraestructura: una lista que solo se navega no paga ningún `/selects`;
 * el costo se incurre recién cuando se interactúa con el filtro o el formulario.
 * El resto de la API (catalogs, isLoading, getCatalog, etc.) es idéntica a `useCatalogs`.
 *
 * @example
 * ```tsx
 * const { catalogs, isLoading, enable } = useLazyCatalogs({
 *   [CatalogType.UNIT]: { _sort: 'name' },
 * })
 * // Dispara la carga la primera vez que se abre el Select:
 * <Select onOpenChange={(open) => { if (open) enable() }}>...</Select>
 * ```
 */
export function useLazyCatalogs(catalogRequest: CatalogRequest) {
  const [enabled, setEnabled] = useState(false)

  const enable = useCallback(() => {
    setEnabled(true)
  }, [])

  const catalogs = useCatalogs(catalogRequest, { enabled })

  return {
    ...catalogs,
    /** Habilita (idempotente) la carga del catálogo. Llamar en `onOpenChange`/foco del Select. */
    enable,
    /** `true` una vez que la carga fue habilitada. */
    isEnabled: enabled,
  }
}

/**
 * Hook simplificado para obtener un solo catálogo
 *
 * @example
 * ```tsx
 * const { data: levels, isLoading } = useCatalog(CatalogType.CYCLING_CLASS_LEVELS, {
 *   _sort: 'name'
 * });
 * ```
 */
export function useCatalog(catalogType: CatalogType, params?: CatalogParams) {
  return useQuery({
    queryKey: ['catalog', catalogType, params],
    queryFn: async () => {
      const catalogRequest = { [catalogType]: params || {} }
      const response = await apiClient.post('/selects', catalogRequest)
      const data = response.data?.data || response.data || {}
      return data[catalogType] || []
    },
    staleTime: 15 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
    refetchOnWindowFocus: false,
    retry: 2,
  })
}

/**
 * Hook para obtener catálogos con invalidación manual
 * Útil cuando necesitas control total sobre cuándo se actualizan
 */
export function useCatalogsManual() {
  const [catalogs, setCatalogs] = useState<Partial<CatalogResponse>>({})
  const [isLoading, setIsLoading] = useState(false)
  const [errors, setErrors] = useState<Error[]>([])

  const fetchCatalogs = useCallback(async (catalogRequest: CatalogRequest) => {
    setIsLoading(true)
    setErrors([])

    try {
      const response = await apiClient.post('/selects', catalogRequest)
      const data = response.data?.data || response.data || {}

      const newCatalogs: Partial<CatalogResponse> = {}

      // Inicializar todos los catálogos solicitados
      Object.keys(catalogRequest).forEach((catalogType) => {
        newCatalogs[catalogType as CatalogType] = []
      })

      // Llenar con los datos recibidos
      Object.entries(data).forEach(([catalogType, catalogItems]) => {
        if (Array.isArray(catalogItems)) {
          newCatalogs[catalogType as CatalogType] = catalogItems
        }
      })

      setCatalogs((prev) => ({ ...prev, ...newCatalogs }))
      return newCatalogs
    } catch (error) {
      setErrors([error as Error])
      return {}
    } finally {
      setIsLoading(false)
    }
  }, [])

  const clearCatalogs = useCallback(() => {
    setCatalogs({})
    setErrors([])
  }, [])

  return {
    catalogs,
    isLoading,
    errors,
    fetchCatalogs,
    clearCatalogs,
  }
}
