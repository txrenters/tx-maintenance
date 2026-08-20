<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Services\AutomatedMessageLogService;
use App\Services\AutomatedMessageTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AutomatedMessageTemplatesTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Registry integrity
    |--------------------------------------------------------------------------
    */

    public function test_every_entry_points_at_a_real_automation_key(): void
    {
        foreach (AutomatedMessageTemplates::TEMPLATES as $key => $entry) {
            $this->assertArrayHasKey(
                $entry['automation'],
                AutomatedMessageLogService::AUTOMATIONS,
                "Template [{$key}] names automation [{$entry['automation']}], which is not in the ledger registry.",
            );
        }
    }

    public function test_every_placeholder_in_every_default_is_declared(): void
    {
        foreach (AutomatedMessageTemplates::TEMPLATES as $key => $entry) {
            preg_match_all('/\{([A-Za-z_]+)\}/', $entry['default'], $matches);

            $this->assertSame(
                [],
                array_values(array_diff(array_unique($matches[1]), array_keys($entry['tokens']))),
                "Template [{$key}] uses undeclared placeholder(s) in its default.",
            );
        }
    }

    public function test_every_required_token_appears_in_its_default(): void
    {
        foreach (AutomatedMessageTemplates::TEMPLATES as $key => $entry) {
            foreach ($entry['required'] as $token) {
                $this->assertArrayHasKey(
                    $token,
                    $entry['tokens'],
                    "Template [{$key}] requires undeclared token [{$token}].",
                );
                $this->assertStringContainsString(
                    '{'.$token.'}',
                    $entry['default'],
                    "Template [{$key}]'s default is missing its required token [{$token}].",
                );
            }
        }
    }

    public function test_every_sample_covers_every_declared_token(): void
    {
        foreach (AutomatedMessageTemplates::TEMPLATES as $key => $entry) {
            $this->assertSame(
                [],
                array_values(array_diff(array_keys($entry['tokens']), array_keys($entry['sample']))),
                "Template [{$key}]'s sample is missing values for some declared tokens.",
            );
        }
    }

    public function test_the_vendor_nag_rotation_has_its_five_variants_in_order(): void
    {
        $this->assertSame([
            'vendor_schedule_follow_up_variant_1',
            'vendor_schedule_follow_up_variant_2',
            'vendor_schedule_follow_up_variant_3',
            'vendor_schedule_follow_up_variant_4',
            'vendor_schedule_follow_up_variant_5',
        ], AutomatedMessageTemplates::variants('vendor_schedule_follow_up_variant_'));
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution
    |--------------------------------------------------------------------------
    */

    public function test_text_returns_the_default_when_nothing_is_stored(): void
    {
        $this->assertSame(
            AutomatedMessageTemplates::default('vendor_schedule_follow_up_first'),
            AutomatedMessageTemplates::text('vendor_schedule_follow_up_first'),
        );
    }

    public function test_text_returns_the_stored_override(): void
    {
        AutomatedMessageTemplates::put('vendor_schedule_follow_up_first', 'CUSTOM NAG');

        $this->assertSame('CUSTOM NAG', AutomatedMessageTemplates::text('vendor_schedule_follow_up_first'));
        $this->assertTrue(AutomatedMessageTemplates::isOverridden('vendor_schedule_follow_up_first'));
    }

    public function test_text_substitutes_repeated_tokens_everywhere(): void
    {
        $text = AutomatedMessageTemplates::text('tenant_portal_link_sms', [
            'greeting' => 'Hi Jane, ',
            'work_order_no' => '43361',
            'link' => 'https://example.test/t/abc',
        ]);

        $this->assertStringContainsString('(WO#43361)', $text);
        $this->assertStringContainsString('(Ref: WO#43361)', $text);
        $this->assertStringContainsString('https://example.test/t/abc', $text);
        $this->assertStringNotContainsString('{', $text);
    }

    public function test_collapse_squashes_the_hole_an_empty_line_token_leaves(): void
    {
        $text = AutomatedMessageTemplates::text('tenant_appointment_sms', [
            'greeting' => 'Hi,',
            'property' => '',
            'vendor_name' => 'ACME Plumbing',
            'scheduled_line' => '',
        ]);

        $this->assertStringContainsString(
            "scheduled with ACME Plumbing.\n\nPlease make sure",
            $text,
        );
        $this->assertStringNotContainsString("\n\n\n", $text);
    }

    public function test_raw_returns_the_template_unsubstituted(): void
    {
        $this->assertStringContainsString(
            '{CLIENT_NAME}',
            AutomatedMessageTemplates::raw('tenant_job_reminder_1_day'),
        );
    }

    public function test_an_unknown_key_throws_a_logic_exception(): void
    {
        $this->expectException(\LogicException::class);

        AutomatedMessageTemplates::text('no_such_template');
    }

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    public function test_put_and_reset_round_trip_and_reset_removes_only_its_key(): void
    {
        AutomatedMessageTemplates::put('vendor_schedule_follow_up_first', 'ONE');
        AutomatedMessageTemplates::put('tenant_service_request_sms', 'TWO');

        AutomatedMessageTemplates::reset('vendor_schedule_follow_up_first');

        $this->assertFalse(AutomatedMessageTemplates::isOverridden('vendor_schedule_follow_up_first'));
        $this->assertSame('TWO', AutomatedMessageTemplates::text('tenant_service_request_sms'));
    }

    public function test_a_missing_settings_table_fails_open_to_the_default(): void
    {
        // Simulates the code landing before app_settings exists on a server:
        // senders must keep sending the default wording, never throw.
        Schema::drop('app_settings');

        $this->assertSame(
            AutomatedMessageTemplates::default('vendor_schedule_follow_up_first'),
            AutomatedMessageTemplates::text('vendor_schedule_follow_up_first'),
        );
        $this->assertFalse(AutomatedMessageTemplates::isOverridden('vendor_schedule_follow_up_first'));
    }

    public function test_a_corrupt_stored_value_falls_back_to_the_default(): void
    {
        // A scalar where the map should be.
        AppSetting::putValue(AutomatedMessageTemplates::KEY, 'not-a-map');
        $this->assertSame(
            AutomatedMessageTemplates::default('tenant_service_request_sms'),
            AutomatedMessageTemplates::text('tenant_service_request_sms'),
        );

        // A non-string / blank entry inside the map.
        AppSetting::putValue(AutomatedMessageTemplates::KEY, [
            'tenant_service_request_sms' => ['nested' => 'array'],
            'tenant_appointment_sms' => '   ',
        ]);
        $this->assertSame(
            AutomatedMessageTemplates::default('tenant_service_request_sms'),
            AutomatedMessageTemplates::text('tenant_service_request_sms'),
        );
        $this->assertFalse(AutomatedMessageTemplates::isOverridden('tenant_appointment_sms'));
    }

    /*
    |--------------------------------------------------------------------------
    | Save-time validation helpers
    |--------------------------------------------------------------------------
    */

    public function test_unknown_tokens_flags_typoed_placeholders(): void
    {
        $this->assertSame(
            ['vendor_nam'],
            AutomatedMessageTemplates::unknownTokens('tenant_appointment_sms', 'Scheduled with {vendor_nam} — {greeting}'),
        );
        $this->assertSame(
            [],
            AutomatedMessageTemplates::unknownTokens('tenant_appointment_sms', 'Scheduled with {vendor_name}'),
        );
    }

    public function test_missing_required_tokens_flags_a_dropped_link(): void
    {
        $this->assertSame(
            ['link'],
            AutomatedMessageTemplates::missingRequiredTokens('tenant_portal_link_sms', 'Hi, please upload photos.'),
        );
        $this->assertSame(
            [],
            AutomatedMessageTemplates::missingRequiredTokens('tenant_portal_link_sms', 'Photos here: {link}'),
        );
    }

    public function test_for_ui_reports_override_state_and_ledger_labels(): void
    {
        AutomatedMessageTemplates::put('tenant_service_request_sms', 'CUSTOM');

        $templates = collect(AutomatedMessageTemplates::forUi()['templates']);

        $this->assertCount(count(AutomatedMessageTemplates::TEMPLATES), $templates);

        $edited = $templates->firstWhere('key', 'tenant_service_request_sms');
        $this->assertTrue($edited['is_overridden']);
        $this->assertSame('CUSTOM', $edited['override']);
        $this->assertSame(
            AutomatedMessageLogService::AUTOMATIONS['tenant_service_request_sms'],
            $edited['automation_label'],
        );

        $untouched = $templates->firstWhere('key', 'owner_appointment_sms');
        $this->assertFalse($untouched['is_overridden']);
        $this->assertNull($untouched['override']);
    }
}
