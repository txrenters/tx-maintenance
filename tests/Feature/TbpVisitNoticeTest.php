<?php

namespace Tests\Feature;

use App\Models\ClientContact;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberVisit;
use App\Models\User;
use App\Services\AutomatedMessageTemplates;
use App\Services\PropertyWareTenantReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TbpVisitNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'tenant'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function staff(string $role = 'woc'): User
    {
        return User::factory()->create()->assignRole($role);
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
        string $phone = '(346) 368-4607',
        string $status = 'Active',
        string $enrolled = 'Yes',
    ): array {
        return ['Lease', 'Yes', $status, $name, $building, 'Owner, Some', 'Last', 'First', $building, 'Primary', $phone, 'tenant@example.test', '', $phone, $enrolled];
    }

    /**
     * @param  list<array<int, string>>  $records
     */
    private function fakeReport(array $records): void
    {
        Http::fake([
            'https://app.propertyware.com/*' => Http::response(['records' => $records]),
        ]);
    }

    private function makeVisit(
        string $clientName = '17710 Winnower Ln',
        string $title = '17710 Winnower Ln - Zone 1 - Q3 2026 Tenant Benefit Package',
        ?string $clientPhone = null,
        ?string $startAt = '2026-08-26 00:00:00',
    ): JobberVisit {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-1',
            'name' => $clientName,
            'phone' => $clientPhone,
            'jobber_web_uri' => 'https://example.test',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-1',
            'jobber_client_id' => $client->id,
        ]);

        $job = Jobber::query()->create([
            'jobber_id' => 'job-1',
            'job_number' => '19188',
            'title' => 'Zone 1 - Q3 2026 Tenant Benefit Package',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);

        return JobberVisit::query()->create([
            'jobber_id' => 'visit-1',
            'jobber_job_id' => $job->id,
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
            'title' => $title,
            'start_at' => $startAt,
            'end_at' => $startAt === null ? null : '2026-08-26 23:59:59',
        ]);
    }

    public function test_the_notice_carries_the_visit_date_and_finds_the_tenant_despite_a_missing_street_suffix(): void
    {
        // The automated reminder never matches "17710 Winnower Ln" against
        // PropertyWare's "17710 Winnower"; the button's suggestions must.
        $this->fakeReport([$this->pwRecord('17710 Winnower', 'Ali R Sharif')]);
        $visit = $this->makeVisit();

        $response = $this->actingAs($this->staff())->getJson(route('visits.tbp_notice', $visit));

        $response->assertOk()
            ->assertJsonPath('is_tbp', true)
            ->assertJsonPath('scheduled_date', 'Wednesday, August 26, 2026')
            ->assertJsonPath('template_key', 'tenant_tbp_visit_notice')
            ->assertJsonPath('max_length', 1600)
            ->assertJsonCount(1, 'recipients')
            ->assertJsonPath('recipients.0.name', 'Ali R Sharif')
            ->assertJsonPath('recipients.0.phone', '(346) 368-4607')
            ->assertJsonPath('recipients.0.source', 'propertyware')
            ->assertJsonPath('recipients.0.selected', true)
            ->assertJsonPath('reminders.7_day', false);

        $message = $response->json('message');
        $this->assertStringStartsWith("Good day,\n\n", $message);
        $this->assertStringContainsString('we have scheduled the following services Wednesday, August 26, 2026:', $message);
        $this->assertStringContainsString('* Body Cameras:', $message);
        $this->assertStringEndsWith("Warm regards,\nTexasRenters, LLC", $message);
        $this->assertStringNotContainsString('{', $message);
        $this->assertSame(mb_strlen($message), $response->json('length'));
        $this->assertLessThanOrEqual(1600, $response->json('length'));
        $this->assertSame([], AutomatedMessageTemplates::nonGsmCharacters($message));
    }

    public function test_other_houses_are_not_suggested(): void
    {
        $this->fakeReport([
            $this->pwRecord('17710 Winnower', 'Ali R Sharif'),
            $this->pwRecord('17711 Winnower', 'Next Door', '(346) 555-0001'),
            $this->pwRecord('17710 Main St', 'Other Street', '(346) 555-0002'),
        ]);
        $visit = $this->makeVisit();

        $this->actingAs($this->staff())->getJson(route('visits.tbp_notice', $visit))
            ->assertOk()
            ->assertJsonCount(1, 'recipients')
            ->assertJsonPath('recipients.0.name', 'Ali R Sharif');
    }

    public function test_jobber_client_phone_and_saved_contacts_are_offered_once_per_number(): void
    {
        $this->fakeReport([$this->pwRecord('17710 Winnower', 'Ali R Sharif')]);
        $visit = $this->makeVisit(clientPhone: '+13463684607');
        ClientContact::query()->create(['jobber_id' => $visit->jobber_job_id, 'name' => 'Spouse', 'phone' => '+13465550100']);
        ClientContact::query()->create(['jobber_id' => $visit->jobber_job_id, 'name' => 'Ali again', 'phone' => '3463684607']);

        $response = $this->actingAs($this->staff())->getJson(route('visits.tbp_notice', $visit));

        $response->assertOk()->assertJsonCount(2, 'recipients');

        $recipients = collect($response->json('recipients'));
        $this->assertSame(['propertyware', 'contact'], $recipients->pluck('source')->all());
        $this->assertSame('Spouse', $recipients[1]['name']);
        $this->assertFalse($recipients[1]['selected']);
    }

    public function test_the_jobber_client_phone_is_preselected_only_when_propertyware_offers_nobody(): void
    {
        $this->fakeReport([]);
        $visit = $this->makeVisit(clientPhone: '+13463684607');

        $this->actingAs($this->staff())->getJson(route('visits.tbp_notice', $visit))
            ->assertOk()
            ->assertJsonCount(1, 'recipients')
            ->assertJsonPath('recipients.0.source', 'jobber')
            ->assertJsonPath('recipients.0.phone', '+13463684607')
            ->assertJsonPath('recipients.0.selected', true);
    }

    public function test_inactive_or_unenrolled_tenants_are_listed_but_not_preselected(): void
    {
        $this->fakeReport([
            $this->pwRecord('17710 Winnower', 'Moved Out', '(346) 555-0001', status: 'Past'),
            $this->pwRecord('17710 Winnower', 'Not Enrolled', '(346) 555-0002', enrolled: 'No'),
            $this->pwRecord('17710 Winnower', 'No Phone', ''),
            $this->pwRecord('17710 Winnower', 'Notice Given', '(346) 555-0004', status: 'Active - Notice Given'),
        ]);
        $visit = $this->makeVisit();

        $recipients = collect($this->actingAs($this->staff())->getJson(route('visits.tbp_notice', $visit))
            ->assertOk()
            ->json('recipients'));

        $this->assertCount(4, $recipients);
        $this->assertSame([false, false, false, true], $recipients->pluck('selected')->all());
        $this->assertNull($recipients->firstWhere('name', 'No Phone')['phone']);
        $this->assertStringContainsString('no phone in PropertyWare', $recipients->firstWhere('name', 'No Phone')['detail']);
    }

    public function test_a_unit_in_a_multi_unit_building_is_preselected_only_on_an_exact_match(): void
    {
        $this->fakeReport([
            $this->pwRecord('1312 Ward Rd Unit 6', 'Unit Six', '(346) 555-0006'),
            $this->pwRecord('1312 Ward Rd Unit 5', 'Unit Five', '(346) 555-0005'),
        ]);
        $visit = $this->makeVisit(clientName: '1312 Ward Rd Unit 5', title: '1312 Ward Rd Unit 5 - TBP');

        $recipients = collect($this->actingAs($this->staff())->getJson(route('visits.tbp_notice', $visit))
            ->assertOk()
            ->json('recipients'));

        $this->assertSame(['Unit Five', 'Unit Six'], $recipients->pluck('name')->all(), 'exact match sorts first');
        $this->assertSame([true, false], $recipients->pluck('selected')->all());
    }

    public function test_a_report_outage_fails_open_to_the_jobber_phone(): void
    {
        Http::fake(['https://app.propertyware.com/*' => Http::response('down', 500)]);
        $visit = $this->makeVisit(clientPhone: '+13463684607');

        $this->actingAs($this->staff())->getJson(route('visits.tbp_notice', $visit))
            ->assertOk()
            ->assertJsonPath('scheduled_date', 'Wednesday, August 26, 2026')
            ->assertJsonCount(1, 'recipients')
            ->assertJsonPath('recipients.0.source', 'jobber')
            ->assertJsonPath('recipients.0.selected', true);
    }

    public function test_the_template_override_from_message_templates_is_used(): void
    {
        $this->fakeReport([]);
        AutomatedMessageTemplates::put('tenant_tbp_visit_notice', 'Custom notice for {SCHEDULED_DATE}.');
        $visit = $this->makeVisit();

        $this->actingAs($this->staff('admin'))->getJson(route('visits.tbp_notice', $visit))
            ->assertOk()
            ->assertJsonPath('message', 'Custom notice for Wednesday, August 26, 2026.')
            ->assertJsonPath('length', mb_strlen('Custom notice for Wednesday, August 26, 2026.'));
    }

    public function test_a_visit_without_a_date_is_rejected(): void
    {
        $this->fakeReport([]);
        $visit = $this->makeVisit(startAt: null);

        $this->actingAs($this->staff())->getJson(route('visits.tbp_notice', $visit))
            ->assertStatus(422);
    }

    public function test_a_non_tbp_visit_is_flagged_so_the_button_can_warn(): void
    {
        $this->fakeReport([]);
        $visit = $this->makeVisit(title: '17710 Winnower Ln - General Maintenance - #41890');
        $visit->job->update(['title' => 'General Maintenance']);

        $this->actingAs($this->staff())->getJson(route('visits.tbp_notice', $visit))
            ->assertOk()
            ->assertJsonPath('is_tbp', false);
    }

    public function test_reminder_flags_are_reported(): void
    {
        $this->fakeReport([]);
        $visit = $this->makeVisit();
        $visit->update(['notified_7_days' => true]);

        $this->actingAs($this->staff())->getJson(route('visits.tbp_notice', $visit))
            ->assertOk()
            ->assertJsonPath('reminders.7_day', true)
            ->assertJsonPath('reminders.3_day', false);
    }

    public function test_only_staff_can_open_it(): void
    {
        $this->fakeReport([]);
        $visit = $this->makeVisit();

        $this->getJson(route('visits.tbp_notice', $visit))->assertUnauthorized();
        $this->actingAs($this->staff('tenant'))->getJson(route('visits.tbp_notice', $visit))->assertForbidden();
        $this->actingAs($this->staff('admin'))->getJson(route('visits.tbp_notice', $visit))->assertOk();
    }

    /**
     * The loose building match, checked against the real Jobber/PropertyWare
     * pairs from the week the automation was found skipping visits.
     */
    public function test_loose_building_match_accepts_suffix_and_spelling_drift_but_not_other_houses(): void
    {
        $same = [
            ['17710 Winnower Ln', '17710 Winnower'],
            ['10414 Hondo Hill Rd', '10414 HONDO HILL'],
            ['11203 Doric Ct', '11203 Doric'],
            ['6510 Gardners Brk', '6510 Gardners Brook'],
            ['19410 Rum River Ct', '19410 Rum River Court'],
            ['10146 Larch Creek Ct', '10146 Larch Creek Ct,'],
            ['4734 Spellman Rd', '4734 Spellman Rd.'],
            ['18402 Willow Moss Dr', '18402 Willow Moss Drive'],
            ['5703 S Braeswood Blvd', '5703 S Braeswood'],
            ['16918 Wedgeside Park', '16918 Wedgeside Park'],
        ];

        foreach ($same as [$jobber, $propertyware]) {
            $this->assertTrue(
                PropertyWareTenantReport::looksLikeSameBuilding(
                    PropertyWareTenantReport::normalize($jobber),
                    PropertyWareTenantReport::normalize($propertyware),
                ),
                "{$jobber} should match {$propertyware}",
            );
        }

        $different = [
            ['17710 Winnower Ln', '17711 Winnower'],
            ['17710 Winnower Ln', '17710 Main St'],
            ['17307 Nordway Dr', ''],
            ['', '17307 Nordway'],
            ['No Number Here', 'No Number There'],
        ];

        foreach ($different as [$jobber, $propertyware]) {
            $this->assertFalse(
                PropertyWareTenantReport::looksLikeSameBuilding(
                    PropertyWareTenantReport::normalize($jobber),
                    PropertyWareTenantReport::normalize($propertyware),
                ),
                "{$jobber} should not match {$propertyware}",
            );
        }
    }
}
