import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import type { NotificationTemplate } from '@/modules/configuration/services/ConfigurationResource'
import type { UseMutationResult } from '@tanstack/react-query'
import { useState } from 'react'

const CARD = 'rounded-[14px] border border-border bg-card p-5 shadow-sm'

interface Props {
  templates: NotificationTemplate[]
  canUpdate: boolean
  templatesMutation: UseMutationResult<unknown, Error, NotificationTemplate[]>
  restoreTemplatesMutation: UseMutationResult<unknown, Error, undefined>
}

export function NotificationTemplatesSection({ templates, canUpdate, templatesMutation, restoreTemplatesMutation }: Props) {
  const [templateEdits, setTemplateEdits] = useState<Record<string, Partial<NotificationTemplate>>>({})

  const templatesForm: NotificationTemplate[] = templates.map((t) => ({ ...t, ...templateEdits[t.slug] }))

  const editTemplate = (slug: string, patch: Partial<NotificationTemplate>) =>
    setTemplateEdits((prev) => ({ ...prev, [slug]: { ...prev[slug], ...patch } }))

  const saveTemplates = () => templatesMutation.mutate(templatesForm, { onSuccess: () => setTemplateEdits({}) })
  const restoreTemplates = () => restoreTemplatesMutation.mutate(undefined, { onSuccess: () => setTemplateEdits({}) })

  return (
    <div className={CARD}>
      {templatesForm.length === 0 ? (
        <p className="py-6 text-center text-[13px] text-[var(--ink-3)]">Sin plantillas configuradas.</p>
      ) : (
        <FieldGroup>
          <div className="space-y-4">
            {templatesForm.map((tpl) => (
              <div key={tpl.slug} className="rounded-[12px] border border-border p-4">
                <div className="mb-2 flex items-center justify-between gap-3">
                  <Input
                    value={tpl.title ?? ''}
                    onChange={(e) => editTemplate(tpl.slug, { title: e.target.value })}
                    placeholder="Título de la notificación"
                    className="max-w-sm font-medium"
                    disabled={!canUpdate || templatesMutation.isPending}
                  />
                  <label className="flex flex-none items-center gap-2 text-[12.5px] text-[var(--ink-3)]">
                    <Switch
                      checked={tpl.is_active ?? true}
                      onCheckedChange={(c) => editTemplate(tpl.slug, { is_active: c })}
                      disabled={!canUpdate || templatesMutation.isPending}
                    />
                    Activa
                  </label>
                </div>
                <p className="mb-1 text-[11px] uppercase tracking-[0.06em] text-[var(--ink-4)]">{tpl.slug.replace('notification_template.', '')}</p>
                <Textarea
                  rows={3}
                  value={tpl.body}
                  onChange={(e) => editTemplate(tpl.slug, { body: e.target.value })}
                  className="resize-none"
                  disabled={!canUpdate || templatesMutation.isPending}
                />
              </div>
            ))}
          </div>
          {canUpdate && (
            <div className="flex justify-between border-t border-[var(--line-2)] pt-4">
              <Button
                variant="outline" className="rounded-full"
                onClick={restoreTemplates}
                disabled={restoreTemplatesMutation.isPending || templatesMutation.isPending}
              >
                Restaurar por defecto
              </Button>
              <Button
                className="min-w-[120px] rounded-full"
                onClick={saveTemplates}
                disabled={templatesMutation.isPending || Object.keys(templateEdits).length === 0}
              >
                {templatesMutation.isPending ? 'Guardando…' : 'Guardar'}
              </Button>
            </div>
          )}
        </FieldGroup>
      )}
    </div>
  )
}
