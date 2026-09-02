<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessageJob;
use App\Jobs\SendVendorWorkOrderInformation;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\Tenants;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use App\Services\WorkOrderInformationPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VendorWorkOrderInformationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::forget('microsoft.graph.token');
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*/messages/*/send' => Http::response([], 202),
            'https://graph.microsoft.com/*/messages' => Http::response([
                'id' => 'TEST_GRAPH_ID',
                'internetMessageId' => '<test@texasrenters.com>',
                'conversationId' => 'TEST_CONVERSATION_ID',
            ]),
        ]);
    }

    private function makeVendor(array $overrides = [], ?string $userPhone = null): Vendor
    {
        $user = User::factory()->create($userPhone ? ['phone' => $userPhone] : []);

        return Vendor::query()->create(array_merge([
            'propertyware_id' => 'V-'.fake()->unique()->numberBetween(1, 100000),
            'name' => 'Southwinds Electric LLC',
            'vendor_type' => 'Electrical',
            'is_active' => true,
            'email' => 'vendor@example.com',
            'user_id' => $user->id,
        ], $overrides));
    }

    private function makeOwner(array $overrides = []): Owner
    {
        $user = User::factory()->create();

        return Owner::query()->create(array_merge([
            'first_name' => 'Keith',
            'last_name' => 'Howard',
            'name' => 'Russell Keith Howard Jr',
            'email' => 'owner@example.com',
            'phone' => '7135030427',
            'percentage_ownership' => 100,
            'user_id' => $user->id,
        ], $overrides));
    }

    public function test_pdf_renders_to_valid_pdf_bytes(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 4377411585,
            'work_order_no' => 43339,
            'description' => 'The garage plug is tripped and will not reset.',
        ]);

        $bytes = app(WorkOrderInformationPdf::class)->render($workOrder);

        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function test_pdf_only_lists_the_recipient_vendor(): void
    {
        $recipient = $this->makeVendor(['name' => 'Alpha Electric LLC']);
        $coAssigned = $this->makeVendor(['name' => 'Beta Plumbing LLC']);

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411585]);
        $workOrder->vendors()->attach([$recipient->id, $coAssigned->id]);

        // A vendor must never learn who else is assigned to their work order.
        $vendors = app(WorkOrderInformationPdf::class)
            ->vendorsToShow($workOrder->fresh(), $recipient);

        $this->assertCount(1, $vendors);
        $this->assertSame($recipient->id, $vendors->first()->id);
    }

    public function test_job_emails_vendor_and_uploads_pdf_to_propertyware(): void
    {
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 987654321], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 987654321], 200),
        ]);

        $vendor = $this->makeVendor(['email' => 'vendor@example.com']);
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411585, 'work_order_no' => 43339]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-abc']);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        Mail::assertNothingSent();
        $this->assertDatabaseHas('email_messages', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'direction' => 'outbound',
            'from_email' => 'workorders@texasrenters.com',
            'to_email' => $vendor->email,
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.propertyware.com/pw/api/rest/v1/docs'
            && $request->method() === 'POST');

        // The uploaded PDF is recorded locally so it shows in the Attachments tab.
        $this->assertDatabaseHas('work_order_documents', [
            'work_order_id' => $workOrder->id,
            'propertyware_id' => 987654321,
            'file_name' => 'Work Order Information.pdf',
            'file_type' => 'application/pdf',
        ]);
    }

    public function test_job_is_idempotent_and_never_notifies_the_vendor_twice(): void
    {
        Bus::fake();
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 987654321], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 987654321], 200),
        ]);
        config(['services.twilio.maintenance_number' => '+15550001111']);
        config(['services.twilio.owner_assignment_sms' => true]);

        $vendor = $this->makeVendor(['email' => 'vendor@example.com'], '3255550101');
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411585, 'work_order_no' => 43339]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-abc']);

        // A double dispatch (or a retry after a worker timeout) must not re-notify.
        foreach (range(1, 2) as $ignored) {
            (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
                ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));
        }

        Mail::assertNothingSent();
        $this->assertDatabaseCount('email_messages', 1);
        $this->assertDatabaseCount('work_order_conversations', 1);
        Bus::assertDispatchedTimes(SendConversationMessageJob::class, 1);
    }

    public function test_job_uploads_to_propertyware_even_without_vendor_email(): void
    {
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-9'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-9'], 200),
        ]);

        $vendor = $this->makeVendor(['email' => null]);
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411585]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-xyz']);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        Mail::assertNothingSent();
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.propertyware.com/pw/api/rest/v1/docs')
            && $request->method() === 'POST');
    }

    public function test_job_texts_vendor_and_logs_woc_conversation(): void
    {
        Bus::fake();          // keep the nested Twilio job from actually sending
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-77'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-77'], 200),
        ]);

        // No coordinator on the work order, so the WOC number falls back to config.
        config(['services.twilio.maintenance_number' => '+15550001111']);
        config(['services.twilio.owner_assignment_sms' => true]);

        $vendor = $this->makeVendor(['email' => 'vendor@example.com'], '3255550101');
        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 4377411585,
            'work_order_no' => 43339,
            'priority' => 'Med',
        ]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-abc']);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        // Saved into the WOC↔Vendor thread, sent as the WOC (its number), to the vendor.
        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'conversation_type' => 'vendor',
            'sender_number' => '+15550001111',
            'receiver_number' => '+13255550101',
            'read_by_vendor' => false,
        ]);

        Bus::assertDispatched(SendConversationMessageJob::class);
    }

    public function test_job_does_not_text_vendor_without_a_phone(): void
    {
        Bus::fake();
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-1'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-1'], 200),
        ]);

        $vendor = $this->makeVendor(['email' => 'vendor@example.com']); // user has no phone
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411585]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok']);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $this->assertDatabaseCount('work_order_conversations', 0);
        Bus::assertNotDispatched(SendConversationMessageJob::class);
    }

    public function test_job_notifies_primary_owner_and_logs_owner_conversation(): void
    {
        Bus::fake();
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-o'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-o'], 200),
        ]);
        config(['services.twilio.maintenance_number' => '+15550001111']);
        config(['services.twilio.owner_assignment_sms' => true]);

        $vendor = $this->makeVendor(['name' => 'Southwinds Electric LLC'], '3255550101');
        $owner = $this->makeOwner(['name' => 'Russell Keith Howard Jr', 'phone' => '7135030427']);

        // An occupied home as PropertyWare imports it: the current lease rides
        // along on the work order.
        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 4377411585,
            'work_order_no' => 43339,
            'lease_id' => 555001,
        ]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-abc']);
        $workOrder->owners()->attach($owner->id);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        // Logged on the owner thread, sent as the WOC (its number), to the owner.
        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'sender_number' => '+15550001111',
            'receiver_number' => '+17135030427',
        ]);

        $ownerMessage = Conversation::where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'owner')
            ->value('message');

        $this->assertStringContainsString('Russell Keith Howard Jr', $ownerMessage);
        $this->assertStringContainsString('Southwinds Electric LLC', $ownerMessage);
        $this->assertStringContainsString('#43339', $ownerMessage);
        $this->assertStringContainsString('contact the tenant directly', $ownerMessage);

        Bus::assertDispatched(SendConversationMessageJob::class);
    }

    public function test_vacant_toggle_owner_text_drops_the_tenant_line_but_still_sends(): void
    {
        Bus::fake();
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-vac'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-vac'], 200),
        ]);
        config(['services.twilio.maintenance_number' => '+15550001111']);
        config(['services.twilio.owner_assignment_sms' => true]);

        $vendor = $this->makeVendor(['name' => 'Southwinds Electric LLC'], '3255550101');
        $owner = $this->makeOwner(['name' => 'Russell Keith Howard Jr', 'phone' => '7135030427']);

        // The WOC's "Vacant" toggle: no tenant to contact, so the owner still
        // gets the assignment text but without the "contact the tenant" line.
        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 4377411585,
            'work_order_no' => 43355,
            'skip_automated_tasks' => true,
        ]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-vac']);
        $workOrder->owners()->attach($owner->id);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $ownerMessage = Conversation::where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'owner')
            ->value('message');

        $this->assertNotNull($ownerMessage);
        $this->assertStringNotContainsString('contact the tenant directly', $ownerMessage);
        $this->assertStringContainsString('Southwinds Electric LLC', $ownerMessage);
        $this->assertStringContainsString('Thank you!', $ownerMessage);

        Bus::assertDispatched(SendConversationMessageJob::class);
    }

    public function test_no_lease_on_file_owner_text_drops_the_tenant_line_but_still_sends(): void
    {
        Bus::fake();
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-nl'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-nl'], 200),
        ]);
        config(['services.twilio.maintenance_number' => '+15550001111']);
        config(['services.twilio.owner_assignment_sms' => true]);

        $vendor = $this->makeVendor(['name' => 'Oops Steam Cleaning LLC'], '2818220561');
        $owner = $this->makeOwner(['name' => 'Rubislaw Properties LLC', 'phone' => '7138585158']);

        // WO#44032: a PropertyWare work order on a vacant home arrives with no
        // lease and no tenant roster, and nobody flips the Vacant toggle or
        // types it as a turnover. The owner must still hear who was assigned,
        // just without "the vendor will contact the tenant".
        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 4403200001,
            'work_order_no' => 44032,
            'lease_id' => null,
            'skip_automated_tasks' => false,
            'type' => 'Service Request',
            'category' => 'carpet Steam clean',
        ]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-nl']);
        $workOrder->owners()->attach($owner->id);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $ownerMessage = Conversation::where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'owner')
            ->value('message');

        $this->assertNotNull($ownerMessage);
        $this->assertStringNotContainsString('contact the tenant directly', $ownerMessage);
        $this->assertStringContainsString('Oops Steam Cleaning LLC', $ownerMessage);
        $this->assertStringContainsString('#44032', $ownerMessage);
        $this->assertStringContainsString('Thank you!', $ownerMessage);

        // No tenant text either: nobody lives there.
        $this->assertDatabaseMissing('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'tenant',
        ]);

        Bus::assertDispatched(SendConversationMessageJob::class);
    }

    public function test_turnover_vendor_email_sends_from_the_thmp_mailbox(): void
    {
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 987654322], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 987654322], 200),
        ]);
        config([
            'services.microsoft.mailbox' => 'workorders@texasrenters.com',
            'services.microsoft.turnover_mailbox' => 'thmp@texasrenters.com',
        ]);

        $vendor = $this->makeVendor(['email' => 'vendor@example.com']);

        // Turnover carried in the category field (type differs) must behave the
        // same — PropertyWare data is inconsistent about which field holds it.
        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 4377411585,
            'work_order_no' => 43357,
            'type' => 'Service Request',
            'category' => 'Turnover',
        ]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-thmp']);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        // The THMP coordinator owns turnover vendor comms — email goes out from
        // (and replies land in) her mailbox instead of the shared one.
        $this->assertDatabaseHas('email_messages', [
            'work_order_id' => $workOrder->id,
            'direction' => 'outbound',
            'from_email' => 'thmp@texasrenters.com',
        ]);
    }

    public function test_turnover_owner_text_drops_the_tenant_line_but_still_sends(): void
    {
        Bus::fake();
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-t'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-t'], 200),
        ]);
        config(['services.twilio.maintenance_number' => '+15550001111']);
        config(['services.twilio.owner_assignment_sms' => true]);

        $vendor = $this->makeVendor(['phone' => '3255550101'], '3255550101');
        $owner = $this->makeOwner(['phone' => '7135030427']);

        // Turnover properties are vacant — the owner still gets the
        // vendor-assignment text but without the "contact the tenant" line.
        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 4377411585,
            'work_order_no' => 43356,
            'type' => 'Turnover',
        ]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-turn']);
        $workOrder->owners()->attach($owner->id);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $ownerMessage = Conversation::where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'owner')
            ->value('message');

        $this->assertNotNull($ownerMessage);
        $this->assertStringNotContainsString('contact the tenant directly', $ownerMessage);
        $this->assertStringContainsString('Thank you!', $ownerMessage);
        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'vendor',
        ]);
    }

    public function test_owner_notification_reaches_every_owner_on_the_work_order(): void
    {
        Bus::fake();
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-o2'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-o2'], 200),
        ]);
        config(['services.twilio.maintenance_number' => '+15550001111']);
        config(['services.twilio.owner_assignment_sms' => true]);

        $vendor = $this->makeVendor([], '3255550101');

        // The real owner (100%) plus a 0% co-owner: BOTH must be texted.
        $realOwner = $this->makeOwner(['name' => 'Xiaochen Feng', 'phone' => '2145551234', 'percentage_ownership' => 100]);
        $coOwner = $this->makeOwner(['name' => 'Tyssen Global Management LLC', 'phone' => '3465550000', 'percentage_ownership' => 0]);

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411585, 'work_order_no' => 43340]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok']);
        $workOrder->owners()->attach([$coOwner->id, $realOwner->id]);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'receiver_number' => '+12145551234',
        ]);
        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'receiver_number' => '+13465550000',
        ]);
    }

    public function test_owners_sharing_one_phone_number_get_a_single_text(): void
    {
        Bus::fake();
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-o3'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-o3'], 200),
        ]);
        config(['services.twilio.maintenance_number' => '+15550001111']);
        config(['services.twilio.owner_assignment_sms' => true]);

        $vendor = $this->makeVendor([], '3255550101');

        // A couple sharing one number, stored in different formats: one text only.
        $husband = $this->makeOwner(['name' => 'Dewayne Lanier', 'phone' => '7138282297', 'percentage_ownership' => 0]);
        $wife = $this->makeOwner(['name' => 'Elaine Lanier', 'phone' => '(713) 828-2297', 'percentage_ownership' => 0]);

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411586, 'work_order_no' => 43341]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok']);
        $workOrder->owners()->attach([$husband->id, $wife->id]);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $this->assertSame(1, DB::table('work_order_conversations')
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'owner')
            ->where('receiver_number', '+17138282297')
            ->count());
    }

    public function test_zero_percent_owner_is_texted_when_the_llc_primary_owner_has_no_phone(): void
    {
        Bus::fake();
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-o4'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-o4'], 200),
        ]);
        config(['services.twilio.maintenance_number' => '+15550001111']);
        config(['services.twilio.owner_assignment_sms' => true]);

        $vendor = $this->makeVendor([], '3255550101');

        // LLC holds 100% with no phone; the human behind it sits at 0%. The old
        // primary-owner-only logic texted nobody on these work orders.
        $llc = $this->makeOwner(['name' => 'Series 4 Lanier Family LLC', 'phone' => '', 'percentage_ownership' => 100]);
        $human = $this->makeOwner(['name' => 'Dewayne Lanier', 'phone' => '7138282297', 'percentage_ownership' => 0]);

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411587, 'work_order_no' => 43342]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok']);
        $workOrder->owners()->attach([$llc->id, $human->id]);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $this->assertDatabaseHas('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'receiver_number' => '+17138282297',
        ]);
    }

    public function test_owner_is_not_texted_when_the_gate_is_off(): void
    {
        Bus::fake();
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-off'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-off'], 200),
        ]);
        config(['services.twilio.maintenance_number' => '+15550001111']);
        config(['services.twilio.owner_assignment_sms' => false]);

        $vendor = $this->makeVendor([], '3255550101');
        $owner = $this->makeOwner(['phone' => '7135030427']);
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411585, 'work_order_no' => 43341]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-off']);
        $workOrder->owners()->attach($owner->id);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $this->assertDatabaseMissing('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
        ]);
    }

    public function test_owner_message_never_uses_the_tenant_street_address(): void
    {
        Bus::fake();
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-a'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-a'], 200),
        ]);
        config(['services.twilio.maintenance_number' => '+15550001111']);
        config(['services.twilio.owner_assignment_sms' => true]);

        $vendor = $this->makeVendor([], '3255550101');
        $owner = $this->makeOwner();
        $tenant = Tenants::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Tenant',
            'address' => '3326 Jane Way',
            'user_id' => User::factory()->create()->id,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'propertyware_id' => 4377411586,
            'work_order_no' => 43402,
            'tenant_id' => $tenant->id,
        ]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-xyz']);
        $workOrder->owners()->attach($owner->id);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $ownerMessage = Conversation::where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'owner')
            ->value('message');

        $this->assertStringNotContainsString('3326 Jane Way', $ownerMessage);
        $this->assertStringContainsString('take care of the repairs at the property', $ownerMessage);
    }

    public function test_owner_is_not_texted_when_the_owner_vendor_placeholder_is_assigned(): void
    {
        Bus::fake();
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-ov'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-ov'], 200),
        ]);
        config(['services.twilio.maintenance_number' => '+15550001111']);
        config(['services.twilio.owner_assignment_sms' => true]);

        // "OWNER VENDOR" = the owner handles the repair themselves; telling the
        // owner we assigned them makes no sense, so no owner text goes out.
        $vendor = $this->makeVendor(['name' => 'OWNER VENDOR', 'email' => null]);
        $owner = $this->makeOwner(['phone' => '7135030427']);

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411585, 'work_order_no' => 43342]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok-ov']);
        $workOrder->owners()->attach($owner->id);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $this->assertDatabaseMissing('work_order_conversations', [
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
        ]);
        Bus::assertNotDispatched(SendConversationMessageJob::class);
    }

    public function test_job_does_not_text_owner_without_a_phone(): void
    {
        Bus::fake();
        Mail::fake();
        Http::fake([
            'api.propertyware.com/pw/api/rest/v1/docs' => Http::response(['id' => 'doc-o3'], 200),
            'api.propertyware.com/pw/api/rest/v1/docs/*' => Http::response(['id' => 'doc-o3'], 200),
        ]);
        config(['services.twilio.maintenance_number' => '+15550001111']);
        config(['services.twilio.owner_assignment_sms' => true]);

        // Vendor has no phone either, so no vendor conversation is created; the
        // owner has no phone, so no owner conversation should be created.
        $vendor = $this->makeVendor(['email' => 'vendor@example.com']);
        $owner = $this->makeOwner(['phone' => null]);

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => 4377411585]);
        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'tok']);
        $workOrder->owners()->attach($owner->id);

        (new SendVendorWorkOrderInformation($workOrder->id, $vendor->id))
            ->handle(app(WorkOrderInformationPdf::class), app(PropertyWareService::class));

        $this->assertDatabaseCount('work_order_conversations', 0);
        Bus::assertNotDispatched(SendConversationMessageJob::class);
    }
}
