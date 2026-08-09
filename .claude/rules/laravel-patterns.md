---
description: PHP conventions, Laravel 13 patterns, common code patterns, anti-patterns, and performance
globs: "**/*.php"
---
# Laravel Patterns & PHP Conventions

## Generic Laravel reference (ante conflicto, gana laravel-backend-architecture skill)

Generic PHP conventions, Laravel 13 structure, casts/migrations craft, Pint, and PHPUnit testing are Boost-owned.

> Canonical: see `backend/CLAUDE.md` (Boost-maintained, never hand-edit). For project-specific backend architecture, see `.agents/skills/laravel-backend-architecture/SKILL.md` (gana ante conflicto).

## Request Handling

**Incorrect - Using undefined property:**
```php
public function testEmail()
{
    $request = $this->request->all(); // Undefined property
}
```

**Correct - Inject Request as parameter:**
```php
public function testEmail(Request $request)
{
    $data = $request->all();
    // or for specific fields
    $slug = $request->input('slug');
}
```

### Import Statements
**Always include necessary imports at the top of files:**
```php
<?php

namespace App\Http\Controllers;

use App\Jobs\EmailJob; // Don't forget to import jobs
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
```

## Project-specific (canonical aquí)

- **Media (Spatie)**: Usar `App\Models\Media`; la lógica de adjuntar archivos va en el **servicio** del módulo (`$model->addMedia(...)->toMediaCollection(...)`), no en el controlador cuando hay reglas de negocio.

> UUID como PK: lo maneja `HasUuids` vía `BaseModel` — NO declarar `$keyType`/`$incrementing` a mano. Canonical: `.agents/skills/laravel-backend-architecture/SKILL.md`.
> Config vs env: canonical en `.claude/rules/laravel-architecture.md` (sección 4.4).

## Job Implementation

### Creating Jobs
Jobs should be placed in app/Jobs/ directory:

```php
<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class EmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $emailData;

    public function __construct(array $emailData)
    {
        $this->emailData = $emailData;
    }

    public function handle(): void
    {
        // Email sending logic
        Mail::to($this->emailData['to'])
            ->send(new DynamicEmail($this->emailData));
    }
}
```

### Dispatching Jobs
```php
// Synchronous dispatch
ProcessDataJob::dispatchSync($data);

// Queued dispatch
ProcessDataJob::dispatch($data);

// Delayed dispatch
ProcessDataJob::dispatch($data)->delay(now()->addMinutes(5));

// On specific queue
ProcessDataJob::dispatch($data)->onQueue('processing');
```

## Dynamic Model Resolution

### Model Mapper Pattern
When dynamically resolving models:

```php
protected array $modelMapper = [
    'Role' => \QCH\Core\Models\Role::class,
    'Country' => \Modules\Country\Models\Country::class,
    // ... other mappings
];

public function resolveModel(string $type): string
{
    $modelName = Str::studly($type);

    // Check mapper first
    if (isset($this->modelMapper[$modelName])) {
        return $this->modelMapper[$modelName];
    }

    // Try module pattern
    $moduleClass = "Modules\\{$modelName}\\Models\\{$modelName}";
    if (class_exists($moduleClass)) {
        return $moduleClass;
    }

    // Fallback to app namespace
    $appClass = "App\\Models\\{$modelName}";
    if (class_exists($appClass)) {
        return $appClass;
    }

    throw new \InvalidArgumentException("Model {$modelName} not found");
}
```

## Query Building Best Practices

### Using ApiHandler

> NON-CANONICAL — el proyecto usa el envelope de Orion, ver `.agents/skills/laravel-backend-architecture/SKILL.md`. Estos ejemplos de `ApiHandler` contradicen la convención de respuesta de Orion y se conservan solo como referencia genérica.

```php
public function index(Request $request)
{
    $model = User::class;
    $relations = ['role', 'profile'];
    $params = $request->all();

    $query = ApiHandler::parseMultiple($model, $relations, $params)
        ->getBuilder();

    // Additional filtering
    if ($request->has('active')) {
        $query->where('active', $request->boolean('active'));
    }

    // Soft deletes handling
    $query->whereNull('deleted_at');

    return $query->paginate($request->input('per_page', 15));
}
```

### Soft Delete Considerations
```php
// Always check for soft deletes unless specifically including trashed
$users = User::query()
    ->whereNull('deleted_at')
    ->get();

// Or use built-in methods
$users = User::query()->get(); // Excludes soft deleted by default
$allUsers = User::withTrashed()->get(); // Includes soft deleted
$trashedOnly = User::onlyTrashed()->get(); // Only soft deleted
```

