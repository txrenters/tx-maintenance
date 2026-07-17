<?php

namespace Tests\Unit;

use App\Services\MicrosoftGraphMailService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MicrosoftGraphMailServiceTest extends TestCase
{
    private function fakeGraph(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response([
                'id' => 'GRAPH_ID',
                'internetMessageId' => '<abc@texasrenters.com>',
                'conversationId' => 'CONV_ID',
            ]),
            'https://graph.microsoft.com/*/mailFolders/inbox/messages*' => Http::response([
                'value' => [['id' => 'M1', 'subject' => 'Re: hi [TX-1000-1]']],
            ]),
        ]);
    }

    public function test_send_mail_drafts_then_sends_and_returns_ids(): void
    {
        $this->fakeGraph();

        $result = app(MicrosoftGraphMailService::class)
            ->sendMail('v@example.com', ['mc@texasrenters.com'], 'Hi [TX-1000-1]', '<p>hi</p>');

        $this->assertSame('GRAPH_ID', $result['graph_message_id']);
        $this->assertSame('<abc@texasrenters.com>', $result['internet_message_id']);
        $this->assertSame('CONV_ID', $result['graph_conversation_id']);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/messages') && $r->method() === 'POST');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/messages/GRAPH_ID/send'));
    }

    public function test_token_is_cached_across_calls(): void
    {
        $this->fakeGraph();

        $service = app(MicrosoftGraphMailService::class);
        $service->fetchInbox(now()->subHour());
        $service->fetchInbox(now()->subHour());

        $tokenCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'oauth2/v2.0/token'))
            ->count();

        $this->assertSame(1, $tokenCalls);
    }

    public function test_get_attachments_decodes_files_and_skips_non_files(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/attachments' => Http::response(['value' => [
                [
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'name' => 'photo.jpg',
                    'contentType' => 'image/jpeg',
                    'contentBytes' => base64_encode('RAWIMG'),
                ],
                [
                    '@odata.type' => '#microsoft.graph.itemAttachment',
                    'name' => 'forwarded.eml',
                ],
            ]]),
        ]);

        $files = app(MicrosoftGraphMailService::class)->getAttachments('M1');

        $this->assertCount(1, $files);
        $this->assertSame('photo.jpg', $files[0]['name']);
        $this->assertSame('RAWIMG', $files[0]['bytes']);
    }
}
