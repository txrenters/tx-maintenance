# Vendor Email System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist, display, manually compose, and receive vendor-reply emails on a work order — sent/received through the `workorders@` mailbox via Microsoft Graph, scoped to work order + vendor.

**Architecture:** A dedicated `EmailMessage` (+ `EmailAttachment`) model stores every outbound/inbound email. Outbound goes through a `WorkOrderEmailSender` service that calls `MicrosoftGraphMailService` (draft-then-send, capturing Graph ids) and persists the row; the existing automated assignment email is re-routed through it while keeping its current Blade design. Inbound replies are pulled by a scheduled `emails:sync-replies` command that matches a `[TX-<wo>-<vendor>]` subject tag (with header/conversationId fallback) and threads them. A new "Emails" tab on the work order page shows the thread with a Tiptap rich-text compose box.

**Tech Stack:** Laravel 12/PHP 8.4, Vue 3 + Inertia, Microsoft Graph REST (via `Http`/Guzzle), Tiptap (`@tiptap/vue-3`, `@tiptap/starter-kit`), PHPUnit.

## Global Constraints

- All PHP/Artisan/Composer/Node commands run through Sail: `vendor/bin/sail ...`.
- No new Composer dependencies. New npm deps limited to `@tiptap/vue-3` + `@tiptap/starter-kit` (approved).
- Automated assignment email keeps the existing `emails.vendor-service-request` Blade view and subject **unchanged** — re-rendered via the existing `VendorServiceRequestMail` mailable; it is trusted HTML and MUST NOT be run through the sanitizer.
- Graph mailbox + credentials come from config only: `services.microsoft.{tenant_id,client_id,client_secret,mailbox}`. Mailbox default `workorders@texasrenters.com`.
- Automated email CC is exactly `mc@texasrenters.com`, `ofm@txhomemp.com` (drop `workorders@` — it is now the From).
- Correlation tag format: `TX-<work_order_no>-<vendor_id>`, embedded in the subject as `[TX-<work_order_no>-<vendor_id>]`.
- Email HTML from untrusted sources (inbound replies) and from the compose editor MUST be passed through `HtmlSanitizer` before storing/displaying. Never `v-html` raw untrusted HTML.
- Inbound reply attachments are downloaded via Graph and stored in `email_attachments` (both directions use this table). Image attachments (`image/jpeg|png|gif|webp`) are compressed via `AttachmentOptimizer` before saving; non-images stored unchanged; optimization failures fall back to original bytes.
- Run `vendor/bin/sail bin pint --dirty --format agent` after PHP changes.

---

## File Structure

**Backend**
- `config/services.php` (modify) — add `microsoft` block.
- `database/migrations/<ts>_create_email_messages_table.php` (create)
- `database/migrations/<ts>_create_email_attachments_table.php` (create)
- `app/Models/EmailMessage.php` (create)
- `app/Models/EmailAttachment.php` (create)
- `app/Models/WorkOrder.php` (modify) — add `emailMessages()` relation.
- `database/factories/EmailMessageFactory.php` (create)
- `app/Services/MicrosoftGraphMailService.php` (create)
- `app/Services/HtmlSanitizer.php` (create)
- `app/Services/AttachmentOptimizer.php` (create)
- `app/Services/EmailAttachmentStore.php` (create)
- `app/Services/WorkOrderEmailSender.php` (create)
- `app/Jobs/SendVendorWorkOrderInformation.php` (modify) — route the automated email through the sender.
- `app/Console/Commands/SyncEmailReplies.php` (create)
- `routes/console.php` (modify) — schedule the poll.
- `app/Http/Requests/SendWorkOrderEmailRequest.php` (create)
- `app/Http/Controllers/WorkOrderEmailController.php` (create)
- `routes/web.php` (modify) — index/store/download routes.

**Frontend**
- `resources/js/Components/RichTextEditor.vue` (create)
- `resources/js/Pages/WorkOrder/Partials/VendorEmail.vue` (create)
- `resources/js/Pages/WorkOrder/Show.vue` (modify) — tab + fetch + render.

**Tests**
- `tests/Unit/MicrosoftGraphMailServiceTest.php`
- `tests/Unit/HtmlSanitizerTest.php`
- `tests/Unit/AttachmentOptimizerTest.php`
- `tests/Feature/WorkOrderEmailSenderTest.php`
- `tests/Feature/VendorAssignmentEmailTest.php`
- `tests/Feature/SyncEmailRepliesTest.php`
- `tests/Feature/WorkOrderEmailControllerTest.php`

---

## Task 1: Data layer (migrations, models, factory, relation)

**Files:**
- Create: `database/migrations/<ts>_create_email_messages_table.php`
- Create: `database/migrations/<ts>_create_email_attachments_table.php`
- Create: `app/Models/EmailMessage.php`
- Create: `app/Models/EmailAttachment.php`
- Create: `database/factories/EmailMessageFactory.php`
- Modify: `app/Models/WorkOrder.php`
- Test: `tests/Feature/WorkOrderEmailSenderTest.php` (relation smoke test added here, expanded in Task 4)

**Interfaces:**
- Produces: `EmailMessage` (guarded=[], casts `cc`→array, `has_attachments`→bool, `emailed_at`→datetime; relations `work_order()`, `vendor()`, `sentByUser()`, `attachments()`). `EmailAttachment` (relation `emailMessage()`). `WorkOrder::emailMessages(): HasMany`. `EmailMessage::factory()`.

- [ ] **Step 1: Create the migrations**