## Response Helpers

> NON-CANONICAL — el proyecto usa el envelope de Orion, ver `.agents/skills/laravel-backend-architecture/SKILL.md`. Los helpers `successResponse`/`errorResponse` contradicen la convención de respuesta de Orion; se conservan solo como referencia genérica.

### Using Controller Helpers Trait
```php
use QCH\Core\Http\Controllers\Helpers;

class UserController extends Controller
{
    use Helpers;

    public function index()
    {
        $users = User::paginate();

        // Success response
        return $this->successResponse($users, 'Users retrieved successfully');
    }

    public function store(Request $request)
    {
        try {
            $user = User::create($request->validated());
            return $this->successResponse($user, 'User created', 201);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to create user', 500);
        }
    }
}
```

## Database Transactions

### Proper Transaction Handling
```php
public function complexOperation(Request $request)
{
    DB::beginTransaction();

    try {
        // Multiple database operations
        $user = User::create($request->input('user'));
        $profile = $user->profile()->create($request->input('profile'));
        $user->roles()->sync($request->input('roles', []));

        DB::commit();

        return $this->successResponse($user->load('profile', 'roles'));
    } catch (\Exception $e) {
        DB::rollBack();

        \Log::error('Complex operation failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return $this->errorResponse('Operation failed', 500);
    }
}
```

## Validation Patterns

### Inline Validation (Quick prototyping only)
```php
public function store(Request $request)
{
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users,email',
        'password' => 'required|string|min:8'
    ]);

    // Process validated data
}
```

### Form Request (Recommended)
```php
public function store(StoreUserRequest $request)
{
    $validated = $request->validated();
    // Process validated data
}
```

## Common Anti-Patterns to Avoid

### Don't use sleep() in production code
```php
// Bad
sleep(5); // Never do this in production
```

### Use queues for delays
```php
// Good
EmailJob::dispatch($data)->delay(now()->addSeconds(5));
```

### Don't hardcode values
```php
// Bad
if ($request->input('type') == 'SOME_HARDCODED_VALUE') {
```

### Use constants or config
```php
// Good
if ($request->input('type') === config('app.types.specific_type')) {
// or
if ($request->input('type') === TypeConstants::SPECIFIC_TYPE) {
```

### Don't catch generic exceptions without logging
```php
// Bad
try {
    // code
} catch (\Exception $e) {
    return false;
}
```

### Always log exceptions with context
```php
// Good
try {
    // code
} catch (\Exception $e) {
    \Log::error('Specific operation failed', [
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    throw new CustomException('User-friendly message', 0, $e);
}
```

## Type Hinting & Null Safety

### Always use return types and parameter types
```php
public function findUser(int $id): ?User
{
    return User::find($id);
}

public function createUser(array $data): User
{
    return User::create($data);
}

public function getUsersCount(): int
{
    return User::count();
}

public function isActive(User $user): bool
{
    return $user->active === true;
}
```

### Use null coalescing operator
```php
// Instead of
$name = isset($request['name']) ? $request['name'] : 'Default';

// Use
$name = $request->input('name', 'Default');
// or
$name = $data['name'] ?? 'Default';
```

### Optional chaining for relationships
```php
// Safe navigation
$cityName = $user->profile?->address?->city?->name ?? 'Unknown';
```

## Performance Optimizations

### Eager Loading
```php
// Bad - N+1 problem
$users = User::all();
foreach ($users as $user) {
    echo $user->profile->bio; // Executes query for each user
}

// Good - Eager loading
$users = User::with('profile')->get();
foreach ($users as $user) {
    echo $user->profile->bio; // No additional queries
}
```

### Query Optimization
```php
// Select only needed columns
$users = User::select(['id', 'name', 'email'])
    ->with(['profile:id,user_id,bio'])
    ->get();

// Use chunks for large datasets
User::chunk(1000, function ($users) {
    foreach ($users as $user) {
        // Process user
    }
});
```

### Use Queued Jobs for Heavy Operations
```php
// Instead of processing inline
ProcessDataJob::dispatch($data)->onQueue('processing');
```

## Code Formatting & Testing (Pint / PHPUnit)

> Canonical: see `backend/CLAUDE.md` (Boost-maintained, never hand-edit) for Pint formatting and PHPUnit testing conventions.
