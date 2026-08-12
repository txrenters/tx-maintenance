<?php

namespace Tests\Feature;

use App\Console\Commands\SendJobReminders;
use App\Jobs\SendJobberVendorAssignment;
use App\Mail\JobberVendorAssignmentMail;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberVisit;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JobberAutomationSettings;
use Carbon\Carbon;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class JobberAutomationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting', 'vendor', 'tenant', 'owner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function staff(string $role = 'admin'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    /*
    |--------------------------------------------------------------------------
    | Endpoint
    |--------------------------------------------------------------------------
    */

    public function test_an_admin_can_read_the_toggle_state_and_labels(): void
    {
        $this->actingAs($this->staff())
            ->getJson(route('jobber.automation.show'))
            ->assertOk()
            ->assertJson(['disabled' => []])
            ->assertJsonStructure(['disabled', 'automations'])
            ->assertJsonFragment(['tenant_job_reminder_sms' => 'Tenant: Jobber visit reminder (text)']);
    }

    public function test_a_woc_can_flip_the_master_switch(): void
    {
        $this->actingAs($this->staff('woc'))
            ->patchJson(route('jobber.automation.toggle'), [
                'automation' => 'all',
                'disabled' => true,
            ])
            ->assertOk()
            ->assertJson(['disabled' => ['all']]);

        $this->assertTrue(JobberAutomationSettings::masterOff());
        $this->assertTrue(JobberAutomationSettings::isDisabled('tenant_job_reminder_sms'));
    }

    public function test_flipping_a_single_automation_off_and_back_on_round_trips(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->patchJson(route('jobber.automation.toggle'), [
            'automation' => 'vendor_jobber_assignment_sms',
            'disabled' => true,
        ])->assertOk();

        $this->assertTrue(JobberAutomationSettings::isDisabled('vendor_jobber_assignment_sms'));
        $this->assertFalse(JobberAutomationSettings::isDisabled('vendor_jobber_assignment_email'));

        $this->actingAs($admin)->patchJson(route('jobber.automation.toggle'), [
            'automation' => 'vendor_jobber_assignment_sms',
            'disabled' => false,
        ])->assertOk()->assertJson(['disabled' => []]);

        $this->assertFalse(JobberAutomationSettings::isDisabled('vendor_jobber_assignment_sms'));
    }

    public function test_other_roles_cannot_read_or_flip(): void
    {
        $accounting = $this->staff('accounting');

        $this->actingAs($accounting)->getJson(route('jobber.automation.show'))->assertForbidden();

        $this->actingAs($accounting)->patchJson(route('jobber.automation.toggle'), [
            'automation' => 'all',
            'disabled' => true,
        ])->assertForbidden();

        $this->assertFalse(JobberAutomationSettings::masterOff());
    }

    public function test_a_missing_settings_table_fails_open_instead_of_crashing_senders(): void
    {
        // Simulates a deploy where the code lands before the app_settings
        // migration has run on the server: every automation must read as
        // enabled and nothing may throw.
        Schema::drop('app_settings');

        $this->assertFalse(JobberAutomationSettings::masterOff());
        $this->assertFalse(JobberAutomationSettings::isDisabled('tenant_job_reminder_sms'));
        $this->assertSame([], JobberAutomationSettings::disabled());
    }

    public function test_unknown_automation_keys_are_rejected(): void
    {
        $this->actingAs($this->staff())
            ->patchJson(route('jobber.automation.toggle'), [
                'automation' => 'tenant_vendor_assignment_sms', // real key, but not Jobber's
                'disabled' => true,
            ])
            ->assertUnprocessable();
    }

    /*
    |--------------------------------------------------------------------------
    | Vendor assignment job (SendJobberVendorAssignment)
    |--------------------------------------------------------------------------
    */

    private function makeJobberJobWithVendor(): array
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-'.uniqid(),
            'name' => 'Outside Client',
            'jobber_web_uri' => 'https://example.test',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-'.uniqid(),
            'jobber_client_id' => $client->id,
            'street' => '100 Elm St',
            'city' => 'Dallas',
        ]);

        $job = Jobber::query()->create([
            'jobber_id' => 'job-'.uniqid(),
            'job_number' => '2001',
            'title' => 'Water heater replacement',
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);

        $user = User::factory()->create();
        $user->forceFill(['phone' => '2815550000'])->save();

        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'Reliable Plumbing',
            'vendor_type' => 'Plumbing',
            'is_active' => true,
            'email' => 'v'.uniqid().'@example.com',
            'user_id' => $user->id,
        ]);

        $job->vendors()->attach($vendor->id, ['access_token' => str_repeat('a', 48)]);

        return [$job, $vendor];
    }

    private function enableVendorNotify(): void
    {
        config([
            'services.jobber.vendor_notify_enabled' => true,
            'services.twilio.from' => '+15125550100',
        ]);
    }

    public function test_master_off_stops_vendor_assignment_email_and_text_but_still_claims(): void
    {
        Mail::fake();
        Queue::fake();
        $this->enableVendorNotify();
        JobberAutomationSettings::set(JobberAutomationSettings::MASTER, true);

        [$job, $vendor] = $this->makeJobberJobWithVendor();

        (new SendJobberVendorAssignment($job->id, $vendor->id))->handle();

        Mail::assertNothingSent();
        $this->assertDatabaseCount('jobber_text_messages', 0);
        // The claim still stamps, so re-enabling never blasts the backlog.
        $this->assertNotNull($job->vendors()->first()->pivot->information_sent_at);
    }

    public function test_email_switch_off_still_sends_the_text(): void
    {
        Mail::fake();
        Queue::fake();
        $this->enableVendorNotify();
        JobberAutomationSettings::set('vendor_jobber_assignment_email', true);

        [$job, $vendor] = $this->makeJobberJobWithVendor();

        (new SendJobberVendorAssignment($job->id, $vendor->id))->handle();

        Mail::assertNothingSent();
        $this->assertDatabaseHas('jobber_text_messages', [
            'jobber_id' => $job->id,
            'receiver_number' => '+12815550000',
        ]);
    }

    public function test_sms_switch_off_still_sends_the_email(): void
    {
        Mail::fake();
        Queue::fake();
        $this->enableVendorNotify();
        JobberAutomationSettings::set('vendor_jobber_assignment_sms', true);

        [$job, $vendor] = $this->makeJobberJobWithVendor();

        (new SendJobberVendorAssignment($job->id, $vendor->id))->handle();

        Mail::assertSent(JobberVendorAssignmentMail::class);
        $this->assertDatabaseCount('jobber_text_messages', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | TBP visit reminders (SendJobReminders)
    |--------------------------------------------------------------------------
    */

    private function makeTbpVisit(Carbon $startAt): JobberVisit
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-'.uniqid(),
            'name' => '100 Elm St',
            'jobber_web_uri' => 'https://example.test',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-'.uniqid(),
            'jobber_client_id' => $client->id,
            'street' => '100 Elm St',
            'city' => 'Dallas',
        ]);

        $job = Jobber::query()->create([
            'jobber_id' => 'job-'.uniqid(),
            'job_number' => '3001',
            'title' => 'Q3 2026 Tenant Benefit Package',
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);

        return JobberVisit::query()->create([
            'jobber_job_id' => $job->id,
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
            'jobber_id' => 'visit-'.uniqid(),
            'title' => 'Q3 2026 Tenant Benefit Package',
            'start_at' => $startAt,
            'notified_7_days' => false,
            'notified_3_days' => false,
            'notified_1_days' => false,
        ]);
    }

    /**
     * A subclass exposing the protected send path so the gate can be tested
     * without going through the run-date plumbing.
     */
    private function remindersCommand(): SendJobReminders
    {
        $command = new class extends SendJobReminders
        {
            public function runSendMessages(Carbon $date, string $field, string $message): void
            {
                $this->sendMessages($date, $field, $message);
            }
        };

        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(
            new ArrayInput([]),
            new BufferedOutput,
        ));

        return $command;
    }

    public function test_reminders_fully_off_skip_the_run_without_burning_the_notified_flags(): void
    {
        // The gate must return before the PropertyWare fetch and before any
        // visit is flagged, so re-enabling within the window resumes cleanly.
        Http::preventStrayRequests();
        JobberAutomationSettings::set('tenant_job_reminder_sms', true);
        JobberAutomationSettings::set('tenant_job_reminder_email', true);

        $monday = Carbon::parse('next monday', 'America/Chicago')->setTime(9, 0);
        $visit = $this->makeTbpVisit($monday);

        $this->remindersCommand()->runSendMessages($monday, 'notified_3_days', 'Dear {CLIENT_NAME}, visit on {SCHEDULED_DATE}');

        $this->assertFalse($visit->fresh()->notified_3_days);
        $this->assertDatabaseCount('jobber_text_messages', 0);
    }

    public function test_reminders_fully_off_skip_the_one_day_run_without_burning_the_flag(): void
    {
        Http::preventStrayRequests();
        JobberAutomationSettings::set('tenant_job_reminder_sms', true);
        JobberAutomationSettings::set('tenant_job_reminder_email', true);

        $monday = Carbon::parse('next monday', 'America/Chicago')->setTime(9, 0);
        $visit = $this->makeTbpVisit($monday);

        $this->remindersCommand()->runSendMessages($monday, 'notified_1_days', 'Dear {CLIENT_NAME}, visit tomorrow, {SCHEDULED_DATE}');

        $this->assertFalse($visit->fresh()->notified_1_days);
        $this->assertDatabaseCount('jobber_text_messages', 0);
    }

    public function test_the_one_day_reminder_claims_its_own_flag(): void
    {
        config([
            'cache.default' => 'array',
            'services.microsoft.job_reminder_mailbox' => 'service@txhomemp.com',
            'services.twilio.sid' => 'ACtest',
            'services.twilio.auth_token' => 'test-token',
        ]);
        Cache::forget('microsoft.graph.token');
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://app.propertyware.com/*' => Http::response(json_encode([
                'records' => [[
                    0 => '1',
                    1 => 'x',
                    2 => 'Active',
                    3 => 'Jane Tenant',
                    4 => '100 Elm St',
                    5 => '',
                    6 => '',
                    7 => '',
                    8 => '',
                    9 => '',
                    10 => '2145550123',
                    11 => 'jane@example.com',
                    12 => '',
                    13 => '',
                    14 => 'Yes',
                ]],
            ])),
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response(['id' => 'REMINDER_GRAPH_ID']),
        ]);

        // SMS off so the run exercises the email channel without Twilio.
        JobberAutomationSettings::set('tenant_job_reminder_sms', true);

        $monday = Carbon::parse('next monday', 'America/Chicago')->setTime(9, 0);
        $visit = $this->makeTbpVisit($monday);

        $this->remindersCommand()->runSendMessages($monday, 'notified_1_days', 'Dear {CLIENT_NAME}, visit tomorrow, {SCHEDULED_DATE}');

        $fresh = $visit->fresh();
        $this->assertTrue($fresh->notified_1_days);
        $this->assertFalse($fresh->notified_3_days);
        $this->assertFalse($fresh->notified_7_days);
    }

    public function test_sms_switch_off_still_sends_the_reminder_email(): void
    {
        config([
            'cache.default' => 'array',
            'services.microsoft.job_reminder_mailbox' => 'service@txhomemp.com',
            // The Twilio client is constructed before the (skipped) SMS loop.
            'services.twilio.sid' => 'ACtest',
            'services.twilio.auth_token' => 'test-token',
        ]);
        Cache::forget('microsoft.graph.token');
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://app.propertyware.com/*' => Http::response(json_encode([
                'records' => [[
                    0 => '1',
                    1 => 'x',
                    2 => 'Active',
                    3 => 'Jane Tenant',
                    4 => '100 Elm St',
                    5 => '',
                    6 => '',
                    7 => '',
                    8 => '',
                    9 => '',
                    10 => '2145550123',
                    11 => 'jane@example.com',
                    12 => '',
                    13 => '',
                    14 => 'Yes',
                ]],
            ])),
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response(['id' => 'REMINDER_GRAPH_ID']),
        ]);

        JobberAutomationSettings::set('tenant_job_reminder_sms', true);

        $monday = Carbon::parse('next monday', 'America/Chicago')->setTime(9, 0);
        $visit = $this->makeTbpVisit($monday);

        $this->remindersCommand()->runSendMessages($monday, 'notified_3_days', 'Dear {CLIENT_NAME}, visit on {SCHEDULED_DATE}');

        // The run proceeded: the visit is claimed by the email channel, no SMS
        // was attempted, and the reminder email went out through Graph.
        $this->assertTrue($visit->fresh()->notified_3_days);
        $this->assertDatabaseCount('jobber_text_messages', 0);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.microsoft.com')
            && str_contains($request->url(), '/messages')
            && ! str_contains($request->url(), '/send')
            && $request['toRecipients'][0]['emailAddress']['address'] === 'jane@example.com');
    }
}