Create `database/migrations/<ts>_create_email_messages_table.php` (use `vendor/bin/sail artisan make:migration create_email_messages_table --no-interaction`, then replace the body):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction'); // outbound | inbound
            $table->string('subject')->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->string('from_email')->nullable();
            $table->string('to_email')->nullable();
            $table->json('cc')->nullable();
            $table->string('correlation_tag')->nullable()->index();
            $table->string('graph_message_id')->nullable()->unique();
            $table->string('graph_conversation_id')->nullable()->index();
            $table->string('internet_message_id')->nullable()->index();
            $table->string('in_reply_to')->nullable()->index();
            $table->boolean('has_attachments')->default(false);
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();

            $table->index(['work_order_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
```

Create `database/migrations/<ts>_create_email_attachments_table.php` (make it AFTER the messages migration so the FK resolves):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_message_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attachments');
    }
};
```

- [ ] **Step 2: Create the models**

`app/Models/EmailMessage.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailMessage extends Model
{
    /** @use HasFactory<\Database\Factories\EmailMessageFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'cc' => 'array',
        'has_attachments' => 'boolean',
        'emailed_at' => 'datetime',
    ];

    public function work_order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }
}
```

`app/Models/EmailAttachment.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAttachment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'size' => 'integer',
    ];

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }
}
```

- [ ] **Step 3: Add the WorkOrder relation**

In `app/Models/WorkOrder.php`, next to the other `HasMany` relations (near `vendor_conversation()`), add:

```php
public function emailMessages(): HasMany
{
    return $this->hasMany(EmailMessage::class);
}
```

(`HasMany` is already imported in this file.)

- [ ] **Step 4: Create the factory**

`database/factories/EmailMessageFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\EmailMessage;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    protected $model = EmailMessage::class;

    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'vendor_id' => fn () => Vendor::query()->create([
                'name' => $this->faker->company(),
                'email' => $this->faker->unique()->safeEmail(),
                'is_active' => true,
            ])->id,
            'direction' => 'outbound',
            'subject' => 'New Service Request [TX-1000-1]',
            'body_html' => '<p>Hello</p>',
            'body_text' => 'Hello',
            'from_email' => 'workorders@texasrenters.com',
            'to_email' => $this->faker->unique()->safeEmail(),
            'cc' => ['mc@texasrenters.com', 'ofm@txhomemp.com'],
            'correlation_tag' => 'TX-1000-1',
            'graph_message_id' => $this->faker->uuid(),
            'graph_conversation_id' => $this->faker->uuid(),
            'internet_message_id' => '<'.$this->faker->uuid().'@texasrenters.com>',
            'in_reply_to' => null,
            'has_attachments' => false,
            'sent_by_user_id' => null,
            'emailed_at' => now(),
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn () => ['direction' => 'inbound']);
    }
}
```

- [ ] **Step 5: Write a relation smoke test**

Create `tests/Feature/WorkOrderEmailSenderTest.php` with just this test for now:

```php
<?php

namespace Tests\Feature;

use App\Models\EmailMessage;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderEmailSenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_order_has_email_messages_relation(): void
    {
        $email = EmailMessage::factory()->create();

        $workOrder = WorkOrder::find($email->work_order_id);

        $this->assertTrue($workOrder->emailMessages()->whereKey($email->id)->exists());
        $this->assertSame(['mc@texasrenters.com', 'ofm@txhomemp.com'], $email->cc);
    }
}
```

- [ ] **Step 6: Run the migration + test**

Run: `vendor/bin/sail artisan migrate --no-interaction && vendor/bin/sail artisan test --compact --filter=test_work_order_has_email_messages_relation`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Models config database routes tests
git commit -m "feat: email_messages + email_attachments data layer"
```

---

## Task 2: MicrosoftGraphMailService

**Files:**
- Modify: `config/services.php`
- Create: `app/Services/MicrosoftGraphMailService.php`
- Test: `tests/Unit/MicrosoftGraphMailServiceTest.php`

**Interfaces:**
- Consumes: `services.microsoft.*` config.
- Produces:
  - `sendMail(string $to, array $cc, string $subject, string $html, array $attachments = []): array` returning `['graph_message_id'=>string,'internet_message_id'=>?string,'graph_conversation_id'=>?string]`. Each `$attachments` item is `['name'=>string,'contentType'=>string,'contentBytes'=>string]` (base64).
  - `fetchInbox(\Carbon\CarbonInterface $since): array` returning raw Graph message arrays.
  - `getAttachments(string $messageId): array` returning `[['name'=>string,'contentType'=>string,'bytes'=>string]]` (decoded), skipping non-file attachment types.
  - `markRead(string $messageId): void`.

- [ ] **Step 1: Add the config block**

In `config/services.php`, add:

```php
'microsoft' => [
    'tenant_id' => env('MICROSOFT_TENANT_ID'),
    'client_id' => env('MICROSOFT_CLIENT_ID'),
    'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
    'mailbox' => env('MICROSOFT_MAILBOX', 'workorders@texasrenters.com'),
],
```

- [ ] **Step 2: Write the failing unit test**

`tests/Unit/MicrosoftGraphMailServiceTest.php`:

```php
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
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/sail artisan test --compact tests/Unit/MicrosoftGraphMailServiceTest.php`
Expected: FAIL (class `MicrosoftGraphMailService` not found).

- [ ] **Step 4: Implement the service**

`app/Services/MicrosoftGraphMailService.php`:

```php
<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MicrosoftGraphMailService
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    private const TOKEN_CACHE_KEY = 'microsoft.graph.token';

    private string $tenantId;

    private string $clientId;

    private string $clientSecret;

    private string $mailbox;

    public function __construct()
    {
        $this->tenantId = (string) config('services.microsoft.tenant_id');
        $this->clientId = (string) config('services.microsoft.client_id');
        $this->clientSecret = (string) config('services.microsoft.client_secret');
        $this->mailbox = (string) config('services.microsoft.mailbox');
    }

    /**
     * @param  array<int, string>  $cc
     * @param  array<int, array{name: string, contentType: string, contentBytes: string}>  $attachments
     * @return array{graph_message_id: string, internet_message_id: ?string, graph_conversation_id: ?string}
     */
    public function sendMail(string $to, array $cc, string $subject, string $html, array $attachments = []): array
    {
        $message = [
            'subject' => $subject,
            'body' => ['contentType' => 'HTML', 'content' => $html],
            'toRecipients' => [['emailAddress' => ['address' => $to]]],
            'ccRecipients' => array_map(
                fn (string $addr) => ['emailAddress' => ['address' => $addr]],
                array_values($cc),
            ),
        ];

        if ($attachments !== []) {
            $message['attachments'] = array_map(fn (array $a) => [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $a['name'],
                'contentType' => $a['contentType'],
                'contentBytes' => $a['contentBytes'],
            ], $attachments);
        }

        $draft = $this->request()
            ->post("/users/{$this->mailbox}/messages", $message)
            ->throw()
            ->json();

        $id = (string) $draft['id'];

        $this->request()
            ->post("/users/{$this->mailbox}/messages/{$id}/send")
            ->throw();

        return [
            'graph_message_id' => $id,
            'internet_message_id' => $draft['internetMessageId'] ?? null,
            'graph_conversation_id' => $draft['conversationId'] ?? null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchInbox(CarbonInterface $since): array
    {
        $messages = [];
        $url = "/users/{$this->mailbox}/mailFolders/inbox/messages";
        $query = [
            '$select' => 'id,subject,from,receivedDateTime,hasAttachments,conversationId,internetMessageId,body,internetMessageHeaders',
            '$filter' => 'receivedDateTime ge '.$since->toIso8601ZuluString(),
            '$orderby' => 'receivedDateTime asc',
            '$top' => 50,
        ];

        do {
            $response = $this->request()->get($url, $query)->throw()->json();
            $messages = array_merge($messages, $response['value'] ?? []);
            $url = $response['@odata.nextLink'] ?? null;
            $query = []; // nextLink already carries the query string
        } while ($url !== null);

        return $messages;
    }

    /**
     * @return array<int, array{name: string, contentType: string, bytes: string}>
     */
    public function getAttachments(string $messageId): array
    {
        $response = $this->request()
            ->get("/users/{$this->mailbox}/messages/{$messageId}/attachments")
            ->throw()
            ->json();

        $files = [];
        foreach ($response['value'] ?? [] as $attachment) {
            if (($attachment['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment') {
                continue; // skip item/reference attachments — no file bytes
            }

            $files[] = [
                'name' => $attachment['name'] ?? 'attachment',
                'contentType' => $attachment['contentType'] ?? 'application/octet-stream',
                'bytes' => base64_decode((string) ($attachment['contentBytes'] ?? ''), true) ?: '',
            ];
        }

        return $files;
    }

    public function markRead(string $messageId): void
    {
        $this->request()
            ->patch("/users/{$this->mailbox}/messages/{$messageId}", ['isRead' => true])
            ->throw();
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->token())
            ->baseUrl(self::GRAPH_BASE)
            ->acceptJson();
    }

    private function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->post(
            "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
            [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ],
        )->throw();

        $token = (string) $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 3600);

        Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addSeconds(max(60, $expiresIn - 300)));

        return $token;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/sail artisan test --compact tests/Unit/MicrosoftGraphMailServiceTest.php`
Expected: PASS (all three tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add config app/Services/MicrosoftGraphMailService.php tests/Unit/MicrosoftGraphMailServiceTest.php
git commit -m "feat: MicrosoftGraphMailService (draft-then-send, inbox poll, token cache)"
```

---

## Task 3: HtmlSanitizer

**Files:**
- Create: `app/Services/HtmlSanitizer.php`
- Test: `tests/Unit/HtmlSanitizerTest.php`

**Interfaces:**
- Produces: `HtmlSanitizer::clean(string $html): string` — returns HTML limited to an allowlist (`p,br,strong,b,em,i,u,ul,ol,li,a`), drops `script/style/etc.` entirely, unwraps other tags to their text, strips every attribute except a safe `http(s)` `href` on `<a>` (to which it adds `target="_blank" rel="noopener noreferrer"`).

- [ ] **Step 1: Write the failing test**

`tests/Unit/HtmlSanitizerTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\HtmlSanitizer;
use Tests\TestCase;

class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new HtmlSanitizer;
    }

    public function test_strips_script_tags_entirely(): void
    {
        $out = $this->sanitizer->clean('<p>hi</p><script>alert(1)</script>');

        $this->assertStringContainsString('<p>hi</p>', $out);
        $this->assertStringNotContainsString('alert', $out);
    }

    public function test_removes_event_handler_attributes(): void
    {
        $out = $this->sanitizer->clean('<p onclick="steal()">hi</p>');

        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringContainsString('hi', $out);
    }

    public function test_drops_javascript_href_but_keeps_http_links(): void
    {
        $bad = $this->sanitizer->clean('<a href="javascript:alert(1)">x</a>');
        $good = $this->sanitizer->clean('<a href="https://texasrenters.com">x</a>');

        $this->assertStringNotContainsString('javascript', $bad);
        $this->assertStringContainsString('https://texasrenters.com', $good);
        $this->assertStringContainsString('rel="noopener noreferrer"', $good);
    }

    public function test_unwraps_disallowed_tags_keeping_text(): void
    {
        $out = $this->sanitizer->clean('<div><span>keep me</span></div>');

        $this->assertStringContainsString('keep me', $out);
        $this->assertStringNotContainsString('<div>', $out);
        $this->assertStringNotContainsString('<span>', $out);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/sail artisan test --compact tests/Unit/HtmlSanitizerTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement the sanitizer**

`app/Services/HtmlSanitizer.php`:

```php
<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

class HtmlSanitizer
{
    /** @var array<int, string> */
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'a'];

    /** @var array<int, string> */
    private const DROP_TAGS = ['script', 'style', 'head', 'title', 'meta', 'link', 'iframe', 'object', 'embed'];

    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="__root__">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $root = $dom->getElementById('__root__');

        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->sanitizeChildren($root);

        $result = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $result .= $dom->saveHTML($child);
        }

        return trim($result);
    }

    private function sanitizeChildren(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_TAGS, true)) {
                $node->removeChild($child);

                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->sanitizeChildren($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attr) {
                $keep = $tag === 'a'
                    && strtolower($attr->name) === 'href'
                    && preg_match('#^https?://#i', (string) $attr->value) === 1;

                if (! $keep) {
                    $child->removeAttribute($attr->name);
                }
            }

            if ($tag === 'a' && $child->hasAttribute('href')) {
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener noreferrer');
            }

            $this->sanitizeChildren($child);
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/sail artisan test --compact tests/Unit/HtmlSanitizerTest.php`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Services/HtmlSanitizer.php tests/Unit/HtmlSanitizerTest.php
git commit -m "feat: HtmlSanitizer allowlist for email bodies"
```

---

## Task 4: Attachment services + WorkOrderEmailSender

**Files:**
- Create: `app/Services/AttachmentOptimizer.php`
- Create: `app/Services/EmailAttachmentStore.php`
- Create: `app/Services/WorkOrderEmailSender.php`
- Test: `tests/Unit/AttachmentOptimizerTest.php`
- Test: `tests/Feature/WorkOrderEmailSenderTest.php` (extend from Task 1)

**Interfaces:**
- Consumes: `MicrosoftGraphMailService::sendMail(...)`, `HtmlSanitizer::clean(...)`, `EmailMessage`, `EmailAttachment`.
- Produces:
  - `AttachmentOptimizer::optimize(string $bytes, string $mime): string` — compresses `image/jpeg|png|gif|webp` via `Spatie\ImageOptimizer\OptimizerChainFactory`; returns original bytes for non-images or on failure.
  - `EmailAttachmentStore::persist(EmailMessage $message, string $filename, string $mime, string $bytes): EmailAttachment` — writes bytes to the local disk and creates the row (does **not** optimize; callers optimize first).
  - `WorkOrderEmailSender::sendVendorEmail(WorkOrder $workOrder, Vendor $vendor, string $subject, string $html, array $files = [], ?User $sentBy = null, array $cc = self::DEFAULT_CC, bool $trustedHtml = false): EmailMessage`
    where each `$files` item is either an `Illuminate\Http\UploadedFile` or `['name'=>string,'contentType'=>string,'bytes'=>string]`. `DEFAULT_CC = ['mc@texasrenters.com','ofm@txhomemp.com']`.

- [ ] **Step 1: Write the AttachmentOptimizer test**

`tests/Unit/AttachmentOptimizerTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\AttachmentOptimizer;
use Tests\TestCase;

class AttachmentOptimizerTest extends TestCase
{
    public function test_non_image_bytes_are_returned_unchanged(): void
    {
        $bytes = '%PDF-1.4 not an image';

        $result = (new AttachmentOptimizer)->optimize($bytes, 'application/pdf');

        $this->assertSame($bytes, $result);
    }

    public function test_image_bytes_are_returned_and_stay_valid(): void
    {
        // A minimal valid PNG generated in-memory via GD.
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        $result = (new AttachmentOptimizer)->optimize($png, 'image/png');

        // Optimization may or may not shrink these bytes, but the result must
        // remain non-empty, valid PNG data (never corrupted or dropped).
        $this->assertNotEmpty($result);
        $this->assertNotFalse(imagecreatefromstring($result));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/sail artisan test --compact tests/Unit/AttachmentOptimizerTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement AttachmentOptimizer**

`app/Services/AttachmentOptimizer.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Spatie\ImageOptimizer\OptimizerChainFactory;

class AttachmentOptimizer
{
    /** @var array<int, string> */
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Compress image bytes in place (lossless-ish, no resize). Non-images and any
     * failure return the original bytes unchanged — mirrors app/Jobs/UploadAttachment.php.
     */
    public function optimize(string $bytes, string $mime): string
    {
        if (! in_array($mime, self::IMAGE_MIMES, true) || $bytes === '') {
            return $bytes;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'emlimg');

        if ($tmp === false) {
            return $bytes;
        }

        try {
            file_put_contents($tmp, $bytes);
            OptimizerChainFactory::create()->optimize($tmp);
            $optimized = file_get_contents($tmp);

            return $optimized !== false && $optimized !== '' ? $optimized : $bytes;
        } catch (\Throwable $e) {
            Log::warning('Email attachment image optimization failed; using original', [
                'mime' => $mime,
                'error' => $e->getMessage(),
            ]);

            return $bytes;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
```

- [ ] **Step 4: Implement EmailAttachmentStore**

`app/Services/EmailAttachmentStore.php`:

```php
<?php

namespace App\Services;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmailAttachmentStore
{
    /**
     * Persist already-final (optimized) bytes to disk and create the row.
     * Callers run AttachmentOptimizer::optimize() first.
     */
    public function persist(EmailMessage $message, string $filename, string $mime, string $bytes): EmailAttachment
    {
        $path = 'email-attachments/'.$message->id.'/'.Str::uuid().'-'.$filename;
        Storage::disk('local')->put($path, $bytes);

        return EmailAttachment::create([
            'email_message_id' => $message->id,
            'filename' => $filename,
            'mime' => $mime,
            'size' => strlen($bytes),
            'path' => $path,
        ]);
    }
}
```

- [ ] **Step 5: Run the optimizer test to verify it passes**

Run: `vendor/bin/sail artisan test --compact tests/Unit/AttachmentOptimizerTest.php`
Expected: PASS.

- [ ] **Step 6: Write the failing sender test**

Append to `tests/Feature/WorkOrderEmailSenderTest.php` (add imports `use App\Models\User; use App\Models\Vendor; use App\Models\WorkOrder; use App\Services\WorkOrderEmailSender; use Illuminate\Support\Facades\Http; use Illuminate\Support\Facades\Storage; use Illuminate\Http\UploadedFile;`):

```php
    private function assignedVendor(WorkOrder $workOrder): Vendor
    {
        $vendor = Vendor::query()->create([
            'name' => 'ABC Plumbing',
            'email' => 'abc@example.com',
            'is_active' => true,
        ]);
        $workOrder->vendors()->attach($vendor->id);

        return $vendor;
    }

    public function test_send_vendor_email_tags_subject_persists_and_stores_attachment(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response([
                'id' => 'GID', 'internetMessageId' => '<x@texasrenters.com>', 'conversationId' => 'CID',
            ]),
        ]);
        Storage::fake('local');

        $workOrder = WorkOrder::factory()->create(['work_order_no' => 1234]);
        $vendor = $this->assignedVendor($workOrder);
        $user = User::factory()->create();

        $message = app(WorkOrderEmailSender::class)->sendVendorEmail(
            workOrder: $workOrder,
            vendor: $vendor,
            subject: 'Quick question',
            html: '<p>Hello <script>bad()</script></p>',
            files: [UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf')],
            sentBy: $user,
        );

        $this->assertStringContainsString('[TX-1234-'.$vendor->id.']', $message->subject);
        $this->assertStringNotContainsString('bad()', $message->body_html);
        $this->assertSame('outbound', $message->direction);
        $this->assertSame('GID', $message->graph_message_id);
        $this->assertSame($user->id, $message->sent_by_user_id);
        $this->assertDatabaseHas('email_attachments', [
            'email_message_id' => $message->id,
            'filename' => 'quote.pdf',
        ]);
        $this->assertTrue($message->has_attachments);
    }

    public function test_trusted_html_is_not_sanitized(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response(['id' => 'GID2']),
        ]);

        $workOrder = WorkOrder::factory()->create(['work_order_no' => 22]);
        $vendor = $this->assignedVendor($workOrder);

        $message = app(WorkOrderEmailSender::class)->sendVendorEmail(
            workOrder: $workOrder,
            vendor: $vendor,
            subject: 'Service Request',
            html: '<table><tr><td>Styled template</td></tr></table>',
            trustedHtml: true,
        );

        $this->assertStringContainsString('<table>', $message->body_html);
    }
```

- [ ] **Step 7: Run the sender test to verify it fails**

Run: `vendor/bin/sail artisan test --compact tests/Feature/WorkOrderEmailSenderTest.php`
Expected: FAIL (class `WorkOrderEmailSender` not found).

- [ ] **Step 8: Implement the sender**

`app/Services/WorkOrderEmailSender.php`:

```php
<?php

namespace App\Services;

use App\Models\EmailMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Http\UploadedFile;

class WorkOrderEmailSender
{
    /** @var array<int, string> */
    public const DEFAULT_CC = ['mc@texasrenters.com', 'ofm@txhomemp.com'];

    public function __construct(
        private MicrosoftGraphMailService $graph,
        private HtmlSanitizer $sanitizer,
        private AttachmentOptimizer $optimizer,
        private EmailAttachmentStore $attachments,
    ) {}

    /**
     * @param  array<int, UploadedFile|array{name: string, contentType: string, bytes: string}>  $files
     * @param  array<int, string>  $cc
     */
    public function sendVendorEmail(
        WorkOrder $workOrder,
        Vendor $vendor,
        string $subject,
        string $html,
        array $files = [],
        ?User $sentBy = null,
        array $cc = self::DEFAULT_CC,
        bool $trustedHtml = false,
    ): EmailMessage {
        $tag = 'TX-'.$workOrder->work_order_no.'-'.$vendor->id;
        $subject = str_contains($subject, '['.$tag.']')
            ? $subject
            : trim($subject).' ['.$tag.']';

        $finalHtml = $trustedHtml ? $html : $this->sanitizer->clean($html);

        // Optimize each file ONCE, then reuse the same bytes for both the Graph
        // attachment and the stored copy.
        $graphAttachments = [];
        $storable = [];
        foreach ($files as $file) {
            [$name, $mime, $bytes] = $this->normalizeFile($file);
            $bytes = $this->optimizer->optimize($bytes, $mime);
            $graphAttachments[] = [
                'name' => $name,
                'contentType' => $mime,
                'contentBytes' => base64_encode($bytes),
            ];
            $storable[] = [$name, $mime, $bytes];
        }

        $ids = $this->graph->sendMail($vendor->email, $cc, $subject, $finalHtml, $graphAttachments);

        $message = EmailMessage::create([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'direction' => 'outbound',
            'subject' => $subject,
            'body_html' => $finalHtml,
            'body_text' => trim(strip_tags($finalHtml)),
            'from_email' => config('services.microsoft.mailbox'),
            'to_email' => $vendor->email,
            'cc' => array_values($cc),
            'correlation_tag' => $tag,
            'graph_message_id' => $ids['graph_message_id'],
            'graph_conversation_id' => $ids['graph_conversation_id'],
            'internet_message_id' => $ids['internet_message_id'],
            'has_attachments' => $storable !== [],
            'sent_by_user_id' => $sentBy?->id,
            'emailed_at' => now(),
        ]);

        foreach ($storable as [$name, $mime, $bytes]) {
            // Bytes are already optimized above — persist stores them as-is.
            $this->attachments->persist($message, $name, $mime, $bytes);
        }

        return $message;
    }

    /**
     * @param  UploadedFile|array{name: string, contentType: string, bytes: string}  $file
     * @return array{0: string, 1: string, 2: string}
     */
    private function normalizeFile(UploadedFile|array $file): array
    {
        if ($file instanceof UploadedFile) {
            return [
                $file->getClientOriginalName(),
                $file->getMimeType() ?: 'application/octet-stream',
                (string) $file->get(),
            ];
        }

        return [$file['name'], $file['contentType'], $file['bytes']];
    }
}
```

- [ ] **Step 9: Run the test to verify it passes**

Run: `vendor/bin/sail artisan test --compact tests/Feature/WorkOrderEmailSenderTest.php`
Expected: PASS (all three tests in the file).

- [ ] **Step 10: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Services/AttachmentOptimizer.php app/Services/EmailAttachmentStore.php app/Services/WorkOrderEmailSender.php tests/Unit/AttachmentOptimizerTest.php tests/Feature/WorkOrderEmailSenderTest.php
git commit -m "feat: attachment optimize+store services and WorkOrderEmailSender"
```

---

## Task 5: Route the automated assignment email through the sender

**Files:**
- Modify: `app/Jobs/SendVendorWorkOrderInformation.php`
- Test: `tests/Feature/VendorAssignmentEmailTest.php`

**Interfaces:**
- Consumes: `WorkOrderEmailSender::sendVendorEmail(...)`, existing `VendorServiceRequestMail::render()`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/VendorAssignmentEmailTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\SendVendorWorkOrderInformation;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use App\Services\WorkOrderInformationPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class VendorAssignmentEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_persists_outbound_email_with_tag_via_graph(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response([
                'id' => 'GID', 'internetMessageId' => '<x@texasrenters.com>', 'conversationId' => 'CID',
            ]),
        ]);

        // PDF + PropertyWare are external; stub them so the job's email path runs in isolation.
        $pdf = Mockery::mock(WorkOrderInformationPdf::class);
        $pdf->shouldReceive('render')->andReturn('%PDF-fake');
        $this->app->instance(WorkOrderInformationPdf::class, $pdf);

        $pw = Mockery::mock(PropertyWareService::class);
        $pw->shouldReceive('uploadWorkOrderPdf')->andReturn(null);
        $this->app->instance(PropertyWareService::class, $pw);

        $workOrder = WorkOrder::factory()->create(['work_order_no' => 4321]);
        $vendor = Vendor::query()->create([
            'name' => 'ABC Plumbing', 'email' => 'abc@example.com', 'is_active' => true,
        ]);
        $workOrder->vendors()->attach($vendor->id);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $this->assertDatabaseHas('email_messages', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'direction' => 'outbound',
            'correlation_tag' => 'TX-4321-'.$vendor->id,
        ]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/sail artisan test --compact tests/Feature/VendorAssignmentEmailTest.php`
Expected: FAIL (no `email_messages` row — job still uses SMTP `Mail::to`).

- [ ] **Step 3: Swap the send path in the job**

In `app/Jobs/SendVendorWorkOrderInformation.php`, replace the email block (currently lines ~73-82):

```php
        // 1) Email the vendor (only when we have an address to send to).
        if (filled($vendor->email)) {
            Mail::to($vendor->email)->send(new VendorServiceRequestMail(
                vendorName: $vendor->name,
                workOrderNo: (string) $workOrder->work_order_no,
                pdfContent: $pdf,
                portalUrl: $portalUrl,
                pdfFileName: $fileName,
            ));
        }
