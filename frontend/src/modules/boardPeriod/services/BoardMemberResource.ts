import type { IBoardMember } from '@/modules/boardPeriod/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export type BoardMemberPayload = {
  board_period_id: string
  user_id: string
  board_role_id: string
}

/**
 * CRUD de miembros de la mesa (§7.3). El backend (`BoardMemberController`)
 * sincroniza los roles Spatie en store/update/destroy cuando el periodo está
 * vigente, así que agregar/editar/quitar un miembro basta con el CRUD de Orion.
 */
export const BoardMemberResource = createCrudApi<IBoardMember, BoardMemberPayload>({
  basePath: '/board_member',
})
