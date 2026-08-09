import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { useProjectComments } from '@/modules/communityProject/hooks/useProjectComments'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { displayInitials } from '@/shared/utils/displayInitials'
import { EyeOff } from 'lucide-react'
import { useState } from 'react'

interface ProjectCommentsProps {
  projectId: string
}

/**
 * Hilo de comentarios de un proyecto comunitario (§11.6). Lista plana: cualquier
 * residente con create.project_comment puede comentar; la mesa
 * (update.project_comment) puede ocultar comentarios (no se borran).
 */
export function ProjectComments({ projectId }: ProjectCommentsProps) {
  const [body, setBody] = useState('')
  const canModerate = usePermissions('update.project_comment')
  const { comments, isLoading, postMutation, hideMutation } = useProjectComments(projectId)

  const post = () => postMutation.mutate(body.trim(), { onSuccess: () => setBody('') })

  return (
    <div className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
      <div className="border-b border-[var(--line-2)] px-4 py-3.5">
        <h3 className="text-sm font-semibold">Comentarios ({comments.length})</h3>
      </div>

      <PermissionGuard permission="create.project_comment">
        <div className="space-y-2 border-b border-[var(--line-2)] p-4">
          <Textarea
            rows={2}
            value={body}
            onChange={(e) => setBody(e.target.value)}
            placeholder="Escribe un comentario…"
            className="resize-none"
            disabled={postMutation.isPending}
          />
          <div className="flex justify-end">
            <Button
              size="sm" className="rounded-full"
              onClick={post}
              disabled={postMutation.isPending || body.trim().length === 0}
            >
              {postMutation.isPending ? 'Publicando…' : 'Comentar'}
            </Button>
          </div>
        </div>
      </PermissionGuard>

      {isLoading ? (
        <p className="px-4 py-8 text-center text-[13px] text-[var(--ink-3)]">Cargando comentarios…</p>
      ) : comments.length === 0 ? (
        <p className="px-4 py-8 text-center text-[13px] text-[var(--ink-3)]">Sé el primero en comentar.</p>
      ) : (
        <ul className="divide-y divide-[var(--line-2)]">
          {comments.map((comment) => (
            <li key={comment.id} className={`flex gap-3 px-4 py-3 ${comment.hidden ? 'opacity-50' : ''}`}>
              <span className="grid size-8 flex-none place-items-center rounded-full bg-[var(--green-bg)] text-[11px] font-semibold text-[var(--green)]">
                {displayInitials(comment.user?.name ?? '?')}
              </span>
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                  <span className="text-[13px] font-semibold">{comment.user?.name ?? 'Usuario'}</span>
                  <span className="text-[11.5px] text-[var(--ink-4)]">{displayFormatDate(comment.created_at)}</span>
                  {comment.hidden && <span className="rounded bg-[var(--surface-2)] px-1.5 text-[10.5px] text-[var(--ink-3)]">Oculto</span>}
                </div>
                <p className="mt-0.5 whitespace-pre-wrap text-[13px] text-[var(--ink-1)]">{comment.body}</p>
              </div>
              {canModerate && !comment.hidden && (
                <Button
                  variant="ghost" size="icon" className="size-7 flex-none text-[var(--ink-3)]"
                  onClick={() => hideMutation.mutate(comment)}
                  disabled={hideMutation.isPending}
                  aria-label="Ocultar comentario"
                  title="Ocultar comentario"
                >
                  <EyeOff className="size-3.5" />
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
