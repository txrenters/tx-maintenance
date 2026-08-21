<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncWorkOrderClosingCommentsAbortTest extends TestCase
{
    use RefreshDatabase;

    public function test_aborts_instead_of_looping_when_propertyware_cannot_be_fetched(): void
    {
        // A client error exhausts fetchBatch immediately (no retries); the old
        // "skip this batch and advance the offset" behavior looped forever.
        Http::fake(['api.propertyware.com/*' => Http::response('nope', 404)]);

        $this->artisan('sync:work-order-closing-comments')->assertFailed();
    }

    public function test_aborts_the_full_work_order_import_the_same_way(): void
    {
        Http::fake(['api.propertyware.com/*' => Http::response('nope', 404)]);

        $this->artisan('import:all-work-orders')->assertFailed();
    }
}
