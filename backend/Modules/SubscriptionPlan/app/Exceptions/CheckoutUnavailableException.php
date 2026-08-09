<?php

namespace Modules\SubscriptionPlan\Exceptions;

use RuntimeException;

/**
 * The requested multi-item checkout cannot proceed as a self-service purchase:
 * the unit count exceeds the published price bands (contact_sales) or no selected
 * package has a billable Stripe price. Keeps MultiItemSubscriptionService free of
 * HTTP concerns — the Slice 2 controller maps this to a 422 response and reads
 * reason() to emit a machine-readable `code` so the frontend can distinguish the
 * two flavors without parsing the (Spanish) message.
 */
class CheckoutUnavailableException extends RuntimeException
{
    /** Unit count is above the published bands → a sales quote is required. */
    public const REASON_CONTACT_SALES = 'contact_sales';

    /** No selected package has a billable (Stripe-synced) price. */
    public const REASON_NO_BILLABLE_PRICE = 'no_billable_price';

    public function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function contactSales(string $message): self
    {
        return new self($message, self::REASON_CONTACT_SALES);
    }

    public static function noBillablePrice(string $message): self
    {
        return new self($message, self::REASON_NO_BILLABLE_PRICE);
    }

    /**
     * Machine-readable discriminator the controller maps to the response `code`.
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
