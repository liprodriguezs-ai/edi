/**
 * System entity — shape returned by Orion `GET /api/system` and `POST /api/system/search`.
 * NOT the same as `IMenuSystem` (the hierarchical `GET /api/menu` payload).
 */
export interface ISystem {
  id: string
  name: string
  slug: string
  description: string | null
  icon: string | null
  sort_order: number
  is_active: boolean
  created_at: string
  updated_at: string
  deleted_at: string | null
}
