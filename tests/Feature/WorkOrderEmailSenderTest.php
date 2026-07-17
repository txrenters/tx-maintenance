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
