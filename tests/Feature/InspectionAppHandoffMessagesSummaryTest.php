<?php

namespace Tests\Feature;

use App\Ai\Agents\WorkOrderMessagesSummaryAgent;
use App\Jobs\PushWorkOrderToInspectionApp;
use App\Models\AiInsight;
use App\Models\Conversation;
use App\Models\InspectionAppHandoff;
use App\Models\OwnerEmailNotification;
use App\Models\ServiceStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\InspectionApp\HandoffService;
use App\Services\InspectionApp\MessagesSummarizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The AI summary of a work order's messages that rides on the inspection-app
 * hand-off (the office, 2026-11-21: "summary for messages from maintenance
 * db"), shown on the inspection app's work order dialog.
 */
class InspectionAppHandoffMessagesSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.inspection_app.url' => 'https://inspection-api.example.test',
            'services.inspection_app.token' => 'trk_live_abc123def456.the-secret-half-of-the-key-43-chars-long-xx',
            'services.inspection_app.handoff' => true,
            'services.inspection_app.handoff_vendor_ids' => '246120584,5466750978,4329930755',
            'services.inspection_app.handoff_vendor_names' => 'Texas Home Maintenance Pros|Crystal Creek Air, LLC|Crystal Creek Pest Control LLC',
            'services.inspection_app.handoff_messages_summary' => true,
            'ai.default' => 'openai',
            'ai.providers.openai.key' => 'test-key',
        ]);

        ServiceStatus::query()->create(['name' => 'New', 'description' => 'New']);
    }

    private function summarizer(): MessagesSummarizer
    {
        return app(MessagesSummarizer::class);
    }

    private function workOrder(): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create(['is_emergency' => false, 'approved_by' => null, 'approved_date' => null]);
        $vendor = Vendor::query()->firstOrCreate(
            ['propertyware_id' => Vendor::THMP_PROPERTYWARE_ID],
            ['name' => Vendor::THMP_NAME, 'is_active' => true, 'user_id' => User::factory()->create()->id],
        );
        DB::table('work_order_vendors')->insert([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'cost_estimate' => 150,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $workOrder->fresh(['vendors']);
    }

    private function text(WorkOrder $workOrder, string $type, string $message, bool $fromOffice = false): void
    {
        Conversation::query()->create([
            'message' => $message,
            'sender_number' => '+15125550100',
            'receiver_number' => '+12813787957',
            'work_order_id' => $workOrder->id,
            'conversation_type' => $type,
            'is_read' => $fromOffice,
        ]);
    }

    private function fakeSummary(string $summary = 'Tenant reports the sink drips; available after 3 pm. Owner said go ahead.'): void
    {
        WorkOrderMessagesSummaryAgent::fake([['summary' => $summary]]);
    }

    public function test_it_summarizes_the_tenant_owner_and_vendor_messages_and_stores_the_summary(): void
    {
        $this->fakeSummary();
        $workOrder = $this->workOrder();
        $this->text($workOrder, 'tenant', 'The kitchen sink drips under the cabinet. Home after 3pm.');
        $this->text($workOrder, 'owner', 'Go ahead with the sink.');
        $this->text($workOrder, 'vendor', 'We can come Tuesday.', fromOffice: true);
        OwnerEmailNotification::factory()->create([
            'work_order_id' => $workOrder->id,
            'direction' => 'inbound',
            'subject' => 'Re: sink',
            'body_text' => 'Please keep it under $300.',
        ]);

        $insight = $this->summarizer()->refresh($workOrder);

        $this->assertSame(AiInsight::TYPE_MESSAGES_SUMMARY, $insight->type);
        $this->assertSame('Tenant reports the sink drips; available after 3 pm. Owner said go ahead.', data_get($insight->data, 'summary'));
        $this->assertSame(4, data_get($insight->data, 'messages_read'));
        WorkOrderMessagesSummaryAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'tenant (')
            && str_contains($prompt->prompt, 'owner (')
            && str_contains($prompt->prompt, 'office to vendor (')
            && str_contains($prompt->prompt, 'Please keep it under $300.'));
    }

    public function test_it_is_written_again_only_when_a_new_message_arrives(): void
    {
        $this->fakeSummary();
        $workOrder = $this->workOrder();
        $this->text($workOrder, 'tenant', 'The sink drips.');
        $first = $this->summarizer()->refresh($workOrder);

        WorkOrderMessagesSummaryAgent::fake([['summary' => 'should not be asked']]);
        // Nothing new: the stored summary stands, and the AI is not asked.
        $again = $this->summarizer()->refresh($workOrder);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(data_get($first->data, 'summary'), data_get($again->data, 'summary'));

        $this->fakeSummary('Tenant now says the leak is worse.');
        $this->text($workOrder, 'tenant', 'It is worse now.');
        $this->assertTrue($this->summarizer()->isStale($workOrder));
        $this->assertSame('Tenant now says the leak is worse.', data_get($this->summarizer()->refresh($workOrder)->data, 'summary'));
    }

    public function test_a_work_order_with_no_messages_costs_no_ai_call_and_sends_no_summary(): void
    {
        WorkOrderMessagesSummaryAgent::fake();
        $workOrder = $this->workOrder();

        $this->assertNull($this->summarizer()->refresh($workOrder));
        $this->assertNull($this->summarizer()->forPayload($workOrder));
        WorkOrderMessagesSummaryAgent::assertNeverPrompted();
    }

    public function test_switched_off_it_never_asks_the_ai(): void
    {
        config(['services.inspection_app.handoff_messages_summary' => false]);
        WorkOrderMessagesSummaryAgent::fake();
        $workOrder = $this->workOrder();
        $this->text($workOrder, 'tenant', 'The sink drips.');

        $this->assertNull($this->summarizer()->refresh($workOrder));
        WorkOrderMessagesSummaryAgent::assertNeverPrompted();
    }

    public function test_the_send_carries_the_summary(): void
    {
        Http::fake(['*' => Http::response(['id' => 'insp_1'], 201)]);
        $this->fakeSummary();
        $workOrder = $this->workOrder();
        $this->text($workOrder, 'tenant', 'The sink drips.');
        $row = InspectionAppHandoff::query()->create(['work_order_id' => $workOrder->id]);

        app(HandoffService::class)->push($row->id);

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $this->assertSame('Tenant reports the sink drips; available after 3 pm. Owner said go ahead.', $body['messages_summary']['summary']);
            $this->assertSame(1, $body['messages_summary']['messages_read']);
            $this->assertNotNull($body['messages_summary']['read_at']);

            return true;
        });
    }

    public function test_the_sweep_sends_again_a_work_order_with_a_message_newer_than_its_last_send(): void
    {
        Queue::fake();
        $quiet = $this->workOrder();
        $talked = $this->workOrder();
        $this->text($quiet, 'tenant', 'Old message.');
        $this->text($talked, 'tenant', 'Old message.');
        InspectionAppHandoff::query()->create(['work_order_id' => $quiet->id, 'status' => InspectionAppHandoff::STATUS_SENT, 'sent_at' => now()->addMinute(), 'last_attempt_at' => now()->addMinute()]);
        $row = InspectionAppHandoff::query()->create(['work_order_id' => $talked->id, 'status' => InspectionAppHandoff::STATUS_SENT, 'sent_at' => now()->subHour(), 'last_attempt_at' => now()->addMinute()]);
        $talked->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();
        $quiet->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        app(HandoffService::class)->sweep();

        Queue::assertPushed(PushWorkOrderToInspectionApp::class, 1);
        $this->assertSame(InspectionAppHandoff::STATUS_PENDING, $row->fresh()->status);
        $this->assertSame(InspectionAppHandoff::STATUS_SENT, InspectionAppHandoff::query()->where('work_order_id', $quiet->id)->value('status'));
    }
}
