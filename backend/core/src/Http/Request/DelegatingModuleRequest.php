<?php

namespace QCH\Core\Http\Request;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Orion\Http\Controllers\BaseController;
use Orion\Http\Requests\Request as OrionRequest;
use ReflectionMethod;

/**
 * Bridges Orion's single-request convention with the project's per-module
 * {Model}CreateRequest / {Model}UpdateRequest pairs.
 *
 * Orion resolves ONE request class per controller (App\Http\Requests\{Model}Request
 * or the controller's $request property) and falls back to its bare Request — which
 * has no rules — when neither exists. This class is that fallback: on store/update
 * (and their batch variants) it locates the sibling Create/Update request by
 * convention (Modules\X\Http\Controllers → Modules\X\Http\Requests) and delegates
 * rules(), messages() and attributes() to it, so the module validation actually runs.
 *
 * A controller can still bypass this entirely by declaring `protected $request`.
 */
class DelegatingModuleRequest extends OrionRequest
{
    /** @var array<string, FormRequest|null> */
    private array $delegates = [];

    public function storeRules(): array
    {
        return $this->delegatedRules('Create', 'storeRules');
    }

    public function updateRules(): array
    {
        return $this->delegatedRules('Update', 'updateRules');
    }

    public function storeMessages(): array
    {
        return $this->delegate('Create')?->messages() ?? [];
    }

    public function updateMessages(): array
    {
        return $this->delegate('Update')?->messages() ?? [];
    }

    public function attributes(): array
    {
        $suffix = $this->delegateSuffixForAction();

        if ($suffix === null) {
            return [];
        }

        return $this->delegate($suffix)?->attributes() ?? [];
    }

    /**
     * Orion validates the DelegatingModuleRequest instance, not the sibling
     * Create/Update request — so without this bridge the child's withValidator()
     * (cross-field / after-validation rules) would silently never run. Delegate it
     * here so module FormRequests can declare withValidator() and have it honored.
     */
    public function withValidator(Validator $validator): void
    {
        $suffix = $this->delegateSuffixForAction();

        if ($suffix === null) {
            return;
        }

        $delegate = $this->delegate($suffix);

        if ($delegate !== null && method_exists($delegate, 'withValidator')) {
            $delegate->withValidator($validator);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function delegatedRules(string $suffix, string $orionRulesMethod): array
    {
        $delegate = $this->delegate($suffix);

        if ($delegate === null) {
            return [];
        }

        if ($delegate instanceof OrionRequest && ! $this->declaresRulesDirectly($delegate)) {
            return array_merge($delegate->commonRules(), $delegate->{$orionRulesMethod}());
        }

        return $delegate->rules();
    }

    /**
     * An Orion-style delegate splits rules into storeRules()/updateRules();
     * a plain FormRequest (or an Orion subclass overriding rules()) defines
     * the full set in rules() directly.
     */
    private function declaresRulesDirectly(OrionRequest $delegate): bool
    {
        return (new ReflectionMethod($delegate, 'rules'))->getDeclaringClass()->getName() !== OrionRequest::class;
    }

    private function delegate(string $suffix): ?FormRequest
    {
        if (array_key_exists($suffix, $this->delegates)) {
            return $this->delegates[$suffix];
        }

        $class = $this->delegateClass($suffix);

        if ($class === null) {
            return $this->delegates[$suffix] = null;
        }

        /** @var FormRequest $delegate */
        $delegate = $class::createFrom($this);
        $delegate->setContainer(app());

        return $this->delegates[$suffix] = $delegate;
    }

    /**
     * @return class-string<FormRequest>|null
     */
    private function delegateClass(string $suffix): ?string
    {
        $controller = $this->route()?->controller;

        if (! $controller instanceof BaseController) {
            return null;
        }

        $controllerNamespace = Str::beforeLast(get_class($controller), '\\');

        if (! Str::endsWith($controllerNamespace, '\\Controllers')) {
            return null;
        }

        $requestsNamespace = Str::replaceLast('\\Controllers', '\\Requests', $controllerNamespace);
        $class = $requestsNamespace.'\\'.class_basename($controller->getModel()).$suffix.'Request';

        if (! class_exists($class) || ! is_subclass_of($class, FormRequest::class)) {
            return null;
        }

        return $class;
    }

    private function delegateSuffixForAction(): ?string
    {
        return match ($this->route()?->getActionMethod()) {
            'store', 'batchStore' => 'Create',
            'update', 'batchUpdate' => 'Update',
            default => null,
        };
    }
}
