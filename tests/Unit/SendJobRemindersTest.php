<?php

namespace Tests\Unit;

use App\Console\Commands\SendJobReminders;
use Carbon\Carbon;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

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

    public function test_it_does_not_double_the_country_code_on_reminder_numbers(): void
    {
        $command = new class extends SendJobReminders
        {
            public function format(string $number): string
            {
                return $this->formatNumber($number);
            }
        };

        $this->assertSame('+18322432975', $command->format('(832) 243-2975'));
        $this->assertSame('+18322432975', $command->format('+1 832-243-2975'));
        $this->assertSame('+18322432975', $command->format('18322432975'));
    }

    public function test_it_sends_default_reminders_for_three_seven_and_fourteen_days(): void
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

        $command->setLaravel($this->app);

        $exitCode = $command->run(new ArrayInput([]), new BufferedOutput);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([
            ['date' => '2026-04-03', 'field' => 'notified_3_days'],
            ['date' => '2026-04-07', 'field' => 'notified_7_days'],
            ['date' => '2026-04-14', 'field' => 'notified_14_days'],
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

        $command->setLaravel($this->app);

        $exitCode = $command->run(new ArrayInput([
            '--days' => ['7'],
        ]), new BufferedOutput);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([
            ['date' => '2026-04-07', 'field' => 'notified_7_days'],
        ], $command->calls);

        Carbon::setTestNow();
    }

    public function test_it_sends_the_one_day_reminder_when_requested(): void
    {
        Carbon::setTestNow('2026-03-31 08:00:00');

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

        $command->setLaravel($this->app);

        $exitCode = $command->run(new ArrayInput([
            '--days' => ['1'],
        ]), new BufferedOutput);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([
            ['date' => '2026-04-01', 'field' => 'notified_1_days'],
        ], $command->calls);

        Carbon::setTestNow();
    }

    public function test_it_sends_the_fourteen_day_reminder_when_requested(): void
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

        $command->setLaravel($this->app);

        $exitCode = $command->run(new ArrayInput([
            '--days' => ['14'],
        ]), new BufferedOutput);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([
            ['date' => '2026-04-14', 'field' => 'notified_14_days'],
        ], $command->calls);

        Carbon::setTestNow();
    }

    public function test_it_skips_tiers_whose_notified_column_is_missing(): void
    {
        Carbon::setTestNow('2026-03-31 10:00:00');

        $command = new class extends SendJobReminders
        {
            /** @var array<int, array{date: string, field: string}> */
            public array $calls = [];

            protected function notifiedColumnExists(string $notifiedField): bool
            {
                return $notifiedField !== 'notified_14_days';
            }

            protected function sendMessages(Carbon $scheduled_date, string $notifiedField, string $messageText): void
            {
                $this->calls[] = [
                    'date' => $scheduled_date->toDateString(),
                    'field' => $notifiedField,
                ];
            }
        };

        $command->setLaravel($this->app);

        $output = new BufferedOutput;
        $exitCode = $command->run(new ArrayInput([]), $output);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([
            ['date' => '2026-04-03', 'field' => 'notified_3_days'],
            ['date' => '2026-04-07', 'field' => 'notified_7_days'],
        ], $command->calls);
        $this->assertStringContainsString('Skipping 14-day reminders', $output->fetch());

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

        $command->setLaravel($this->app);

        $output = new BufferedOutput;
        $exitCode = $command->run(new ArrayInput([
            '--days' => ['5'],
        ]), $output);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertFalse($command->wasCalled);
        $this->assertStringContainsString('Unsupported reminder day override(s): 5. Supported values: 1, 3, 7, 14.', $output->fetch());

        Carbon::setTestNow();
    }

    public function test_it_surfaces_similar_propertyware_candidates_from_the_building_field(): void
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
            ],
            [
                2 => 'Active',
                3 => 'John Tenant',
                4 => '8800 Different Street',
            ],
        ], '10107 Mariposa Green Ct');

        $this->assertCount(1, $candidates);
        $this->assertSame('10107 Mariposa Green Court', $candidates[0]['propertyware_building']);
        $this->assertSame('Jane Tenant', $candidates[0]['tenant_name']);
    }
}
