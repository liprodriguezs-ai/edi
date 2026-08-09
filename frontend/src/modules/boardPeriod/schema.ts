import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const BOARD_PERIOD_STATUS = {
  CURRENT: '43dfabcf-0245-4370-a742-50f3f744b040',
  CLOSED: '8b133be2-c5d4-45a6-a5b9-6a621b70af80',
} as const

export const BOARD_ROLE = {
  PRESIDENT: 'e4134bb8-c7ac-445d-887b-c690c6cb748e',
  TREASURER: 'e300a1ca-d9aa-4126-9cba-843b416dc170',
  SECRETARY: '75e8277c-ab17-4150-99a5-4ae6d0241860',
} as const

export interface IBoardMember extends IEntity {
  board_period_id: string
  user_id: string
  board_role_id: string
  unit_id?: string | null
  user?: { id: string; name?: string; email?: string | null; phone?: string | null } | null
  board_role?: { id: string; name: string } | null
  board_period?: IBoardPeriod | null
}

export interface IBoardPeriod extends IEntity {
  name: string
  start_date: string
  end_date: string
  board_period_status_id: string
  notes?: string | null
  board_period_status?: { id: string; name: string } | null
  members?: IBoardMember[]
}

/**
 * §7.3.2: cargos que la mesa SIEMPRE debe conservar, con su etiqueta en español.
 * Fuente única consumida tanto por `startPeriodSchema` (al iniciar) como por el
 * CRUD individual (al editar/quitar un miembro).
 */
export const REQUIRED_BOARD_ROLES: ReadonlyArray<readonly [string, string]> = [
  [BOARD_ROLE.PRESIDENT, 'Presidente'],
  [BOARD_ROLE.TREASURER, 'Tesorero'],
  [BOARD_ROLE.SECRETARY, 'Secretario'],
] as const

/** Mensaje único cuando una acción dejaría a la mesa sin un cargo obligatorio. */
export const REQUIRED_BOARD_ROLES_MESSAGE =
  'La mesa debe conservar Presidente, Tesorero y Secretario'

export const boardMemberSchema = z.object({
  // "Selecciona…" y no "es requerida": el campo suele verse lleno con el nombre
  // tecleado, y decir "requerida" ahí se lee como un error de la app.
  user_id: z.string().min(1, 'Selecciona una persona de la lista'),
  board_role_id: z.string().min(1, 'El cargo es requerido'),
  /** Solo para mostrar el nombre en el buscador (builder / edición) */
  user_name: z.string().optional(),
})

export type BoardMemberValues = z.infer<typeof boardMemberSchema>

export const startPeriodSchema = z
  .object({
    name: z.string().min(1, 'El nombre es requerido').max(255),
    start_date: z.string().min(1, 'La fecha de inicio es requerida'),
    end_date: z.string().min(1, 'La fecha de fin es requerida'),
    notes: z.string().optional(),
    auto_close_current: z.boolean(),
    members: z.array(boardMemberSchema).min(1, 'Agrega al menos un miembro'),
  })
  .superRefine((values, ctx) => {
    if (values.end_date && values.start_date && values.end_date <= values.start_date) {
      ctx.addIssue({ code: 'custom', path: ['end_date'], message: 'Debe ser posterior al inicio' })
    }
    // §7.3.2: la mesa requiere Presidente, Tesorero y Secretario
    const roleIds = new Set(values.members.map((member) => member.board_role_id))
    const missing = REQUIRED_BOARD_ROLES.filter(([id]) => !roleIds.has(id)).map(
      ([, label]) => label
    )
    if (missing.length > 0) {
      ctx.addIssue({
        code: 'custom',
        path: ['members'],
        message: `Faltan cargos obligatorios: ${missing.join(', ')}`,
      })
    }
    // §7.5: una persona no puede ocupar dos cargos en el mismo periodo
    const seen = new Set<string>()
    values.members.forEach((member, index) => {
      if (seen.has(member.user_id)) {
        ctx.addIssue({
          code: 'custom',
          path: ['members', index, 'user_id'],
          message: 'La persona ya tiene un cargo',
        })
      }
      seen.add(member.user_id)
    })
  })

export type StartPeriodValues = z.infer<typeof startPeriodSchema>

type BoardMemberRoleRef = { id: string; board_role_id: string }

/**
 * §7.3.2: si `memberId` dejara vacante su cargo (al quitarlo, o al reasignarlo a
 * otro cargo) y ese cargo es obligatorio y nadie más lo ocupa, devuelve la
 * etiqueta del cargo que quedaría descubierto; si no, `null`. Fuente única para
 * el bloqueo al quitar (página) y al editar (schema del modal).
 */
export const findUncoveredRequiredRole = (
  members: BoardMemberRoleRef[],
  memberId: string
): string | null => {
  const target = members.find((member) => member.id === memberId)
  if (!target) {
    return null
  }
  const requiredRole = REQUIRED_BOARD_ROLES.find(([id]) => id === target.board_role_id)
  if (!requiredRole) {
    return null
  }
  const stillCovered = members.some(
    (member) => member.id !== memberId && member.board_role_id === target.board_role_id
  )
  return stillCovered ? null : requiredRole[1]
}

interface BoardMemberSchemaContext {
  /** Miembros actuales del periodo (para invariantes de duplicado y cargos obligatorios) */
  members: Array<{ id: string; user_id: string; board_role_id: string }>
  /** Id del miembro que se edita; `null`/`undefined` en alta */
  editingMemberId?: string | null
}

/**
 * Schema del modal de un solo miembro. Con el contexto del periodo:
 * - §7.5: rechaza duplicar a una persona ya con cargo (excluye al que se edita).
 * - §7.3.2: al editar, rechaza cambiar el cargo del último ocupante de un cargo
 *   obligatorio (dejaría a la mesa sin Presidente/Tesorero/Secretario).
 */
export const makeBoardMemberSchema = ({ members, editingMemberId }: BoardMemberSchemaContext) =>
  boardMemberSchema.superRefine((values, ctx) => {
    // §7.5: persona no duplicada
    const takenUserIds = members
      .filter((member) => member.id !== editingMemberId)
      .map((member) => member.user_id)
    if (values.user_id && takenUserIds.includes(values.user_id)) {
      ctx.addIssue({
        code: 'custom',
        path: ['user_id'],
        message: 'La persona ya tiene un cargo en este periodo',
      })
    }

    // §7.3.2: al editar, no vaciar un cargo obligatorio cambiando de rol
    if (editingMemberId) {
      const editing = members.find((member) => member.id === editingMemberId)
      const changingRole = editing !== undefined && values.board_role_id !== editing.board_role_id
      if (changingRole && findUncoveredRequiredRole(members, editingMemberId) !== null) {
        ctx.addIssue({
          code: 'custom',
          path: ['board_role_id'],
          message: REQUIRED_BOARD_ROLES_MESSAGE,
        })
      }
    }
  })
