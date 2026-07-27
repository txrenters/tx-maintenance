<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\OwnerEmailNotification;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\MicrosoftGraphMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class SyncOwnerEmailRepliesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_matches_tag_sanitizes_and_marks_read_in_workorders_mailbox(): void
    {
        Cache::clear();
        config(['services.microsoft.mailbox' => 'workorders@texasrenters.com']);
        $workOrder = WorkOrder::factory()->create(['work_order_no' => 4321]);
        $owner = Owner::factory()->create();
        $vendor = Vendor::query()->create(['propertyware_id' => uniqid('V-'), 'name' => 'Acme', 'is_active' => true, 'user_id' => User::factory()->create()->id]);
        OwnerEmailNotification::factory()->create(['work_order_id' => $workOrder->id, 'owner_id' => $owner->id, 'vendor_id' => $vendor->id, 'correlation_tag' => 'TXO-4321-'.$owner->id]);
        $graph = Mockery::mock(MicrosoftGraphMailService::class);
        $graph->shouldReceive('fetchInbox')->once()->andReturn([[
            'id' => 'OWNER-REPLY', 'subject' => 'Re: [TXO-4321-'.$owner->id.']',
            'from' => ['emailAddress' => ['address' => 'owner@example.com']], 'receivedDateTime' => now()->toIso8601ZuluString(),
            'hasAttachments' => false, 'conversationId' => 'C2', 'internetMessageId' => '<reply>',
            'body' => ['contentType' => 'html', 'content' => '<p>Approved<script>bad()</script></p>'], 'internetMessageHeaders' => [],
        ]]);
        $graph->shouldReceive('markRead')->once()->with('OWNER-REPLY', 'workorders@texasrenters.com');
        $this->app->instance(MicrosoftGraphMailService::class, $graph);

        $this->artisan('owner-emails:sync-replies')->assertSuccessful();

        $reply = OwnerEmailNotification::query()->where('direction', 'inbound')->sole();
        $this->assertSame($owner->id, $reply->owner_id);
        $this->assertStringNotContainsString('script', $reply->body_html);
    }
}
