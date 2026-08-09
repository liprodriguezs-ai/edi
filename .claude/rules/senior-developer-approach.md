---
description: Enterprise-grade PHP code quality standards, SOLID principles, design patterns, and testing
globs: "**/*.php"
---
# Senior Developer Approach & Quality Standards

> Filosofía de calidad GENÉRICA. Ejemplos ILUSTRATIVOS — ante conflicto con laravel-backend-architecture/stancl-tenancy, gana el skill. Este proyecto usa controllers Orion + Services (NO el patrón Repository mostrado acá) y el envelope de respuesta de Orion (NO un Handler {success,message,code} custom).
>
> Subordinado a `CLAUDE.md` sección 1 (filosofía canónica) y a los skills `.agents/skills/laravel-backend-architecture/SKILL.md` / `.agents/skills/stancl-tenancy/SKILL.md`. Cuando un ejemplo de aquí difiera del skill, el skill manda.

## Core Philosophy

This project demands **production-grade, enterprise-level code quality**. Every piece of code should be written as if it will:
- Be maintained by a team for years
- Scale to handle millions of users
- Be audited for security compliance
- Serve as a reference for junior developers

## Code Quality Standards

### 1. **SOLID Principles - Always Applied**

#### Single Responsibility Principle
```php
// ❌ Bad - Multiple responsibilities
class EntityController {
    public function create() {
        // Validation
        // Entity creation
        // Notification sending
        // Logging
    }
}

// ✅ Good - Separated concerns
class EntityController {
    public function create(CreateEntityRequest $request) {
        $entity = $this->entityService->create($request->validated());
        return new EntityResource($entity);
    }
}

class EntityService {
    public function create(array $data): Entity {
        $entity = $this->repository->create($data);
        event(new EntityCreated($entity));
        return $entity;
    }
}
```

#### Open/Closed Principle
```php
// Use interfaces and abstractions
interface PaymentGatewayInterface {
    public function charge(float $amount, array $options): PaymentResult;
}

class StripePaymentGateway implements PaymentGatewayInterface { }
class PayPalPaymentGateway implements PaymentGatewayInterface { }
```

#### Liskov Substitution Principle
```php
// Subtypes must be substitutable for their base types
abstract class Repository {
    abstract public function find(int $id): ?Model;
}

class UserRepository extends Repository {
    public function find(int $id): ?User { // Covariant return type
        return User::find($id);
    }
}
```

#### Interface Segregation Principle
```php
// ❌ Bad - Fat interface
interface UserInterface {
    public function login();
    public function logout();
    public function updateProfile();
    public function deleteAccount();
    public function exportData();
}

// ✅ Good - Segregated interfaces
interface Authenticatable {
    public function login(array $credentials): bool;
    public function logout(): void;
}

interface ProfileManageable {
    public function updateProfile(array $data): bool;
}

interface AccountManageable {
    public function deleteAccount(): bool;
    public function exportData(): array;
}
```

#### Dependency Inversion Principle
```php
// Depend on abstractions, not concretions
class OrderService {
    public function __construct(
        private PaymentGatewayInterface $paymentGateway,
        private NotificationServiceInterface $notificationService,
        private OrderRepositoryInterface $repository
    ) {}
}
```

### 2. **Design Patterns**

#### Repository Pattern

> Ilustración del patrón, NO canon del proyecto. Este proyecto NO usa Repository: la lógica vive en controllers Orion + Services. Ver `.agents/skills/laravel-backend-architecture/SKILL.md`.

```php
interface EntityRepositoryInterface {
    public function findByIdentifier(string $identifier): ?Entity;
    public function createWithRelations(array $entityData, array $relationData): Entity;
    public function getActiveEntities(): Collection;
}

class EloquentEntityRepository implements EntityRepositoryInterface {
    public function findByIdentifier(string $identifier): ?Entity {
        return Entity::where('identifier', $identifier)->first();
    }

    public function createWithRelations(array $entityData, array $relationData): Entity {
        return DB::transaction(function () use ($entityData, $relationData) {
            $entity = Entity::create($entityData);
            $entity->relations()->create($relationData);
            return $entity->fresh('relations');
        });
    }
}
```

