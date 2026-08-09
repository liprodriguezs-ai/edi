<?php

namespace Modules\Collections\Support;

use Modules\Collections\Contracts\PaymentEarmarkHandler;

/**
 * PaymentEarmarkRegistry — earmark_type => handler class-string map.
 *
 * Bound as a singleton in CollectionsServiceProvider::register(); consuming
 * modules (e.g. Projects) register their handler from their provider's boot().
 * Octane-safe: only class-strings are stored (registered once per worker boot,
 * idempotent by key), handlers are resolved fresh through the container.
 */
class PaymentEarmarkRegistry
{
    /** @var array<string, class-string<PaymentEarmarkHandler>> */
    private array $handlers = [];

    /**
     * Register (or overwrite) the handler class for an earmark type.
     *
     * @param  class-string<PaymentEarmarkHandler>  $handlerClass
     */
    public function register(string $type, string $handlerClass): void
    {
        $this->handlers[$type] = $handlerClass;
    }

    /**
     * Registered earmark type keys (used by controllers for Rule::in validation).
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Resolve the handler instance for an earmark type.
     *
     * @throws \InvalidArgumentException When no handler is registered for the type.
     */
    public function handlerFor(string $type): PaymentEarmarkHandler
    {
        if (! isset($this->handlers[$type])) {
            throw new \InvalidArgumentException("No hay un manejador registrado para el destino de pago '{$type}'.");
        }

        return app($this->handlers[$type]);
    }
}
