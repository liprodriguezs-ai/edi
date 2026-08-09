# Subscription Status Allow-list Specification

## Purpose

One shared definition of the Stripe subscription statuses that grant access, built on SDK constants, replacing four hand-written literals.

## Requirements

### Requirement: Single SDK-constant allow-list

A single shared definition built on `Stripe\Subscription::STATUS_ACTIVE` and `Stripe\Subscription::STATUS_TRIALING` MUST be the only source of the "subscription grants access" status set, promoted from the existing constant in `BillingCheckoutController.php:35`. All four current sites MUST consume it: `StripeWebhookListener.php:263`, `ConfigurationController.php:1216`, `ConfigurationController.php:1486`, and `BillingCheckoutController.php:35`.

#### Scenario: Zero hand-written literals remain

- GIVEN the implemented change
- WHEN `backend/app` and `backend/Modules` are searched for hand-written `['active', 'trialing']`-equivalent status literals, excluding `backend/.claude/worktrees/`
- THEN zero matches remain outside the shared definition

#### Scenario: Behavior unchanged at every consumer

- GIVEN a subscription in `active` or `trialing` status
- WHEN each of the four consuming sites evaluates it
- THEN it is accepted exactly as before the change (no status added to or removed from the set)