#### Service Pattern
```php
class ProcessService {
    public function __construct(
        private ProcessRepository $processRepository,
        private ValidationService $validationService,
        private ResourceService $resourceService,
        private NotificationService $notificationService
    ) {}

    public function executeProcess(array $processData): ProcessResult {
        DB::beginTransaction();

        try {
            // Validate resources
            $this->resourceService->validateAvailability($processData['resources']);

            // Create process
            $process = $this->processRepository->create($processData);

            // Execute validation
            $validationResult = $this->validationService->validate($process);

            if (!$validationResult->isSuccessful()) {
                throw new ValidationFailedException($validationResult->getError());
            }

            // Update resources
            $this->resourceService->updateStatus($process->resources);

            // Send notifications
            $this->notificationService->notifyProcessSuccess($process);

            DB::commit();

            return new ProcessResult(true, $process);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Process execution failed', [
                'process_data' => $processData,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new ProcessResult(false, null, $e->getMessage());
        }
    }
}
```

#### Factory Pattern
```php
interface NotificationChannelInterface {
    public function send(User $user, string $message): bool;
}

class NotificationChannelFactory {
    public function create(string $channel): NotificationChannelInterface {
        return match($channel) {
            'email' => new EmailNotificationChannel(),
            'sms' => new SmsNotificationChannel(),
            'push' => new PushNotificationChannel(),
            default => throw new InvalidArgumentException("Unknown channel: {$channel}")
        };
    }
}
```

### 3. **Error Handling Excellence**

> El `Handler` global custom con envelope `{success, message, code, context}` es ilustración, NO canon del proyecto. Este proyecto usa el envelope de respuesta de Orion. Ver `.agents/skills/laravel-backend-architecture/SKILL.md`.

```php
// Custom exception hierarchy
abstract class DomainException extends \Exception {
    protected string $errorCode;
    protected array $context = [];

    public function getErrorCode(): string {
        return $this->errorCode;
    }

    public function getContext(): array {
        return $this->context;
    }
}

class EntityNotFoundException extends DomainException {
    protected string $errorCode = 'ENTITY_NOT_FOUND';

    public function __construct(int $entityId) {
        parent::__construct("Entity with ID {$entityId} not found");
        $this->context = ['entity_id' => $entityId];
    }
}

// Global exception handler
class Handler extends ExceptionHandler {
    public function render($request, Throwable $exception) {
        if ($request->wantsJson()) {
            if ($exception instanceof DomainException) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'code' => $exception->getErrorCode(),
                    'context' => $exception->getContext()
                ], $this->getStatusCode($exception));
            }
        }

        return parent::render($request, $exception);
    }
}
```

### 4. **Testing as First-Class Citizen**

```php
class ProcessServiceTest extends TestCase {
    private ProcessService $service;
    private MockInterface $processRepository;
    private MockInterface $validationService;

    protected function setUp(): void {
        parent::setUp();

        $this->processRepository = $this->mock(ProcessRepository::class);
        $this->validationService = $this->mock(ValidationService::class);

        $this->service = new ProcessService(
            $this->processRepository,
            $this->validationService,
            // ... other dependencies
        );
    }

    /** @test */
    public function it_executes_process_successfully(): void {
        // Arrange
        $processData = [
            'entity_id' => 1,
            'resources' => [['resource_id' => 1, 'quantity' => 2]]
        ];

        $process = new Process($processData);
        $validationResult = new ValidationResult(true, 'VALID123');

        $this->processRepository
            ->shouldReceive('create')
            ->once()
            ->with($processData)
            ->andReturn($process);

        $this->validationService
            ->shouldReceive('validate')
            ->once()
            ->with($process)
            ->andReturn($validationResult);

        // Act
        $result = $this->service->executeProcess($processData);

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals($process->id, $result->getProcess()->id);
    }

    /** @test */
    public function it_rolls_back_transaction_on_validation_failure(): void {
        // Test implementation
    }
}
```

### 5. **Performance Optimization**

```php
// Query optimization
class EntityRepository {
    public function getEntitiesWithDetails(array $filters): Collection {
        return Entity::query()
            ->select(['id', 'name', 'status', 'category_id'])
            ->with([
                'category:id,name',
                'attachments:id,entity_id,url,is_primary',
                'metadata' => function ($query) {
                    $query->where('active', true)
                          ->orderBy('priority');
                }
            ])
            ->when($filters['category'] ?? null, function ($query, $category) {
                $query->where('category_id', $category);
            })
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->orderBy('created_at', 'desc')
            ->get();
    }
}

// Caching strategy
class CachedEntityService {
    public function __construct(
        private EntityRepository $repository,
        private CacheInterface $cache
    ) {}

    public function getFeaturedEntities(): Collection {
        return $this->cache->remember(
            'featured_entities',
            now()->addHours(2),
            fn() => $this->repository->getFeaturedEntities()
        );
    }
}
```

