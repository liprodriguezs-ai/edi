<?php

namespace Modules\User\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Modules\Email\Jobs\SendAccountOwnerNotificationJob;
use Modules\User\Http\Requests\UserRequest;
use Modules\User\Services\UserService;
use Modules\User\Transformers\UseResource;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class UserController extends Controller
{
    protected $model = User::class;

    protected $keyForPermission = 'user';

    protected $resource = UseResource::class;

    public function searchableBy(): array
    {
        return [
            'id',
            'name',
            'last_name',
            'second_last_name',
            'email',
            'phone',
            'code',
        ];
    }

    public function includes(): array
    {
        return [
            'company',
            'roles',
            'roles.slug',
            'state',
            'notifications',
            'user_register_status',
            'role',
            'tenants',
            'tenants.billing_package',
            // 'cash_register_session',
        ];
    }

    public function filterableBy(): array
    {
        return [
            'roles.slug',
            'name',
            'last_name',
            'second_last_name',
            'phone',
            'email',
            'genre.id',
            'roles.name',
            'country.id',
            'state.id',
            'municipality.id',
            'code',
            'deleted_reason',
            'deleted_request_user_id',
            'deleted_confirm_user_id',
            'created_at',
            'updated_at',
            'deleted_at',
            'is_blacklisted',
        ];
    }

    protected $request = UserRequest::class;

    /**
     * Build index query without Orion's raw search (PostgreSQL reserves "user").
     * We apply search with quoted table name.
     */
    protected function buildIndexFetchQuery(Request $request, array $requestedRelations): Builder
    {
        $search = $request->get('search');
        if ($search !== null) {
            $request->request->remove('search');
        }
        $query = parent::buildIndexFetchQuery($request, $requestedRelations);
        if ($search !== null) {
            $request->request->add(['search' => $search]);
            $this->applyUserSearch($query, $search);
        }

        $this->applyRoleVisibilityFilter($query);

        return $query;
    }

    private function applyUserSearch(Builder $query, array $searchDescriptor): void
    {
        $value = $searchDescriptor['value'] ?? '';
        if ($value === '') {
            return;
        }
        $caseSensitive = (bool) Arr::get(
            $searchDescriptor,
            'case_sensitive',
            config('orion.search.case_sensitive', false)
        );
        $table = (new User)->getTable();
        $quotedTable = '"'.$table.'"';
        $searchables = $this->searchableBy();
        $query->where(function ($q) use ($searchables, $value, $caseSensitive, $quotedTable) {
            foreach ($searchables as $field) {
                if (strpos($field, '.') !== false) {
                    continue;
                }
                $qualified = $quotedTable.'."'.$field.'"';
                if (! $caseSensitive) {
                    $q->orWhereRaw('lower('.$qualified.'::text) like lower(?)', ['%'.$value.'%']);
                } else {
                    $q->orWhereRaw($qualified.'::text like ?', ['%'.$value.'%']);
                }
            }
        });
    }

    private function applyRoleVisibilityFilter(Builder $query): void
    {
        // EDI: la visibilidad la gobiernan el permiso read.user (ruta) y el
        // UserTenantScope (cada actor de la organización solo ve usuarios de SU tenant;
        // el super admin no tiene tenant y ve todo). El filtro CRM por roles
        // RH/COMPANY_ADMIN dejaba listas vacías para account.owner — eliminado.
    }

    /**
     * Delegates create to UserService while keeping Orion's store flow.
     */
    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $created = app(UserService::class)->create(Arr::except($request->all(), self::PROTECTED_ATTRIBUTES));
        $entity->setRawAttributes($created->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    /**
     * sec-input-08 — credential/verification/activation state must never be
     * mass-assigned through the generic create OR update endpoint: each has a
     * dedicated flow (updatePassword, revoke-sessions, employee termination,
     * onboarding).
     */
    private const PROTECTED_ATTRIBUTES = [
        'auth_active',
        'email_verified_at',
        'verification_code',
        'update_password',
        'user_register_status_id',
        'last_active_tenant_id',
        'onboarding',
        'onboarding_completed_at',
        'user_created_id',
        'user_updated_id',
        'user_deleted_id',
        'user_restored_id',
    ];

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        parent::performUpdate($request, $entity, Arr::except($attributes, self::PROTECTED_ATTRIBUTES));
    }

    /**
     * Unset empty password so Orion's performUpdate does not overwrite with empty.
     */
    protected function beforeSave(Request $request, Model $entity)
    {
        if (isset($request->password) && $request->password === '') {
            $request->offsetUnset('password');
            $request->offsetUnset('password_confirmation');
        }

        return null;
    }

    /**
     * Apply role change and account-owner notification; return 403 when only superadmin may change owner role.
     */
    protected function afterUpdate(Request $request, Model $entity)
    {
        $user = auth()->user();
        $currentRole = $entity->roles()->first();

        $ownerInvolved = ($currentRole?->uuid === \QCH\Core\Models\Role::ACCOUNT_OWNER)
            || ($request->role_id === \QCH\Core\Models\Role::ACCOUNT_OWNER);

        // hasRole() matches on the `name` column, which holds the SLUG (see
        // database/seeders/csv/roles.csv). Passing the uuid constant made this
        // check always false, locking even the super admin out of the flow.
        if ($ownerInvolved && ! $user->hasRole(\QCH\Core\Models\Role::SLUG_SUPER_ADMIN)) {
            return response()->json([
                'message' => 'Solo el superadmin puede cambiar el rol de un propietario.',
            ], 403);
        }

        if (isset($request->role_id) && $currentRole?->uuid != $request->role_id) {
            $newRole = Role::find($request->role_id);

            if (! $newRole) {
                return response()->json([
                    'message' => 'El rol indicado no existe.',
                ], 422);
            }

            // SECURITY: same escalation vector as UserService::create — role_id
            // arrives in the request body, so an actor may only grant roles of
            // their own tier or below. Without this, holding update.user was
            // enough to promote any account to platform super admin.
            if (! \QCH\Core\Models\Role::canAssignSlug($user, $newRole->name)) {
                return response()->json([
                    'message' => 'No tienes permiso para asignar ese rol.',
                ], 403);
            }

            $entity->roles()->detach();
            $entity->assignRole($newRole->name);
            if ($newRole->name === \QCH\Core\Models\Role::SLUG_ACCOUNT_OWNER) {
                SendAccountOwnerNotificationJob::dispatch($entity);
            }
        }

        $entity->load('roles');

        return null;
    }

    public function resetPassword(Request $request)
    {
        // validate current password
        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'new_password' => 'required|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = auth()->user();
        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'errors' => ['current_password' => 'The current password is incorrect.'],
            ], 422);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'message' => 'Password reset successfully.',
        ]);
    }

    /**
     * Authorize a given action for the current user.
     *
     * @param  mixed  $ability
     * @param  mixed|array  $arguments
     * @return Response
     *
     * @throws AuthorizationException
     */
    public function authorize($ability, $arguments = [])
    {
        if ($ability == 'update.user' && auth()->user()->id == $arguments->id) {
            return $this->authorized();
        }

        [$ability, $arguments] = $this->parseAbilityAndArguments($ability, $arguments);

        $user = auth()->user();
        if ($user) {
            if ($user->tokenCan($ability)) {
                return $this->authorized();
            } elseif ($user->tokenCan('*')) {
                return $this->authorized();
            } else {
                throw new AuthorizationException('Unauthorized action.');
            }
        }
    }

    // token sender
    public function setTokenSender(Request $request)
    {
        $this->validate($request, [
            'token_sender' => 'required',
        ]);

        $user = auth()->user();

        User::whereNotNull('token_sender')
            ->where('id', '!=', $user->id)
            ->where('token_sender', $request['token_sender'])->update([
                'token_sender' => null,
            ]);

        $user->update([
            'token_sender' => $request['token_sender'],
        ]);

        return response()->json([
            'message' => 'Token sender updated successfully',
            'data' => $user,
        ]);
    }

    public function updatePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required',
            'new_password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = auth()->user();

        // Verificar que la contraseña actual sea correcta
        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'errors' => ['password' => 'La contraseña actual es incorrecta'],
            ], 422);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        $user->tokens()->delete();

        return response()->json([
            'message' => 'Contraseña actualizada exitosamente. Por favor, inicie sesión nuevamente.',
            'data' => $user,
            'redirect' => '/login',
        ]);
    }

    public function uploadDocuments(Request $request)
    {
        // 10485760=10 MB
        $request->validate([
            'file_id' => 'required|file|mimes:pdf,jpeg,png|max:10485760',
            'file_bank_statement' => 'required|file|mimes:pdf,jpeg,png|max:10485760',
        ]);

        $user = auth()->user();
        if ($request->hasFile('file_id')) {

            $user->clearMediaCollection('id');
            $user->addMediaFromRequest('file_id')->toMediaCollection('id');
        }
        if ($request->hasFile('file_bank_statement')) {
            $user->clearMediaCollection('bank_statement');
            $user->addMediaFromRequest('file_bank_statement')->toMediaCollection('bank_statement');
        }

        $user->onboarding = 1;
        $user->save();

        return response()->json([
            'message' => 'Documents uploaded successfully',
            'data' => [
                'id' => $user->getIdMedia(),
                'bank_statement' => $user->getBankStatementMedia(),
            ],
        ]);
    }

    /**
     * POST /api/user/uploadProfileImage — the authenticated user sets their avatar.
     * Single-file collection: a new upload replaces the previous one.
     */
    public function uploadProfileImage(HttpRequest $request): JsonResponse
    {
        $request->validate([
            'profile_image' => ['required', 'image', 'max:5120'],
        ]);

        $user = auth()->user();
        $user->clearMediaCollection('profile_image');
        $user->addMediaFromRequest('profile_image')->toMediaCollection('profile_image');

        return response()->json([
            'message' => 'Foto de perfil actualizada.',
            'data' => ['profile_image_url' => $user->fresh()->profile_image_url],
        ]);
    }

    /**
     * POST /api/user/deleteProfileImage — the authenticated user removes their avatar.
     */
    public function deleteProfileImage(): JsonResponse
    {
        $user = auth()->user();
        $user->clearMediaCollection('profile_image');

        return response()->json([
            'message' => 'Foto de perfil eliminada.',
            'data' => ['profile_image_url' => null],
        ]);
    }

    /**
     * POST /api/user/{id}/anonymize — right to be forgotten (§18.3.4, global-06).
     *
     * Admin-only: requires the update.user ability AND an administrator /
     * account-owner / super-admin role (a resident token holding update.user for
     * self-service must NOT be able to anonymize a neighbour). Self-anonymization
     * is rejected, and a tenant can never be left without an account owner.
     * The target resolves through UserTenantScope, so a member of another
     * organization is a plain 404.
     */
    public function anonymize(HttpRequest $request, string $id): JsonResponse
    {
        $actor = $request->user();

        $allowedRoles = [
            \QCH\Core\Models\Role::SLUG_ADMINISTRATOR,
            \QCH\Core\Models\Role::SLUG_ACCOUNT_OWNER,
            \QCH\Core\Models\Role::SLUG_SUPER_ADMIN,
        ];

        if (! $actor->tokenCan('update.user') || ! $actor->hasAnyRole($allowedRoles)) {
            return response()->json([
                'message' => 'No tienes permiso para anonimizar usuarios.',
            ], 403);
        }

        $target = User::query()->findOrFail($id);

        if ($target->id === $actor->id) {
            return response()->json([
                'message' => 'No puedes anonimizar tu propia cuenta.',
            ], 422);
        }

        $userService = app(UserService::class);

        if ($userService->isLastAccountOwnerOfAnyTenant($target)) {
            return response()->json([
                'message' => 'No se puede anonimizar al último propietario de cuenta de la organización.',
            ], 422);
        }

        $anonymized = $userService->anonymize($target);

        return response()->json([
            'message' => 'Usuario anonimizado exitosamente.',
            'data' => [
                'id' => $anonymized->id,
                'name' => $anonymized->name,
            ],
        ]);
    }
}
