<?php

namespace Tests\Unit;

use App\Console\Commands\SendJobReminders;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class SendJobRemindersTest extends TestCase
{
    public function test_it_matches_building_references_with_common_street_abbreviations(): void
    {
        $command = new class extends SendJobReminders
        {
            public function matches(string $jobberClientName, string $propertywareClientReference): bool
            {
                return $this->buildingReferenceMatches($jobberClientName, $propertywareClientReference);
            }
        };

        $this->assertTrue($command->matches('123 Main Drive', '123 Main Dr'));
        $this->assertTrue($command->matches('456 Sunset Lane', '456 Sunset Ln'));
        $this->assertTrue($command->matches('789 Elm Rd.', '789 Elm Road'));
    }

    public function test_it_does_not_match_different_building_references(): void
    {
        $command = new class extends SendJobReminders
        {
            public function matches(string $jobberClientName, string $propertywareClientReference): bool
            {
                return $this->buildingReferenceMatches($jobberClientName, $propertywareClientReference);
            }
        };

        $this->assertFalse($command->matches('123 Main Drive', '123 Main Street'));
        $this->assertFalse($command->matches('456 Sunset Lane', '789 Sunset Lane'));
    }

    public function test_it_sends_default_reminders_for_three_and_seven_days(): void
    {
        Carbon::setTestNow('2026-03-31 10:00:00');

        $command = new class extends SendJobReminders
        {
            /** @var array<int, array{date: string, field: string}> */
            public array $calls = [];

            protected function sendMessages(Carbon $scheduled_date, string $notifiedField, string $messageText): void
            {
                $this->calls[] = [
                    'date' => $scheduled_date->toDateString(),
                    'field' => $notifiedField,
                ];
            }
        };

        $exitCode = $command->run(new ArrayInput([]), new BufferedOutput);

        $this->assertSame(self::SUCCESS, $exitCode);
        $this->assertSame([
            ['date' => '2026-04-03', 'field' => 'notified_3_days'],
            ['date' => '2026-04-07', 'field' => 'notified_7_days'],
        ], $command->calls);

        Carbon::setTestNow();
    }

    public function test_it_allows_manually_overriding_the_reminder_days(): void
    {
        Carbon::setTestNow('2026-03-31 10:00:00');

        $command = new class extends SendJobReminders
        {
            /** @var array<int, array{date: string, field: string}> */
            public array $calls = [];

            protected function sendMessages(Carbon $scheduled_date, string $notifiedField, string $messageText): void
            {
                $this->calls[] = [
                    'date' => $scheduled_date->toDateString(),
                    'field' => $notifiedField,
                ];
            }
        };

        $exitCode = $command->run(new ArrayInput([
            '--days' => ['7'],
        ]), new BufferedOutput);

        $this->assertSame(self::SUCCESS, $exitCode);
        $this->assertSame([
            ['date' => '2026-04-07', 'field' => 'notified_7_days'],
        ], $command->calls);

        Carbon::setTestNow();
    }

    public function test_it_rejects_unsupported_manual_day_overrides(): void
    {
        Carbon::setTestNow('2026-03-31 10:00:00');

        $command = new class extends SendJobReminders
        {
            public bool $wasCalled = false;

            protected function sendMessages(Carbon $scheduled_date, string $notifiedField, string $messageText): void
            {
                $this->wasCalled = true;
            }
        };

        $output = new BufferedOutput;
        $exitCode = $command->run(new ArrayInput([
            '--days' => ['5'],
        ]), $output);

        $this->assertSame(self::FAILURE, $exitCode);
        $this->assertFalse($command->wasCalled);
        $this->assertStringContainsString('Unsupported reminder day override(s): 5. Supported values: 3, 7.', $output->fetch());

        Carbon::setTestNow();
    }

    public function test_it_surfaces_similar_propertyware_candidates_from_the_address_field(): void
    {
        $command = new class extends SendJobReminders
        {
            /**
             * @param  array<int, array<int, string>>  $records
             * @return array<int, array<string, string>>
             */
            public function similarCandidates(array $records, string $jobberClientName): array
            {
                return $this->propertywareSimilarCandidates($records, $jobberClientName);
            }
        };

        $candidates = $command->similarCandidates([
            [
                2 => 'Active',
                3 => 'Jane Tenant',
                4 => '10107 Mariposa Green Court',
                15 => '',
            ],
            [
                2 => 'Active',
                3 => 'John Tenant',
                4 => '8800 Different Street',
                15 => '',
            ],
        ], '10107 Mariposa Green Ct');

        $this->assertCount(1, $candidates);
        $this->assertSame('10107 Mariposa Green Court', $candidates[0]['propertyware_address']);
        $this->assertSame('Jane Tenant', $candidates[0]['tenant_name']);
    }
}
