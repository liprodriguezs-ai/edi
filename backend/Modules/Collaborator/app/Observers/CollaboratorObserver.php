<?php

namespace Modules\Collaborator\Observers;

use App\Notifications\Concerns\NotifiesResidents;
use Modules\Collaborator\Models\Collaborator;
use Modules\Company\Services\CompanyService;
use Modules\User\Services\UserInvitationService;

class CollaboratorObserver
{
    use NotifiesResidents;

    /**
     * When an existing collaborator has user_id cleared (unlink), create_as_user must be false.
     */
    public function saving(Collaborator $collaborator): void
    {
        $userLinkCleared = $collaborator->exists
            && $collaborator->user_id === null
            && $collaborator->getOriginal('user_id') !== null;
        if ($userLinkCleared) {
            $collaborator->create_as_user = false;
        }
    }

    /**
     * When a collaborator is created or updated with create_as_user and no user_id,
     * create and send a user invitation (no User is created until the invitee accepts).
     */
    public function created(Collaborator $collaborator): void
    {
        $this->createInvitationIfRequested($collaborator);
        $this->notifyEmployeeHired($collaborator);
    }

    /**
     * §7.7: SMS the newly-hired employee. Only fires for active hires linked to a
     * central user (so a recipient id exists). Never breaks the create on failure.
     */
    private function notifyEmployeeHired(Collaborator $collaborator): void
    {
        if (! $collaborator->user_id || ! $collaborator->is_active) {
            return;
        }

        $name = trim((string) ($collaborator->name ?? ''));

        $this->notifyUsers(
            [$collaborator->user_id],
            'Bienvenido al equipo',
            'Hola '.($name !== '' ? $name : 'colaborador').', has sido dado de alta como empleado del condominio.',
            ['click_action' => '/collaborator/me', 'collaborator_id' => $collaborator->id],
            channels: ['log', 'sms']
        );
    }

    public function updated(Collaborator $collaborator): void
    {
        $this->createInvitationIfRequested($collaborator);
    }

    private function createInvitationIfRequested(Collaborator $collaborator): void
    {
        if ($collaborator->company_id === null) {
            $companyService = new CompanyService;
            $company = $companyService->ensureMainCompanyForTenant(tenant());
            $collaborator->company_id = $company->id ?? null;
            $collaborator->saveQuietly();
        }

        if ($collaborator->user_id !== null) {
            return;
        }
        if (! $this->shouldCreateUser($collaborator)) {
            return;
        }
        $email = $collaborator->email ? trim((string) $collaborator->email) : '';
        if ($email === '') {
            return;
        }

        $service = app(UserInvitationService::class);
        $service->createAndSendInvitation($collaborator);
    }

    private function shouldCreateUser(Collaborator $collaborator): bool
    {
        return (bool) ($collaborator->create_as_user ?? false);
    }
}
