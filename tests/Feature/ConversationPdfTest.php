<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Smalot\PdfParser\Parser;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConversationPdfTest extends TestCase
{
    use RefreshDatabase;

    private const OUR_NUMBER = '+15125550000';

    private const TENANT_NUMBER = '+15125551111';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting', 'vendor', 'owner', 'tenant'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function message(WorkOrder $workOrder, string $text, bool $outbound, array $attributes = []): Conversation
    {
        return Conversation::query()->create([
            'message' => $text,
            'conversation_type' => 'tenant',
            'sender_number' => $outbound ? self::OUR_NUMBER : self::TENANT_NUMBER,
            'receiver_number' => $outbound ? self::TENANT_NUMBER : self::OUR_NUMBER,
            'work_order_id' => $workOrder->id,
            'is_read' => $outbound,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<int, int>  $messageIds
     */
    private function download(User $user, WorkOrder $workOrder, array $messageIds, array $overrides = []): TestResponse
    {
        return $this->actingAs($user)->postJson(route('work_order.conversation.pdf', $workOrder), [
            'title' => 'Tenant ↔ Coordinator',
            'our_number' => self::OUR_NUMBER,
            'message_ids' => $messageIds,
            'participants' => [
                ['phone' => self::TENANT_NUMBER, 'name' => 'Dana Smith', 'role' => 'Tenant'],
                ['phone' => self::OUR_NUMBER, 'name' => 'Casey Coordinator', 'role' => 'Coordinator'],
            ],
            ...$overrides,
        ]);
    }

    private function pdfText(TestResponse $response): string
    {
        return (new Parser)->parseContent($response->getContent())->getText();
    }

    public function test_a_coordinator_downloads_the_thread_as_a_pdf(): void
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => '44100']);
        $first = $this->message($workOrder, 'The sink is leaking again.', outbound: false);
        $second = $this->message($workOrder, 'A plumber is booked for Tuesday.', outbound: true);

        $response = $this->download($this->userWithRole('woc'), $workOrder, [$second->id, $first->id]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'attachment; filename="WO-44100 Tenant conversation.pdf"');

        $text = $this->pdfText($response);
        $this->assertStringContainsString('WO#44100', $text);
        $this->assertStringContainsString('Dana Smith (Tenant)', $text);
        $this->assertStringContainsString('Casey Coordinator (Coordinator)', $text);
        $this->assertStringContainsString('2 messages', $text);
        $this->assertLessThan(
            strpos($text, 'A plumber is booked'),
            strpos($text, 'The sink is leaking'),
            'Messages print oldest first.',
        );
    }

    public function test_admin_and_accounting_may_download(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $message = $this->message($workOrder, 'Hello', outbound: true);

        $this->download($this->userWithRole('admin'), $workOrder, [$message->id])->assertOk();
        $this->download($this->userWithRole('accounting'), $workOrder, [$message->id])->assertOk();
    }

    public function test_messages_from_another_work_order_are_never_included(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $other = WorkOrder::factory()->create();
        $ours = $this->message($workOrder, 'Our own thread message.', outbound: true);
        $theirs = $this->message($other, 'Someone else entirely.', outbound: true);

        $text = $this->pdfText($this->download($this->userWithRole('woc'), $workOrder, [$ours->id, $theirs->id]));

        $this->assertStringContainsString('Our own thread message.', $text);
        $this->assertStringNotContainsString('Someone else entirely.', $text);
        $this->assertStringContainsString('1 message', $text);
    }

    public function test_an_undelivered_message_is_marked(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $message = $this->message($workOrder, 'Are you home today?', outbound: true, attributes: ['twilio_status' => 'undelivered']);

        $text = $this->pdfText($this->download($this->userWithRole('woc'), $workOrder, [$message->id]));

        $this->assertStringContainsString('Not delivered (undelivered)', $text);
    }

    public function test_an_empty_thread_still_downloads(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $response = $this->download($this->userWithRole('woc'), $workOrder, []);

        $response->assertOk();
        $this->assertStringContainsString('No messages in this conversation.', $this->pdfText($response));
    }

    public function test_tenants_owners_and_vendors_cannot_download(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $message = $this->message($workOrder, 'Hello', outbound: true);

        // A party not on the work order is stopped by the work order scope (404)
        // before the staff-only check (403); either way no PDF is served.
        foreach (['tenant', 'owner', 'vendor'] as $role) {
            $response = $this->download($this->userWithRole($role), $workOrder, [$message->id]);

            $this->assertContains($response->status(), [403, 404], "A {$role} must not download.");
            $this->assertNotSame('application/pdf', $response->headers->get('Content-Type'));
        }
    }

    public function test_guests_cannot_download(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->postJson(route('work_order.conversation.pdf', $workOrder), [
            'title' => 'Tenant ↔ Coordinator',
            'message_ids' => [],
        ])->assertUnauthorized();
    }

    public function test_a_title_is_required(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $this->download($this->userWithRole('woc'), $workOrder, [], ['title' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }
}
