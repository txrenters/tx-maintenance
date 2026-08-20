<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AutomatedMessageTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AutomatedMessageTemplatesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function staff(string $role = 'admin'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    public function test_guests_are_redirected(): void
    {
        $this->get(route('it-tools.automated-messages.templates'))->assertRedirect();
    }

    public function test_admin_and_woc_can_read_the_templates(): void
    {
        foreach (['admin', 'woc'] as $role) {
            $this->actingAs($this->staff($role))
                ->getJson(route('it-tools.automated-messages.templates'))
                ->assertOk()
                ->assertJsonStructure(['templates' => [[
                    'key', 'automation', 'automation_label', 'label', 'group', 'channel',
                    'audience', 'sends_when', 'tokens', 'required', 'sample', 'default',
                    'override', 'is_overridden',
                ]]])
                ->assertJsonCount(count(AutomatedMessageTemplates::TEMPLATES), 'templates');
        }
    }

    public function test_other_roles_cannot_read_or_write(): void
    {
        $accounting = $this->staff('accounting');

        $this->actingAs($accounting)
            ->getJson(route('it-tools.automated-messages.templates'))
            ->assertForbidden();

        $this->actingAs($accounting)
            ->putJson(route('it-tools.automated-messages.templates.update', 'tenant_service_request_sms'), [
                'text' => 'Nope.',
            ])
            ->assertForbidden();

        $this->assertFalse(AutomatedMessageTemplates::isOverridden('tenant_service_request_sms'));
    }

    /*
    |--------------------------------------------------------------------------
    | Saving
    |--------------------------------------------------------------------------
    */

    public function test_a_woc_can_save_a_rewording_and_it_sticks(): void
    {
        $this->actingAs($this->staff('woc'))
            ->putJson(route('it-tools.automated-messages.templates.update', 'tenant_service_request_sms'), [
                'text' => "{greeting}\n\nWe got your request{property} and are on it.",
            ])
            ->assertOk()
            ->assertJsonPath('template.is_overridden', true)
            ->assertJsonPath('template.override', "{greeting}\n\nWe got your request{property} and are on it.");

        $this->assertSame(
            "Hi Jane,\n\nWe got your request for 123 Main St and are on it.",
            AutomatedMessageTemplates::text('tenant_service_request_sms', [
                'greeting' => 'Hi Jane,',
                'property' => ' for 123 Main St',
            ]),
        );
    }

    public function test_an_unknown_placeholder_blocks_the_save(): void
    {
        $this->actingAs($this->staff())
            ->putJson(route('it-tools.automated-messages.templates.update', 'tenant_appointment_sms'), [
                'text' => 'Scheduled with {vendor_nam} soon.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('text');

        $this->assertFalse(AutomatedMessageTemplates::isOverridden('tenant_appointment_sms'));
    }

    public function test_dropping_a_required_placeholder_blocks_the_save(): void
    {
        $this->actingAs($this->staff())
            ->putJson(route('it-tools.automated-messages.templates.update', 'tenant_portal_link_sms'), [
                'text' => 'Hi, please upload photos of the issue. Thanks!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('text');

        $this->assertFalse(AutomatedMessageTemplates::isOverridden('tenant_portal_link_sms'));
    }

    public function test_non_gsm_characters_block_the_save(): void
    {
        // An em dash or curly quote would make Twilio drop the text (30019).
        $this->actingAs($this->staff('woc'))
            ->putJson(route('it-tools.automated-messages.templates.update', 'vendor_schedule_follow_up_first'), [
                'text' => 'Hello — please set the schedule.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('text');

        $this->assertFalse(AutomatedMessageTemplates::isOverridden('vendor_schedule_follow_up_first'));
    }

    public function test_an_unknown_template_key_is_a_404(): void
    {
        $this->actingAs($this->staff())
            ->putJson(route('it-tools.automated-messages.templates.update', 'no_such_template'), [
                'text' => 'Hello.',
            ])
            ->assertNotFound();
    }

    public function test_an_over_length_text_is_rejected(): void
    {
        $this->actingAs($this->staff())
            ->putJson(route('it-tools.automated-messages.templates.update', 'vendor_schedule_follow_up_first'), [
                'text' => str_repeat('a', 5001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('text');
    }

    /*
    |--------------------------------------------------------------------------
    | Resetting
    |--------------------------------------------------------------------------
    */

    public function test_reset_returns_a_template_to_its_default(): void
    {
        AutomatedMessageTemplates::put('vendor_schedule_follow_up_first', 'CUSTOM');

        $this->actingAs($this->staff('woc'))
            ->deleteJson(route('it-tools.automated-messages.templates.reset', 'vendor_schedule_follow_up_first'))
            ->assertOk()
            ->assertJsonPath('template.is_overridden', false)
            ->assertJsonPath('template.override', null);

        $this->assertSame(
            AutomatedMessageTemplates::default('vendor_schedule_follow_up_first'),
            AutomatedMessageTemplates::text('vendor_schedule_follow_up_first'),
        );
    }

    public function test_resetting_an_unknown_key_is_a_404(): void
    {
        $this->actingAs($this->staff())
            ->deleteJson(route('it-tools.automated-messages.templates.reset', 'no_such_template'))
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    */

    public function test_saves_are_audited_outside_the_sends_ledger(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)
            ->putJson(route('it-tools.automated-messages.templates.update', 'tenant_service_request_sms'), [
                'text' => 'New wording.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'message_template',
            'description' => 'updated',
            'causer_id' => $admin->id,
        ]);
        // Never under the automated_message log name — the ledger page and the
        // vendor-nag rotation counter both filter on it.
        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'automated_message',
            'description' => 'updated',
        ]);
    }
}
