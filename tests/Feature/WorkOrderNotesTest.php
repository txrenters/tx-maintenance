<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderJobberNote;
use App\Models\WorkOrderNotes;
use App\Services\PropertyWareService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkOrderNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'vendor', 'owner', 'tenant'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    /**
     * @return array{0: User, 1: Vendor}
     */
    private function makeVendorUser(): array
    {
        $user = User::factory()->create();
        $user->assignRole('vendor');

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.$user->id,
            'name' => 'Texas Home Maintenance Pros',
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => $user->id,
        ]);

        return [$user, $vendor];
    }

    private function makeWoc(): User
    {
        $user = User::factory()->create();
        $user->assignRole('woc');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function note(WorkOrder $workOrder, array $attributes = []): WorkOrderNotes
    {
        return WorkOrderNotes::query()->create(array_merge([
            'work_order_id' => $workOrder->id,
            'subject' => 'Note',
            'body' => 'Body',
            'is_private' => false,
        ], $attributes));
    }

    public function test_assigned_vendor_note_is_saved_privately_and_pushed_to_propertyware(): void
    {
        [$vendorUser, $vendor] = $this->makeVendorUser();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 777001]);
        $workOrder->vendors()->attach($vendor->id);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('addVendorNotes')->once()->andReturn(true);
        });

        $this->actingAs($vendorUser)
            ->post(route('api.work_order_notes.store'), [
                'subject' => 'Diagnosis',
                'body' => 'A/C & heat: compressor < 60 psi.',
                'work_order_id' => $workOrder->id,
            ])
            ->assertRedirect()
            ->assertSessionMissing('warning');

        $this->assertDatabaseHas('work_order_notes', [
            'work_order_id' => $workOrder->id,
            'user_id' => $vendorUser->id,
            'subject' => 'Diagnosis',
            'body' => 'A/C & heat: compressor < 60 psi.',
            'is_private' => 1,
        ]);
    }

    public function test_note_is_kept_on_the_dashboard_when_propertyware_rejects_it(): void
    {
        [$vendorUser, $vendor] = $this->makeVendorUser();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 777001]);
        $workOrder->vendors()->attach($vendor->id);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('addVendorNotes')->once()->andReturn(false);
        });

        $this->actingAs($vendorUser)
            ->post(route('api.work_order_notes.store'), [
                'subject' => 'Diagnosis',
                'body' => 'Compressor failed.',
                'work_order_id' => $workOrder->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('work_order_notes', [
            'work_order_id' => $workOrder->id,
            'user_id' => $vendorUser->id,
            'body' => 'Compressor failed.',
        ]);
    }

    public function test_note_is_kept_on_the_dashboard_when_propertyware_throws(): void
    {
        [$vendorUser, $vendor] = $this->makeVendorUser();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 777001]);
        $workOrder->vendors()->attach($vendor->id);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('addVendorNotes')->once()->andThrow(new \RuntimeException('SOAP down'));
        });

        $this->actingAs($vendorUser)
            ->post(route('api.work_order_notes.store'), [
                'subject' => 'Diagnosis',
                'body' => 'Compressor failed.',
                'work_order_id' => $workOrder->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('work_order_notes', 1);
    }

    public function test_vendor_cannot_note_a_work_order_they_are_not_assigned_to(): void
    {
        [$vendorUser] = $this->makeVendorUser();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 777001]);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldNotReceive('addVendorNotes');
        });

        $this->actingAs($vendorUser)
            ->post(route('api.work_order_notes.store'), [
                'subject' => 'Diagnosis',
                'body' => 'Not my job.',
                'work_order_id' => $workOrder->id,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('work_order_notes', 0);
    }

    public function test_coordinator_can_note_any_work_order(): void
    {
        $woc = $this->makeWoc();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 777001]);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('addVendorNotes')->once()->andReturn(true);
        });

        $this->actingAs($woc)
            ->post(route('api.work_order_notes.store'), [
                'subject' => 'Office',
                'body' => 'Owner approved the estimate.',
                'work_order_id' => $workOrder->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('work_order_notes', ['user_id' => $woc->id, 'subject' => 'Office']);
    }

    public function test_vendor_cannot_delete_another_users_note(): void
    {
        [$vendorUser, $vendor] = $this->makeVendorUser();
        $workOrder = WorkOrder::factory()->create();
        $workOrder->vendors()->attach($vendor->id);
        $note = $this->note($workOrder, ['user_id' => $this->makeWoc()->id]);

        $this->actingAs($vendorUser)
            ->delete(route('api.work_order_notes.destroy', $note))
            ->assertForbidden();

        $this->assertDatabaseHas('work_order_notes', ['id' => $note->id]);
    }

    public function test_vendor_can_delete_their_own_unpushed_note(): void
    {
        [$vendorUser, $vendor] = $this->makeVendorUser();
        $workOrder = WorkOrder::factory()->create();
        $workOrder->vendors()->attach($vendor->id);
        $note = $this->note($workOrder, ['user_id' => $vendorUser->id, 'propertyware_id' => null]);

        $this->actingAs($vendorUser)
            ->delete(route('api.work_order_notes.destroy', $note))
            ->assertRedirect()
            ->assertSessionMissing('warning');

        $this->assertDatabaseMissing('work_order_notes', ['id' => $note->id]);
    }

    public function test_a_note_stored_in_propertyware_is_not_deleted_locally(): void
    {
        $woc = $this->makeWoc();
        $workOrder = WorkOrder::factory()->create();
        $note = $this->note($workOrder, ['propertyware_id' => 555]);

        $this->actingAs($woc)
            ->delete(route('api.work_order_notes.destroy', $note))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('work_order_notes', ['id' => $note->id]);
    }

    public function test_staff_see_private_notes_but_tenant_and_owner_logins_do_not(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->note($workOrder, ['subject' => 'Public note', 'is_private' => false]);
        $this->note($workOrder, ['subject' => 'Internal note', 'is_private' => true, 'user_id' => $this->makeWoc()->id]);

        $this->actingAs($this->makeWoc())
            ->getJson(route('api.work_order_notes.show', $workOrder))
            ->assertOk()
            ->assertJsonCount(2, 'notes');

        $tenantUser = User::factory()->create();
        $tenantUser->assignRole('tenant');
        $tenant = Tenants::factory()->create(['user_id' => $tenantUser->id]);
        $workOrder->update(['tenant_id' => $tenant->id]);

        $this->actingAs($tenantUser)
            ->getJson(route('api.work_order_notes.show', $workOrder))
            ->assertOk()
            ->assertJsonCount(1, 'notes')
            ->assertJsonPath('notes.0.subject', 'Public note');

        $ownerUser = User::factory()->create();
        $ownerUser->assignRole('owner');
        $owner = Owner::query()->create([
            'first_name' => 'Pat',
            'last_name' => 'Owner',
            'email' => 'pat.owner@example.com',
            'user_id' => $ownerUser->id,
        ]);
        $workOrder->owners()->attach($owner->id);

        $this->actingAs($ownerUser)
            ->getJson(route('api.work_order_notes.show', $workOrder))
            ->assertOk()
            ->assertJsonCount(1, 'notes')
            ->assertJsonPath('notes.0.subject', 'Public note');

        // The full page ships the work order with its (filtered) notes relation.
        $this->actingAs($tenantUser)
            ->get(route('work_orders.details', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('workOrder.notes', 1)
                ->where('workOrder.notes.0.subject', 'Public note'));

        $this->actingAs($this->makeWoc())
            ->get(route('work_orders.details', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('workOrder.notes', 2));
    }

    public function test_each_note_reports_when_it_was_added(): void
    {
        $woc = $this->makeWoc();
        $workOrder = WorkOrder::factory()->create();

        // Written on the dashboard: the row's own timestamp is the truth.
        $this->travelTo(Carbon::parse('2026-08-28 20:15:00', 'UTC'));
        $this->note($workOrder, ['subject' => 'Dashboard', 'user_id' => $woc->id]);

        // Copied in by a later sync: PropertyWare's note date beats the import time.
        $this->travelTo(Carbon::parse('2026-08-29 03:00:00', 'UTC'));
        $this->note($workOrder, ['subject' => 'From PropertyWare', 'propertyware_id' => 901, 'date' => '2026-08-20T10:00:00']);
        $this->note($workOrder, ['subject' => 'Day only', 'propertyware_id' => 902, 'date' => '2026-08-21']);
        $this->note($workOrder, ['subject' => 'Blank date', 'propertyware_id' => 903, 'date' => '']);
        $this->note($workOrder, ['subject' => 'Bad date', 'propertyware_id' => 904, 'date' => 'not a date']);
        $this->travelBack();

        $response = $this->actingAs($woc)
            ->getJson(route('api.work_order_notes.show', $workOrder))
            ->assertOk()
            ->assertJsonCount(5, 'notes');

        $addedAt = collect($response->json('notes'))->pluck('added_at', 'subject')->all();

        $this->assertSame([
            'Dashboard' => '2026-08-28T20:15:00.000000Z',
            'From PropertyWare' => '2026-08-20T10:00:00.000000Z',
            'Day only' => '2026-08-21T00:00:00.000000Z',
            'Blank date' => '2026-08-29T03:00:00.000000Z',
            'Bad date' => '2026-08-29T03:00:00.000000Z',
        ], $addedAt);

        // The full work order page ships the same field with its notes.
        $this->actingAs($woc)
            ->get(route('work_orders.details', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('workOrder.notes', 5)
                ->where('workOrder.notes.0.added_at', '2026-08-28T20:15:00.000000Z'));
    }

    public function test_each_note_reports_who_wrote_it(): void
    {
        $woc = $this->makeWoc();
        $workOrder = WorkOrder::factory()->create();

        $this->note($workOrder, ['subject' => 'Dashboard', 'user_id' => $woc->id]);
        $this->note($workOrder, ['subject' => 'From PropertyWare', 'propertyware_id' => 901]);

        // The Notes tab prints the writer's name next to the timestamp, so the
        // payload must carry the user for a dashboard note — and none for a
        // PropertyWare note, which the tab labels "PropertyWare" instead.
        $this->actingAs($woc)
            ->getJson(route('api.work_order_notes.show', $workOrder))
            ->assertOk()
            ->assertJsonPath('notes.0.user.name', $woc->name)
            ->assertJsonPath('notes.1.user', null);

        // The full work order page seeds the tab from its own props.
        $this->actingAs($woc)
            ->get(route('work_orders.details', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workOrder.notes.0.user.name', $woc->name)
                ->where('workOrder.notes.1.user', null));
    }

    public function test_a_note_added_on_the_dashboard_is_stamped_with_the_moment_it_was_saved(): void
    {
        $woc = $this->makeWoc();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 777001]);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('addVendorNotes')->once()->andReturn(true);
        });

        $this->travelTo(Carbon::parse('2026-08-28 14:55:00', 'UTC'));

        $this->actingAs($woc)
            ->post(route('api.work_order_notes.store'), [
                'subject' => 'Visit',
                'body' => 'Replaced the capacitor.',
                'work_order_id' => $workOrder->id,
            ])
            ->assertRedirect();

        $this->travelBack();

        // No PropertyWare date on a dashboard note, so the save time is what shows.
        $this->actingAs($woc)
            ->getJson(route('api.work_order_notes.show', $workOrder))
            ->assertOk()
            ->assertJsonPath('notes.0.date', null)
            ->assertJsonPath('notes.0.added_at', '2026-08-28T14:55:00.000000Z');
    }

    public function test_coordinator_can_send_an_unpushed_note_to_propertyware_by_hand(): void
    {
        $woc = $this->makeWoc();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 8144617505, 'work_order_no' => 43649]);
        $note = $this->note($workOrder, ['user_id' => $woc->id, 'subject' => 'Closing Comment', 'body' => 'Invoice uploaded.']);

        $this->mock(PropertyWareService::class, function ($mock) use ($note) {
            // PropertyWare is read first; it has nothing, so the note is sent.
            $mock->shouldReceive('workOrderNotesFromPropertyWare')->once()
                ->withArgs(fn ($number) => (string) $number === '43649')
                ->andReturn([]);
            $mock->shouldReceive('addVendorNotes')->once()
                ->withArgs(fn (WorkOrderNotes $pushed) => $pushed->id === $note->id)
                ->andReturnUsing(function (WorkOrderNotes $pushed): bool {
                    $pushed->forceFill(['propertyware_id' => 8001])->saveQuietly();

                    return true;
                });
        });

        $this->actingAs($woc)
            ->post(route('api.work_order_notes.push', $note))
            ->assertRedirect()
            ->assertSessionMissing('warning');

        $this->assertSame('8001', (string) $note->fresh()->propertyware_id);
    }

    public function test_a_note_propertyware_already_has_is_linked_instead_of_sent_twice(): void
    {
        $woc = $this->makeWoc();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 8144617505, 'work_order_no' => 43649]);
        $note = $this->note($workOrder, ['user_id' => $woc->id, 'subject' => 'Closing Comment', 'body' => "Invoice uploaded.\nThanks."]);

        $this->mock(PropertyWareService::class, function ($mock) {
            // The first push did land (its id was unreadable): same text, PropertyWare's id.
            $mock->shouldReceive('workOrderNotesFromPropertyWare')->once()->andReturn([
                ['ID' => 8002, 'subject' => 'Closing Comment', 'body' => "Invoice uploaded.\r\nThanks.", 'private' => true, 'date' => '2026-09-02T21:46:00'],
            ]);
            $mock->shouldNotReceive('addVendorNotes');
        });

        $this->actingAs($woc)
            ->post(route('api.work_order_notes.push', $note))
            ->assertRedirect()
            ->assertSessionHas('warning', fn (string $warning) => str_contains($warning, 'already had this note'));

        $this->assertSame('8002', (string) $note->fresh()->propertyware_id);
        $this->assertDatabaseCount('work_order_notes', 1);
    }

    public function test_nothing_is_sent_while_propertyware_cannot_be_read(): void
    {
        $woc = $this->makeWoc();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 8144617505, 'work_order_no' => 43649]);
        $note = $this->note($workOrder, ['user_id' => $woc->id]);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('workOrderNotesFromPropertyWare')->once()->andReturn(null);
            $mock->shouldNotReceive('addVendorNotes');
        });

        $this->actingAs($woc)
            ->post(route('api.work_order_notes.push', $note))
            ->assertRedirect()
            ->assertSessionHas('warning', fn (string $warning) => str_contains($warning, 'could not be read'));

        $this->assertNull($note->fresh()->propertyware_id);
    }

    public function test_a_refused_resend_keeps_the_note_and_says_so(): void
    {
        $woc = $this->makeWoc();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 8144617505, 'work_order_no' => 43649]);
        $note = $this->note($workOrder, ['user_id' => $woc->id]);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('workOrderNotesFromPropertyWare')->once()->andReturn([]);
            $mock->shouldReceive('addVendorNotes')->once()->andReturn(false);
        });

        $this->actingAs($woc)
            ->post(route('api.work_order_notes.push', $note))
            ->assertRedirect()
            ->assertSessionHas('warning', fn (string $warning) => str_contains($warning, 'did not accept'));

        $this->assertDatabaseHas('work_order_notes', ['id' => $note->id, 'propertyware_id' => null]);
    }

    public function test_notes_that_need_no_sending_are_refused_without_calling_propertyware(): void
    {
        $woc = $this->makeWoc();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 8144617505]);
        $fromPropertyWare = $this->note($workOrder, ['propertyware_id' => 901]);
        $alreadyLinked = $this->note($workOrder, ['user_id' => $woc->id, 'propertyware_id' => 902]);
        $noPropertyWareWorkOrder = $this->note(WorkOrder::factory()->create(['propertyware_id' => null]), ['user_id' => $woc->id]);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldNotReceive('workOrderNotesFromPropertyWare', 'addVendorNotes');
        });

        foreach ([$fromPropertyWare, $alreadyLinked, $noPropertyWareWorkOrder] as $note) {
            $this->actingAs($woc)
                ->post(route('api.work_order_notes.push', $note))
                ->assertRedirect()
                ->assertSessionHas('warning');
        }
    }

    public function test_only_staff_can_resend_a_note(): void
    {
        [$vendorUser, $vendor] = $this->makeVendorUser();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 8144617505]);
        $workOrder->vendors()->attach($vendor->id);
        $note = $this->note($workOrder, ['user_id' => $vendorUser->id]);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldNotReceive('workOrderNotesFromPropertyWare', 'addVendorNotes');
        });

        $this->actingAs($vendorUser)
            ->post(route('api.work_order_notes.push', $note))
            ->assertForbidden();

        $tenantUser = User::factory()->create();
        $tenantUser->assignRole('tenant');

        $this->actingAs($tenantUser)
            ->post(route('api.work_order_notes.push', $note))
            ->assertForbidden();
    }

    public function test_a_propertyware_note_is_edited_there_before_it_is_saved_here(): void
    {
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 8144617505]);
        $note = $this->note($workOrder, ['propertyware_id' => '55501', 'user_id' => null]);

        $this->mock(PropertyWareService::class, function ($mock) use ($note) {
            $mock->shouldReceive('updateNote')
                ->once()
                ->withArgs(fn (WorkOrderNotes $sent, string $subject, string $body) => $sent->is($note)
                    && $subject === 'Corrected subject'
                    && $body === 'Corrected body')
                ->andReturn(true);
        });

        $this->actingAs($this->makeWoc())
            ->put(route('api.work_order_notes.update', $note), [
                'subject' => 'Corrected subject',
                'body' => 'Corrected body',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('work_order_notes', [
            'id' => $note->id,
            'subject' => 'Corrected subject',
            'body' => 'Corrected body',
        ]);
    }

    public function test_a_refused_propertyware_edit_leaves_the_note_untouched(): void
    {
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 8144617505]);
        $note = $this->note($workOrder, ['propertyware_id' => '55502', 'user_id' => null]);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('updateNote')->once()->andReturn(false);
        });

        $this->actingAs($this->makeWoc())
            ->put(route('api.work_order_notes.update', $note), [
                'subject' => 'Attempted subject',
                'body' => 'Attempted body',
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        // The row must still read exactly as it did: a local-first save would
        // be reverted by the next sync without anyone noticing.
        $this->assertDatabaseHas('work_order_notes', [
            'id' => $note->id,
            'subject' => 'Note',
            'body' => 'Body',
        ]);
    }

    public function test_a_note_not_yet_in_propertyware_is_edited_locally_without_calling_it(): void
    {
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 8144617505]);
        $woc = $this->makeWoc();
        $note = $this->note($workOrder, ['user_id' => $woc->id]);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldNotReceive('updateNote');
        });

        $this->actingAs($woc)
            ->put(route('api.work_order_notes.update', $note), [
                'subject' => 'Fixed before it left',
                'body' => 'Fixed body',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('work_order_notes', [
            'id' => $note->id,
            'subject' => 'Fixed before it left',
        ]);
    }

    public function test_a_vendor_cannot_edit_someone_elses_note(): void
    {
        [$vendorUser, $vendor] = $this->makeVendorUser();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 8144617505]);
        $workOrder->vendors()->attach($vendor->id);
        $note = $this->note($workOrder, ['user_id' => User::factory()->create()->id]);

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldNotReceive('updateNote');
        });

        $this->actingAs($vendorUser)
            ->put(route('api.work_order_notes.update', $note), [
                'subject' => 'Not mine',
                'body' => 'Not mine',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('work_order_notes', [
            'id' => $note->id,
            'subject' => 'Note',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function jobberNote(WorkOrder $workOrder, array $attributes = []): WorkOrderJobberNote
    {
        return WorkOrderJobberNote::query()->create(array_merge([
            'work_order_id' => $workOrder->id,
            'jobber_note_gid' => 'note-'.uniqid(),
            'note_type' => 'JobNote',
            'message' => 'Replaced the thermocouple.',
            'author_name' => 'Marco Ruiz',
            'jobber_created_at' => now(),
        ], $attributes));
    }

    public function test_jobber_notes_are_in_the_notes_payload_for_staff(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->jobberNote($workOrder);

        $response = $this->actingAs($this->makeWoc())
            ->getJson(route('api.work_order_notes.show', $workOrder))
            ->assertOk();

        $response->assertJsonPath('jobber_notes.0.message', 'Replaced the thermocouple.');
        $response->assertJsonPath('jobber_notes.0.author_name', 'Marco Ruiz');
        $response->assertJsonPath('jobber_notes.0.note_type', 'JobNote');
    }

    /**
     * THMP's own account works these jobs, so it keeps the crew's notes; the
     * same rule the boards use for the Jobber deep link.
     */
    public function test_thmp_vendor_receives_jobber_notes(): void
    {
        [$vendorUser, $vendor] = $this->makeVendorUser();
        $workOrder = WorkOrder::factory()->create();
        $workOrder->vendors()->attach($vendor->id);
        $this->jobberNote($workOrder);

        $this->actingAs($vendorUser)
            ->getJson(route('api.work_order_notes.show', $workOrder))
            ->assertOk()
            ->assertJsonPath('jobber_notes.0.message', 'Replaced the thermocouple.');
    }

    public function test_a_non_thmp_vendor_does_not_receive_jobber_notes(): void
    {
        $user = User::factory()->create();
        $user->assignRole('vendor');

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-outside-'.$user->id,
            'name' => 'Some Other Plumbing',
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => $user->id,
        ]);

        $workOrder = WorkOrder::factory()->create();
        $workOrder->vendors()->attach($vendor->id);
        $this->jobberNote($workOrder);

        $this->actingAs($user)
            ->getJson(route('api.work_order_notes.show', $workOrder))
            ->assertOk()
            ->assertJsonPath('jobber_notes', []);
    }

    public function test_an_owner_login_does_not_receive_jobber_notes(): void
    {
        $user = User::factory()->create();
        $user->assignRole('owner');

        $workOrder = WorkOrder::factory()->create();
        $this->jobberNote($workOrder);

        $this->actingAs($user)
            ->getJson(route('api.work_order_notes.show', $workOrder))
            ->assertOk()
            ->assertJsonPath('jobber_notes', []);
    }

    /**
     * The two tables have separate id spaces, so a Jobber note's id can
     * collide with a dashboard note's. Deleting by that id must never reach
     * across and take the wrong note.
     */
    public function test_a_jobber_note_id_cannot_delete_a_dashboard_note(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $woc = $this->makeWoc();

        $dashboardNote = $this->note($workOrder, ['user_id' => $woc->id]);
        $jobberNote = $this->jobberNote($workOrder);

        // Line the ids up so a mix-up would be visible rather than lucky.
        $this->assertSame($dashboardNote->id, $jobberNote->id);

        $this->actingAs($woc)
            ->delete(route('api.work_order_notes.destroy', $jobberNote->id));

        // Whatever the endpoint made of that id, the Jobber note is still here:
        // it is not reachable through the work_order_notes routes at all.
        $this->assertDatabaseHas('work_order_jobber_notes', ['id' => $jobberNote->id]);
    }
}