```

with:

```php
        // 1) Email the vendor via Microsoft Graph (only when we have an address).
        //    The Blade design is unchanged — we render the existing mailable to
        //    HTML and hand it to the sender as trusted template HTML (no sanitize),
        //    which persists it as an outbound EmailMessage and threads replies.
        if (filled($vendor->email)) {
            $html = (new VendorServiceRequestMail(
                vendorName: $vendor->name,
                workOrderNo: (string) $workOrder->work_order_no,
                pdfContent: $pdf,
                portalUrl: $portalUrl,
                pdfFileName: $fileName,
            ))->render();

            app(WorkOrderEmailSender::class)->sendVendorEmail(
                workOrder: $workOrder,
                vendor: $vendor,
                subject: 'New Service Request - Work Order #'.$workOrder->work_order_no,
                html: $html,
                files: [[
                    'name' => $fileName,
                    'contentType' => 'application/pdf',
                    'bytes' => $pdf,
                ]],
                trustedHtml: true,
            );
        }
```

Add `use App\Services\WorkOrderEmailSender;` to the imports. Leave the existing `use App\Mail\VendorServiceRequestMail;` and `use Illuminate\Support\Facades\Mail;` — `Mail` may still be referenced elsewhere; if a `use`-unused lint appears for `Mail`, remove that import.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/sail artisan test --compact tests/Feature/VendorAssignmentEmailTest.php`
Expected: PASS.

