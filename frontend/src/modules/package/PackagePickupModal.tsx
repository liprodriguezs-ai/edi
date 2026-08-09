import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import type { IPackage, IVerifyCodePackage, IVerifyCodeResult } from '@/modules/package/schema'
import { PackageResource } from '@/modules/package/services/PackageResource'
import { PackageStatusBadge } from '@/modules/package/components/PackageStatusBadge'
import { QrScannerView } from '@/modules/package/components/QrScannerView'
import { SignaturePadCanvas } from '@/modules/package/components/SignaturePadCanvas'
import type { SignaturePadHandle } from '@/modules/package/components/SignaturePadCanvas'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Check, Package2, QrCode, ScanLine } from 'lucide-react'
import { useMemo, useRef, useState } from 'react'

// UUID constant — must match backend PackageStatus::READY
const PACKAGE_STATUS_READY = 'a2001001-0000-4000-8000-000000000002'

// ─── Types ────────────────────────────────────────────────────────────────────

type Step = 'scan' | 'select' | 'confirm'

interface PolicySettings {
  requires_pickup_signature: boolean
  requires_pickup_evidence: boolean
  require_pickup_code: boolean
}

// ─── Step 1: Scan code ────────────────────────────────────────────────────────

interface ScanStepProps {
  /** Callback receives the verify result AND the code string that was accepted. */
  onFound: (_result: IVerifyCodeResult, _code: string) => void
  onClose: () => void
}

