<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Services\TenantDatabaseEnsurer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Client\Models\Client;
use Modules\Client\Services\ClientService;
use Modules\Deal\Models\Deal;
use Modules\Deal\Services\DealService;
use Modules\Tenant\Database\Seeders\TenantDatabaseSeeder;
use Modules\Tenant\Models\Tenant;
use Modules\TimelineActivity\Models\TimelineActivity;
use Modules\Workflow\Models\WorkflowStage;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

final class LifecycleTransitionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private function makeTenantWithDefaults(): Tenant
    {
        $tenant = Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => 'test-lifecycle-'.bin2hex(random_bytes(4)),
                'tenancy_db_name' => 'tenant_test_'.bin2hex(random_bytes(4)),
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );

        TenantDatabaseEnsurer::ensure($tenant, seed: false);

        $tenant->run(function (): void {
            Artisan::call('db:seed', [
                '--class' => TenantDatabaseSeeder::class,
                '--force' => true,
            ]);
        });

        return $tenant;
    }

    private function makeClient(string $lifecycle = Client::LIFECYCLE_LEAD): Client
    {
        return Client::create([
            'name' => 'Test '.bin2hex(random_bytes(2)),
            'email' => 'c-'.bin2hex(random_bytes(3)).'@example.com',
            'client_type' => Client::TYPE_CRM,
            'lifecycle_stage' => $lifecycle,
        ]);
    }

    public function test_change_lifecycle_stage_writes_timeline_event(): void
    {
        $tenant = $this->makeTenantWithDefaults();

        $tenant->run(function (): void {
            $client = $this->makeClient(Client::LIFECYCLE_LEAD);
            $service = app(ClientService::class);

            $updated = $service->changeLifecycleStage($client, Client::LIFECYCLE_MQL, 'Calificado por marketing');

            $this->assertSame(Client::LIFECYCLE_MQL, $updated->lifecycle_stage);

            $event = TimelineActivity::query()
                ->where('subject_type', Client::class)
                ->where('subject_id', $client->id)
                ->where('name', TimelineActivity::EVENT_LIFECYCLE_CHANGED)
                ->latest('happens_at')
                ->first();

            $this->assertNotNull($event);
            $this->assertSame(Client::LIFECYCLE_LEAD, $event->properties['from_stage']);
            $this->assertSame(Client::LIFECYCLE_MQL, $event->properties['to_stage']);
            $this->assertSame('Calificado por marketing', $event->properties['reason']);
        });
    }

    public function test_ensure_lifecycle_at_least_never_downgrades(): void
    {
        $tenant = $this->makeTenantWithDefaults();

        $tenant->run(function (): void {
            $client = $this->makeClient(Client::LIFECYCLE_CUSTOMER);
            $service = app(ClientService::class);

            // Trying to "bump" a customer down to lead must be a no-op.
            $result = $service->ensureLifecycleAtLeast($client, Client::LIFECYCLE_LEAD);

            $this->assertSame(Client::LIFECYCLE_CUSTOMER, $result->lifecycle_stage);

            $this->assertSame(
                0,
                TimelineActivity::query()
                    ->where('subject_id', $client->id)
                    ->where('name', TimelineActivity::EVENT_LIFECYCLE_CHANGED)
                    ->count(),
                'No timeline event should have been recorded because no transition happened.',
            );
        });
    }

    public function test_ensure_lifecycle_at_least_does_upgrade_when_target_is_later(): void
    {
        $tenant = $this->makeTenantWithDefaults();

        $tenant->run(function (): void {
            $client = $this->makeClient(Client::LIFECYCLE_LEAD);
            $service = app(ClientService::class);

            $result = $service->ensureLifecycleAtLeast($client, Client::LIFECYCLE_OPPORTUNITY);

            $this->assertSame(Client::LIFECYCLE_OPPORTUNITY, $result->lifecycle_stage);
        });
    }

    public function test_moving_deal_to_won_stage_advances_client_to_customer(): void
    {
        $tenant = $this->makeTenantWithDefaults();

        $tenant->run(function (): void {
            $client = $this->makeClient(Client::LIFECYCLE_OPPORTUNITY);
            $stages = WorkflowStage::query()->orderBy('order')->get();
            $openStage = $stages->firstWhere('type', 'open');
            $wonStage = $stages->firstWhere('type', 'won');

            $this->assertNotNull($openStage, 'Tenant seed should create at least one open stage.');
            $this->assertNotNull($wonStage, 'Tenant seed should create a won stage.');

            $deal = Deal::create([
                'name' => 'Test deal',
                'amount' => 1000,
                'workflow_id' => $openStage->workflow_id,
                'workflow_stage_id' => $openStage->id,
                'client_id' => $client->id,
            ]);

            app(DealService::class)->moveDeal($deal, $wonStage->id);

            $this->assertSame(
                Client::LIFECYCLE_CUSTOMER,
                $client->fresh()->lifecycle_stage,
                'Client should auto-advance to customer when deal closes won.',
            );
        });
    }

    public function test_moving_deal_to_lost_stage_keeps_prospect_as_prospect(): void
    {
        $tenant = $this->makeTenantWithDefaults();

        $tenant->run(function (): void {
            // Client is still in opportunity (never bought). A lost deal
            // should NOT mark them as churned — they were never a customer.
            $client = $this->makeClient(Client::LIFECYCLE_OPPORTUNITY);
            $stages = WorkflowStage::query()->orderBy('order')->get();
            $openStage = $stages->firstWhere('type', 'open');
            $lostStage = $stages->firstWhere('type', 'lost');

            $deal = Deal::create([
                'name' => 'Test deal',
                'amount' => 1000,
                'workflow_id' => $openStage->workflow_id,
                'workflow_stage_id' => $openStage->id,
                'client_id' => $client->id,
            ]);

            app(DealService::class)->moveDeal($deal, $lostStage->id);

            $this->assertSame(
                Client::LIFECYCLE_OPPORTUNITY,
                $client->fresh()->lifecycle_stage,
                'Client that never became a customer stays in opportunity on a lost deal.',
            );
        });
    }

    public function test_moving_deal_to_lost_churns_an_existing_customer(): void
    {
        $tenant = $this->makeTenantWithDefaults();

        $tenant->run(function (): void {
            $client = $this->makeClient(Client::LIFECYCLE_CUSTOMER);
            $stages = WorkflowStage::query()->orderBy('order')->get();
            $openStage = $stages->firstWhere('type', 'open');
            $lostStage = $stages->firstWhere('type', 'lost');

            $deal = Deal::create([
                'name' => 'Renewal deal',
                'amount' => 1000,
                'workflow_id' => $openStage->workflow_id,
                'workflow_stage_id' => $openStage->id,
                'client_id' => $client->id,
            ]);

            app(DealService::class)->moveDeal($deal, $lostStage->id);

            $this->assertSame(
                Client::LIFECYCLE_CHURNED,
                $client->fresh()->lifecycle_stage,
                'Existing customer with a lost renewal deal should be marked as churned.',
            );
        });
    }

    public function test_creating_deal_advances_lead_to_opportunity(): void
    {
        $tenant = $this->makeTenantWithDefaults();

        $tenant->run(function (): void {
            $client = $this->makeClient(Client::LIFECYCLE_LEAD);
            $stages = WorkflowStage::query()->orderBy('order')->get();
            $openStage = $stages->firstWhere('type', 'open');

            $deal = Deal::create([
                'name' => 'New deal',
                'amount' => 500,
                'workflow_id' => $openStage->workflow_id,
                'workflow_stage_id' => $openStage->id,
                'client_id' => $client->id,
            ]);

            // Simulates the DealController::performStore hook which manually invokes this.
            app(DealService::class)->advanceClientLifecycleOnDealCreation($deal);

            $this->assertSame(
                Client::LIFECYCLE_OPPORTUNITY,
                $client->fresh()->lifecycle_stage,
                'Lead should advance to opportunity when first deal is created against them.',
            );
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Tenant::withTrashed()
            ->where('name', 'like', 'test-lifecycle-%')
            ->get()
            ->each(static function (Tenant $tenant): void {
                try {
                    $manager = $tenant->database()->manager();
                    if ($manager && $manager->databaseExists($tenant->database()->getName())) {
                        $manager->deleteDatabase($tenant);
                    }
                } catch (\Throwable) {
                    //
                }
                Tenant::withoutEvents(static fn () => $tenant->forceDelete());
            });

        parent::tearDown();
    }
}
