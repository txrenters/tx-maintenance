<?php

namespace Tests\Feature;

use App\Models\InvoiceEmailReply;
use App\Models\User;
use App\Models\Vendor;
use App\Services\InvoiceMailboxAutoReplyService;
use App\Services\MicrosoftGraphMailService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class InvoiceMailboxAutoReplyTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $graph;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.microsoft.invoices_mailbox' => 'invoices@texasrenters.com',
            'services.invoices_mailbox.auto_reply_enabled' => true,
        ]);

        $this->graph = Mockery::mock(MicrosoftGraphMailService::class);
        $this->app->instance(MicrosoftGraphMailService::class, $this->graph);
    }

    public function test_known_vendor_gets_a_reply_with_their_dashboard_link(): void
    {
        $vendor = $this->vendor('abc@example.com');
        $this->fakeInbox([$this->graphMessage(['id' => 'M-1', 'from' => $this->sender('ABC@Example.com')])]);

        $this->graph->shouldReceive('reply')
            ->once()
            ->withArgs(function (string $messageId, string $html, ?string $mailbox) use ($vendor): bool {
                $vendor->refresh();

                return $messageId === 'M-1'
                    && $mailbox === 'invoices@texasrenters.com'
                    && str_contains($html, route('vendor.portal.dashboard', $vendor->portal_token))
                    && str_contains($html, 'Hello ABC Plumbing,')
                    && str_contains($html, 'does not process invoices');
            });

        $this->artisan('invoices:auto-reply')
            ->expectsOutputToContain('Replied to 1 of 1 message(s).')
            ->assertSuccessful();

        $row = InvoiceEmailReply::query()->sole();
        $this->assertSame(InvoiceMailboxAutoReplyService::OUTCOME_REPLIED_VENDOR, $row->outcome);
        $this->assertSame($vendor->id, $row->vendor_id);
        $this->assertSame('abc@example.com', $row->from_email);
        $this->assertNotNull($row->replied_at);
    }

    public function test_unknown_sender_with_an_attached_invoice_gets_the_generic_reply(): void
    {
        $this->fakeInbox([$this->graphMessage([
            'from' => $this->sender('stranger@plumbing.example'),
            'subject' => 'Invoice 4471 for 12 Main St',
        ])]);

        $this->graph->shouldReceive('reply')
            ->once()
            ->withArgs(fn (string $id, string $html) => str_contains($html, 'Hello,')
                && str_contains($html, 'service request email or text')
                && ! str_contains($html, '/vendor/'));

        $this->artisan('invoices:auto-reply')->assertSuccessful();

        $row = InvoiceEmailReply::query()->sole();
        $this->assertSame(InvoiceMailboxAutoReplyService::OUTCOME_REPLIED_GENERIC, $row->outcome);
        $this->assertNull($row->vendor_id);
    }

    public function test_unknown_sender_without_an_attachment_is_not_answered(): void
    {
        $this->fakeInbox([$this->graphMessage([
            'from' => $this->sender('stranger@plumbing.example'),
            'subject' => 'Invoice question',
            'hasAttachments' => false,
        ])]);

        $this->graph->shouldNotReceive('reply');

        $this->artisan('invoices:auto-reply')->assertSuccessful();

        $this->assertSame(InvoiceMailboxAutoReplyService::OUTCOME_SKIPPED_NOT_INVOICE, InvoiceEmailReply::query()->sole()->outcome);
    }

    public function test_known_vendor_needs_an_attachment_or_an_invoice_word(): void
    {
        $this->vendor('abc@example.com');
        $this->fakeInbox([
            $this->graphMessage(['id' => 'M-chat', 'hasAttachments' => false, 'subject' => 'Hello', 'body' => ['contentType' => 'text', 'content' => 'Are you open Friday?']]),
            $this->graphMessage(['id' => 'M-words', 'hasAttachments' => false, 'subject' => 'Payment for last month', 'body' => ['contentType' => 'text', 'content' => 'See below']]),
            $this->graphMessage(['id' => 'M-other', 'hasAttachments' => false, 'subject' => 'Payment for last month', 'from' => $this->sender('other@example.com')]),
        ]);

        // The vendor's plain chat is left alone, the vendor's "payment" message
        // is answered even without a file, and "other@" is not a vendor and has
        // no attachment, so the word alone does not earn a reply.
        $this->graph->shouldReceive('reply')->once()->withArgs(fn (string $id) => $id === 'M-words');

        $this->artisan('invoices:auto-reply')->assertSuccessful();

        $this->assertSame(
            [
                InvoiceMailboxAutoReplyService::OUTCOME_SKIPPED_NOT_INVOICE,
                InvoiceMailboxAutoReplyService::OUTCOME_REPLIED_VENDOR,
                InvoiceMailboxAutoReplyService::OUTCOME_SKIPPED_NOT_INVOICE,
            ],
            InvoiceEmailReply::query()->orderBy('id')->pluck('outcome')->all(),
        );
    }

    public function test_internal_and_system_senders_are_skipped(): void
    {
        $this->fakeInbox([
            $this->graphMessage(['from' => $this->sender('oa@texasrenters.com')]),
            $this->graphMessage(['from' => $this->sender('thmp@txhomemp.com')]),
            $this->graphMessage(['from' => $this->sender('no-reply@vendor.example')]),
        ]);

        $this->graph->shouldNotReceive('reply');

        $this->artisan('invoices:auto-reply')->assertSuccessful();

        $this->assertSame(3, InvoiceEmailReply::query()->where('outcome', InvoiceMailboxAutoReplyService::OUTCOME_SKIPPED_INTERNAL)->count());
    }

    public function test_bounces_and_out_of_office_replies_are_skipped(): void
    {
        $this->vendor('abc@example.com');
        $this->fakeInbox([
            $this->graphMessage(['internetMessageHeaders' => [['name' => 'Auto-Submitted', 'value' => 'auto-replied']]]),
            $this->graphMessage(['internetMessageHeaders' => [['name' => 'X-Auto-Response-Suppress', 'value' => 'All']]]),
            $this->graphMessage(['internetMessageHeaders' => [['name' => 'Precedence', 'value' => 'bulk']]]),
            $this->graphMessage(['subject' => 'Automatic reply: Invoice 12']),
        ]);

        $this->graph->shouldNotReceive('reply');

        $this->artisan('invoices:auto-reply')->assertSuccessful();

        $this->assertSame(4, InvoiceEmailReply::query()->where('outcome', InvoiceMailboxAutoReplyService::OUTCOME_SKIPPED_AUTOMATED)->count());
    }

    public function test_a_sender_is_answered_at_most_once_a_day(): void
    {
        $vendor = $this->vendor('abc@example.com');
        InvoiceEmailReply::query()->create([
            'graph_message_id' => 'M-earlier',
            'from_email' => 'abc@example.com',
            'vendor_id' => $vendor->id,
            'outcome' => InvoiceMailboxAutoReplyService::OUTCOME_REPLIED_VENDOR,
            'replied_at' => now()->subHours(3),
        ]);
        $this->fakeInbox([$this->graphMessage(['id' => 'M-again'])]);

        $this->graph->shouldNotReceive('reply');

        $this->artisan('invoices:auto-reply')->assertSuccessful();

        $this->assertSame(
            InvoiceMailboxAutoReplyService::OUTCOME_SKIPPED_RECENT,
            InvoiceEmailReply::query()->where('graph_message_id', 'M-again')->sole()->outcome,
        );
    }

    public function test_a_sender_is_answered_again_after_a_day(): void
    {
        $vendor = $this->vendor('abc@example.com');
        InvoiceEmailReply::query()->create([
            'graph_message_id' => 'M-earlier',
            'from_email' => 'abc@example.com',
            'vendor_id' => $vendor->id,
            'outcome' => InvoiceMailboxAutoReplyService::OUTCOME_REPLIED_VENDOR,
            'replied_at' => now()->subDays(2),
        ]);
        $this->fakeInbox([$this->graphMessage(['id' => 'M-again'])]);

        $this->graph->shouldReceive('reply')->once();

        $this->artisan('invoices:auto-reply')->assertSuccessful();

        $this->assertSame(
            InvoiceMailboxAutoReplyService::OUTCOME_REPLIED_VENDOR,
            InvoiceEmailReply::query()->where('graph_message_id', 'M-again')->sole()->outcome,
        );
    }

    public function test_a_message_already_in_the_ledger_is_not_looked_at_again(): void
    {
        $this->vendor('abc@example.com');
        InvoiceEmailReply::query()->create([
            'graph_message_id' => 'M-seen',
            'from_email' => 'abc@example.com',
            'outcome' => InvoiceMailboxAutoReplyService::OUTCOME_SKIPPED_NOT_INVOICE,
        ]);
        $this->fakeInbox([$this->graphMessage(['id' => 'M-seen'])]);

        $this->graph->shouldNotReceive('reply');

        $this->artisan('invoices:auto-reply')
            ->expectsOutputToContain('No new messages')
            ->assertSuccessful();

        $this->assertSame(1, InvoiceEmailReply::query()->count());
    }

    public function test_dry_run_lists_decisions_without_sending_or_recording(): void
    {
        $this->vendor('abc@example.com');
        $this->fakeInbox([$this->graphMessage(['id' => 'M-dry', 'subject' => 'Invoice 9'])]);

        $this->graph->shouldNotReceive('reply');

        $this->artisan('invoices:auto-reply --dry-run')
            ->expectsOutputToContain('replied_vendor')
            ->expectsOutputToContain('Would reply to 1 of 1 message(s).')
            ->assertSuccessful();

        $this->assertSame(0, InvoiceEmailReply::query()->count());
        $this->assertNull(Cache::get('invoices.auto_reply.cursor'));
    }

    public function test_dry_run_works_while_the_feature_is_off(): void
    {
        config(['services.invoices_mailbox.auto_reply_enabled' => false]);
        $this->fakeInbox([]);

        $this->artisan('invoices:auto-reply --dry-run')
            ->expectsOutputToContain('No new messages')
            ->assertSuccessful();
    }

    public function test_feature_off_touches_nothing(): void
    {
        config(['services.invoices_mailbox.auto_reply_enabled' => false]);

        $this->graph->shouldNotReceive('fetchInbox');
        $this->graph->shouldNotReceive('reply');

        $this->artisan('invoices:auto-reply')
            ->expectsOutputToContain('is off')
            ->assertSuccessful();
    }

    public function test_a_failed_reply_stops_the_run_and_keeps_the_message_for_next_tick(): void
    {
        $this->vendor('abc@example.com');
        $this->fakeInbox([
            $this->graphMessage(['id' => 'M-fail', 'receivedDateTime' => now()->subMinutes(10)->toIso8601ZuluString()]),
            $this->graphMessage(['id' => 'M-after', 'from' => $this->sender('xyz@example.com'), 'subject' => 'Invoice 2']),
        ]);

        $this->graph->shouldReceive('reply')->once()->andThrow(new \RuntimeException('403 Forbidden'));

        $this->artisan('invoices:auto-reply')->assertSuccessful();

        $this->assertSame(0, InvoiceEmailReply::query()->count());
        $this->assertLessThan(
            now()->subMinutes(5)->getTimestamp(),
            Carbon::parse(Cache::get('invoices.auto_reply.cursor'))->getTimestamp(),
        );
    }

    public function test_the_cursor_advances_to_the_newest_message_seen(): void
    {
        $this->vendor('abc@example.com');
        $newest = now()->subMinute()->startOfSecond();
        $this->fakeInbox([
            $this->graphMessage(['id' => 'M-1', 'receivedDateTime' => now()->subMinutes(20)->toIso8601ZuluString()]),
            $this->graphMessage(['id' => 'M-2', 'from' => $this->sender('oa@texasrenters.com'), 'receivedDateTime' => $newest->toIso8601ZuluString()]),
        ]);
        $this->graph->shouldReceive('reply')->once();

        $this->artisan('invoices:auto-reply')->assertSuccessful();

        $this->assertSame($newest->toIso8601ZuluString(), Cache::get('invoices.auto_reply.cursor'));
    }

    private function vendor(string $email): Vendor
    {
        return Vendor::query()->create([
            'propertyware_id' => 'V-'.uniqid(),
            'name' => 'ABC Plumbing',
            'email' => $email,
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function fakeInbox(array $messages): void
    {
        $this->graph->shouldReceive('fetchInbox')
            ->once()
            ->withArgs(fn ($since, ?string $mailbox) => $mailbox === 'invoices@texasrenters.com')
            ->andReturn($messages);
    }

    /**
     * @return array{emailAddress: array{address: string}}
     */
    private function sender(string $address): array
    {
        return ['emailAddress' => ['address' => $address]];
    }

    /**
     * A vendor's invoice email: a PDF attached, an invoice subject, from the
     * known vendor address unless overridden.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function graphMessage(array $overrides = []): array
    {
        return array_merge([
            'id' => 'M-'.uniqid(),
            'subject' => 'Invoice #1234 - 12 Main St',
            'from' => $this->sender('abc@example.com'),
            'receivedDateTime' => now()->toIso8601ZuluString(),
            'hasAttachments' => true,
            'conversationId' => 'CONV-'.uniqid(),
            'internetMessageId' => '<msg@example.com>',
            'body' => ['contentType' => 'html', 'content' => '<p>Please find our invoice attached.</p>'],
            'internetMessageHeaders' => [],
        ], $overrides);
    }
}