- [ ] **Step 5: Also update the second sender in the sync command**

`app/Console/Commands/SyncWorkOrderDocumentsCommand.php:231` still calls `Mail::to($vendor->email)->send(new VendorServiceRequestMail(...))`. Replace it with the same `app(WorkOrderEmailSender::class)->sendVendorEmail(... trustedHtml: true)` pattern (rendering the mailable to HTML, attaching the PDF bytes it already has). Add the `use App\Services\WorkOrderEmailSender;` import there too. Run:

Run: `vendor/bin/sail artisan test --compact tests/Feature/VendorAssignmentEmailTest.php`
Expected: still PASS (no regression).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Jobs/SendVendorWorkOrderInformation.php app/Console/Commands/SyncWorkOrderDocumentsCommand.php tests/Feature/VendorAssignmentEmailTest.php
git commit -m "feat: route automated vendor email through Graph sender (design unchanged)"
```

---

## Task 6: SyncEmailReplies command

**Files:**
- Create: `app/Console/Commands/SyncEmailReplies.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/SyncEmailRepliesTest.php`

**Interfaces:**
- Consumes: `MicrosoftGraphMailService::fetchInbox(...)`, `MicrosoftGraphMailService::markRead(...)`, `HtmlSanitizer::clean(...)`, `EmailMessage`, `WorkOrder`, `Vendor`.
- Produces: Artisan command `emails:sync-replies`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/SyncEmailRepliesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EmailMessage;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\MicrosoftGraphMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncEmailRepliesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array{name: string, contentType: string, bytes: string}>  $attachments
     */
    private function fakeInbox(array $messages, array $attachments = []): void
    {
        $graph = Mockery::mock(MicrosoftGraphMailService::class);
        $graph->shouldReceive('fetchInbox')->andReturn($messages);
        $graph->shouldReceive('getAttachments')->andReturn($attachments);
        $graph->shouldReceive('markRead')->andReturnNull();
        $this->app->instance(MicrosoftGraphMailService::class, $graph);
    }

    private function assignedVendor(int $workOrderNo): array
    {
        $workOrder = WorkOrder::factory()->create(['work_order_no' => $workOrderNo]);
        $vendor = Vendor::query()->create([
            'name' => 'ABC', 'email' => 'abc@example.com', 'is_active' => true,
        ]);
        $workOrder->vendors()->attach($vendor->id);

        return [$workOrder, $vendor];
    }

    private function graphMessage(array $overrides = []): array
    {
        return array_merge([
            'id' => 'M-'.uniqid(),
            'subject' => 'Re: hi',
            'from' => ['emailAddress' => ['address' => 'abc@example.com']],
            'receivedDateTime' => now()->toIso8601ZuluString(),
            'hasAttachments' => false,
            'conversationId' => null,
            'internetMessageId' => '<reply@example.com>',
            'body' => ['contentType' => 'html', 'content' => '<p>on my way</p>'],
            'internetMessageHeaders' => [],
        ], $overrides);
    }

    public function test_matches_reply_by_subject_tag(): void
    {
        [$workOrder, $vendor] = $this->assignedVendor(5001);
        $this->fakeInbox([$this->graphMessage([
            'subject' => 'Re: New Service Request [TX-5001-'.$vendor->id.']',
        ])]);

        $this->artisan('emails:sync-replies')->assertSuccessful();

        $this->assertDatabaseHas('email_messages', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'direction' => 'inbound',
        ]);
    }

    public function test_matches_reply_by_conversation_id_when_tag_missing(): void
    {
        [$workOrder, $vendor] = $this->assignedVendor(5002);
        EmailMessage::factory()->create([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'direction' => 'outbound',
            'graph_conversation_id' => 'CONV-XYZ',
        ]);

        $this->fakeInbox([$this->graphMessage([
            'subject' => 'Re: no tag here',
            'conversationId' => 'CONV-XYZ',
        ])]);

        $this->artisan('emails:sync-replies')->assertSuccessful();

        $this->assertSame(1, EmailMessage::where('direction', 'inbound')->count());
    }

    public function test_ignores_unmatched_mail(): void
    {
        $this->fakeInbox([$this->graphMessage(['subject' => 'Random newsletter'])]);

        $this->artisan('emails:sync-replies')->assertSuccessful();

        $this->assertSame(0, EmailMessage::where('direction', 'inbound')->count());
    }

    public function test_does_not_duplicate_already_stored_message(): void
    {
        [$workOrder, $vendor] = $this->assignedVendor(5003);
        EmailMessage::factory()->inbound()->create([
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'graph_message_id' => 'DUP-1',
        ]);

        $this->fakeInbox([$this->graphMessage([
            'id' => 'DUP-1',
            'subject' => 'Re: [TX-5003-'.$vendor->id.']',
        ])]);

        $this->artisan('emails:sync-replies')->assertSuccessful();

        $this->assertSame(1, EmailMessage::where('graph_message_id', 'DUP-1')->count());
    }

    public function test_downloads_and_stores_attachments(): void
    {
        Storage::fake('local');

        [$workOrder, $vendor] = $this->assignedVendor(5004);
        $this->fakeInbox(
            [$this->graphMessage([
                'subject' => 'Re: [TX-5004-'.$vendor->id.']',
                'hasAttachments' => true,
            ])],
            [['name' => 'invoice.pdf', 'contentType' => 'application/pdf', 'bytes' => '%PDF-fake']],
        );

        $this->artisan('emails:sync-replies')->assertSuccessful();

        $inbound = EmailMessage::where('direction', 'inbound')->firstOrFail();
        $this->assertTrue($inbound->has_attachments);
        $this->assertSame(1, $inbound->attachments()->count());
        $this->assertDatabaseHas('email_attachments', [
            'email_message_id' => $inbound->id,
            'filename' => 'invoice.pdf',
        ]);
    }
}
```

