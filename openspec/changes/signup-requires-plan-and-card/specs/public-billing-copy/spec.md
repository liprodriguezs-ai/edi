# Public Billing Copy Specification

## Purpose

Every public and in-app surface must state the real offer — 30-day trial, card required, auto-charge on day 31 — and signup legal links must resolve to the real pages.

---

## Requirements

### Requirement: All surfaces state 30 days and card required

All public copy MUST state that the trial lasts 30 days AND requires a card; NO surface may still claim a card-free trial ("sin tarjeta"). Covered surfaces and their traps:

| Surface | Trap |
|---|---|
| Landing pricing (`Pricing.astro`) | Trial copy at `:630` lives inside a client `<script>` — updating only the static markup makes the text revert on hydration |
| JSON-LD structured data (`BaseHead.astro:85`) | Search results keep advertising the old offer |
| Terms (`terminos.astro:60`) | The "no card needed" contractual claim becomes false once cards auto-charge |
| `/docs` product manual | User-facing pages describing the trial |
| In-app copy (select-plan, banners) | Trial messaging inside the React app |

#### Scenario: Pricing copy survives hydration

- GIVEN the landing pricing page
- WHEN client scripts hydrate the page
- THEN the rendered trial copy still states 30 days with card required

#### Scenario: No surface claims a card-free trial

- GIVEN the landing, JSON-LD, terms, `/docs`, and app UI
- WHEN swept for trial claims
- THEN zero occurrences state a card-free trial AND all trial claims state 30 days

#### Scenario: Terms match the contract

- GIVEN the published terms page
- WHEN the trial clause renders
- THEN it states the 30-day card-required trial and the day-31 auto-charge

### Requirement: Signup legal links resolve

The signup form's terms and privacy links MUST navigate to the real public legal pages (`terminos`, `privacidad` on the landing) instead of `href='#'` (`signup-form.tsx:240,244`) — mandatory before cards auto-charge.

#### Scenario: Legal links open the real pages

- GIVEN the signup form
- WHEN the user activates the terms or privacy link
- THEN the corresponding public legal page opens AND no link points to `#`
