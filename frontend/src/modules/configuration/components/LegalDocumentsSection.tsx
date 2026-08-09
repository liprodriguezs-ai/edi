import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import {
  LEGAL_TYPES,
  type LegalDocument,
} from '@/modules/configuration/services/ConfigurationResource'
import type { UseMutationResult } from '@tanstack/react-query'
import { useState, type ChangeEvent } from 'react'

const CARD = 'rounded-[14px] border border-border bg-card p-5 shadow-sm'

interface Props {
  legalDocuments: LegalDocument[]
  canUpdate: boolean
  uploadLegalMutation: UseMutationResult<unknown, Error, { payload: { type: string; title?: string; is_visible_to_residents?: boolean }; file: File }>
}

export function LegalDocumentsSection({ legalDocuments, canUpdate, uploadLegalMutation }: Props) {
  const [legalType, setLegalType] = useState<string>(LEGAL_TYPES[0].value)
  const [legalTitle, setLegalTitle] = useState('')
  const [legalVisible, setLegalVisible] = useState(true)

  const onPickLegal = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    if (file) {
      uploadLegalMutation.mutate(
        { payload: { type: legalType, title: legalTitle || undefined, is_visible_to_residents: legalVisible }, file },
        { onSuccess: () => setLegalTitle('') },
      )
    }
    event.target.value = ''
  }

  return (
    <div className={CARD}>
      <FieldGroup>
        {canUpdate && (
          <div className="grid grid-cols-12 items-end gap-4 border-b border-[var(--line-2)] pb-4">
            <Field className="col-span-12 md:col-span-4">
              <FieldLabel htmlFor="legal-type">Tipo de documento</FieldLabel>
              <Select value={legalType} onValueChange={setLegalType} disabled={uploadLegalMutation.isPending}>
                <SelectTrigger id="legal-type" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {LEGAL_TYPES.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}
                </SelectContent>
              </Select>
            </Field>
            <Field className="col-span-12 md:col-span-4">
              <FieldLabel htmlFor="legal-title">Título (opcional)</FieldLabel>
              <Input id="legal-title" value={legalTitle} onChange={(e) => setLegalTitle(e.target.value)} disabled={uploadLegalMutation.isPending} />
            </Field>
            <Field className="col-span-12 md:col-span-4">
              <label className="mb-2 flex items-center gap-2 text-[13px]">
                <Switch checked={legalVisible} onCheckedChange={setLegalVisible} disabled={uploadLegalMutation.isPending} />
                Visible a residentes
              </label>
              <label className="cursor-pointer rounded-full border border-border px-3.5 py-2 text-center text-[13px] font-medium hover:border-[var(--ink-3)]">
                {uploadLegalMutation.isPending ? 'Subiendo…' : 'Subir PDF / imagen'}
                <input type="file" accept=".pdf,image/*" className="hidden" onChange={onPickLegal} disabled={uploadLegalMutation.isPending} />
              </label>
            </Field>
          </div>
        )}

        {legalDocuments.length === 0 ? (
          <p className="py-6 text-center text-[13px] text-[var(--ink-3)]">Sin documentos legales cargados.</p>
        ) : (
          <div className="space-y-2">
            {legalDocuments.map((doc) => (
              <div key={doc.id} className="flex items-center gap-3 rounded-[10px] border border-border px-4 py-2.5 text-[13px]">
                <div className="min-w-0 flex-1">
                  <p className="font-medium">{doc.title ?? LEGAL_TYPES.find((t) => t.value === doc.type)?.label ?? doc.type}</p>
                  <p className="text-[11.5px] text-[var(--ink-3)]">
                    {LEGAL_TYPES.find((t) => t.value === doc.type)?.label ?? doc.type}
                    {doc.version ? ` · v${doc.version}` : ''}
                    {doc.is_visible_to_residents ? ' · visible a residentes' : ''}
                  </p>
                </div>
                {doc.is_current && <span className="rounded-full bg-[var(--green-bg)] px-2 py-0.5 text-[11px] text-[var(--green-deep)]">Vigente</span>}
                {doc.file_url && (
                  <a href={doc.file_url} target="_blank" rel="noreferrer" className="rounded-full border border-border px-3 py-1 text-[12px] hover:border-[var(--ink-3)]">
                    Ver
                  </a>
                )}
              </div>
            ))}
          </div>
        )}
      </FieldGroup>
    </div>
  )
}