(Add `use Illuminate\Support\Facades\Storage;` to the test's imports.)

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/sail artisan test --compact tests/Feature/SyncEmailRepliesTest.php`
Expected: FAIL (command `emails:sync-replies` not defined).

- [ ] **Step 3: Implement the command**

`app/Console/Commands/SyncEmailReplies.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\AttachmentOptimizer;
use App\Services\EmailAttachmentStore;
use App\Services\HtmlSanitizer;
use App\Services\MicrosoftGraphMailService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncEmailReplies extends Command
{
    protected $signature = 'emails:sync-replies';

    protected $description = 'Poll the workorders mailbox for vendor email replies and thread them onto work orders.';

    private const CURSOR_KEY = 'emails.replies.cursor';

    public function handle(
        MicrosoftGraphMailService $graph,
        HtmlSanitizer $sanitizer,
        AttachmentOptimizer $optimizer,
        EmailAttachmentStore $attachments,
    ): int {
        $cursor = Cache::get(self::CURSOR_KEY);
        $since = $cursor ? Carbon::parse($cursor) : now()->subHour();

        $messages = $graph->fetchInbox($since);
        $maxReceived = $since->copy();

        foreach ($messages as $message) {
            try {
                $received = Carbon::parse($message['receivedDateTime'] ?? now());
                if ($received->greaterThan($maxReceived)) {
                    $maxReceived = $received;
                }

                $graphId = $message['id'] ?? null;
                if (! $graphId || EmailMessage::where('graph_message_id', $graphId)->exists()) {
                    continue;
                }

                $match = $this->match($message);
                if (! $match) {
                    continue;
                }

                [$workOrder, $vendor] = $match;

                $bodyType = strtolower($message['body']['contentType'] ?? 'html');
                $bodyContent = (string) ($message['body']['content'] ?? '');

                $hasAttachments = (bool) ($message['hasAttachments'] ?? false);

                $inbound = EmailMessage::create([
                    'work_order_id' => $workOrder->id,
                    'vendor_id' => $vendor->id,
                    'direction' => 'inbound',
                    'subject' => $message['subject'] ?? '',
                    'body_html' => $bodyType === 'html' ? $sanitizer->clean($bodyContent) : null,
                    'body_text' => $bodyType === 'html' ? trim(strip_tags($bodyContent)) : $bodyContent,
                    'from_email' => $message['from']['emailAddress']['address'] ?? null,
                    'to_email' => config('services.microsoft.mailbox'),
                    'correlation_tag' => $vendor ? 'TX-'.$workOrder->work_order_no.'-'.$vendor->id : null,
                    'graph_message_id' => $graphId,
                    'graph_conversation_id' => $message['conversationId'] ?? null,
                    'internet_message_id' => $message['internetMessageId'] ?? null,
                    'in_reply_to' => $this->firstHeaderId($message),
                    'has_attachments' => $hasAttachments,
                    'emailed_at' => $received,
                ]);

                if ($hasAttachments) {
                    foreach ($graph->getAttachments($graphId) as $file) {
                        $bytes = $optimizer->optimize($file['bytes'], $file['contentType']);
                        $attachments->persist($inbound, $file['name'], $file['contentType'], $bytes);
                    }
                }

                $graph->markRead($graphId);
            } catch (\Throwable $e) {
                Log::warning('emails:sync-replies failed for a message', [
                    'id' => $message['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Cache::put(self::CURSOR_KEY, $maxReceived->toIso8601ZuluString());

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{0: WorkOrder, 1: Vendor}|null
     */
    private function match(array $message): ?array
    {
        $subject = (string) ($message['subject'] ?? '');

        if (preg_match('/\[TX-(\d+)-(\d+)\]/', $subject, $m)) {
            $workOrder = WorkOrder::where('work_order_no', $m[1])->first();
            $vendor = Vendor::find((int) $m[2]);
            if ($workOrder && $vendor && $this->vendorAssigned($workOrder, $vendor)) {
                return [$workOrder, $vendor];
            }
        }

        $headerIds = $this->replyHeaderIds($message);
        $conversationId = $message['conversationId'] ?? null;

        if ($headerIds === [] && ! $conversationId) {
            return null;
        }

        $outbound = EmailMessage::query()
            ->where('direction', 'outbound')
            ->where(function ($q) use ($headerIds, $conversationId) {
                if ($headerIds !== []) {
                    $q->orWhereIn('internet_message_id', $headerIds);
                }
                if ($conversationId) {
                    $q->orWhere('graph_conversation_id', $conversationId);
                }
            })
            ->latest('id')
            ->first();

        if ($outbound && $outbound->work_order_id && $outbound->vendor_id) {
            $workOrder = WorkOrder::find($outbound->work_order_id);
            $vendor = Vendor::find($outbound->vendor_id);
            if ($workOrder && $vendor) {
                return [$workOrder, $vendor];
            }
        }

        return null;
    }

    private function vendorAssigned(WorkOrder $workOrder, Vendor $vendor): bool
    {
        return $workOrder->vendors()->where('vendors.id', $vendor->id)->exists();
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<int, string>
     */
    private function replyHeaderIds(array $message): array
    {
        $ids = [];
        foreach (($message['internetMessageHeaders'] ?? []) as $header) {
            $name = strtolower($header['name'] ?? '');
            if (in_array($name, ['in-reply-to', 'references'], true)) {
                preg_match_all('/<[^>]+>/', (string) ($header['value'] ?? ''), $matches);
                foreach ($matches[0] as $id) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function firstHeaderId(array $message): ?string
    {
        return $this->replyHeaderIds($message)[0] ?? null;
    }
}
```

- [ ] **Step 4: Schedule the command**

Append to `routes/console.php`:

```php
Schedule::command('emails:sync-replies')
    ->everyThreeMinutes()
    ->withoutOverlapping()
    ->runInBackground();
```

- [ ] **Step 5: Run to verify pass**

Run: `vendor/bin/sail artisan test --compact tests/Feature/SyncEmailRepliesTest.php`
Expected: PASS (all five tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Console/Commands/SyncEmailReplies.php routes/console.php tests/Feature/SyncEmailRepliesTest.php
git commit -m "feat: emails:sync-replies command + schedule"
```

---

## Task 7: Controller, request, routes

**Files:**
- Create: `app/Http/Requests/SendWorkOrderEmailRequest.php`
- Create: `app/Http/Controllers/WorkOrderEmailController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/WorkOrderEmailControllerTest.php`

**Interfaces:**
- Consumes: `WorkOrderEmailSender::sendVendorEmail(...)`, `EmailMessage`, `EmailAttachment`.
- Produces: routes `work_order.email.index` (GET `/work_orders/{workOrder}/emails`), `work_order.email.send` (POST same path), `work_order.email.attachment` (GET `/email-attachments/{attachment}/download`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/WorkOrderEmailControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EmailMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkOrderEmailControllerTest extends TestCase
{
    use RefreshDatabase;

    private function assignedVendor(WorkOrder $workOrder, string $email = 'abc@example.com'): Vendor
    {
        $vendor = Vendor::query()->create(['name' => 'ABC', 'email' => $email, 'is_active' => true]);
        $workOrder->vendors()->attach($vendor->id);

        return $vendor;
    }

    public function test_index_returns_only_the_requested_vendor_thread(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $vendorA = $this->assignedVendor($workOrder, 'a@example.com');
        $vendorB = $this->assignedVendor($workOrder, 'b@example.com');

        EmailMessage::factory()->create(['work_order_id' => $workOrder->id, 'vendor_id' => $vendorA->id, 'subject' => 'A msg']);
        EmailMessage::factory()->create(['work_order_id' => $workOrder->id, 'vendor_id' => $vendorB->id, 'subject' => 'B msg']);

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('work_order.email.index', $workOrder).'?vendor_id='.$vendorA->id);

        $response->assertOk();
        $subjects = collect($response->json('vendor_emails'))->pluck('subject');
        $this->assertTrue($subjects->contains('A msg'));
        $this->assertFalse($subjects->contains('B msg'));
    }

    public function test_store_sends_and_persists_outbound_email(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response(['id' => 'GID', 'internetMessageId' => '<x@x>', 'conversationId' => 'CID']),
        ]);
        Storage::fake('local');

        $workOrder = WorkOrder::factory()->create(['work_order_no' => 777]);
        $vendor = $this->assignedVendor($workOrder);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('work_order.email.send', $workOrder), [
                'vendor_id' => $vendor->id,
                'subject' => 'Following up',
                'body' => '<p>Any update?</p>',
                'attachments' => [UploadedFile::fake()->create('note.pdf', 5, 'application/pdf')],
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('email_messages', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'direction' => 'outbound',
            'correlation_tag' => 'TX-777-'.$vendor->id,
        ]);
    }

    public function test_store_requires_subject_and_body(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $vendor = $this->assignedVendor($workOrder);

        $this->actingAs(User::factory()->create())
            ->post(route('work_order.email.send', $workOrder), ['vendor_id' => $vendor->id])
            ->assertSessionHasErrors(['subject', 'body']);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/sail artisan test --compact tests/Feature/WorkOrderEmailControllerTest.php`
Expected: FAIL (route not defined).

- [ ] **Step 3: Create the form request**

`app/Http/Requests/SendWorkOrderEmailRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendWorkOrderEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240'],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

`app/Http/Controllers/WorkOrderEmailController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendWorkOrderEmailRequest;
use App\Models\EmailAttachment;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\WorkOrderEmailSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkOrderEmailController extends Controller
{
    public function __construct(private WorkOrderEmailSender $sender) {}

    public function index(WorkOrder $workOrder, Request $request): JsonResponse
    {
        $emails = $workOrder->emailMessages()
            ->when(
                $request->filled('vendor_id'),
                fn ($q) => $q->where('vendor_id', $request->integer('vendor_id')),
            )
            ->with('attachments')
            ->orderBy('emailed_at')
            ->get();

        return response()->json(['vendor_emails' => $emails], 200);
    }

    public function store(SendWorkOrderEmailRequest $request, WorkOrder $workOrder): RedirectResponse
    {
        $data = $request->validated();
        $vendor = Vendor::findOrFail($data['vendor_id']);

        $this->sender->sendVendorEmail(
            workOrder: $workOrder,
            vendor: $vendor,
            subject: $data['subject'],
            html: $data['body'],
            files: $request->file('attachments', []),
            sentBy: $request->user(),
        );

        return redirect()->back();
    }

    public function download(EmailAttachment $attachment): StreamedResponse
    {
        return Storage::disk('local')->download($attachment->path, $attachment->filename);
    }
}
```

- [ ] **Step 5: Register the routes**

In `routes/web.php`, inside the authenticated group where `work_order.conversation.send` is defined, add:

```php
Route::get('/work_orders/{workOrder}/emails', [WorkOrderEmailController::class, 'index'])->name('work_order.email.index');
Route::post('/work_orders/{workOrder}/emails', [WorkOrderEmailController::class, 'store'])->name('work_order.email.send');
Route::get('/email-attachments/{attachment}/download', [WorkOrderEmailController::class, 'download'])->name('work_order.email.attachment');
```

Add `use App\Http\Controllers\WorkOrderEmailController;` to the top of `routes/web.php`.

- [ ] **Step 6: Run to verify pass**

Run: `vendor/bin/sail artisan test --compact tests/Feature/WorkOrderEmailControllerTest.php`
Expected: PASS (all three tests).

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http routes/web.php tests/Feature/WorkOrderEmailControllerTest.php
git commit -m "feat: WorkOrderEmailController (index/store/download) + routes"
```

---

## Task 8: Tiptap RichTextEditor component

**Files:**
- Modify: `package.json` (via npm install)
- Create: `resources/js/Components/RichTextEditor.vue`

**Interfaces:**
- Produces: `RichTextEditor.vue` — a `v-model`-compatible editor (`modelValue: String`, emits `update:modelValue` with HTML). Toolbar: bold, italic, underline, bullet list, ordered list, link.

Note: the project has no JS test harness; verify via `npm run build` and manual UI check.

- [ ] **Step 1: Install Tiptap**

Run: `vendor/bin/sail npm install @tiptap/vue-3 @tiptap/starter-kit @tiptap/extension-underline @tiptap/extension-link`
Expected: packages added to `package.json` dependencies.

- [ ] **Step 2: Create the component**

`resources/js/Components/RichTextEditor.vue`:

```vue
<template>
    <div class="rounded-md border border-gray-300">
        <div
            v-if="editor"
            class="flex flex-wrap gap-1 border-b border-gray-200 bg-gray-50 p-1"
        >
            <button type="button" class="rounded px-2 py-1 text-sm hover:bg-gray-200"
                :class="{ 'bg-gray-300': editor.isActive('bold') }"
                @click="editor.chain().focus().toggleBold().run()">B</button>
            <button type="button" class="rounded px-2 py-1 text-sm italic hover:bg-gray-200"
                :class="{ 'bg-gray-300': editor.isActive('italic') }"
                @click="editor.chain().focus().toggleItalic().run()">I</button>
            <button type="button" class="rounded px-2 py-1 text-sm underline hover:bg-gray-200"
                :class="{ 'bg-gray-300': editor.isActive('underline') }"
                @click="editor.chain().focus().toggleUnderline().run()">U</button>
            <button type="button" class="rounded px-2 py-1 text-sm hover:bg-gray-200"
                :class="{ 'bg-gray-300': editor.isActive('bulletList') }"
                @click="editor.chain().focus().toggleBulletList().run()">• List</button>
            <button type="button" class="rounded px-2 py-1 text-sm hover:bg-gray-200"
                :class="{ 'bg-gray-300': editor.isActive('orderedList') }"
                @click="editor.chain().focus().toggleOrderedList().run()">1. List</button>
            <button type="button" class="rounded px-2 py-1 text-sm hover:bg-gray-200"
                @click="setLink">Link</button>
        </div>
        <EditorContent :editor="editor" class="prose max-w-none p-3 text-sm focus:outline-none" />
    </div>
</template>

<script setup>
import { watch } from "vue";
import { useEditor, EditorContent } from "@tiptap/vue-3";
import StarterKit from "@tiptap/starter-kit";
import Underline from "@tiptap/extension-underline";
import Link from "@tiptap/extension-link";

const props = defineProps({
    modelValue: { type: String, default: "" },
});
const emit = defineEmits(["update:modelValue"]);

const editor = useEditor({
    content: props.modelValue,
    extensions: [
        StarterKit,
        Underline,
        Link.configure({ openOnClick: false }),
    ],
    onUpdate: ({ editor }) => {
        emit("update:modelValue", editor.getHTML());
    },
});

const setLink = () => {
    const url = window.prompt("Enter URL (https://...)");
    if (url) {
        editor.value.chain().focus().setLink({ href: url }).run();
    }
};

// Keep the editor in sync when the parent resets the model (e.g. after send).
watch(
    () => props.modelValue,
    (value) => {
        if (editor.value && editor.value.getHTML() !== value) {
            editor.value.commands.setContent(value || "", false);
        }
    },
);
</script>
```

Note: `window.prompt` is acceptable here (a browser prompt, not a JS `alert`/`confirm` modal triggered by automation) — it is user-initiated in the real UI.

- [ ] **Step 3: Build to verify it compiles**

Run: `vendor/bin/sail npm run build`
Expected: build succeeds with no unresolved-import errors for the tiptap packages.

- [ ] **Step 4: Commit**

```bash
git add package.json package-lock.json resources/js/Components/RichTextEditor.vue
git commit -m "feat: Tiptap RichTextEditor component"
```

---

## Task 9: VendorEmail thread partial

**Files:**
- Create: `resources/js/Pages/WorkOrder/Partials/VendorEmail.vue`

**Interfaces:**
- Consumes: `RichTextEditor.vue`; route `work_order.email.send`; the `vendor_emails` array shape from `work_order.email.index`.
- Props: `workOrder: Object`, `vendorEmails: Array`, `vendorId: Number`. Emits `update-vendor-email` after a successful send.

- [ ] **Step 1: Create the partial**

`resources/js/Pages/WorkOrder/Partials/VendorEmail.vue`:

```vue
<template>
    <div class="flex h-full flex-col">
        <div class="flex-1 space-y-3 overflow-y-auto p-2">
            <p v-if="!vendorEmails.length" class="py-8 text-center text-sm text-gray-400">
                No emails yet.
            </p>

            <div
                v-for="email in vendorEmails"
                :key="email.id"
                class="rounded-lg border p-3"
                :class="email.direction === 'outbound'
                    ? 'ml-8 border-blue-200 bg-blue-50'
                    : 'mr-8 border-gray-200 bg-gray-50'"
            >
                <div class="mb-1 flex items-center justify-between text-xs text-gray-500">
                    <span class="font-semibold">
                        {{ email.direction === 'outbound' ? 'Sent' : 'Received' }}
                        · {{ email.direction === 'outbound' ? email.to_email : email.from_email }}
                    </span>
                    <span>{{ formatDate(email.emailed_at) }}</span>
                </div>
                <div class="mb-1 text-sm font-medium">{{ email.subject }}</div>
                <div class="prose max-w-none text-sm" v-html="email.body_html || email.body_text"></div>

                <div v-if="email.attachments && email.attachments.length" class="mt-2 space-y-1">
                    <a
                        v-for="att in email.attachments"
                        :key="att.id"
                        :href="route('work_order.email.attachment', att.id)"
                        class="block text-xs text-blue-600 underline"
                    >
                        {{ att.filename }}
                    </a>
                </div>
                <p
                    v-else-if="email.direction === 'inbound' && email.has_attachments"
                    class="mt-2 text-xs italic text-gray-500"
                >
                    Attachments couldn't be retrieved — check the mailbox.
                </p>
            </div>
        </div>

        <form class="border-t p-2" @submit.prevent="send">
            <input
                v-model="subject"
                type="text"
                placeholder="Subject"
                class="mb-2 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
            />
            <RichTextEditor v-model="body" />
            <div class="mt-2 flex items-center justify-between">
                <input type="file" multiple class="text-xs" @change="onFiles" />
                <button
                    type="submit"
                    :disabled="sending"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-50"
                >
                    {{ sending ? "Sending..." : "Send Email" }}
                </button>
            </div>
        </form>
    </div>
</template>

<script setup>
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import RichTextEditor from "@/Components/RichTextEditor.vue";

const props = defineProps({
    workOrder: { type: Object, required: true },
    vendorEmails: { type: Array, default: () => [] },
    vendorId: { type: Number, required: true },
});
const emit = defineEmits(["update-vendor-email"]);

const subject = ref("");
const body = ref("");
const files = ref([]);
const sending = ref(false);

const onFiles = (event) => {
    files.value = Array.from(event.target.files || []);
};

const formatDate = (value) => (value ? new Date(value).toLocaleString() : "");

const send = () => {
    if (!subject.value.trim() || !body.value.trim()) {
        return;
    }
    sending.value = true;

    const formData = new FormData();
    formData.append("vendor_id", props.vendorId);
    formData.append("subject", subject.value);
    formData.append("body", body.value);
    files.value.forEach((file) => formData.append("attachments[]", file));

    router.post(route("work_order.email.send", props.workOrder.id), formData, {
        preserveScroll: true,
        onSuccess: () => {
            subject.value = "";
            body.value = "";
            files.value = [];
            emit("update-vendor-email");
        },
        onFinish: () => {
            sending.value = false;
        },
    });
};
</script>
```

- [ ] **Step 2: Build to verify it compiles**

Run: `vendor/bin/sail npm run build`
Expected: build succeeds.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/WorkOrder/Partials/VendorEmail.vue
git commit -m "feat: VendorEmail thread + compose partial"
```

---

## Task 10: Wire the Emails tab into Show.vue

**Files:**
- Modify: `resources/js/Pages/WorkOrder/Show.vue`

**Interfaces:**
- Consumes: `VendorEmail.vue`, route `work_order.email.index`.

- [ ] **Step 1: Import the partial**

Near the other partial imports (top `<script setup>`, around line 10-23), add:

```js
import VendorEmail from "./Partials/VendorEmail.vue";
```

- [ ] **Step 2: Add the tab button**

In the `tabButtons` array, add this entry right after the `vendor_conversation` entry (the one with `requires: ["admin", "woc"]`):

```js
{
    name: "vendor_email",
    tooltip: "Vendor Email",
    icon: "@",
    requires: ["admin", "woc"],
},
```

- [ ] **Step 3: Add the state + fetch method**

Near the other conversation refs (around line 240-244), add:

```js
const vendorEmails = ref([]);
```

Near the other fetch methods (e.g. after `fetchVendorConversation`, ~line 302-315), add:

```js
const selectedVendorId = ref(null);

const fetchVendorEmails = async () => {
    // Default to the first assigned vendor on the work order.
    const firstVendor = workOrderForm.vendors?.[0];
    selectedVendorId.value = firstVendor ? firstVendor.id : null;

    const params = selectedVendorId.value
        ? { vendor_id: selectedVendorId.value }
        : {};

    const res = await axios.get(
        route("work_order.email.index", workOrderForm.id),
        { params },
    );
    vendorEmails.value = res.data.vendor_emails;
};
```

(If `workOrderForm.vendors` is not present on this page's form, use the same source the `vendor_conversation` tab uses to resolve the current vendor — check how `VendorConversation` receives its vendor and mirror it. The controller `index` returns all work-order emails when `vendor_id` is omitted, so a null vendor still renders a usable thread.)

- [ ] **Step 4: Add the switchTab branch**

In `switchTab`, add:

```js
if (tabName === "vendor_email") fetchVendorEmails();
```

- [ ] **Step 5: Render the partial**

Find where `<VendorConversation ... />` is rendered in the template and add, following the same `v-if="activeTab === '...'"` pattern:

```vue
<VendorEmail
    v-if="activeTab === 'vendor_email'"
    :work-order="workOrderForm"
    :vendor-emails="vendorEmails"
    :vendor-id="selectedVendorId"
    @update-vendor-email="fetchVendorEmails"
/>
```

- [ ] **Step 6: Build + manual verification**

Run: `vendor/bin/sail npm run build`
Expected: build succeeds.

Manual check (dev): open a work order with an assigned vendor as an admin/woc user, click the Vendor Email (`@`) tab, confirm the thread loads, compose a test email (Graph will attempt a real send — use a safe test vendor address or a mailbox that no-ops), and confirm a new outbound row appears after refetch.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/WorkOrder/Show.vue
git commit -m "feat: Emails tab on work order page"
```

---

## Final verification

- [ ] **Run the full email test suite**

Run: `vendor/bin/sail artisan test --compact --filter="Email|SyncEmailReplies|VendorAssignment"`
Expected: all PASS.

- [ ] **Run the broader suite to check for regressions**

Run: `vendor/bin/sail artisan test --compact`
Expected: no new failures introduced by these changes.

- [ ] **Final Pint pass**

Run: `vendor/bin/sail bin pint --dirty --format agent`
