<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderModalMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_modal_meta_returns_supporting_lists(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(route('api.work_order_modal.meta'));

        $response->assertOk()
            ->assertJsonStructure([
                'categories',
                'vendors',
                'users',
                'service_status',
                'types',
            ]);
    }

    public function test_modal_meta_requires_authentication(): void
    {
        $this->getJson(route('api.work_order_modal.meta'))->assertUnauthorized();
    }
}
