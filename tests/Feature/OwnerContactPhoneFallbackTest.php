<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillOwnerPhones;
use App\Console\Commands\WorkOrderImportCommand;
use App\Jobs\ImportWorkOrderJob;
use App\Jobs\SendOwnerServiceRequestNotificationJob;
use App\Models\Owner;
use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * WO #43819: PropertyWare's portfolio owner (Luis Cuevas) has every phone
 * field blank, while his contact record carries the home phone. The sync
 * used to store only the owner's `phone`, so the Owner tab showed no number.
 */
class OwnerContactPhoneFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const CONTACT_URL = 'api.propertyware.com/pw/api/rest/v1/contacts/*';

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('owner', 'web');
        Role::findOrCreate('woc', 'web');
        Cache::flush();
        Sleep::fake();
    }

    /**
     * The portfolio owner exactly as PropertyWare's SOAP work order returns
     * it for WO #43819: every phone field present and blank.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function pwOwner(array $overrides = []): array
    {
        return array_merge([
            'clientData' => null,
            'ID' => 6136987740,
            'address' => '3418 Center Street',
            'address2' => '',
            'altPhone' => '',
            'city' => 'Houston',
            'companyName' => '',
            'contactId' => 6136987740,
            'country' => 'USA',
            'customFields' => null,
            'email' => 'owner.cuevas@example.com',
            'firstName' => 'Luis',
            'homePhone' => '',
            'lastName' => 'Cuevas',
            'mobile' => '',
            'name' => 'Luis Cuevas',
            'nameOnCheck' => 'Luis Cuevas',
            'notes' => null,
            'percentageOwnership' => 100,
            'phone' => '',
            'state' => 'TX',
            'workTelePhone' => '',
            'zip' => '77007',
        ], $overrides);
    }

    /**
     * The REST contact as PropertyWare returns it for the same owner.
     *
     * @param  array<string, string>  $phones
     * @return array<string, mixed>
     */
    private function pwContact(array $phones = ['homePhone' => '(281) 686-4966']): array
    {
        return array_merge([
            'id' => 6136987740,
            'firstName' => 'Luis',
            'lastName' => 'Cuevas',
            'workPhone' => '',
            'homePhone' => '',
            'mobilePhone' => '',
            'otherPhone' => '',
            'fax' => '',
        ], $phones);
    }

    private function fakeContact(array $phones = ['homePhone' => '(281) 686-4966'], int $status = 200): void
    {
        Http::fake([self::CONTACT_URL => Http::response($this->pwContact($phones), $status)]);
    }

    private function contactCalls(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), '/contacts/'))->count();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function importers(): array
    {
        return [
            'scheduled SOAP import' => [WorkOrderImportCommand::class],
            'queued import job' => [ImportWorkOrderJob::class],
            'single work order import' => [WorkOrderService::class],
        ];
    }

    /**
     * @param  array<string, mixed>  $pwOwner
     */
    private function processOwners(string $importer, array $pwOwner, ?WorkOrder $workOrder = null): void
    {
        $workOrder ??= WorkOrder::factory()->create();

        $instance = match ($importer) {
            ImportWorkOrderJob::class => new ImportWorkOrderJob([]),
            WorkOrderService::class => new WorkOrderService,
            default => app($importer),
        };

        $method = new ReflectionMethod($instance, 'processOwners');
        $method->invoke($instance, ['portfolio' => ['owners' => [$pwOwner]]], $workOrder->id, now()->toDateTimeString());
    }

    private function storedPhone(): ?string
    {
        return DB::table('owners')->where('propertyware_id', 6136987740)->value('phone');
    }

    #[DataProvider('importers')]
    public function test_a_blank_owner_takes_the_contact_home_phone(string $importer): void
    {
        $this->fakeContact();

        $this->processOwners($importer, $this->pwOwner());

        $this->assertSame('(281) 686-4966', $this->storedPhone());
        $this->assertSame('+12816864966', Owner::query()->where('propertyware_id', 6136987740)->first()->phone);
    }

    #[DataProvider('importers')]
    public function test_an_owner_with_a_phone_of_its_own_never_asks_the_contact(string $importer): void
    {
        $this->fakeContact();

        $this->processOwners($importer, $this->pwOwner(['phone' => '713-555-0101']));

        $this->assertSame('713-555-0101', $this->storedPhone());
        $this->assertSame(0, $this->contactCalls());
    }

    #[DataProvider('importers')]
    public function test_a_number_found_earlier_survives_a_blank_sync(string $importer): void
    {
        Owner::factory()->create(['propertyware_id' => 6136987740, 'phone' => '(281) 686-4966']);
        $this->fakeContact([]);

        $this->processOwners($importer, $this->pwOwner());

        $this->assertSame('(281) 686-4966', $this->storedPhone());
        $this->assertSame(0, $this->contactCalls());
    }

    public function test_the_owner_own_mobile_beats_its_home_phone(): void
    {
        $this->fakeContact();

        $this->processOwners(WorkOrderImportCommand::class, $this->pwOwner([
            'homePhone' => '713-555-0102',
            'mobile' => '713-555-0103',
        ]));

        $this->assertSame('713-555-0103', $this->storedPhone());
        $this->assertSame(0, $this->contactCalls());
    }

    public function test_the_contact_mobile_beats_home_and_home_beats_work(): void
    {
        Http::fake([
            '*/contacts/1' => Http::response($this->pwContact(['mobilePhone' => '713-555-0104', 'homePhone' => '713-555-0105', 'workPhone' => '713-555-0106'])),
            '*/contacts/2' => Http::response($this->pwContact(['homePhone' => '713-555-0105', 'workPhone' => '713-555-0106'])),
            '*/contacts/3' => Http::response($this->pwContact(['workPhone' => '713-555-0106', 'otherPhone' => '713-555-0107'])),
        ]);
        $service = app(PropertyWareService::class);

        $this->assertSame('713-555-0104', $service->getContactPhone(1));
        $this->assertSame('713-555-0105', $service->getContactPhone(2));
        $this->assertSame('713-555-0106', $service->getContactPhone(3));
    }

    public function test_a_failed_contact_lookup_leaves_the_phone_blank_and_the_import_carries_on(): void
    {
        $this->fakeContact([], 500);

        $this->processOwners(WorkOrderImportCommand::class, $this->pwOwner());

        $this->assertDatabaseHas('owners', ['propertyware_id' => 6136987740, 'phone' => null]);
        $this->assertSame(1, DB::table('work_order_owners')->count());
    }

    public function test_a_failed_lookup_is_not_cached_so_the_next_sync_retries(): void
    {
        Http::fake([self::CONTACT_URL => Http::sequence()
            ->push('Server Error', 500)
            ->push($this->pwContact())]);
        $service = app(PropertyWareService::class);

        $this->assertNull($service->getContactPhone(6136987740));
        $this->assertSame('(281) 686-4966', $service->getContactPhone(6136987740));
    }

    public function test_an_answer_is_cached_so_the_five_minute_syncs_ask_once(): void
    {
        $this->fakeContact([]);

        $this->processOwners(WorkOrderImportCommand::class, $this->pwOwner());
        $this->processOwners(WorkOrderImportCommand::class, $this->pwOwner());

        $this->assertSame(1, $this->contactCalls());
    }

    public function test_resyncing_an_existing_work_order_does_not_send_the_owner_the_new_request_text(): void
    {
        Queue::fake();

        if (! ServiceStatus::query()->where('name', 'New')->exists()) {
            ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
        }

        WorkOrder::factory()->create(['propertyware_id' => 777001]);

        $propertyWare = Mockery::mock(PropertyWareService::class);
        $propertyWare->shouldReceive('getWorkOrders')->andReturn([[
            'ID' => 777001,
            'number' => 43819,
            'building' => ['portfolio' => 'LUISCUEVAS', 'abbreviation' => 'BLDG1', 'ID' => 4100947970],
            'category' => 'Plumbing',
            'status' => 'Open',
            'priorityAsInt' => 3,
            'description' => 'Leak under the sink.',
            'customFields' => [['fieldName' => 'Service Status', 'value' => 'New']],
            'portfolio' => ['ID' => 3714842624, 'owners' => [$this->pwOwner()]],
        ]]);
        $propertyWare->shouldReceive('getContactPhone')->with(6136987740)->andReturn('(281) 686-4966');
        $this->app->instance(PropertyWareService::class, $propertyWare);

        $this->artisan('import:work-orders')->assertExitCode(0);

        $this->assertSame('(281) 686-4966', $this->storedPhone());
        Queue::assertNotPushed(SendOwnerServiceRequestNotificationJob::class);
    }

    private function ownerOnAWorkOrder(array $attributes = []): Owner
    {
        $owner = Owner::factory()->create(array_merge([
            'propertyware_id' => 6136987740,
            'contact_id' => 6136987740,
            'phone' => null,
        ], $attributes));

        DB::table('work_order_owners')->insert([
            'work_order_id' => WorkOrder::factory()->create()->id,
            'owner_id' => $owner->id,
        ]);

        return $owner;
    }

    public function test_backfill_dry_run_saves_nothing(): void
    {
        $this->fakeContact();
        $owner = $this->ownerOnAWorkOrder();

        $this->artisan('owners:backfill-phones', ['--dry-run' => true])
            ->expectsOutputToContain('would set (281) 686-4966')
            ->assertSuccessful();

        $this->assertNull(DB::table('owners')->where('id', $owner->id)->value('phone'));
        $this->assertSame(0, Activity::query()->where('description', BackfillOwnerPhones::LOG_DESCRIPTION)->count());
    }

    public function test_backfill_fills_only_blank_owners_and_contacts_nobody(): void
    {
        Queue::fake();
        Bus::fake();
        Mail::fake();
        Notification::fake();
        $this->fakeContact();

        $blank = $this->ownerOnAWorkOrder();
        $withPhone = $this->ownerOnAWorkOrder(['propertyware_id' => 111, 'contact_id' => 111, 'phone' => '713-555-0110']);
        $notOnAWorkOrder = Owner::factory()->create(['contact_id' => 222, 'phone' => null]);

        $this->artisan('owners:backfill-phones')->assertSuccessful();

        $this->assertSame('(281) 686-4966', DB::table('owners')->where('id', $blank->id)->value('phone'));
        $this->assertSame('713-555-0110', DB::table('owners')->where('id', $withPhone->id)->value('phone'));
        $this->assertNull(DB::table('owners')->where('id', $notOnAWorkOrder->id)->value('phone'));

        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/contacts/6136987740'));

        $this->assertSame(1, Activity::query()->where('description', BackfillOwnerPhones::LOG_DESCRIPTION)->count());
    }

    public function test_backfill_refuses_to_apply_while_the_owner_follow_up_text_is_on(): void
    {
        config(['services.twilio.owner_schedule_followup_sms' => true]);
        $this->fakeContact();
        $owner = $this->ownerOnAWorkOrder();

        $this->artisan('owners:backfill-phones')->assertFailed();

        $this->assertNull(DB::table('owners')->where('id', $owner->id)->value('phone'));
        $this->assertSame(0, $this->contactCalls());
    }

    public function test_backfill_rerun_finds_nothing_and_undo_clears_only_what_it_wrote(): void
    {
        $this->fakeContact();
        $kept = $this->ownerOnAWorkOrder();
        $cleared = $this->ownerOnAWorkOrder(['propertyware_id' => 333, 'contact_id' => 333]);

        $this->artisan('owners:backfill-phones')->assertSuccessful();
        $this->artisan('owners:backfill-phones')
            ->expectsOutputToContain('0 owner(s) on a work order have no phone')
            ->assertSuccessful();

        DB::table('owners')->where('id', $kept->id)->update(['phone' => '713-555-0120']);

        $this->artisan('owners:backfill-phones', ['--undo' => true])->assertSuccessful();

        $this->assertSame('713-555-0120', DB::table('owners')->where('id', $kept->id)->value('phone'));
        $this->assertNull(DB::table('owners')->where('id', $cleared->id)->value('phone'));
    }
}