function ScanStep({ onFound, onClose }: ScanStepProps) {
  const [code, setCode] = useState('')
  const [showScanner, setShowScanner] = useState(false)
  const [cameraError, setCameraError] = useState<string | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  // Focus the text input on mount so a HID barcode scanner can type directly.
  const handleRef = (el: HTMLInputElement | null) => {
    if (el) {
      inputRef.current = el
      el.focus()
    }
  }

  const verifyMutation = useMutation({
    mutationFn: (c: string) => PackageResource.verifyCode(c.trim().toUpperCase()),
    // `variables` = the argument passed to mutate(); used to capture the verified code.
    onSuccess: (result, variables) => {
      if (!result.packages || result.packages.length === 0) {
        toast.error('No se encontraron paquetes pendientes para ese código')
        return
      }
      onFound(result, variables)
    },
    onError: (error) => {
      const msg =
        error instanceof ApiError && error.status === 422
          ? 'Código inválido o sin paquetes pendientes'
          : formatApiErrorMessage(error instanceof ApiError ? error : new ApiError('Error', 500)).title
      toast.error(msg)
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (code.trim()) verifyMutation.mutate(code)
  }

  // Called by QrScannerView on a successful camera decode.
  // Closes the camera view, auto-fills the code field, and triggers verify.
  const handleQrScan = (decodedText: string) => {
    const normalized = decodedText.trim().toUpperCase()
    setShowScanner(false)
    setCode(normalized)
    verifyMutation.mutate(normalized)
  }

  const handleCameraError = () => {
    setShowScanner(false)
    setCameraError('No se pudo acceder a la cámara. Verifica los permisos del navegador e intenta de nuevo.')
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-5">
      <RequiredFieldsLegend />
      <div className="flex flex-col items-center gap-3 rounded-[12px] bg-[var(--surface-2)] py-6 px-4 text-center">
        <span className="grid size-12 place-items-center rounded-xl bg-[var(--green-bg)] text-[var(--green-deep)]">
          <ScanLine className="size-6" />
        </span>
        <p className="text-[13px] text-muted-foreground">
          Escanea el QR del residente con la cámara o ingresa el código manualmente (formato XXXX-XXXX).
        </p>
      </div>

      {/* Camera QR scanner — only rendered while showScanner=true to avoid leaked streams */}
      {showScanner ? (
        <div className="space-y-2">
          <QrScannerView onScan={handleQrScan} onPermissionDenied={handleCameraError} />
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="w-full"
            onClick={() => setShowScanner(false)}
          >
            Cancelar escaneo
          </Button>
        </div>
      ) : (
        <Button
          type="button"
          variant="outline"
          className="flex w-full items-center justify-center gap-2"
          onClick={() => {
            setCameraError(null)
            setShowScanner(true)
          }}
          disabled={verifyMutation.isPending}
        >
          <QrCode className="size-4" />
          Escanear QR
        </Button>
      )}

      {/* Camera permission error — shown after QrScannerView calls onPermissionDenied */}
      {cameraError && (
        <p className="rounded-[8px] bg-destructive/10 px-3 py-2 text-[12.5px] text-destructive">
          {cameraError}
        </p>
      )}

      {/* Manual text input — always visible as a fallback */}
      <Field>
        <FieldLabel htmlFor="pickup-code" required>
          Código de retiro
        </FieldLabel>
        <Input
          ref={handleRef}
          id="pickup-code"
          placeholder="ABCD-1234"
          value={code}
          onChange={(e) => setCode(e.target.value.toUpperCase())}
          className="font-mono text-center text-lg tracking-widest"
          maxLength={9}
          aria-label="Código de retiro del paquete"
        />
        <FieldDescription>El residente encuentra su código en la app Kivo7.</FieldDescription>
      </Field>

      <DialogFooter>
        <Button type="button" variant="outline" onClick={onClose}>
          Cancelar
        </Button>
        <Button type="submit" disabled={!code.trim() || verifyMutation.isPending}>
          {verifyMutation.isPending ? 'Verificando…' : 'Verificar'}
        </Button>
      </DialogFooter>
    </form>
  )
}

// ─── Step 2: Select packages ──────────────────────────────────────────────────

interface SelectStepProps {
  result: IVerifyCodeResult
  onSelected: (_ids: string[]) => void
  onBack: () => void
}

function SelectStep({ result, onSelected, onBack }: SelectStepProps) {
  const [selectedIds, setSelectedIds] = useState<string[]>(
    result.packages.map((p) => p.id),
  )

  const toggle = (id: string) => {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
    )
  }

  const unitLabel =
    result.unit?.full_identifier ?? result.unit?.number ?? 'Unidad desconocida'

  return (
    <div className="space-y-4">
      <div className="rounded-[10px] border border-border bg-[var(--surface-2)] px-4 py-3">
        <p className="text-[12px] text-muted-foreground">Unidad</p>
        <p className="text-sm font-semibold">{unitLabel}</p>
      </div>

      <p className="text-[13px] text-muted-foreground">
        Selecciona los paquetes que el residente retira en este momento.
      </p>

      <ul className="space-y-2" role="list">
        {result.packages.map((pkg) => (
          <li
            key={pkg.id}
            className={`flex cursor-pointer items-start gap-3 rounded-[10px] border p-3 transition-colors ${
              selectedIds.includes(pkg.id)
                ? 'border-[var(--green-deep)] bg-[var(--green-bg)]'
                : 'border-border bg-card hover:bg-[var(--surface-2)]'
            }`}
            onClick={() => toggle(pkg.id)}
            role="checkbox"
            aria-checked={selectedIds.includes(pkg.id)}
            tabIndex={0}
            onKeyDown={(e) => { if (e.key === ' ' || e.key === 'Enter') toggle(pkg.id) }}
          >
            <Checkbox
              checked={selectedIds.includes(pkg.id)}
              onCheckedChange={() => toggle(pkg.id)}
              aria-hidden
              tabIndex={-1}
            />
            <div className="flex-1 text-[13px]">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">
                  {pkg.package_carrier?.name ?? pkg.carrier?.name ?? pkg.carrier_other ?? 'Sin transportista'}
                </span>
                <PackageStatusBadge
                  statusName={pkg.package_status?.name ?? pkg.status?.name ?? null}
                  statusKey={null}
                  statusColor={null}
                />
              </div>
              {pkg.tracking_number && (
                <span className="mt-0.5 block font-mono text-[11.5px] text-muted-foreground">
                  {pkg.tracking_number}
                </span>
              )}
              {pkg.description && (
                <span className="mt-0.5 block text-[12px] text-muted-foreground">{pkg.description}</span>
              )}
              {pkg.received_at && (
                <span className="mt-0.5 block text-[11.5px] text-muted-foreground">
                  Recibido: {displayFormatDate(pkg.received_at, 'mediumDate')}
                </span>
              )}
            </div>
          </li>
        ))}
      </ul>

      <DialogFooter>
        <Button type="button" variant="outline" onClick={onBack}>
          Atrás
        </Button>
        <Button
          type="button"
          onClick={() => onSelected(selectedIds)}
          disabled={selectedIds.length === 0}
        >
          Continuar ({selectedIds.length})
        </Button>
      </DialogFooter>
    </div>
  )
}

// ─── Step 3: Confirm (proxy + evidence) ──────────────────────────────────────

interface ConfirmStepProps {
  packageIds: string[]
  policy: PolicySettings
  /** Code that was verified in the scan step — forwarded to handover for backend validation. */
  verifiedCode?: string | null
  onConfirmed: () => void
  onBack: () => void
  onClose: () => void
}

function ConfirmStep({ packageIds, policy, verifiedCode, onConfirmed, onBack, onClose }: ConfirmStepProps) {
  const [proxyName, setProxyName] = useState('')
  const [proxyRelationship, setProxyRelationship] = useState('')
  const [isProxy, setIsProxy] = useState(false)
  const evidenceRef = useRef<HTMLInputElement>(null)
  // Canvas signature pad ref — exposes isEmpty / clear / toBlob
  const padRef = useRef<SignaturePadHandle>(null)
  const [hasDrawn, setHasDrawn] = useState(false)
  // File-upload fallback for signature (secondary UX)
  const [showSignatureFileUpload, setShowSignatureFileUpload] = useState(false)
  const [signatureFile, setSignatureFile] = useState<File | null>(null)
  const [evidenceFile, setEvidenceFile] = useState<File | null>(null)

  const mutation = useMutation({
    // async so we can await canvas.toBlob before building the FormData
    mutationFn: async () => {
      const form = new FormData()
      packageIds.forEach((id) => form.append('package_ids[]', id))
      if (isProxy && proxyName) form.append('pickup_proxy_name', proxyName)
      if (isProxy && proxyRelationship) form.append('pickup_proxy_relationship', proxyRelationship)
      // Forward the verified pickup code so the backend can validate it.
      if (verifiedCode) form.append('pickup_code', verifiedCode)

      if (needsSignature) {
        const pad = padRef.current
        let signatureAppended = false
        if (pad && !pad.isEmpty()) {
          // Export canvas drawing as a PNG File
          const blob = await new Promise<Blob | null>((resolve) => pad.toBlob(resolve))
          if (blob) {
            form.append(
              'pickup_signature',
              new File([blob], 'firma.png', { type: 'image/png' }),
            )
            signatureAppended = true
          }
        } else if (signatureFile) {
          // Fallback: user uploaded a photo of their signature
          form.append('pickup_signature', signatureFile)
          signatureAppended = true
        }
        // Safety net: the submit button enables on the reactive `hasDrawn` flag, which can
        // desync from the pad's real content. Never let a required signature slip through empty.
        if (!signatureAppended) {
          throw new ApiError('La firma de conformidad es obligatoria para esta entrega.', 422)
        }
      }

      if (evidenceFile) form.append('pickup_evidence', evidenceFile)
      return PackageResource.handover(form)
    },
    onSuccess: () => {
      toast.success(
        `${packageIds.length} paquete${packageIds.length !== 1 ? 's' : ''} entregado${packageIds.length !== 1 ? 's' : ''}`,
      )
      onConfirmed()
    },
    onError: (error) => {
      const { title } = formatApiErrorMessage(
        error instanceof ApiError ? error : new ApiError('Error', 500),
      )
      toast.error(title)
    },
  })

  // Policy enforcement: show signature pad when required by admin config or when a proxy picks up.
  const needsSignature = policy.requires_pickup_signature || isProxy
  const needsEvidence = policy.requires_pickup_evidence

  // Signature is provided when the canvas has content OR a file has been uploaded.
  const signatureProvided = hasDrawn || signatureFile !== null

  const canSubmit =
    (!needsSignature || signatureProvided) &&
    (!needsEvidence || evidenceFile !== null) &&
    (!isProxy || proxyName.trim() !== '')

  return (
    <div className="space-y-5">
      {(isProxy || needsSignature || needsEvidence) && <RequiredFieldsLegend />}
      <div className="rounded-[10px] border border-border bg-[var(--surface-2)] px-4 py-3">
        <div className="flex items-center gap-2 text-[13px]">
          <Package2 className="size-4 text-muted-foreground" />
          <span className="font-medium">
            {packageIds.length} paquete{packageIds.length !== 1 ? 's' : ''} a entregar
          </span>
        </div>
      </div>

      {/* Proxy pickup */}
      <Field>
        <label className="flex cursor-pointer items-center gap-2 text-sm font-medium">
          <Checkbox
            checked={isProxy}
            onCheckedChange={(checked) => setIsProxy(checked === true)}
          />
          Retira un tercero (no el residente)
        </label>
      </Field>

      {isProxy && (
        <div className="grid grid-cols-2 gap-3">
          <Field>
            <FieldLabel htmlFor="pc-proxy-name" required>
              Nombre del tercero
            </FieldLabel>
            <Input
              id="pc-proxy-name"
              placeholder="Nombre completo"
              value={proxyName}
              onChange={(e) => setProxyName(e.target.value)}
              maxLength={150}
            />
          </Field>
          <Field>
            <FieldLabel htmlFor="pc-proxy-rel">Parentesco / relación</FieldLabel>
            <Input
              id="pc-proxy-rel"
              placeholder="Familiar, empleado…"
              value={proxyRelationship}
              onChange={(e) => setProxyRelationship(e.target.value)}
              maxLength={100}
            />
          </Field>
        </div>
      )}

      {/* Signature pad — canvas draw pad with file-upload fallback */}
      {needsSignature && (
        <Field>
          <FieldLabel required={policy.requires_pickup_signature || isProxy}>
            Firma de conformidad
          </FieldLabel>

          {/* Primary UX: draw on canvas */}
          <div className="overflow-hidden rounded-[10px] border border-border bg-white">
            <SignaturePadCanvas
              ref={padRef}
              onBegin={() => setHasDrawn(true)}
              className="h-[160px] w-full"
            />
          </div>

          <div className="flex items-center justify-between">
            <p className="text-[11.5px] text-muted-foreground">
              Firme con el dedo o el puntero en el área de arriba.
            </p>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-7 px-2 text-[12px]"
              onClick={() => {
                padRef.current?.clear()
                setHasDrawn(false)
              }}
              disabled={!hasDrawn}
            >
              Limpiar
            </Button>
          </div>

          {/* Secondary UX: file upload fallback */}
          {showSignatureFileUpload ? (
            <div className="mt-1 space-y-1">
              <input
                id="pc-signature-file"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-full file:border-0 file:bg-[var(--surface-2)] file:px-3 file:py-1.5 file:text-xs file:font-medium"
                onChange={(e) => setSignatureFile(e.target.files?.[0] ?? null)}
              />
              {signatureFile && (
                <p className="text-[11.5px] text-muted-foreground">{signatureFile.name}</p>
              )}
            </div>
          ) : (
            <button
              type="button"
              className="mt-1 text-[12px] text-muted-foreground underline underline-offset-2 hover:text-foreground"
              onClick={() => setShowSignatureFileUpload(true)}
            >
              o sube una imagen de firma
            </button>
          )}

          {hasDrawn && (
            <p className="text-[11.5px] text-[var(--green-deep)]">Firma capturada</p>
          )}
        </Field>
      )}

      {needsEvidence && (
        <Field>
          <FieldLabel htmlFor="pc-evidence" required>
            Evidencia de entrega
          </FieldLabel>
          <input
            ref={evidenceRef}
            id="pc-evidence"
            type="file"
            accept="image/jpeg,image/png,image/webp,application/pdf"
            className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-full file:border-0 file:bg-[var(--surface-2)] file:px-3 file:py-1.5 file:text-xs file:font-medium"
            onChange={(e) => setEvidenceFile(e.target.files?.[0] ?? null)}
          />
          {evidenceFile && (
            <p className="text-[11.5px] text-muted-foreground">{evidenceFile.name}</p>
          )}
        </Field>
      )}

      <DialogFooter>
        <Button type="button" variant="outline" onClick={onBack} disabled={mutation.isPending}>
          Atrás
        </Button>
        <Button type="button" variant="outline" onClick={onClose} disabled={mutation.isPending}>
          Cancelar
        </Button>
        <Button
          type="button"
          onClick={() => mutation.mutate()}
          disabled={!canSubmit || mutation.isPending}
        >
          {mutation.isPending ? 'Entregando…' : (
            <>
              <Check className="mr-1.5 size-4" />
              Confirmar entrega
            </>
          )}
        </Button>
      </DialogFooter>
    </div>
  )
}

// ─── Inner stateful component (mounts fresh each time the modal opens) ────────
//
// Holds step/verifyResult/selectedIds. Mounted when isOpen=true; unmounted on
// close so React naturally resets all state — no useEffect-setState needed.
// The `key` prop in the parent forces a fresh mount when initialPackage changes.

interface PackagePickupStepsProps {
  initialPackage: IPackage | null | undefined
  policy: PolicySettings
  onClose: () => void
  onHandedOver: () => void
}

function PackagePickupSteps({ initialPackage, policy, onClose, onHandedOver }: PackagePickupStepsProps) {
  const { require_pickup_code: requirePickupCode } = policy

  // When requirePickupCode=true the guard MUST scan first, even for a specific package.
  // When false with initialPackage we skip directly to select and load all unit packages.
  const initialStep: Step = !requirePickupCode && initialPackage ? 'select' : 'scan'

  const [step, setStep] = useState<Step>(initialStep)
  // Result from the scan step — null when bypassing scan via initialPackage.
  const [scannedResult, setScannedResult] = useState<IVerifyCodeResult | null>(null)
  const [selectedIds, setSelectedIds] = useState<string[]>([])
  // Code used during verification — forwarded to handover payload so the backend can audit it.
  const [verifiedCode, setVerifiedCode] = useState<string | null>(null)

  // When bypassing the scan step, load ALL ready packages for the unit so the guard
  // can hand over multiple packages in one operation (not just the clicked row).
  const unitPackagesQuery = useQuery({
    queryKey: ['unit-ready-packages', initialPackage?.unit_id],
    queryFn: () =>
      PackageResource.search({
        includes: [{ relation: 'carrier' }, { relation: 'status' }],
        filters: [
          { field: 'unit_id', operator: '=', value: initialPackage!.unit_id },
          { field: 'package_status_id', operator: '=', value: PACKAGE_STATUS_READY },
        ],
        limit: 50,
      }),
    enabled: Boolean(!requirePickupCode && initialPackage),
    staleTime: 30_000,
  })

  // Derive the effective verify result without setState-in-effect:
  // — use scannedResult when the guard went through the scan step
  // — otherwise compute from initialPackage + live unit-packages query
  const verifyResult = useMemo<IVerifyCodeResult | null>(() => {
    if (scannedResult) return scannedResult
    if (!requirePickupCode && initialPackage) {
      const queryPackages = unitPackagesQuery.data?.data ?? []
      const packages: IVerifyCodePackage[] =
        queryPackages.length > 0
          ? queryPackages.map((p) => ({
              id: p.id,
              description: p.description,
              storage_location: p.storage_location,
              received_at: p.received_at,
              tracking_number: p.tracking_number,
              carrier_other: p.carrier_other,
              carrier: p.carrier,
              status: p.status,
            }))
          : [initialPackage as unknown as IVerifyCodePackage]
      return { unit: initialPackage.unit ?? null, packages }
    }
    return null
  }, [scannedResult, requirePickupCode, initialPackage, unitPackagesQuery.data])

  const stepTitles: Record<Step, string> = {
    scan: 'Entregar paquete — Paso 1: Código',
    select: 'Entregar paquete — Paso 2: Seleccionar paquetes',
    confirm: 'Entregar paquete — Paso 3: Confirmar',
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{stepTitles[step]}</DialogTitle>
        {/* Required by Radix for accessibility — screen readers announce this as the dialog description */}
        <DialogDescription className="sr-only">
          Proceso de entrega de paquetes en portería, tres pasos: verificar código, seleccionar paquetes y confirmar entrega.
        </DialogDescription>
      </DialogHeader>

      {step === 'scan' && (
        <ScanStep
          onFound={(result, code) => {
            setScannedResult(result)
            setVerifiedCode(code)
            setStep('select')
          }}
          onClose={onClose}
        />
      )}

      {step === 'select' && verifyResult && (
        <SelectStep
          result={verifyResult}
          onSelected={(ids) => {
            setSelectedIds(ids)
            setStep('confirm')
          }}
          onBack={() => {
            // Only close on back when we were dropped directly to select without a code scan.
            if (!requirePickupCode && initialPackage) {
              onClose()
            } else {
              setStep('scan')
            }
          }}
        />
      )}

      {step === 'confirm' && (
        <ConfirmStep
          packageIds={selectedIds}
          policy={policy}
          verifiedCode={verifiedCode}
          onConfirmed={onHandedOver}
          onBack={() => setStep('select')}
          onClose={onClose}
        />
      )}
    </>
  )
}

// ─── Main modal ───────────────────────────────────────────────────────────────

interface PackagePickupModalProps {
  isOpen: boolean
  /** When pre-populated from the list (clicking "Entregar" on a specific package). */
  initialPackage?: IPackage | null
  onClose: () => void
  onHandedOver: () => void
}

export function PackagePickupModal({
  isOpen,
  initialPackage,
  onClose,
  onHandedOver,
}: PackagePickupModalProps) {
  // Fetch pickup-policy via the dedicated endpoint (GET /package/pickup-policy).
  // This endpoint requires update.package (which guards hold) rather than
  // read.configuration (which they do NOT hold), fixing the 403 for security users.
  const policyQuery = useQuery({
    queryKey: ['package-pickup-policy'],
    queryFn: () => PackageResource.pickupPolicy(),
    enabled: isOpen,
    staleTime: 60_000,
  })

  const policy: PolicySettings = {
    requires_pickup_signature: Boolean(policyQuery.data?.requires_pickup_signature),
    requires_pickup_evidence: Boolean(policyQuery.data?.requires_pickup_evidence),
    // Secure default: when the policy can't be read, force code verification (scan)
    // rather than silently allowing a no-verification handover.
    require_pickup_code: policyQuery.data ? Boolean(policyQuery.data.require_pickup_code) : true,
  }

  return (
    <Dialog open={isOpen} onOpenChange={(open) => { if (!open) onClose() }}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-[520px]">
        {/*
          Render the stateful steps only while the modal is open. When isOpen becomes false
          React unmounts PackagePickupSteps, automatically resetting step/verifyResult/
          selectedIds — no useEffect-setState needed.

          The `key` changes when initialPackage.id changes, forcing a fresh mount so the
          component initializes from the new package without stale state.
        */}
        {isOpen && policyQuery.isLoading && (
          <>
            <DialogHeader>
              <DialogTitle>Entregar paquete</DialogTitle>
            </DialogHeader>
            <div className="py-10 text-center text-[13px] text-muted-foreground">Cargando…</div>
          </>
        )}
        {/*
          Mount the stateful steps ONLY after the pickup-policy has resolved. Mounting
          earlier let initialStep compute from require_pickup_code=false (the loading
          default), so the row-launched "Entregar" opened at the select step and skipped
          the scan gate. Waiting for the policy guarantees the correct initial step.
        */}
        {isOpen && !policyQuery.isLoading && (
          <PackagePickupSteps
            key={initialPackage?.id ?? '__scan__'}
            initialPackage={initialPackage}
            policy={policy}
            onClose={onClose}
            onHandedOver={onHandedOver}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}
