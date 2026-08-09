import type { SileoOptions, SileoPosition } from 'sileo'
import { sileo } from 'sileo'

const DEFAULTS: Partial<SileoOptions> = {
  fill: 'black',
  styles: {
    title: 'text-white!',
    description: 'text-white/75!',
    badge: 'bg-white/20!',
    button: 'bg-white/10!',
  },
  duration: 7000,
}

// BUG FIX (E2E QA round 3 — sileo toast blocking Dialog/Sheet footer buttons):
// the app's global <Toaster> mounts at `bottom-right` (main.tsx), and this
// project's Sheets slide in from `side='right'` with their action footer
// pinned to the bottom of that panel — i.e. the SAME screen corner. Sileo's
// own CSS already keeps its *viewport* (the fixed corner container) at
// `pointer-events:none` with only the individual toast box at `auto` (also
// pinned explicitly in index.css) — that pattern only stops the EMPTY padding
// around a toast from blocking clicks. When the toast box itself physically
// overlaps a footer button (verified live: an error toast's rect sat exactly
// on top of "Enviar pago"), the toast still MUST keep `pointer-events:auto`
// to stay interactive/dismissable, so no CSS selector can make that
// overlapping click reach the button underneath — that's the actual root
// cause, not a wrong selector. Error/validation toasts are the ones that fire
// WHILE the user is mid-interaction with a form (right after clicking
// submit), so only they get pushed to `top-center`, away from any
// bottom-anchored footer; success/info/warning keep the default corner.
const ERROR_POSITION_OVERRIDE: Partial<SileoOptions> = {
  position: 'top-center',
}

type ToastType = 'success' | 'error' | 'info' | 'warning'

interface PromiseOptions<T = unknown> {
  loading: SileoOptions
  success: SileoOptions | ((_data: T) => SileoOptions)
  error: SileoOptions | ((_err: unknown) => SileoOptions)
  action?: SileoOptions | ((_data: T) => SileoOptions)
  position?: SileoPosition
}

const TOAST_METHODS = {
  success: sileo.success,
  error: sileo.error,
  warning: sileo.warning,
  info: sileo.info,
} as const

function withDefaults(opts: SileoOptions): SileoOptions {
  return { ...DEFAULTS, ...opts }
}

// Merges DEFAULTS + the error-only position override, letting an explicit
// `opts.position` from the caller win (spread order: override, then opts).
function withErrorDefaults(opts: SileoOptions): SileoOptions {
  return { ...DEFAULTS, ...ERROR_POSITION_OVERRIDE, ...opts }
}

function notify(type: ToastType, opts: SileoOptions): string {
  if (type === 'error') return sileo.error(withErrorDefaults(opts))
  return TOAST_METHODS[type](withDefaults(opts))
}

function success(titleOrOpts: string | SileoOptions, description?: string): string {
  const opts = typeof titleOrOpts === 'string' ? { title: titleOrOpts, description } : titleOrOpts
  return sileo.success(withDefaults(opts))
}

function error(titleOrOpts: string | SileoOptions, description?: string): string {
  const opts = typeof titleOrOpts === 'string' ? { title: titleOrOpts, description } : titleOrOpts
  return sileo.error(withErrorDefaults(opts))
}

function warning(titleOrOpts: string | SileoOptions, description?: string): string {
  const opts = typeof titleOrOpts === 'string' ? { title: titleOrOpts, description } : titleOrOpts
  return sileo.warning(withDefaults(opts))
}

function info(titleOrOpts: string | SileoOptions, description?: string): string {
  const opts = typeof titleOrOpts === 'string' ? { title: titleOrOpts, description } : titleOrOpts
  return sileo.info(withDefaults(opts))
}

function action(opts: SileoOptions): string {
  return sileo.action(withDefaults(opts))
}

function promise<T>(p: Promise<T> | (() => Promise<T>), opts: PromiseOptions<T>): Promise<T> {
  return sileo.promise(p, opts)
}

function dismiss(id: string): void {
  sileo.dismiss(id)
}

function clear(position?: SileoPosition): void {
  sileo.clear(position)
}

export const toast = {
  notify,
  success,
  error,
  warning,
  info,
  action,
  promise,
  dismiss,
  clear,
}

export type { PromiseOptions, SileoOptions }
