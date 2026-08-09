# Billing Catalog Integrity Specification

## Purpose

The plan catalog must stay purchasable across re-seeds, degrade loudly when a plan has no billable price, and route the `a_medida` plan through contact-sales with manual activation.

---

## Requirements

### Requirement: Re-seeding preserves Stripe identifiers

Re-running `PlanModuleMatrixSeeder` against a database already synced with Stripe MUST NOT clear `stripe_price_id` or `stripe_product_id`. (Today `upsertPrice()` nulls both on every re-seed — `PlanModuleMatrixSeeder.php:230-245` — and `start.sh:419` runs `db:seed`, so a routine re-seed silently kills all signups.)

#### Scenario: Seed after sync keeps ids

- GIVEN a database where `billing:sync-stripe` populated all Stripe ids
- WHEN `db:seed` runs again
- THEN every `stripe_price_id` / `stripe_product_id` retains its value AND checkout still succeeds

### Requirement: Plans without a billable price fail loudly, not silently

A plan whose prices lack a `stripe_price_id` MUST be surfaced by the catalog as not purchasable, distinguishable from deliberate contact-sales plans (today `is_contact_sales` derives only from the absence of price rows — `PlanPricingService.php:428-429`). If checkout is attempted anyway, the 422 `no_billable_price` failure MUST reach the user as an actionable, distinguishable error — not the current generic-toast dead end (`useBillingQuoter.ts:181-212` ignores the code).

#### Scenario: Unpurchasable plan in catalog

- GIVEN a plan with price rows but no `stripe_price_id`
- WHEN the catalog is built
- THEN that plan is marked not purchasable

#### Scenario: Checkout failure is actionable

- GIVEN checkout is attempted for such a plan
- WHEN the API returns 422 `no_billable_price`
- THEN the UI shows a specific message identifying the failure AND pointing to a next step (contact support/sales)

### Requirement: a_medida is contact-sales with manual activation

The `a_medida` plan MUST be shown in the catalog with a contact-sales CTA and MUST NOT offer self-serve checkout. Activation is manual: a super admin grants entitlements directly through the existing provisioning path — no Stripe subscription, no card, and NO Stripe trial for these negotiated clusters.

#### Scenario: Catalog shows sales CTA

- GIVEN the public plan catalog
- WHEN `a_medida` renders
- THEN it shows a contact-sales CTA AND no checkout action

#### Scenario: Manual activation unblocks without Stripe

- GIVEN a blocked negotiated cluster
- WHEN a super admin grants its entitlements via the provisioning path
- THEN the cluster unblocks with non-Stripe entitlements AND no Stripe subscription, card, or trial exists
