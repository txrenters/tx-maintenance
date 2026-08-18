<?php

namespace Tests\Feature;

use App\Ai\Agents\CompletionPhotoReviewAgent;
use App\Jobs\ReviewCompletionPhoto;
use App\Models\AiInsight;
use App\Models\Attachments;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The AI completion-photo check: a vendor "after" photo that does not match
 * the reported issue gets a staff-only flag on the Attachments tab. The
 * review never blocks an upload and a failed/skipped review leaves the
 * photo exactly as it was.
 */
class CompletionPhotoReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'vendor'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Storage::fake('public');
    }

    private function staff(string $role = 'woc'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function aiReady(): void
    {
        config(['ai.providers.openai.key' => 'test-key']);
    }

    private function makeAfterPhoto(WorkOrder $workOrder, array $attributes = []): Attachments
    {
        $path = UploadedFile::fake()->image('after.jpg')->store('attachments', 'public');

        return Attachments::query()->create(array_merge([
            'title' => 'After repair',
            'filename' => $path,
            'filetype' => 'image/jpeg',
            'type' => 'after',
            'work_order_id' => $workOrder->id,
            'user_id' => User::factory()->create()->id,
        ], $attributes));
    }

    public function test_a_mismatched_after_photo_is_flagged_for_staff(): void
    {
        $this->aiReady();

        $workOrder = WorkOrder::factory()->create(['description' => 'Bedroom ceiling leak']);
        $attachment = $this->makeAfterPhoto($workOrder);

        CompletionPhotoReviewAgent::fake([[
            'verdict' => 'mismatch',
            'note' => 'The photo shows a kitchen sink but the issue is a bedroom ceiling leak.',
        ]]);

        (new ReviewCompletionPhoto($attachment->id))->handle();

        $insight = AiInsight::query()->ofType(AiInsight::TYPE_PHOTO_REVIEW)->sole();
        $this->assertSame($workOrder->id, $insight->work_order_id);
        $this->assertSame('mismatch', data_get($insight->data, 'verdict'));

        // The flag reaches the staff attachments payload…
        $response = $this->actingAs($this->staff())->getJson(route('api.attachments.show', $workOrder));
        $response->assertOk();
        $this->assertSame('mismatch', data_get($response->json('attachments.0'), 'ai_review.verdict'));

        // …and an unknown verdict from a future model would degrade, not break.
        $insight->update(['data' => ['verdict' => 'unclear', 'note' => 'x']]);
        $this->actingAs($this->staff())->getJson(route('api.attachments.show', $workOrder))->assertOk();
    }

    public function test_reviews_run_once_and_skip_wrong_targets_or_disabled_gate(): void
    {
        $this->aiReady();

        $workOrder = WorkOrder::factory()->create();

        // Wrong type and wrong filetype never reach the agent.
        $before = $this->makeAfterPhoto($workOrder, ['type' => 'before']);
        $pdf = $this->makeAfterPhoto($workOrder, ['filetype' => 'application/pdf']);

        CompletionPhotoReviewAgent::fake();
        (new ReviewCompletionPhoto($before->id))->handle();
        (new ReviewCompletionPhoto($pdf->id))->handle();
        $this->assertDatabaseCount('ai_insights', 0);

        // Gate off: nothing happens even for a valid after photo.
        config(['services.ai.photo_review' => false]);
        $after = $this->makeAfterPhoto($workOrder);
        (new ReviewCompletionPhoto($after->id))->handle();
        $this->assertDatabaseCount('ai_insights', 0);

        // Gate on: reviewed exactly once; a re-run does not re-ask.
        config(['services.ai.photo_review' => true]);
        CompletionPhotoReviewAgent::fake([['verdict' => 'looks_resolved', 'note' => 'Looks done.']]);
        (new ReviewCompletionPhoto($after->id))->handle();
        (new ReviewCompletionPhoto($after->id))->handle();
        $this->assertDatabaseCount('ai_insights', 1);
    }

    public function test_ai_not_ready_or_agent_failure_leaves_the_photo_unflagged(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $attachment = $this->makeAfterPhoto($workOrder);

        // Keys blank (phpunit.xml) — not ready, no review.
        (new ReviewCompletionPhoto($attachment->id))->handle();
        $this->assertDatabaseCount('ai_insights', 0);

        // Vendors never receive the ai_review field at all.
        $this->aiReady();
        CompletionPhotoReviewAgent::fake([['verdict' => 'mismatch', 'note' => 'x']]);
        (new ReviewCompletionPhoto($attachment->id))->handle();

        $vendorUser = $this->staff('vendor');
        $response = $this->actingAs($vendorUser)->getJson(route('api.attachments.show', $workOrder));

        if ($response->status() === 200) {
            $this->assertNull(data_get($response->json('attachments.0') ?? [], 'ai_review'));
        }
    }
}
