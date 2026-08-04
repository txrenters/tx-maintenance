<?php

namespace Tests\Feature;

use App\Models\ServiceStatus;
use App\Models\TwilioPhoneNumber;
use App\Models\User;
use App\Models\WOCNumbers;
use App\Models\WorkOrder;
use App\Services\DescriptionChangeAlertService;
use App\Services\PropertyWareService;
use App\Services\TwilioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DescriptionChangeAlertTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DescriptionChangeAlertService
    {
        return app(DescriptionChangeAlertService::class);
    }

    private function alertCount(): int
    {
        return Activity::query()->where('event', 'work_order_description_updated')->count();
    }

    public function test_changed_description_creates_a_staff_alert(): void
    {
        $workOrder = WorkOrder::factory()->create(['description' => 'Leaky faucet.']);

        $this->service()->detectAndAlert($workOrder, 'Leaky faucet.', 'Leaky faucet. Also the disposal is broken.', 'rest_status_sync');

        $alert = Activity::query()->where('event', 'work_order_description_updated')->first();
        $this->assertNotNull($alert);
        $this->assertSame($workOrder->id, $alert->properties['work_order_id']);
        $this->assertSame('Leaky faucet.', $alert->properties['old_description']);
        $this->assertSame('Leaky faucet. Also the disposal is broken.', $alert->properties['new_description']);
        $this->assertSame('rest_status_sync', $alert->properties['source']);
        $this->assertFalse($alert->properties['read']);
    }

    public function test_identical_description_creates_no_alert(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->service()->detectAndAlert($workOrder, 'Same text.', 'Same text.', 'rest_status_sync');

        $this->assertSame(0, $this->alertCount());
    }

    public function test_whitespace_only_difference_creates_no_alert(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->service()->detectAndAlert(
            $workOrder,
            "Fix sink\r\nand  tub. ",
            "Fix sink\nand tub.",
            'soap_import'
        );

        $this->assertSame(0, $this->alertCount());
    }

    public function test_blank_incoming_description_creates_no_alert(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->service()->detectAndAlert($workOrder, 'Existing description.', null, 'soap_import');
        $this->service()->detectAndAlert($workOrder, 'Existing description.', '   ', 'soap_import');

        $this->assertSame(0, $this->alertCount());
    }

    public function test_first_population_of_an_empty_description_creates_no_alert(): void
    {
        $workOrder = WorkOrder::factory()->create(['description' => null]);

        $this->service()->detectAndAlert($workOrder, null, 'Fresh description from PropertyWare.', 'rest_status_sync');
        $this->service()->detectAndAlert($workOrder, '', 'Fresh description from PropertyWare.', 'rest_status_sync');

        $this->assertSame(0, $this->alertCount());
    }

    public function test_sms_gate_off_creates_the_bell_alert_without_texting(): void
    {
        config(['services.twilio.description_change_sms' => false]);

        $this->mock(TwilioService::class)->shouldNotReceive('sendMessage');

        $workOrder = WorkOrder::factory()->create();

        $this->service()->detectAndAlert($workOrder, 'Old text.', 'New text with more items.', 'rest_status_sync');

        $this->assertSame(1, $this->alertCount());
    }

    public function test_sms_gate_on_texts_the_assigned_woc_from_their_own_number(): void
    {
        config(['services.twilio.description_change_sms' => true]);

        $woc = User::factory()->create(['phone' => '+15550001111']);
        $twilioNumber = TwilioPhoneNumber::query()->create([
            'account_sid' => 'AC123',
            'sid' => 'PN123',
            'phone_number' => '+15550009999',
        ]);
        WOCNumbers::query()->create([
            'user_id' => $woc->id,
            'twilio_phone_number_id' => $twilioNumber->id,
        ]);

        $workOrder = WorkOrder::factory()->create(['user_id' => $woc->id]);

        $this->mock(TwilioService::class)
            ->shouldReceive('sendMessage')
            ->once()
            ->with('+15550001111', '+15550009999', Mockery::type('string'));

        $this->service()->detectAndAlert($workOrder, 'Old text.', 'New text with more items.', 'soap_import');

        $this->assertSame(1, $this->alertCount());
    }

    public function test_a_distinct_second_edit_alerts_again(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->service()->detectAndAlert($workOrder, 'Version one.', 'Version two.', 'rest_status_sync');
        $this->service()->detectAndAlert($workOrder, 'Version two.', 'Version three.', 'rest_status_sync');

        $this->assertSame(2, $this->alertCount());
    }

    public function test_the_same_edit_within_the_dedupe_window_alerts_once(): void
    {
        $workOrder = WorkOrder::factory()->create();

        // The two PropertyWare syncs can both read the old value before
        // either writes, so the same edit may be detected twice.
        $this->service()->detectAndAlert($workOrder, 'Version one.', 'Version two.', 'soap_import');
        $this->service()->detectAndAlert($workOrder, 'Version one.', 'Version two.', 'rest_status_sync');

        $this->assertSame(1, $this->alertCount());
    }

    public function test_rest_sync_detects_a_description_change_and_rerun_does_not_realert(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 555001,
            'description' => 'Original tenant request.',
        ]);

        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('getWorkOrdersViaRestAPI')
            ->twice()
            ->andReturn([[
                'id' => 555001,
                'status' => 'Open',
                'description' => 'Original tenant request. Plus five added items.',
            ]]);
        $this->app->instance(PropertyWareService::class, $mock);

        $this->artisan('update:work-orders-status')->assertExitCode(0);

        $this->assertSame(1, $this->alertCount());
        $this->assertSame(
            'Original tenant request. Plus five added items.',
            $workOrder->fresh()->description
        );

        // The value is applied now, so a second run sees no difference.
        $this->artisan('update:work-orders-status')->assertExitCode(0);

        $this->assertSame(1, $this->alertCount());
    }

    /**
     * Minimal SOAP payload for import:work-orders — building keys are read
     * unguarded, and the Service Status custom field must resolve to a real
     * service_status row (the column is a non-nullable foreign key).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function soapWorkOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'ID' => 777001,
            'number' => 4321,
            'building' => ['portfolio' => 'Portfolio A', 'abbreviation' => 'BLDG1', 'ID' => 999001],
            'category' => 'Maintenance',
            'status' => 'Open',
            'priorityAsInt' => 3,
            'description' => 'Original tenant request.',
            'customFields' => [
                ['fieldName' => 'Service Status', 'value' => 'New'],
            ],
        ], $overrides);
    }

    private function prepareSoapImport(array $payload): void
    {
        Role::findOrCreate('woc', 'web');

        if (! ServiceStatus::query()->where('name', 'New')->exists()) {
            ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
        }

        $mock = Mockery::mock(PropertyWareService::class);
        $mock->shouldReceive('getWorkOrders')->andReturn([$payload]);
        $this->app->instance(PropertyWareService::class, $mock);
    }

    public function test_soap_import_detects_a_description_change_on_an_existing_work_order(): void
    {
        Queue::fake();

        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 777001,
            'description' => 'Original tenant request.',
        ]);

        $this->prepareSoapImport($this->soapWorkOrderPayload([
            'description' => 'Original tenant request. Tenant added five more items.',
        ]));

        $this->artisan('import:work-orders')->assertExitCode(0);

        $this->assertSame(1, $this->alertCount());
        $alert = Activity::query()->where('event', 'work_order_description_updated')->first();
        $this->assertSame($workOrder->id, $alert->properties['work_order_id']);
        $this->assertSame('soap_import', $alert->properties['source']);
    }

    public function test_soap_import_of_a_new_work_order_creates_no_alert(): void
    {
        Queue::fake();

        $this->prepareSoapImport($this->soapWorkOrderPayload());

        $this->artisan('import:work-orders')->assertExitCode(0);

        $this->assertDatabaseHas('work_orders', ['propertyware_id' => 777001]);
        $this->assertSame(0, $this->alertCount());
    }

    public function test_soap_import_without_a_description_creates_no_alert(): void
    {
        Queue::fake();

        WorkOrder::factory()->create([
            'propertyware_id' => 777001,
            'description' => 'Original tenant request.',
        ]);

        $payload = $this->soapWorkOrderPayload();
        unset($payload['description']);

        $this->prepareSoapImport($payload);

        $this->artisan('import:work-orders')->assertExitCode(0);

        $this->assertSame(0, $this->alertCount());
    }
}
