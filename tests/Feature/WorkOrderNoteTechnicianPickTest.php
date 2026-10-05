<?php

namespace Tests\Feature;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberVisit;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotes;
use App\Services\PropertyWareService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * THMP's field crew shares one vendor login, so the login cannot say who typed
 * a note. The note dialog makes that login pick a name from the active
 * technician roster before a note is saved; the picked name is kept on the
 * note and shown as its author instead of the Jobber-visit guess.
 */
class WorkOrderNoteTechnicianPickTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'vendor', 'owner', 'tenant'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->mock(PropertyWareService::class, function ($mock) {
            $mock->shouldReceive('addVendorNotes')->andReturn(true);
            $mock->shouldReceive('updateNote')->andReturn(true);
        });
    }

    /**
     * @return array{0: User, 1: Vendor}
     */
    private function makeVendorUser(string $vendorName = Vendor::THMP_NAME): array
    {
        $user = User::factory()->create(['name' => $vendorName]);
        $user->assignRole('vendor');

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.$user->id,
            'name' => $vendorName,
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

    private function workOrderFor(Vendor $vendor): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 777001]);
        $workOrder->vendors()->attach($vendor->id);

        return $workOrder;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function notePayload(WorkOrder $workOrder, array $extra = []): array
    {
        return array_merge([
            'subject' => 'Everything completed except electrical outlet',
            'body' => 'Ceiling fan is now installed and working properly.',
            'work_order_id' => $workOrder->id,
        ], $extra);
    }

    /**
     * A Jobber job for the work order whose only visit names Emanuel Hall —
     * the guess the old inference would put on every THMP note.
     */
    private function assignEmanuelInJobber(WorkOrder $workOrder): void
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-'.uniqid(),
            'name' => '17026 Cypresswood Glen Trl',
            'jobber_web_uri' => 'https://example.test',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-'.uniqid(),
            'jobber_client_id' => $client->id,
        ]);

        $job = Jobber::query()->create([
            'jobber_id' => 'job-'.uniqid(),
            'job_number' => '20052',
            'title' => '17026 Cypresswood Glen Trl, - Zone 2 - Code Work',
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);

        JobberVisit::query()->create([
            'jobber_id' => 'visit-'.uniqid(),
            'jobber_job_id' => $job->id,
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
            'start_at' => Carbon::now(),
            'assigned_to' => [['id' => 'gid://Jobber/User/1', 'name' => 'Emanuel Hall']],
        ]);

        $workOrder->forceFill(['jobber_job_gid' => $job->jobber_id])->saveQuietly();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchNotes(WorkOrder $workOrder): array
    {
        return $this->actingAs($this->makeWoc())
            ->getJson(route('api.work_order_notes.show', $workOrder))
            ->assertOk()
            ->json('notes');
    }

    public function test_the_thmp_login_cannot_save_a_note_without_picking_a_name(): void
    {
        [$thmpUser, $vendor] = $this->makeVendorUser();
        $workOrder = $this->workOrderFor($vendor);
        Technician::factory()->create(['name' => 'Kevin Granados']);

        $this->actingAs($thmpUser)
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder))
            ->assertSessionHasErrors(['technician_id' => 'Select your name before saving the note.']);

        $this->assertDatabaseCount('work_order_notes', 0);
    }

    public function test_the_picked_technician_is_saved_on_the_note(): void
    {
        [$thmpUser, $vendor] = $this->makeVendorUser();
        $workOrder = $this->workOrderFor($vendor);
        $kevin = Technician::factory()->create(['name' => 'Kevin Granados']);

        $this->actingAs($thmpUser)
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder, ['technician_id' => (string) $kevin->id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_order_notes', [
            'work_order_id' => $workOrder->id,
            'user_id' => $thmpUser->id,
            'technician_id' => $kevin->id,
            'technician_name' => 'Kevin Granados',
        ]);
    }

    public function test_the_picked_name_is_shown_instead_of_the_jobber_visit_guess(): void
    {
        [$thmpUser, $vendor] = $this->makeVendorUser();
        $workOrder = $this->workOrderFor($vendor);
        $this->assignEmanuelInJobber($workOrder);
        $kevin = Technician::factory()->create(['name' => 'Kevin Granados']);

        $this->actingAs($thmpUser)
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder, ['technician_id' => $kevin->id]))
            ->assertSessionHasNoErrors();

        $note = $this->fetchNotes($workOrder)[0];

        $this->assertSame('Kevin Granados', $note['technician_name']);
        $this->assertArrayNotHasKey('jobber_technician', $note);
    }

    public function test_a_note_from_before_the_picker_still_gets_the_jobber_visit_guess(): void
    {
        [$thmpUser, $vendor] = $this->makeVendorUser();
        $workOrder = $this->workOrderFor($vendor);
        $this->assignEmanuelInJobber($workOrder);
        Technician::factory()->create(['name' => 'Kevin Granados']);

        WorkOrderNotes::query()->create([
            'work_order_id' => $workOrder->id,
            'subject' => 'Old note',
            'body' => 'Typed before the name picker existed.',
            'user_id' => $thmpUser->id,
            'is_private' => true,
        ]);

        $note = $this->fetchNotes($workOrder)[0];

        $this->assertNull($note['technician_name']);
        $this->assertSame('Emanuel Hall', $note['jobber_technician']);
    }

    public function test_the_name_keeps_reading_the_same_after_the_technician_is_renamed_or_removed(): void
    {
        [$thmpUser, $vendor] = $this->makeVendorUser();
        $workOrder = $this->workOrderFor($vendor);
        $kevin = Technician::factory()->create(['name' => 'Kevin Granados']);

        $this->actingAs($thmpUser)
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder, ['technician_id' => $kevin->id]))
            ->assertSessionHasNoErrors();

        $kevin->delete();

        $this->assertSame('Kevin Granados', $this->fetchNotes($workOrder)[0]['technician_name']);
    }

    public function test_an_inactive_or_unknown_technician_cannot_be_picked(): void
    {
        [$thmpUser, $vendor] = $this->makeVendorUser();
        $workOrder = $this->workOrderFor($vendor);
        Technician::factory()->create(['name' => 'Kevin Granados']);
        $gone = Technician::factory()->create(['name' => 'Former Tech', 'is_active' => false]);

        foreach ([$gone->id, 999999, 'someone'] as $picked) {
            $this->actingAs($thmpUser)
                ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder, ['technician_id' => $picked]))
                ->assertSessionHasErrors(['technician_id' => 'Select your name from the list.']);
        }

        $this->assertDatabaseCount('work_order_notes', 0);
    }

    public function test_not_listed_signs_the_note_with_the_login_and_stops_the_jobber_guess(): void
    {
        [$thmpUser, $vendor] = $this->makeVendorUser();
        $workOrder = $this->workOrderFor($vendor);
        $this->assignEmanuelInJobber($workOrder);
        Technician::factory()->create(['name' => 'Kevin Granados']);

        $this->actingAs($thmpUser)
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder, ['technician_id' => WorkOrderNotes::TECHNICIAN_NOT_LISTED]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_order_notes', [
            'work_order_id' => $workOrder->id,
            'technician_id' => null,
            'technician_name' => Vendor::THMP_NAME,
        ]);

        $note = $this->fetchNotes($workOrder)[0];

        $this->assertSame(Vendor::THMP_NAME, $note['technician_name']);
        $this->assertArrayNotHasKey('jobber_technician', $note);
    }

    public function test_nothing_is_asked_while_the_roster_has_no_active_technician(): void
    {
        [$thmpUser, $vendor] = $this->makeVendorUser();
        $workOrder = $this->workOrderFor($vendor);
        Technician::factory()->create(['is_active' => false]);

        $this->actingAs($thmpUser)
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_order_notes', [
            'work_order_id' => $workOrder->id,
            'technician_id' => null,
            'technician_name' => null,
        ]);
    }

    public function test_another_vendor_is_not_asked_and_cannot_sign_as_a_technician(): void
    {
        [$otherUser, $otherVendor] = $this->makeVendorUser('Acme Plumbing');
        $workOrder = $this->workOrderFor($otherVendor);
        $kevin = Technician::factory()->create(['name' => 'Kevin Granados']);

        $this->actingAs($otherUser)
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder))
            ->assertSessionHasNoErrors();

        $this->actingAs($otherUser)
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder, ['technician_id' => $kevin->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('work_order_notes', 2);
        $this->assertDatabaseMissing('work_order_notes', ['technician_name' => 'Kevin Granados']);
    }

    public function test_office_staff_are_not_asked_for_a_name(): void
    {
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 777001]);
        $kevin = Technician::factory()->create(['name' => 'Kevin Granados']);

        $this->actingAs($this->makeWoc())
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder, ['technician_id' => $kevin->id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_order_notes', [
            'work_order_id' => $workOrder->id,
            'technician_id' => null,
            'technician_name' => null,
        ]);
    }

    public function test_the_thmp_login_can_correct_the_name_when_editing_its_note(): void
    {
        [$thmpUser, $vendor] = $this->makeVendorUser();
        $workOrder = $this->workOrderFor($vendor);
        $kevin = Technician::factory()->create(['name' => 'Kevin Granados']);
        $moses = Technician::factory()->create(['name' => 'Moses Rodriguez']);

        $note = WorkOrderNotes::query()->create([
            'work_order_id' => $workOrder->id,
            'subject' => 'Note',
            'body' => 'Body',
            'user_id' => $thmpUser->id,
            'is_private' => true,
            'technician_id' => $kevin->id,
            'technician_name' => 'Kevin Granados',
        ]);

        $this->actingAs($thmpUser)
            ->put(route('api.work_order_notes.update', $note), [
                'subject' => 'Note',
                'body' => 'Body, corrected',
                'technician_id' => (string) $moses->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_order_notes', [
            'id' => $note->id,
            'body' => 'Body, corrected',
            'technician_id' => $moses->id,
            'technician_name' => 'Moses Rodriguez',
        ]);
    }

    public function test_an_edit_that_picks_nothing_keeps_the_name_on_the_note(): void
    {
        [$thmpUser, $vendor] = $this->makeVendorUser();
        $workOrder = $this->workOrderFor($vendor);
        $kevin = Technician::factory()->create(['name' => 'Kevin Granados']);

        $note = WorkOrderNotes::query()->create([
            'work_order_id' => $workOrder->id,
            'subject' => 'Note',
            'body' => 'Body',
            'user_id' => $thmpUser->id,
            'is_private' => true,
            'technician_id' => $kevin->id,
            'technician_name' => 'Kevin Granados',
        ]);

        // The THMP login leaving the picker alone, then a coordinator's edit.
        $this->actingAs($thmpUser)
            ->put(route('api.work_order_notes.update', $note), ['subject' => 'Note', 'body' => 'First edit', 'technician_id' => ''])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->makeWoc())
            ->put(route('api.work_order_notes.update', $note), ['subject' => 'Note', 'body' => 'Second edit'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_order_notes', [
            'id' => $note->id,
            'body' => 'Second edit',
            'technician_id' => $kevin->id,
            'technician_name' => 'Kevin Granados',
        ]);
    }

    public function test_only_the_thmp_login_is_sent_the_names_to_pick_from(): void
    {
        [$thmpUser] = $this->makeVendorUser();
        [$otherUser] = $this->makeVendorUser('Acme Plumbing');
        $moses = Technician::factory()->create(['name' => 'Moses Rodriguez']);
        $kevin = Technician::factory()->create(['name' => 'Kevin Granados']);
        Technician::factory()->create(['name' => 'Former Tech', 'is_active' => false]);

        $this->actingAs($thmpUser)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('note_technicians', [
                ['id' => $kevin->id, 'name' => 'Kevin Granados'],
                ['id' => $moses->id, 'name' => 'Moses Rodriguez'],
            ]));

        foreach ([$otherUser, $this->makeWoc()] as $user) {
            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertInertia(fn (Assert $page) => $page->where('note_technicians', []));
        }
    }

    /**
     * THMP's crew on production works from a staff-type login
     * (thmp@texasrenters.com): no vendor role, no vendor record.
     */
    private function makeSharedStaffLogin(string $email = 'thmp@texasrenters.com'): User
    {
        $user = User::factory()->create(['name' => 'THMP', 'email' => $email]);
        $user->assignRole('woc');

        return $user;
    }

    public function test_the_shared_staff_type_thmp_login_is_sent_the_names_and_must_pick_one(): void
    {
        $shared = $this->makeSharedStaffLogin();
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 777001]);
        $kevin = Technician::factory()->create(['name' => 'Kevin Granados']);

        $this->actingAs($shared)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('note_technicians', [
                ['id' => $kevin->id, 'name' => 'Kevin Granados'],
            ]));

        $this->actingAs($shared)
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder))
            ->assertSessionHasErrors(['technician_id' => 'Select your name before saving the note.']);

        $this->assertDatabaseCount('work_order_notes', 0);

        $this->actingAs($shared)
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder, ['technician_id' => $kevin->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_order_notes', [
            'work_order_id' => $workOrder->id,
            'user_id' => $shared->id,
            'technician_id' => $kevin->id,
            'technician_name' => 'Kevin Granados',
        ]);

        $this->assertSame('Kevin Granados', $this->fetchNotes($workOrder)[0]['technician_name']);
    }

    public function test_the_shared_login_list_ignores_case_and_spaces_and_can_be_changed(): void
    {
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 777001]);
        Technician::factory()->create(['name' => 'Kevin Granados']);

        config(['services.work_order.note_technician_logins' => ' Crew@Example.com , thmp@texasrenters.com']);

        $this->actingAs($this->makeSharedStaffLogin('crew@example.com'))
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder))
            ->assertSessionHasErrors('technician_id');

        // Blank list: the staff-type login is an ordinary coordinator again.
        config(['services.work_order.note_technician_logins' => '']);

        $shared = $this->makeSharedStaffLogin();

        $this->actingAs($shared)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('note_technicians', []));

        $this->actingAs($shared)
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('work_order_notes', 1);
    }

    public function test_a_login_is_never_asked_for_a_name_it_was_not_shown(): void
    {
        // A staff login that happens to own the THMP vendor record, without
        // the vendor role and not on the shared-login list.
        $staff = User::factory()->create();
        $staff->assignRole('woc');
        Vendor::query()->create([
            'propertyware_id' => 'V-'.$staff->id,
            'name' => Vendor::THMP_NAME,
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => $staff->id,
        ]);
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 777001]);
        Technician::factory()->create(['name' => 'Kevin Granados']);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('note_technicians', []));

        $this->actingAs($staff)
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('work_order_notes', 1);
    }

    public function test_the_picker_stays_off_on_a_database_without_the_note_columns(): void
    {
        [$thmpUser, $vendor] = $this->makeVendorUser();
        $workOrder = $this->workOrderFor($vendor);
        $kevin = Technician::factory()->create(['name' => 'Kevin Granados']);

        Schema::table('work_order_notes', function ($table) {
            $table->dropColumn(['technician_id', 'technician_name']);
        });

        $this->assertFalse(WorkOrderNotes::recordsTechnician());

        $this->actingAs($thmpUser)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('note_technicians', []));

        // Neither asked for, nor written when sent anyway.
        $this->actingAs($thmpUser)
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder))
            ->assertSessionHasNoErrors();

        $this->actingAs($thmpUser)
            ->post(route('api.work_order_notes.store'), $this->notePayload($workOrder, ['technician_id' => $kevin->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('work_order_notes', 2);
    }
}
