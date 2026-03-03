<?php

namespace Database\Seeders;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberVisit;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ScheduledVisitSeeder extends Seeder
{
    /**
     * Seed dummy scheduled visits for the current Chicago week.
     */
    public function run(): void
    {
        $weekStart = Carbon::now('America/Chicago')->startOfWeek(Carbon::SUNDAY);

        $jobs = [
            [
                'client' => [
                    'jobber_id' => 'dummy-client-001',
                    'first_name' => 'Maria',
                    'last_name' => 'Santos',
                    'company_name' => null,
                    'name' => 'Maria Santos',
                    'secondary_name' => null,
                    'title' => null,
                    'email' => 'maria.santos@example.com',
                    'phone' => '(713) 555-0101',
                    'balance' => 0,
                    'jobber_web_uri' => 'https://example.com/jobber/clients/dummy-client-001',
                ],
                'property' => [
                    'jobber_id' => 'dummy-property-001',
                    'street' => '1801 Waugh Dr',
                    'city' => 'Houston',
                    'province' => 'TX',
                    'postal_code' => '77006',
                    'country' => 'USA',
                    'is_billing_address' => false,
                    'jobber_web_uri' => 'https://example.com/jobber/properties/dummy-property-001',
                ],
                'job' => [
                    'jobber_id' => 'dummy-job-001',
                    'job_number' => 'TBP-1001',
                    'title' => 'Tenant Benefit Inspection - Waugh Dr',
                    'job_status' => 'scheduled',
                    'job_type' => 'inspection',
                    'total' => 125,
                    'will_client_be_automatically_charged' => false,
                    'instructions' => 'Call tenant on arrival and inspect smoke detectors, locks, and HVAC filter.',
                    'jobber_web_uri' => 'https://example.com/jobber/jobs/dummy-job-001',
                ],
                'visits' => [
                    [
                        'jobber_id' => 'dummy-visit-001',
                        'title' => 'Tenant Benefit Initial Inspection',
                        'visit_status' => 'scheduled',
                        'duration' => 90,
                        'instructions' => 'Meet tenant at front door and document all visible maintenance items.',
                        'start' => $weekStart->copy()->addDay()->setTime(9, 0),
                        'end' => $weekStart->copy()->addDay()->setTime(10, 30),
                        'is_complete' => false,
                        'is_last_scheduled_visit' => false,
                        'notified_7_days' => true,
                        'notified_3_days' => false,
                    ],
                    [
                        'jobber_id' => 'dummy-visit-002',
                        'title' => 'Tenant Benefit Follow-up',
                        'visit_status' => 'scheduled',
                        'duration' => 60,
                        'instructions' => 'Confirm access with tenant and capture final inspection photos.',
                        'start' => $weekStart->copy()->addDays(3)->setTime(14, 0),
                        'end' => $weekStart->copy()->addDays(3)->setTime(15, 0),
                        'is_complete' => false,
                        'is_last_scheduled_visit' => true,
                        'notified_7_days' => false,
                        'notified_3_days' => true,
                    ],
                ],
            ],
            [
                'client' => [
                    'jobber_id' => 'dummy-client-002',
                    'first_name' => 'Derrick',
                    'last_name' => 'Coleman',
                    'company_name' => 'North Loop Rentals',
                    'name' => 'Derrick Coleman',
                    'secondary_name' => 'North Loop Rentals',
                    'title' => 'Property Manager',
                    'email' => 'derrick.coleman@example.com',
                    'phone' => '(281) 555-0142',
                    'balance' => 48.75,
                    'jobber_web_uri' => 'https://example.com/jobber/clients/dummy-client-002',
                ],
                'property' => [
                    'jobber_id' => 'dummy-property-002',
                    'street' => '5022 Creekbend Dr',
                    'city' => 'Houston',
                    'province' => 'TX',
                    'postal_code' => '77035',
                    'country' => 'USA',
                    'is_billing_address' => false,
                    'jobber_web_uri' => 'https://example.com/jobber/properties/dummy-property-002',
                ],
                'job' => [
                    'jobber_id' => 'dummy-job-002',
                    'job_number' => 'TBP-1002',
                    'title' => 'Tenant Benefit Inspection - Creekbend',
                    'job_status' => 'scheduled',
                    'job_type' => 'inspection',
                    'total' => 150,
                    'will_client_be_automatically_charged' => true,
                    'instructions' => 'Gate code 4421. Tenant prefers text confirmation 30 minutes before arrival.',
                    'jobber_web_uri' => 'https://example.com/jobber/jobs/dummy-job-002',
                ],
                'visits' => [
                    [
                        'jobber_id' => 'dummy-visit-003',
                        'title' => 'TBP Annual Inspection',
                        'visit_status' => 'completed',
                        'duration' => 75,
                        'instructions' => 'Completed walkthrough and submitted maintenance notes.',
                        'start' => $weekStart->copy()->addDay()->setTime(13, 30),
                        'end' => $weekStart->copy()->addDay()->setTime(14, 45),
                        'is_complete' => true,
                        'is_last_scheduled_visit' => true,
                        'notified_7_days' => true,
                        'notified_3_days' => true,
                        'completed_at' => $weekStart->copy()->addDay()->setTime(15, 0),
                    ],
                ],
            ],
            [
                'client' => [
                    'jobber_id' => 'dummy-client-003',
                    'first_name' => 'Alyssa',
                    'last_name' => 'Nguyen',
                    'company_name' => null,
                    'name' => 'Alyssa Nguyen',
                    'secondary_name' => null,
                    'title' => null,
                    'email' => 'alyssa.nguyen@example.com',
                    'phone' => '(832) 555-0198',
                    'balance' => 0,
                    'jobber_web_uri' => 'https://example.com/jobber/clients/dummy-client-003',
                ],
                'property' => [
                    'jobber_id' => 'dummy-property-003',
                    'street' => '1107 Heights Blvd',
                    'city' => 'Houston',
                    'province' => 'TX',
                    'postal_code' => '77008',
                    'country' => 'USA',
                    'is_billing_address' => false,
                    'jobber_web_uri' => 'https://example.com/jobber/properties/dummy-property-003',
                ],
                'job' => [
                    'jobber_id' => 'dummy-job-003',
                    'job_number' => 'TBP-1003',
                    'title' => 'Tenant Benefit Inspection - Heights',
                    'job_status' => 'scheduled',
                    'job_type' => 'inspection',
                    'total' => 135,
                    'will_client_be_automatically_charged' => false,
                    'instructions' => 'Tenant has a dog. Knock first before unlocking the door.',
                    'jobber_web_uri' => 'https://example.com/jobber/jobs/dummy-job-003',
                ],
                'visits' => [
                    [
                        'jobber_id' => 'dummy-visit-004',
                        'title' => 'Tenant Benefit Midweek Visit',
                        'visit_status' => 'scheduled',
                        'duration' => 45,
                        'instructions' => 'Quick interior inspection and filter replacement confirmation.',
                        'start' => $weekStart->copy()->addDays(4)->setTime(11, 0),
                        'end' => $weekStart->copy()->addDays(4)->setTime(11, 45),
                        'is_complete' => false,
                        'is_last_scheduled_visit' => true,
                        'notified_7_days' => false,
                        'notified_3_days' => false,
                    ],
                    [
                        'jobber_id' => 'dummy-visit-005',
                        'title' => 'TBP Saturday Access Window',
                        'visit_status' => 'scheduled',
                        'duration' => 120,
                        'instructions' => 'Weekend access requested by tenant. Bring replacement batteries.',
                        'start' => $weekStart->copy()->addDays(6)->setTime(10, 0),
                        'end' => $weekStart->copy()->addDays(6)->setTime(12, 0),
                        'is_complete' => false,
                        'is_last_scheduled_visit' => false,
                        'notified_7_days' => false,
                        'notified_3_days' => false,
                    ],
                ],
            ],
        ];

        foreach ($jobs as $payload) {
            $client = JobberClient::updateOrCreate(
                ['jobber_id' => $payload['client']['jobber_id']],
                $payload['client']
            );

            $property = JobberProperty::updateOrCreate(
                ['jobber_id' => $payload['property']['jobber_id']],
                [
                    ...$payload['property'],
                    'jobber_client_id' => $client->id,
                ]
            );

            $firstVisit = $payload['visits'][0];
            $lastVisit = $payload['visits'][count($payload['visits']) - 1];

            $job = Jobber::updateOrCreate(
                ['jobber_id' => $payload['job']['jobber_id']],
                [
                    ...$payload['job'],
                    'jobber_client_id' => $client->id,
                    'jobber_property_id' => $property->id,
                    'start_at' => $this->formatChicagoTimestamp($firstVisit['start']),
                    'end_at' => $this->formatChicagoTimestamp($lastVisit['end']),
                    'created_at_jobber' => $this->formatChicagoTimestamp($firstVisit['start']->copy()->subDays(7)),
                    'updated_at_jobber' => $this->formatChicagoTimestamp(now('America/Chicago')),
                ]
            );

            foreach ($payload['visits'] as $visitPayload) {
                JobberVisit::updateOrCreate(
                    ['jobber_id' => $visitPayload['jobber_id']],
                    [
                        'jobber_job_id' => $job->id,
                        'jobber_client_id' => $client->id,
                        'jobber_property_id' => $property->id,
                        'title' => $visitPayload['title'],
                        'all_day' => false,
                        'is_complete' => $visitPayload['is_complete'],
                        'is_last_scheduled_visit' => $visitPayload['is_last_scheduled_visit'],
                        'visit_status' => $visitPayload['visit_status'],
                        'duration' => $visitPayload['duration'],
                        'instructions' => $visitPayload['instructions'],
                        'start_at' => $this->formatChicagoTimestamp($visitPayload['start']),
                        'end_at' => $this->formatChicagoTimestamp($visitPayload['end']),
                        'completed_at' => isset($visitPayload['completed_at'])
                            ? $this->formatChicagoTimestamp($visitPayload['completed_at'])
                            : null,
                        'notified_7_days' => $visitPayload['notified_7_days'],
                        'notified_3_days' => $visitPayload['notified_3_days'],
                    ]
                );
            }
        }
    }

    private function formatChicagoTimestamp(Carbon $timestamp): string
    {
        return $timestamp->copy()->timezone('America/Chicago')->format('Y-m-d H:i:s');
    }
}
