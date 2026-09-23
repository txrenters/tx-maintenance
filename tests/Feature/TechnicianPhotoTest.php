<?php

namespace Tests\Feature;

use App\Models\ServiceSchedule;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The technician photo the tenant appointment text attaches: uploading and
 * removing it on the Technicians settings page, the picker options for the
 * service-schedule dialog, and the schedule persisting the chosen technician.
 */
class TechnicianPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

        foreach (['admin', 'woc', 'vendor', 'tenant', 'owner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function staff(string $role = 'woc'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function uploadFor(Technician $technician, string $name = 'kevin.jpg'): TestResponse
    {
        return $this->post(route('technicians.photo.update', $technician), [
            'photo' => UploadedFile::fake()->image($name, 200, 200),
        ]);
    }

    public function test_a_coordinator_uploads_a_photo(): void
    {
        $this->actingAs($this->staff());
        $technician = Technician::factory()->create();

        $this->uploadFor($technician)->assertRedirect();

        $technician->refresh();
        $this->assertTrue($technician->hasPhoto());
        $this->assertSame('image/jpeg', $technician->photo_content_type);
        Storage::assertExists($technician->photo_path);
    }

    public function test_replacing_the_photo_keeps_the_old_file_for_sent_threads(): void
    {
        $this->actingAs($this->staff());
        $technician = Technician::factory()->create();

        $this->uploadFor($technician);
        $firstPath = $technician->refresh()->photo_path;

        $this->uploadFor($technician, 'kevin-new.jpg');
        $technician->refresh();

        $this->assertNotSame($firstPath, $technician->photo_path);
        // Conversation media rows in already-sent threads point at the old
        // file, so it must survive the replacement.
        Storage::assertExists($firstPath);
        Storage::assertExists($technician->photo_path);
    }

    public function test_removing_the_photo_clears_the_columns_but_keeps_the_file(): void
    {
        $this->actingAs($this->staff());
        $technician = Technician::factory()->create();

        $this->uploadFor($technician);
        $path = $technician->refresh()->photo_path;

        $this->delete(route('technicians.photo.destroy', $technician))->assertRedirect();

        $technician->refresh();
        $this->assertFalse($technician->hasPhoto());
        $this->assertNull($technician->photo_content_type);
        Storage::assertExists($path);
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        $this->actingAs($this->staff());
        $technician = Technician::factory()->create();

        $this->post(route('technicians.photo.update', $technician), [
            'photo' => UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf'),
        ])->assertSessionHasErrors('photo');

        $this->assertFalse($technician->refresh()->hasPhoto());
    }

    public function test_carrier_unfriendly_uploads_are_rejected(): void
    {
        $this->actingAs($this->staff());
        $technician = Technician::factory()->create();

        // WebP is not reliably delivered over US MMS, so it cannot be saved.
        $this->post(route('technicians.photo.update', $technician), [
            'photo' => UploadedFile::fake()->image('kevin.webp', 200, 200),
        ])->assertSessionHasErrors('photo');

        // Neither can an image over the 2MB carrier-safe cap.
        $this->post(route('technicians.photo.update', $technician), [
            'photo' => UploadedFile::fake()->create('huge.jpg', 3000, 'image/jpeg'),
        ])->assertSessionHasErrors('photo');

        $this->assertFalse($technician->refresh()->hasPhoto());
    }

    public function test_a_vendor_cannot_touch_the_photo(): void
    {
        $this->actingAs($this->staff('vendor'));
        $technician = Technician::factory()->create();

        $this->uploadFor($technician)->assertForbidden();
        $this->delete(route('technicians.photo.destroy', $technician))->assertForbidden();
        $this->get(route('technicians.photo.show', $technician))->assertForbidden();
    }

    public function test_the_staff_preview_streams_the_photo(): void
    {
        $this->actingAs($this->staff('admin'));
        $technician = Technician::factory()->create();

        // Nothing on file yet.
        $this->get(route('technicians.photo.show', $technician))->assertNotFound();

        $this->uploadFor($technician);

        $this->get(route('technicians.photo.show', $technician->refresh()))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_options_lists_only_active_technicians(): void
    {
        $this->actingAs($this->staff());

        $active = Technician::factory()->create(['name' => 'Kevin Cole']);
        Technician::factory()->inactive()->create(['name' => 'Gone Away']);

        $this->getJson(route('technicians.options'))
            ->assertOk()
            ->assertJsonCount(1, 'technicians')
            ->assertJsonPath('technicians.0.id', $active->id)
            ->assertJsonPath('technicians.0.name', 'Kevin Cole')
            ->assertJsonPath('technicians.0.has_photo', false);
    }

    public function test_options_is_staff_only(): void
    {
        $this->actingAs($this->staff('vendor'));

        $this->getJson(route('technicians.options'))->assertForbidden();
    }

    private function makeVendor(): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Reliable Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    public function test_the_schedule_stores_the_chosen_technician(): void
    {
        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);
        $vendor = $this->makeVendor();
        $technician = Technician::factory()->create();

        $this->post(route('work_order.service_schedule.create'), [
            'title' => 'Water heater',
            'date' => now()->addDays(2)->toDateString(),
            'vendor_id' => $vendor->id,
            'work_order_id' => $workOrder->id,
            'technician_ids' => [$technician->id],
        ])->assertRedirect();

        $schedule = ServiceSchedule::query()->where('work_order_id', $workOrder->id)->firstOrFail();

        $this->assertDatabaseHas('service_schedule_technicians', [
            'service_schedule_id' => $schedule->id,
            'technician_id' => $technician->id,
        ]);
        // The older single column mirrors the pick so a code revert keeps it.
        $this->assertSame($technician->id, $schedule->technician_id);
    }

    public function test_the_schedule_stores_two_technicians_for_one_visit(): void
    {
        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);
        $vendor = $this->makeVendor();
        $kevin = Technician::factory()->create(['name' => 'Kevin Cole']);
        $emanuel = Technician::factory()->create(['name' => 'Emanuel Hall']);

        $this->post(route('work_order.service_schedule.create'), [
            'title' => 'Water heater',
            'date' => now()->addDays(2)->toDateString(),
            'vendor_id' => $vendor->id,
            'work_order_id' => $workOrder->id,
            'technician_ids' => [$kevin->id, $emanuel->id],
        ])->assertRedirect();

        $schedule = ServiceSchedule::query()->where('work_order_id', $workOrder->id)->firstOrFail();

        $this->assertEqualsCanonicalizing(
            [$kevin->id, $emanuel->id],
            $schedule->technicians()->pluck('technicians.id')->all(),
        );
        $this->assertSame($kevin->id, $schedule->technician_id);

        // The page's fetch carries every pick, ordered by name.
        $this->actingAs($this->staff())
            ->getJson(route('work_order.service_schedules', $workOrder))
            ->assertOk()
            ->assertJsonPath('service_schedules.0.technicians.0.name', 'Emanuel Hall')
            ->assertJsonPath('service_schedules.0.technicians.1.name', 'Kevin Cole');
    }

    public function test_an_unknown_technician_is_rejected(): void
    {
        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);
        $vendor = $this->makeVendor();

        $this->postJson(route('work_order.service_schedule.create'), [
            'title' => 'Water heater',
            'date' => now()->addDays(2)->toDateString(),
            'vendor_id' => $vendor->id,
            'work_order_id' => $workOrder->id,
            'technician_ids' => [999999],
        ])->assertJsonValidationErrors('technician_ids.0');

        $this->assertDatabaseCount('service_schedules', 0);
    }

    public function test_an_update_without_the_field_keeps_the_technicians(): void
    {
        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);
        $vendor = $this->makeVendor();
        $kevin = Technician::factory()->create(['name' => 'Kevin Cole']);
        $emanuel = Technician::factory()->create(['name' => 'Emanuel Hall']);

        $schedule = ServiceSchedule::query()->create([
            'title' => 'Water heater',
            'scheduled_date' => now()->addDays(2),
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
        ]);
        $schedule->setTechnicians([$kevin->id, $emanuel->id]);

        // A vendor-portal style edit carries no technician field at all.
        $this->put(route('work_order.service_schedule.update', $schedule), [
            'title' => 'Water heater - moved',
            'date' => now()->addDays(4)->toDateString(),
            'vendor_id' => $vendor->id,
        ])->assertRedirect();

        $this->assertSame(2, $schedule->technicians()->count());
        $this->assertSame($kevin->id, $schedule->fresh()->technician_id);

        // The staff dialog unticking one keeps the other.
        $this->put(route('work_order.service_schedule.update', $schedule), [
            'title' => 'Water heater - moved',
            'date' => now()->addDays(4)->toDateString(),
            'vendor_id' => $vendor->id,
            'technician_ids' => [$emanuel->id],
        ])->assertRedirect();

        $this->assertSame([$emanuel->id], $schedule->technicians()->pluck('technicians.id')->all());
        $this->assertSame($emanuel->id, $schedule->fresh()->technician_id);

        // The staff dialog sending an empty list clears them all.
        $this->put(route('work_order.service_schedule.update', $schedule), [
            'title' => 'Water heater - moved',
            'date' => now()->addDays(4)->toDateString(),
            'vendor_id' => $vendor->id,
            'technician_ids' => [],
        ])->assertRedirect();

        $this->assertSame(0, $schedule->technicians()->count());
        $this->assertNull($schedule->fresh()->technician_id);
    }

    public function test_deleting_the_schedule_drops_its_technician_picks(): void
    {
        $workOrder = WorkOrder::factory()->create(['status' => 'Open']);
        $technician = Technician::factory()->create();

        $schedule = ServiceSchedule::query()->create([
            'title' => 'Water heater',
            'scheduled_date' => now()->addDays(2),
            'work_order_id' => $workOrder->id,
            'vendor_id' => $this->makeVendor()->id,
        ]);
        $schedule->setTechnicians([$technician->id]);

        $this->post(route('service_schedule.status.completed', $schedule), ['status' => 'delete'])
            ->assertRedirect();

        $this->assertDatabaseCount('service_schedules', 0);
        $this->assertDatabaseCount('service_schedule_technicians', 0);
    }
}