### 6. **Security First**

> Para el patrón real de validación (FormRequest / custom-action) en este proyecto, ver `.agents/skills/laravel-backend-architecture/SKILL.md` (§2.10/§2.12). El ejemplo de abajo es ilustrativo.

```php
// Input sanitization and validation
class UpdateEntityRequest extends FormRequest {
    public function rules(): array {
        return [
            'name' => ['required', 'string', 'max:255', new SafeString()],
            'identifier' => ['required', 'string', Rule::unique('entities')->ignore($this->entity()->id)],
            'description' => ['nullable', 'string', 'max:1000', new NoHtmlTags()],
            'url' => ['nullable', 'url', 'max:255', new SafeUrl()],
        ];
    }

    protected function prepareForValidation(): void {
        $this->merge([
            'name' => strip_tags($this->name),
            'description' => clean($this->description), // Using HTMLPurifier
        ]);
    }
}

// SQL injection prevention (always use query builder or Eloquent)
public function searchEntities(string $term): Collection {
    return Entity::where('name', 'like', '%' . $term . '%')
               ->orWhere('identifier', 'like', '%' . $term . '%')
               ->limit(50)
               ->get();
}
```

### 7. **Documentation & Code Clarity**

```php
/**
 * Process a reversal for a transaction
 *
 * @param Transaction $transaction The transaction to reverse
 * @param float $amount The amount to reverse (partial reversals allowed)
 * @param string $reason The reason for the reversal
 *
 * @return ReversalResult
 *
 * @throws InvalidReversalAmountException When amount exceeds transaction total
 * @throws ReversalNotAllowedException When transaction is not eligible for reversal
 * @throws ProcessingGatewayException When processing gateway fails
 */
public function processReversal(Transaction $transaction, float $amount, string $reason): ReversalResult {
    // Validate reversal eligibility
    if (!$transaction->isEligibleForReversal()) {
        throw new ReversalNotAllowedException($transaction);
    }

    // Validate amount
    if ($amount > $transaction->getReversibleAmount()) {
        throw new InvalidReversalAmountException($amount, $transaction->getReversibleAmount());
    }

    // Process reversal through gateway
    $result = $this->processingGateway->reverse($transaction->reference_id, $amount);

    // Record reversal in database
    $reversal = $this->createReversalRecord($transaction, $amount, $reason, $result);

    // Update transaction status
    $transaction->updateReversalStatus($reversal);

    return new ReversalResult($reversal, $result);
}
```

## Code Review Mindset

When reviewing or writing code, always ask:

1. **Is this the simplest solution that works correctly?**
2. **Will another developer understand this in 6 months?**
3. **Are all edge cases handled?**
4. **Is this testable?**
5. **Does this follow established patterns in the codebase?**
6. **Are there any security implications?**
7. **Will this scale?**
8. **Is error handling comprehensive?**
9. **Are there any performance bottlenecks?**
10. **Is the code DRY without being overly abstract?**

## Refactoring Approach

When encountering legacy or poorly written code:

1. **Understand before changing** - Read tests, trace execution
2. **Add tests first** - Cover existing behavior
3. **Refactor incrementally** - Small, safe changes
4. **Improve names** - Variables, methods, classes
5. **Extract methods** - Break down large functions
6. **Introduce abstractions** - Only when patterns emerge
7. **Document decisions** - Why changes were made

## Performance Monitoring

```php
// Use Laravel Telescope or similar in development
// Add performance tracking
public function complexOperation() {
    $startTime = microtime(true);

    // Operation code

    $duration = microtime(true) - $startTime;

    if ($duration > 1.0) { // Log slow operations
        Log::warning('Slow operation detected', [
            'operation' => 'complexOperation',
            'duration' => $duration,
            'context' => $this->getContext()
        ]);
    }
}
```

## Final Principles

1. **Write code for humans, not computers**
2. **Favor composition over inheritance**
3. **Make dependencies explicit**
4. **Fail fast with clear error messages**
5. **Optimize for readability and maintainability**
6. **Measure performance, don't guess**
7. **Security is not optional**
8. **Tests are documentation**
9. **Refactor continuously**
10. **Always think about the next developer (it might be you)**
