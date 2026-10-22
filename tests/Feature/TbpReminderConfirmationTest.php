<?php

namespace Tests\Feature;

use App\Ai\Agents\TbpVisitConfirmationJudgeAgent;
use App\Console\Commands\SendJobReminders;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberTextMessage;
use App\Models\JobberVisit;
use App\Services\AutomatedMessageTemplates;
use App\Services\JobberAutomationSettings;
use Carbon\Carbon;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * A tenant who already confirmed their TBP visit by text gets the short
 * "thank you for confirming" reminder at 7/3/1 days instead of the notice
 * asking them to confirm again (2026-10-23 request). The AI reads the reply;
 * the verdict is per tenant phone number.
 */
class TbpReminderConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private const OUR_NUMBER = '+18175550100';

    private const MICHAEL_PHONE = '(817) 313-8253';

    private const ROOMMATE_PHONE = '(817) 313-9999';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'services.microsoft.job_reminder_mailbox' => 'service@txhomemp.com',
            'services.twilio.sid' => 'ACtest',
            'services.twilio.auth_token' => 'test-token',
            'services.ai.tbp_confirmation_judge' => true,
            'services.ai.tbp_confirmation_min_confidence' => 75,
            'ai.providers.openai.key' => 'test-key',
        ]);
        Cache::forget('microsoft.graph.token');
        Mail::fake();

        // SMS off so the run is read through the email channel; the email
        // follows the tenant's own text verdict.
        JobberAutomationSettings::set('tenant_job_reminder_sms', true);
    }

    /**
     * @return array<int, string>
     */
    private function pwRecord(string $building, string $name, string $email, string $phone): array
    {
        return ['Lease', 'Yes', 'Active', $name, $building, 'Owner, Some', 'Last', 'First', $building, 'Primary', $phone, $email, '', '', 'Yes'];
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

    private function michaelOnly(): void
    {
        $this->fakeReportAndGraph([
            $this->pwRecord('418 Drennan St', 'Michael Tenant', 'michael@example.com', self::MICHAEL_PHONE),
        ]);
    }

    private function makeTbpVisit(Carbon $startAt): JobberVisit
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-'.uniqid(),
            'name' => '418 Drennan St',
            'jobber_web_uri' => 'https://example.test',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-'.uniqid(),
            'jobber_client_id' => $client->id,
            'street' => '418 Drennan St',
            'city' => 'Houston',
        ]);

        $job = Jobber::query()->create([
            'jobber_id' => 'job-'.uniqid(),
            'job_number' => '20638',
            'title' => 'Zone 4 - Q4 TBP',
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);

        return JobberVisit::query()->create([
            'jobber_job_id' => $job->id,
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
            'jobber_id' => 'visit-'.uniqid(),
            'title' => 'Zone 4 - Q4 TBP',
            'start_at' => $startAt,
            'notified_14_days' => true,
            'notified_7_days' => false,
            'notified_3_days' => false,
            'notified_1_days' => false,
        ]);
    }

    private function tenantReplied(JobberVisit $visit, string $body, string $phone = '+18173138253', ?Carbon $at = null): void
    {
        JobberTextMessage::query()->create([
            'sender_number' => self::OUR_NUMBER,
            'receiver_number' => $phone,
            'messages' => 'Dear Michael Tenant, As part of your Tenant Benefit Package... Please confirm receipt of this notice',
            'jobber_id' => $visit->jobber_job_id,
        ]);

        $reply = JobberTextMessage::query()->create([
            'sender_number' => $phone,
            'receiver_number' => self::OUR_NUMBER,
            'messages' => $body,
            'jobber_id' => $visit->jobber_job_id,
        ]);

        if ($at !== null) {
            JobberTextMessage::query()->whereKey($reply->id)->update(['created_at' => $at]);
        }
    }

    private function judgeSays(bool $confirmed, int $confidence = 95): void
    {
        TbpVisitConfirmationJudgeAgent::fake([[
            'confirmed' => $confirmed,
            'confidence' => $confidence,
            'reason' => $confirmed ? 'The tenant replied yes.' : 'The tenant asked to reschedule.',
        ]]);
    }

    private function runReminder(JobberVisit $visit, string $field): void
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

        $template = [
            'notified_14_days' => 'tenant_job_reminder_14_day',
            'notified_7_days' => 'tenant_job_reminder_7_day',
            'notified_3_days' => 'tenant_job_reminder_3_day',
            'notified_1_days' => 'tenant_job_reminder_1_day',
        ][$field];

        $command->runSendMessages(Carbon::parse($visit->start_at), $field, AutomatedMessageTemplates::raw($template));
    }

    /**
     * @return array<string, string> email address => body
     */
    private function sentBodies(): array
    {
        $bodies = [];

        Http::assertSent(function ($request) use (&$bodies) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/messages')) {
                $bodies[$request['toRecipients'][0]['emailAddress']['address']] = (string) $request['body']['content'];
            }

            return true;
        });

        return $bodies;
    }

    private function nextMonday(): Carbon
    {
        return Carbon::parse('next monday', 'America/Chicago')->setTime(9, 0);
    }

    public function test_a_tenant_who_confirmed_gets_the_short_thank_you_reminder_at_seven_days(): void
    {
        $this->michaelOnly();
        $visit = $this->makeTbpVisit($this->nextMonday());
        $this->tenantReplied($visit, 'Yes confirmed, thank you');
        $this->judgeSays(true);

        $this->runReminder($visit, 'notified_7_days');

        $body = $this->sentBodies()['michael@example.com'];
        $this->assertStringContainsString('Thank you for confirming your appointment', $body);
        $this->assertStringContainsString('Hello Michael Tenant', $body);
        $this->assertStringContainsString('scheduled for '.$this->nextMonday()->format('l, F d, Y'), $body);
        $this->assertStringNotContainsString('Please confirm receipt', $body);
        TbpVisitConfirmationJudgeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'them: Yes confirmed, thank you'));
    }

    public function test_the_day_before_reminder_for_a_confirmed_tenant_says_tomorrow(): void
    {
        $this->michaelOnly();
        $visit = $this->makeTbpVisit($this->nextMonday());
        $this->tenantReplied($visit, 'ok');
        $this->judgeSays(true);

        $this->runReminder($visit, 'notified_1_days');

        $body = $this->sentBodies()['michael@example.com'];
        $this->assertStringContainsString('scheduled for tomorrow, '.$this->nextMonday()->format('l, F d, Y'), $body);
        $this->assertStringContainsString('Thank you for confirming your appointment', $body);
    }

    public function test_a_tenant_who_never_replied_gets_the_full_notice_again_without_asking_the_ai(): void
    {
        $this->michaelOnly();
        $visit = $this->makeTbpVisit($this->nextMonday());
        TbpVisitConfirmationJudgeAgent::fake();

        $this->runReminder($visit, 'notified_7_days');

        $body = $this->sentBodies()['michael@example.com'];
        $this->assertStringContainsString('Please confirm receipt', $body);
        $this->assertStringNotContainsString('Thank you for confirming', $body);
        TbpVisitConfirmationJudgeAgent::assertNeverPrompted();
    }

    public function test_an_unconfirmed_tenant_keeps_the_regular_three_day_reminder(): void
    {
        $this->michaelOnly();
        $visit = $this->makeTbpVisit($this->nextMonday());
        TbpVisitConfirmationJudgeAgent::fake();

        $this->runReminder($visit, 'notified_3_days');

        $body = $this->sentBodies()['michael@example.com'];
        $this->assertStringContainsString('This is a friendly reminder of the scheduled visit', $body);
        $this->assertStringNotContainsString('Thank you for confirming', $body);
    }

    public function test_a_reschedule_request_is_not_a_confirmation(): void
    {
        $this->michaelOnly();
        $visit = $this->makeTbpVisit($this->nextMonday());
        $this->tenantReplied($visit, 'Can we move this to next week?');
        $this->judgeSays(false);

        $this->runReminder($visit, 'notified_7_days');

        $this->assertStringContainsString('Please confirm receipt', $this->sentBodies()['michael@example.com']);
    }

    public function test_a_confirmation_below_the_confidence_bar_is_not_trusted(): void
    {
        $this->michaelOnly();
        $visit = $this->makeTbpVisit($this->nextMonday());
        $this->tenantReplied($visit, 'k');
        $this->judgeSays(true, 60);

        $this->runReminder($visit, 'notified_7_days');

        $this->assertStringContainsString('Please confirm receipt', $this->sentBodies()['michael@example.com']);
    }

    public function test_an_ai_failure_falls_back_to_the_full_notice(): void
    {
        $this->michaelOnly();
        $visit = $this->makeTbpVisit($this->nextMonday());
        $this->tenantReplied($visit, 'Yes');
        TbpVisitConfirmationJudgeAgent::fake([fn () => throw new RuntimeException('provider timed out')]);

        $this->runReminder($visit, 'notified_7_days');

        $this->assertStringContainsString('Please confirm receipt', $this->sentBodies()['michael@example.com']);
    }

    public function test_the_switch_off_keeps_the_old_messages_and_never_asks_the_ai(): void
    {
        config(['services.ai.tbp_confirmation_judge' => false]);
        $this->michaelOnly();
        $visit = $this->makeTbpVisit($this->nextMonday());
        $this->tenantReplied($visit, 'Yes');
        TbpVisitConfirmationJudgeAgent::fake();

        $this->runReminder($visit, 'notified_7_days');

        $this->assertStringContainsString('Please confirm receipt', $this->sentBodies()['michael@example.com']);
        TbpVisitConfirmationJudgeAgent::assertNeverPrompted();
    }

    public function test_the_fourteen_day_notice_is_always_the_full_notice(): void
    {
        $this->michaelOnly();
        $visit = $this->makeTbpVisit($this->nextMonday());
        $visit->update(['notified_14_days' => false]);
        $this->tenantReplied($visit, 'Yes');
        TbpVisitConfirmationJudgeAgent::fake();

        $this->runReminder($visit, 'notified_14_days');

        $this->assertStringContainsString('This advance notice is sent two weeks ahead', $this->sentBodies()['michael@example.com']);
        TbpVisitConfirmationJudgeAgent::assertNeverPrompted();
    }

    public function test_only_the_roommate_who_confirmed_is_thanked(): void
    {
        $this->fakeReportAndGraph([
            $this->pwRecord('418 Drennan St', 'Michael Tenant', 'michael@example.com', self::MICHAEL_PHONE),
            $this->pwRecord('418 Drennan St', 'Robin Roommate', 'robin@example.com', self::ROOMMATE_PHONE),
        ]);
        $visit = $this->makeTbpVisit($this->nextMonday());
        $this->tenantReplied($visit, 'Confirmed');
        $this->judgeSays(true);

        $this->runReminder($visit, 'notified_7_days');

        $bodies = $this->sentBodies();
        $this->assertStringContainsString('Thank you for confirming', $bodies['michael@example.com']);
        $this->assertStringContainsString('Please confirm receipt', $bodies['robin@example.com']);
        $this->assertStringNotContainsString('Thank you for confirming', $bodies['robin@example.com']);
    }

    public function test_a_reply_from_before_this_visits_notice_window_does_not_count(): void
    {
        $this->michaelOnly();
        $visit = $this->makeTbpVisit($this->nextMonday());
        $this->tenantReplied($visit, 'Yes', at: $this->nextMonday()->subDays(30));
        TbpVisitConfirmationJudgeAgent::fake();

        $this->runReminder($visit, 'notified_7_days');

        $this->assertStringContainsString('Please confirm receipt', $this->sentBodies()['michael@example.com']);
        TbpVisitConfirmationJudgeAgent::assertNeverPrompted();
    }

    public function test_the_ledger_records_which_tenants_had_confirmed(): void
    {
        $this->michaelOnly();
        $visit = $this->makeTbpVisit($this->nextMonday());
        $this->tenantReplied($visit, 'Yes');
        $this->judgeSays(true);

        $this->runReminder($visit, 'notified_7_days');

        $entry = Activity::query()->where('event', 'tenant_job_reminder_email')->sole();
        $this->assertTrue((bool) $entry->getExtraProperty('tenant_confirmed'));
    }

    public function test_the_confirmed_reminder_is_an_editable_gsm7_template(): void
    {
        $text = AutomatedMessageTemplates::default('tenant_job_reminder_confirmed');

        $this->assertSame([], AutomatedMessageTemplates::nonGsmCharacters($text));
        $this->assertSame([], AutomatedMessageTemplates::unknownTokens('tenant_job_reminder_confirmed', $text));
        $this->assertSame([], AutomatedMessageTemplates::missingRequiredTokens('tenant_job_reminder_confirmed', $text));
        $this->assertStringContainsString('{VISIT_DAY}', $text);
    }
}
