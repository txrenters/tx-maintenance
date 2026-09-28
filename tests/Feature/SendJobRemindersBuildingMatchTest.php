<?php

namespace Tests\Feature;

use App\Console\Commands\SendJobReminders;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberVisit;
use App\Services\JobberAutomationSettings;
use Carbon\Carbon;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The automated TBP reminders find the tenant the way the Send notification
 * button does: same street number and street name, whether or not the
 * PropertyWare building name carries the street suffix. Found on Jobber job
 * #20638 (client "418 Drennan St", PropertyWare "418 Drennan") whose tenant
 * got no 14/7/3/1-day reminder.
 */
class SendJobRemindersBuildingMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'services.microsoft.job_reminder_mailbox' => 'service@txhomemp.com',
            // The Twilio client is constructed before the (muted) SMS loop.
            'services.twilio.sid' => 'ACtest',
            'services.twilio.auth_token' => 'test-token',
        ]);
        Cache::forget('microsoft.graph.token');
        Mail::fake();

        // SMS off so the run exercises the email channel without Twilio; the
        // tenant lookup is the same for both channels.
        JobberAutomationSettings::set('tenant_job_reminder_sms', true);
    }

    /**
     * One row of the PropertyWare tenant report, in its column order:
     * 0 Lease Name, 1 Is Active, 2 Lease Status, 3 Full Name, 4 Building,
     * 5 Portfolio, 6 Last Name, 7 First Name, 8 Unit, 9 Role, 10 Mobile,
     * 11 Email, 12 Work, 13 Home, 14 Enrolled in TBP.
     *
     * @return array<int, string>
     */
    private function pwRecord(
        string $building,
        string $name,
        string $email,
        string $status = 'Active',
        string $enrolled = 'Yes',
    ): array {
        return ['Lease', 'Yes', $status, $name, $building, 'Owner, Some', 'Last', 'First', $building, 'Primary', '(817) 313-8253', $email, '', '(817) 313-8253', $enrolled];
    }

    /**
     * @param  list<array<int, string>>  $records
     */
    private function fakeReportAndGraph(array $records): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://app.propertyware.com/*' => Http::response(['records' => $records]),
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response(['id' => 'REMINDER_GRAPH_ID']),
        ]);
    }

    private function makeTbpVisit(Carbon $startAt, string $clientName = '418 Drennan St'): JobberVisit
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-'.uniqid(),
            'name' => $clientName,
            'jobber_web_uri' => 'https://example.test',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-'.uniqid(),
            'jobber_client_id' => $client->id,
            'street' => $clientName,
            'city' => 'Houston',
        ]);

        $job = Jobber::query()->create([
            'jobber_id' => 'job-'.uniqid(),
            'job_number' => '20638',
            'title' => 'Zone 4 - Q3 TBP - Revisit',
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);

        return JobberVisit::query()->create([
            'jobber_job_id' => $job->id,
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
            'jobber_id' => 'visit-'.uniqid(),
            'title' => 'Zone 4 - Q3 TBP - Revisit',
            'start_at' => $startAt,
            'notified_14_days' => false,
            'notified_7_days' => false,
            'notified_3_days' => false,
            'notified_1_days' => false,
        ]);
    }

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
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        return $command;
    }

    private function nextMonday(): Carbon
    {
        return Carbon::parse('next monday', 'America/Chicago')->setTime(9, 0);
    }

    /**
     * @return list<string>
     */
    private function reminderRecipients(): array
    {
        $recipients = [];

        Http::assertSent(function ($request) use (&$recipients) {
            if (str_ends_with($request->url(), '/messages') && $request->method() === 'POST') {
                $recipients[] = $request['toRecipients'][0]['emailAddress']['address'];
            }

            return true;
        });

        return $recipients;
    }

    public function test_the_tenant_is_reminded_when_propertyware_leaves_the_street_suffix_off(): void
    {
        $this->fakeReportAndGraph([
            $this->pwRecord('418 Drennan', 'Olutomilola Asekun', 'tenant@example.test'),
        ]);

        $monday = $this->nextMonday();
        $visit = $this->makeTbpVisit($monday, '418 Drennan St');

        $this->remindersCommand()->runSendMessages($monday, 'notified_3_days', 'Dear {CLIENT_NAME}, visit on {SCHEDULED_DATE}');

        $this->assertTrue($visit->fresh()->notified_3_days);
        $this->assertSame(['tenant@example.test'], $this->reminderRecipients());
    }

    public function test_the_tenant_is_reminded_when_jobber_leaves_the_street_suffix_off(): void
    {
        $this->fakeReportAndGraph([
            $this->pwRecord('17710 Winnower Ln', 'Jane Tenant', 'jane@example.test'),
        ]);

        $monday = $this->nextMonday();
        $visit = $this->makeTbpVisit($monday, '17710 Winnower');

        $this->remindersCommand()->runSendMessages($monday, 'notified_1_days', 'Dear {CLIENT_NAME}, visit tomorrow');

        $this->assertTrue($visit->fresh()->notified_1_days);
        $this->assertSame(['jane@example.test'], $this->reminderRecipients());
    }

    public function test_another_house_with_the_same_street_number_is_not_the_tenant(): void
    {
        $this->fakeReportAndGraph([
            $this->pwRecord('3222 Cedar Knolls Dr', 'Other Tenant', 'other@example.test'),
            $this->pwRecord('3223 Hunterwood Dr', 'Neighbour Tenant', 'neighbour@example.test'),
        ]);

        $monday = $this->nextMonday();
        $visit = $this->makeTbpVisit($monday, '3222 Hunterwood Dr');

        $this->remindersCommand()->runSendMessages($monday, 'notified_3_days', 'Dear {CLIENT_NAME}, visit on {SCHEDULED_DATE}');

        // No tenant found: the visit stays unflagged so a corrected name is
        // picked up on the next run, and nobody else is texted.
        $this->assertFalse($visit->fresh()->notified_3_days);
        $this->assertSame([], $this->reminderRecipients());
    }

    public function test_a_client_matching_two_different_propertyware_buildings_is_logged_for_review(): void
    {
        Log::spy();

        $this->fakeReportAndGraph([
            $this->pwRecord('1515 Stoney Park', 'Front Tenant', 'front@example.test'),
            $this->pwRecord('1515 Stoney Park B', 'Back Tenant', 'back@example.test'),
        ]);

        $monday = $this->nextMonday();
        $visit = $this->makeTbpVisit($monday, '1515 Stoney Park');

        $this->remindersCommand()->runSendMessages($monday, 'notified_7_days', 'Dear {CLIENT_NAME}, visit on {SCHEDULED_DATE}');

        $this->assertTrue($visit->fresh()->notified_7_days);
        $this->assertEqualsCanonicalizing(['front@example.test', 'back@example.test'], $this->reminderRecipients());

        Log::shouldHaveReceived('warning')
            ->with('Jobber client matched more than one PropertyWare building', Mockery::on(function (array $context): bool {
                return $context['jobber_client_name'] === '1515 Stoney Park'
                    && $context['job_number'] === '20638'
                    && $context['propertyware_buildings'] === ['1515 Stoney Park', '1515 Stoney Park B'];
            }))
            ->once();
    }

    public function test_two_tenants_on_the_same_lease_are_not_a_collision(): void
    {
        Log::spy();

        $this->fakeReportAndGraph([
            $this->pwRecord('418 Drennan', 'Olutomilola Asekun', 'tenant@example.test'),
            $this->pwRecord('418 Drennan', 'Ashley-Nicole Martin', 'second@example.test'),
        ]);

        $monday = $this->nextMonday();
        $visit = $this->makeTbpVisit($monday, '418 Drennan St');

        $this->remindersCommand()->runSendMessages($monday, 'notified_3_days', 'Dear {CLIENT_NAME}, visit on {SCHEDULED_DATE}');

        $this->assertEqualsCanonicalizing(['tenant@example.test', 'second@example.test'], $this->reminderRecipients());
        Log::shouldNotHaveReceived('warning', ['Jobber client matched more than one PropertyWare building', Mockery::any()]);
    }

    public function test_a_tenant_who_gave_notice_or_went_month_to_month_still_lives_there(): void
    {
        $this->fakeReportAndGraph([
            $this->pwRecord('418 Drennan', 'Olutomilola Asekun', 'notice@example.test', 'Active - Notice Given'),
            $this->pwRecord('418 Drennan', 'Month To Month', 'mtm@example.test', 'Going MTM'),
            $this->pwRecord('418 Drennan', 'Evicted Tenant', 'eviction@example.test', 'Eviction'),
            $this->pwRecord('418 Drennan', 'Draft Lease', 'draft@example.test', 'Draft'),
        ]);

        $monday = $this->nextMonday();
        $this->makeTbpVisit($monday, '418 Drennan St');

        $this->remindersCommand()->runSendMessages($monday, 'notified_3_days', 'Dear {CLIENT_NAME}, visit on {SCHEDULED_DATE}');

        $this->assertEqualsCanonicalizing(['notice@example.test', 'mtm@example.test'], $this->reminderRecipients());
    }
}
