<?php

namespace Modules\Collaborator\Services;

use App\Models\Role as AppRole;
use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use Modules\Board\Models\BoardMember;
use Modules\Board\Models\BoardPeriodStatus;
use Modules\Collaborator\Models\Collaborator;
use Modules\Collaborator\Models\EmployeePosition;

class EmployeeService
{
    use NotifiesResidents;

    /**
     * Resolve the Kivo7 Spatie role for an employee's position (§7.4.2: app
     * access is created "con rol del puesto", NOT the legacy CRM 'collaborator'
     * role). The slug now lives in employee_position.grants_role (tenant-scoped
     * catalog); positions without one fall back to operations_staff.
     */
    public function resolveKivo7Role(Collaborator $employee): ?AppRole
    {
        $slug = EmployeePosition::query()
            ->whereKey($employee->employee_position_id)
            ->value('grants_role') ?? 'operations_staff';

        return AppRole::where('name', $slug)->where('guard_name', 'api')->first();
    }

    /**
     * Terminate an employee (sets is_active=false + termination fields; does NOT delete).
     * Preserves all documents and historical records per business rule §7.5.2.
     *
     * When $revokeAppAccess is true (default) AND the employee has a linked central
     * user, that user's app access is revoked immediately (spec §7.4.4): auth_active
     * is set to false and all Sanctum tokens are deleted, so an in-flight session and
     * any future login attempt are both blocked.
     */
    public function terminate(
        Collaborator $employee,
        string $terminationDate,
        string $terminationReason,
        ?string $details = null,
        bool $revokeAppAccess = true,
    ): Collaborator {
        if (! $employee->is_active) {
            throw new \InvalidArgumentException('El empleado ya está dado de baja.');
        }

        $employee->update([
            'is_active' => false,
            'termination_date' => $terminationDate,
            'termination_reason' => $terminationReason.($details ? ': '.$details : ''),
        ]);

        if ($revokeAppAccess) {
            $this->revokeAppAccess($employee);
        }

        // §7.7: email the Administrator + President that an employee was terminated.
        $employeeName = trim((string) ($employee->name ?? '')) ?: 'Un empleado';
        $this->notifyUsers(
            $this->userIdsWithRole(['administrator', 'president']),
            'Empleado dado de baja',
            $employeeName.' fue dado de baja del condominio. Motivo: '.$terminationReason.'.',
            ['click_action' => '/collaborator/'.$employee->id, 'collaborator_id' => $employee->id],
            channels: ['log', 'resend_email']
        );

        return $employee->refresh();
    }

    /**
     * Revoke the linked central user's app access: deactivate auth, drop all tokens,
     * AND strip the global Spatie role granted on hire. The user is resolved without
     * global scopes because it is a central record.
     */
    private function revokeAppAccess(Collaborator $employee): void
    {
        if (! $employee->user_id) {
            return;
        }

        $user = User::withoutGlobalScopes()->find($employee->user_id);
        if (! $user) {
            return;
        }

        $user->forceFill(['auth_active' => false])->save();
        $user->tokens()->delete();

        // §7.4.4: the position's Spatie role was assigned on invitation accept. Spatie
        // roles are global, so leaving it on the central user is a stale privilege that
        // re-grants permissions the instant auth is restored. Strip it on termination.
        $this->revokeKivo7Role($employee, $user);
    }

    /**
     * Remove the employee's position Spatie role from the central user, UNLESS the user
     * still legitimately holds the same slug via another ACTIVE employment or a CURRENT
     * board seat (mirrors BoardService::revokeSpatieRole's still-granted guard). Spatie
     * roles being global, removing a still-earned slug would break another seat.
     */
    private function revokeKivo7Role(Collaborator $employee, User $user): void
    {
        $role = $this->resolveKivo7Role($employee);
        if (! $role || ! $user->hasRole($role)) {
            return;
        }

        if ($this->roleStillGrantedByOtherSeat($employee, $role->name)) {
            return;
        }

        $user->removeRole($role);
    }

    /**
     * Whether the employee's user still holds $slug through another ACTIVE employment
     * or a CURRENT board seat. The terminating employee is already is_active=false by
     * the time this runs, so it is naturally excluded from the employment scan.
     */
    private function roleStillGrantedByOtherSeat(Collaborator $employee, string $slug): bool
    {
        $otherActiveEmployments = Collaborator::query()
            ->where('is_active', true)
            ->where('user_id', $employee->user_id)
            ->whereKeyNot($employee->getKey())
            ->get();

        foreach ($otherActiveEmployments as $other) {
            if ($this->resolveKivo7Role($other)?->name === $slug) {
                return true;
            }
        }

        return BoardMember::query()
            ->where('user_id', $employee->user_id)
            ->whereHas('board_role', fn ($query) => $query->where('spatie_role_slug', $slug))
            ->whereHas('board_period', fn ($query) => $query->where('board_period_status_id', BoardPeriodStatus::CURRENT))
            ->exists();
    }

    /**
     * Reactivate a terminated employee by creating a NEW Collaborator record.
     * Per spec §7.5.2: reactivation = new employment record, not reverting the termination.
     */
    public function reactivate(Collaborator $employee, array $newEmploymentData): Collaborator
    {
        if ($employee->is_active) {
            throw new \InvalidArgumentException('El empleado está activo; no es necesario reactivarlo.');
        }

        // Clone the employee's personal data into a new active record.
        $newEmployee = Collaborator::create(array_merge(
            [
                'user_id' => $employee->user_id,
                'name' => $employee->name,
                'last_name' => $employee->last_name,
                'second_last_name' => $employee->second_last_name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'rfc' => $employee->rfc,
                'curp' => $employee->curp,
                'date_of_birth' => $employee->date_of_birth,
                'gender' => $employee->gender,
                'company_id' => $employee->company_id,
                'address' => $employee->address,
                'is_active' => true,
                'termination_date' => null,
                'termination_reason' => null,
            ],
            $newEmploymentData,
        ));

        // The standard baja revokes app access (auth_active=false + role removed). A
        // rehire must restore it on the linked central user, otherwise the reactivated
        // employee can never log in again (auth_active is firewalled from the generic
        // update endpoint) and would hold no permissions.
        $this->restoreAppAccess($newEmployee);

        return $newEmployee;
    }

    /**
     * Re-enable the linked central user's app access on rehire: reactivate auth and
     * re-grant the new position's Spatie role. No-op when the employee has no linked
     * user (was never app-enabled). Mirrors revokeAppAccess in reverse.
     */
    private function restoreAppAccess(Collaborator $employee): void
    {
        if (! $employee->user_id) {
            return;
        }

        $user = User::withoutGlobalScopes()->find($employee->user_id);
        if (! $user) {
            return;
        }

        $user->forceFill(['auth_active' => true])->save();

        $role = $this->resolveKivo7Role($employee);
        if ($role && ! $user->hasRole($role)) {
            $user->assignRole($role);
        }
    }
}
