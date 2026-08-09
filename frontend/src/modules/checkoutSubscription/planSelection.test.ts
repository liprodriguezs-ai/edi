/**
 * Tests de la lógica PURA del catálogo de planes. Ya no se sugiere plan por número
 * de unidades (el input se retiró: el precio es fijo por banda y la grilla de planes
 * es el único control), así que lo que se cubre acá es la detección del plan de venta
 * consultiva, el precio del plan y los enlaces de contacto.
 */
import {
  billablePlans,
  contactMailtoHref,
  contactWhatsappHref,
  findContactSalesPlan,
  isContactSalesPlan,
  isPurchasablePlan,
  planPriceFor,
} from '@/modules/checkoutSubscription/planSelection'
import type { IBillingPlan, IPlanCatalog } from '@/modules/checkoutSubscription/schema'
import { describe, expect, it } from 'vitest'

function makePlan(
  key: string,
  min: number | null,
  max: number | null,
  extra: Partial<IBillingPlan> = {}
): IBillingPlan {
  return {
    key,
    name: key,
    suggested_unit_min: min,
    suggested_unit_max: max,
    modules: [],
    ...extra,
  }
}

const basico = makePlan('basico', 1, 25)
const inicial = makePlan('inicial', 26, 60)
const premium = makePlan('premium', 151, 300, {
  prices: { month: { unit_amount: 300000, currency: 'MXN' } },
})
const aMedida = makePlan('a_medida', 301, null, { is_contact_sales: true, prices: {} })

const plans = [basico, inicial, premium, aMedida]

describe('billablePlans', () => {
  it('deja fuera el plan de venta consultiva', () => {
    expect(billablePlans(plans).map((plan) => plan.key)).toEqual(['basico', 'inicial', 'premium'])
  })

  it('devuelve una lista vacía si todos son de venta consultiva', () => {
    expect(billablePlans([aMedida])).toEqual([])
  })
})

describe('isContactSalesPlan / findContactSalesPlan', () => {
  it('trata la clave ausente como false', () => {
    expect(isContactSalesPlan(basico)).toBe(false)
    expect(isContactSalesPlan(null)).toBe(false)
    expect(isContactSalesPlan(aMedida)).toBe(true)
  })

  it('encuentra el plan de venta consultiva del catálogo', () => {
    expect(findContactSalesPlan(plans)?.key).toBe('a_medida')
    expect(findContactSalesPlan([basico, inicial])).toBeNull()
  })
})

describe('isPurchasablePlan', () => {
  it('trata la clave ausente (catálogos viejos/cacheados) como purchasable', () => {
    expect(isPurchasablePlan(basico)).toBe(true)
  })

  it('es false cuando el backend marca el plan explícitamente como no sincronizado', () => {
    const brokenPlan = makePlan('roto', 1, 25, { is_purchasable: false })
    expect(isPurchasablePlan(brokenPlan)).toBe(false)
  })

  it('es true cuando el backend marca el plan como sincronizado', () => {
    const syncedPlan = makePlan('sincronizado', 1, 25, { is_purchasable: true })
    expect(isPurchasablePlan(syncedPlan)).toBe(true)
  })

  it('el plan de venta consultiva NUNCA es purchasable, sin importar la flag', () => {
    expect(isPurchasablePlan(aMedida)).toBe(false)
    expect(isPurchasablePlan(makePlan('a_medida', 301, null, { is_contact_sales: true, is_purchasable: true }))).toBe(
      false
    )
  })

  it('null/undefined es false', () => {
    expect(isPurchasablePlan(null)).toBe(false)
    expect(isPurchasablePlan(undefined)).toBe(false)
  })
})

describe('planPriceFor', () => {
  it('devuelve el precio del plan en el intervalo pedido', () => {
    expect(planPriceFor(premium, 'month')?.unit_amount).toBe(300000)
  })

  it('tolera prices vacío o ausente sin reventar', () => {
    expect(planPriceFor(aMedida, 'month')).toBeNull()
    expect(planPriceFor(premium, 'year')).toBeNull()
    expect(planPriceFor(basico, 'month')).toBeNull()
    expect(planPriceFor(null, 'month')).toBeNull()
  })
})

describe('enlaces de contacto', () => {
  const catalog = (contact: IPlanCatalog['contact']): IPlanCatalog => ({
    currency: 'MXN',
    plans,
    contact,
  })

  it('arma el mailto cuando hay email', () => {
    expect(contactMailtoHref(catalog({ email: 'hola@edi.example.com', whatsapp: null }))).toContain(
      'mailto:hola@edi.example.com'
    )
  })

  it('devuelve null sin datos de contacto', () => {
    expect(contactMailtoHref(catalog(undefined))).toBeNull()
    expect(contactWhatsappHref(catalog({ email: 'hola@edi.example.com', whatsapp: null }))).toBeNull()
    expect(contactWhatsappHref(null)).toBeNull()
  })

  it('normaliza el número de WhatsApp', () => {
    expect(contactWhatsappHref(catalog({ email: null, whatsapp: '+52 (55) 1234-5678' }))).toContain(
      'https://wa.me/525512345678'
    )
  })
})
