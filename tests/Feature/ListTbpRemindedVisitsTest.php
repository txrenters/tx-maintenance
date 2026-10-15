<?php

namespace Tests\Feature;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberTextMessage;
use App\Models\JobberVisit;
use App\Services\AutomatedMessageLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The tbp:reminded-visits report: which tenants were told about an upcoming
 * TBP visit (automated 14/7/3/1-day reminders and the manual Send
 * notification text), whether the visit still stands, and who wrote back.
 * Built when the office pulled every scheduled TBP visit and needed the list
 * of tenants to tell "we cannot visit".
 */
class ListTbpRemindedVisitsTest extends TestCase
{
    use RefreshDatabase;

    private const THMP = '+12816999281';

    private const TENANT = '+18173138253';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.twilio.from' => self::THMP]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeJob(string $clientName = '418 Drennan St', string $jobNumber = '20638', string $suffix = '1'): Jobber
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-'.$suffix,
            'name' => $clientName,
            'jobber_web_uri' => 'https://example.test',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-'.$suffix,
            'jobber_client_id' => $client->id,
            'street' => $clientName,
            'city' => 'Houston',
            'province' => 'TX',
        ]);

        return Jobber::query()->create([
            'jobber_id' => 'job-'.$suffix,
            'job_number' => $jobNumber,
            'title' => 'Zone 4 - Q3 TBP - Revisit',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);
    }

    private function makeVisit(Jobber $job, string $date, string $suffix = '1'): JobberVisit
    {
        return JobberVisit::query()->create([
            'jobber_id' => 'visit-'.$suffix,
            'jobber_job_id' => $job->id,
            'jobber_client_id' => $job->jobber_client_id,
            'jobber_property_id' => $job->jobber_property_id,
            'title' => 'Zone 4 - Q3 TBP - Revisit',
            'start_at' => $date.' 00:00:00',
            'end_at' => $date.' 23:59:59',
        ]);
    }

    /**
     * What SendJobReminders writes for one SMS recipient: the message row and
     * the ledger entry that points at it.
     */
    private function remind(Jobber $job, ?JobberVisit $visit, string $visitDate, string $sentAt, string $phone = self::TENANT): JobberTextMessage
    {
        Carbon::setTestNow(Carbon::parse($sentAt, 'America/Chicago'));

        $body = 'Good day Jane Doe, As part of your Tenant Benefit Package (TBP), we have scheduled the following services on '
            .Carbon::parse($visitDate)->format('l, F d, Y').': pest control, air filter replacement, occupied inspection.';

        $text = JobberTextMessage::query()->create([
            'messages' => $body,
            'sender_number' => self::THMP,
            'receiver_number' => $phone,
            'jobber_id' => $job->id,
            'jobber_visit_id' => $visit?->id,
        ]);

        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_SMS,
            'tenant',
            'tenant_job_reminder_sms',
            $phone,
            $job,
            $body,
            [
                'jobber_id' => $job->id,
                'jobber_visit_id' => $visit?->id,
                'job_number' => $job->job_number,
                'jobber_text_message_id' => $text->id,
            ],
        );

        Carbon::setTestNow();

        return $text;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function csv(array $parameters): string
    {
        $this->assertSame(0, Artisan::call('tbp:reminded-visits', $parameters));

        return Artisan::output();
    }

    private function reply(Jobber $job, string $at, string $body = 'Ok thank you, noted.', string $phone = self::TENANT): JobberTextMessage
    {
        Carbon::setTestNow(Carbon::parse($at, 'America/Chicago'));

        $text = JobberTextMessage::query()->create([
            'messages' => $body,
            'sender_number' => $phone,
            'receiver_number' => self::THMP,
            'jobber_id' => $job->id,
        ]);

        Carbon::setTestNow();

        return $text;
    }

    public function test_lists_a_reminded_visit_that_is_still_scheduled(): void
    {
        $job = $this->makeJob();
        $visit = $this->makeVisit($job, '2026-10-22');
        $this->remind($job, $visit, '2026-10-22', '2026-10-15 10:00:00');

        $this->artisan('tbp:reminded-visits', ['--from' => '2026-10-15'])
            ->expectsOutputToContain('20638')
            ->expectsOutputToContain('1 tenant/visit rows across 1 jobs; 0 replied.')
            ->doesntExpectOutputToContain('unscheduled')
            ->assertSuccessful();
    }

    public function test_a_visit_deleted_after_the_reminder_is_still_listed_as_unscheduled(): void
    {
        $job = $this->makeJob();
        $visit = $this->makeVisit($job, '2026-10-22');
        $this->remind($job, $visit, '2026-10-22', '2026-10-15 10:00:00');

        // The VISIT_DESTROY webhook deletes the row; the message FK goes null.
        $visit->delete();

        $this->assertNull(JobberTextMessage::query()->sole()->jobber_visit_id);

        $csv = $this->csv(['--from' => '2026-10-15', '--csv' => '-']);

        $this->assertStringContainsString('20638', $csv);
        $this->assertStringContainsString('2026-10-22', $csv);
        $this->assertStringContainsString('unscheduled', $csv);
    }

    public function test_flags_the_tenant_reply_that_followed_the_reminder(): void
    {
        $job = $this->makeJob();
        $visit = $this->makeVisit($job, '2026-10-22');

        $this->reply($job, '2026-10-01 09:00:00', 'Old message about something else');
        $this->remind($job, $visit, '2026-10-22', '2026-10-15 10:00:00');
        $this->reply($job, '2026-10-15 10:30:00', "Received,  thanks.\nWe will be home.");

        $csv = $this->csv(['--from' => '2026-10-15', '--csv' => '-']);

        $this->assertStringContainsString(',yes,"2026-10-15 10:30","Received, thanks. We will be home."', $csv);
        $this->assertStringNotContainsString('Old message', $csv);
    }

    public function test_a_message_sent_before_the_reminder_is_not_a_reply(): void
    {
        $job = $this->makeJob();
        $visit = $this->makeVisit($job, '2026-10-22');

        $this->reply($job, '2026-10-14 09:00:00', 'Earlier question');
        $this->remind($job, $visit, '2026-10-22', '2026-10-15 10:00:00');

        $csv = $this->csv(['--from' => '2026-10-15', '--csv' => '-']);

        $this->assertStringContainsString(',no,,', $csv);
    }

    public function test_the_window_keeps_only_visits_between_from_and_to(): void
    {
        $past = $this->makeJob('100 Past Ln', '20001', 'past');
        $this->remind($past, $this->makeVisit($past, '2026-10-14', 'past'), '2026-10-14', '2026-10-07 10:00:00', '+15550000001');

        $inside = $this->makeJob('200 Inside Dr', '20002', 'inside');
        $this->remind($inside, $this->makeVisit($inside, '2026-10-20', 'inside'), '2026-10-20', '2026-10-13 10:00:00', '+15550000002');

        $late = $this->makeJob('300 Late Ct', '20003', 'late');
        $this->remind($late, $this->makeVisit($late, '2026-10-28', 'late'), '2026-10-28', '2026-10-14 10:00:00', '+15550000003');

        $csv = $this->csv(['--from' => '2026-10-15', '--csv' => '-']);

        $this->assertStringNotContainsString('20001', $csv);
        $this->assertStringContainsString('20002', $csv);
        $this->assertStringContainsString('20003', $csv);

        $csv = $this->csv(['--from' => '2026-10-15', '--to' => '2026-10-21', '--csv' => '-']);

        $this->assertStringContainsString('20002', $csv);
        $this->assertStringNotContainsString('20003', $csv);
    }

    public function test_replies_only_drops_tenants_who_did_not_answer(): void
    {
        $quiet = $this->makeJob('100 Quiet Ln', '20001', 'quiet');
        $this->remind($quiet, $this->makeVisit($quiet, '2026-10-20', 'quiet'), '2026-10-20', '2026-10-13 10:00:00', '+15550000001');

        $talker = $this->makeJob('200 Talker Dr', '20002', 'talker');
        $this->remind($talker, $this->makeVisit($talker, '2026-10-20', 'talker'), '2026-10-20', '2026-10-13 10:00:00', '+15550000002');
        $this->reply($talker, '2026-10-13 11:00:00', 'Got it', '+15550000002');

        $csv = $this->csv(['--from' => '2026-10-15', '--replies-only' => true, '--csv' => '-']);

        $this->assertStringContainsString('20002', $csv);
        $this->assertStringNotContainsString('20001', $csv);
    }

    public function test_csv_to_stdout_has_the_header_and_one_row_per_tenant(): void
    {
        $job = $this->makeJob();
        $visit = $this->makeVisit($job, '2026-10-22');
        $this->remind($job, $visit, '2026-10-22', '2026-10-08 10:00:00', '+15550000001');
        $this->remind($job, $visit, '2026-10-22', '2026-10-08 10:00:01', '+15550000002');

        $csv = $this->csv(['--from' => '2026-10-15', '--csv' => '-']);

        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertCount(3, $lines);
        $this->assertSame('"Job #","Job title","Jobber client",Property,"Visit date",Reminders,Channel,Recipient,"Last reminder","Visit now",Replied,"Reply at",Reply', $lines[0]);
        $this->assertStringContainsString('+15550000001', $lines[1]);
        $this->assertStringContainsString('+15550000002', $lines[2]);
    }

    public function test_tiers_for_the_same_tenant_and_visit_collapse_into_one_row(): void
    {
        $job = $this->makeJob();
        $visit = $this->makeVisit($job, '2026-10-22');
        $this->remind($job, $visit, '2026-10-22', '2026-10-08 10:00:00');
        $this->remind($job, $visit, '2026-10-22', '2026-10-15 10:00:00');

        $csv = $this->csv(['--from' => '2026-10-15', '--csv' => '-']);

        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertCount(2, $lines);
        $this->assertStringContainsString(',2026-10-22,"14,7",sms,', $lines[1]);
        $this->assertStringContainsString(',"2026-10-15 10:00",', $lines[1]);
    }

    public function test_a_manual_send_notification_text_is_listed_with_the_manual_tier(): void
    {
        $job = $this->makeJob();
        $visit = $this->makeVisit($job, '2026-10-22');

        Carbon::setTestNow(Carbon::parse('2026-10-14 15:00:00', 'America/Chicago'));
        JobberTextMessage::query()->create([
            'messages' => "Good day,\n\nAs part of your Tenant Benefit Package (TBP), we have scheduled the following services Thursday, October 22, 2026:\n* Pest control treatment",
            'sender_number' => self::THMP,
            'receiver_number' => self::TENANT,
            'jobber_id' => $job->id,
            'jobber_visit_id' => $visit->id,
        ]);
        Carbon::setTestNow();

        $csv = $this->csv(['--from' => '2026-10-15', '--csv' => '-']);

        $this->assertStringContainsString(',2026-10-22,manual,sms,'.self::TENANT.',"2026-10-14 15:00",scheduled,no,', $csv);
    }

    public function test_a_rescheduled_visit_shows_the_new_date(): void
    {
        $job = $this->makeJob();
        $visit = $this->makeVisit($job, '2026-10-22');
        $this->remind($job, $visit, '2026-10-22', '2026-10-15 10:00:00');

        $visit->update(['start_at' => '2026-11-03 00:00:00', 'end_at' => '2026-11-03 23:59:59']);

        $csv = $this->csv(['--from' => '2026-10-15', '--csv' => '-']);

        $this->assertStringContainsString(',2026-10-22,7,sms,'.self::TENANT.',"2026-10-15 10:00","moved to 2026-11-03",', $csv);
    }

    public function test_an_email_reminder_is_listed_without_a_reply_column(): void
    {
        $job = $this->makeJob();
        $visit = $this->makeVisit($job, '2026-10-22');

        Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00', 'America/Chicago'));
        AutomatedMessageLogService::log(
            AutomatedMessageLogService::CHANNEL_EMAIL,
            'tenant',
            'tenant_job_reminder_email',
            'jane@example.com',
            $job,
            extra: [
                'jobber_id' => $job->id,
                'jobber_visit_id' => $visit->id,
                'job_number' => $job->job_number,
                'subject' => '418 Drennan - Reminder: Scheduled TBP Service on Thursday, October 22, 2026',
            ],
        );
        Carbon::setTestNow();

        $csv = $this->csv(['--from' => '2026-10-15', '--csv' => '-']);

        $this->assertStringContainsString(',2026-10-22,7,email,jane@example.com,"2026-10-15 10:00",scheduled,"n/a (email)",,', $csv);
    }

    public function test_rejects_a_malformed_date(): void
    {
        $this->artisan('tbp:reminded-visits', ['--from' => '15-10-2026'])
            ->expectsOutputToContain('Dates must use the Y-m-d format')
            ->assertFailed();
    }

    public function test_says_so_when_nothing_was_reminded(): void
    {
        $this->artisan('tbp:reminded-visits', ['--from' => '2026-10-15'])
            ->expectsOutputToContain('No reminded tenants found in that window.')
            ->assertSuccessful();
    }
}
