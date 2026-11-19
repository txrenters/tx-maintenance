<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ClientAppPromoEmailTest extends TestCase
{
    public function test_branded_email_with_app_promo_links_both_stores_and_the_signup_video(): void
    {
        $html = Blade::render('<x-mail.branded app-promo heading="Work Order #1">Body</x-mail.branded>');

        $this->assertStringContainsString('Get the TexasRenters app', $html);
        $this->assertStringContainsString('https://apps.apple.com/us/app/texasrenters-app/id6818547029', $html);
        $this->assertStringContainsString('https://play.google.com/store/apps/details?id=com.texasrenters.portal', $html);
        $this->assertStringContainsString('https://www.youtube.com/watch?v=Xp0__SI4WgY', $html);
        $this->assertStringContainsString('Watch how to sign up', $html);
        $this->assertStringContainsString('https://youtu.be/8oDalAMczQs', $html);
        $this->assertStringContainsString('Watch the app video', $html);
    }

    public function test_branded_email_without_app_promo_leaves_the_app_out(): void
    {
        $html = Blade::render('<x-mail.branded heading="Invoice">Body</x-mail.branded>');

        $this->assertStringNotContainsString('Get the TexasRenters app', $html);
        $this->assertStringNotContainsString('apps.apple.com', $html);
    }

    public function test_signup_video_line_is_dropped_when_no_video_is_configured(): void
    {
        config(['client_app.signup_video_url' => null, 'client_app.app_video_url' => null]);

        $html = Blade::render('<x-mail.branded app-promo>Body</x-mail.branded>');

        $this->assertStringContainsString('Get the TexasRenters app', $html);
        $this->assertStringNotContainsString('Watch how to sign up', $html);
        $this->assertStringNotContainsString('Watch the app video', $html);
    }

    public function test_app_block_is_dropped_when_no_store_link_is_configured(): void
    {
        config(['client_app.app_store_url' => null, 'client_app.play_store_url' => null]);

        $html = Blade::render('<x-mail.branded app-promo>Body</x-mail.branded>');

        $this->assertStringNotContainsString('Get the TexasRenters app', $html);
    }

    public function test_owner_approval_email_carries_the_app_block(): void
    {
        $html = view('emails.owner-approval-nudge', [
            'workOrder' => new WorkOrder(['work_order_no' => '4321']),
            'body' => 'Please review the estimate.',
            'portalLink' => 'https://example.com/portal',
        ])->render();

        $this->assertStringContainsString('Please review the estimate.', $html);
        $this->assertStringContainsString('Get the TexasRenters app', $html);
    }

    public function test_internal_accounting_email_does_not_use_the_app_block(): void
    {
        $this->assertStringNotContainsString(
            'app-promo',
            file_get_contents(resource_path('views/emails/operation-accounting-invoice.blade.php')),
        );
    }
}
