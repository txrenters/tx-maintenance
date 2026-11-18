<?php

namespace Tests\Feature;

use App\Models\Tenants;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDocuments;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Rotating a PropertyWare-synced photo. The bytes never leave PropertyWare, so
 * the turn is stored on the row and applied when the document is streamed.
 * The fake photo is 200x100, blue, with a red block in its top-left corner.
 */
class WorkOrderDocumentRotateTest extends TestCase
{
    use RefreshDatabase;

    private const RED = [255, 0, 0];

    private const BLUE = [0, 0, 255];

    private function photoBytes(): string
    {
        $image = imagecreatetruecolor(200, 100);
        imagefilledrectangle($image, 0, 0, 199, 99, imagecolorallocate($image, ...self::BLUE));
        imagefilledrectangle($image, 0, 0, 19, 19, imagecolorallocate($image, ...self::RED));

        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function photoDocument(?WorkOrder $workOrder = null, array $overrides = []): WorkOrderDocuments
    {
        return WorkOrderDocuments::create(array_merge([
            'work_order_id' => ($workOrder ?? WorkOrder::factory()->create())->id,
            'propertyware_id' => 8609103940,
            'file_name' => 'Receipt.png',
            'file_type' => 'image/png',
        ], $overrides));
    }

    private function rotate(WorkOrderDocuments $document, string $direction, ?User $as = null)
    {
        return $this->actingAs($as ?? User::factory()->create())
            ->from('/work-order')
            ->post(route('api.work_order_documents.rotate', $document), ['direction' => $direction]);
    }

    private function mockDownload(string $bytes, string $mime = 'image/png'): void
    {
        $this->mock(PropertyWareService::class, function (Mockery\MockInterface $mock) use ($bytes, $mime) {
            $mock->shouldReceive('downloadDocument')
                ->once()
                ->with(8609103940)
                ->andReturn(['content' => $bytes, 'mime' => $mime]);
        });
    }

    /**
     * @return array{int, int, int}
     */
    private function pixelAt(string $bytes, int $x, int $y): array
    {
        $image = imagecreatefromstring($bytes);
        $rgb = imagecolorat($image, $x, $y);
        imagedestroy($image);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    public function test_a_quarter_turn_is_remembered_on_the_document(): void
    {
        $document = $this->photoDocument();

        $response = $this->rotate($document, 'clockwise');

        $response->assertRedirect('/work-order');
        $response->assertSessionHasNoErrors();
        $this->assertSame(90, $document->fresh()->rotation);
    }

    public function test_turns_add_up_and_wrap_around(): void
    {
        $document = $this->photoDocument();

        $this->rotate($document, 'clockwise');
        $this->rotate($document->fresh(), 'clockwise');
        $this->assertSame(180, $document->fresh()->rotation);

        $this->rotate($document->fresh(), 'counterclockwise');
        $this->assertSame(90, $document->fresh()->rotation);

        $this->rotate($document->fresh(), 'counterclockwise');
        $this->assertSame(0, $document->fresh()->rotation);

        $this->rotate($document->fresh(), 'counterclockwise');
        $this->assertSame(270, $document->fresh()->rotation);
    }

    public function test_the_download_streams_the_photo_turned(): void
    {
        $document = $this->photoDocument(null, ['rotation' => 90]);
        $this->mockDownload($this->photoBytes());

        $response = $this->actingAs(User::factory()->create())
            ->get(route('api.work_order_documents.download', $document));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');

        $bytes = $response->getContent();
        $info = getimagesizefromstring($bytes);

        $this->assertSame([100, 200], [$info[0], $info[1]]);
        $this->assertSame('image/png', $info['mime']);
        $this->assertSame(self::RED, $this->pixelAt($bytes, 90, 10));
        $this->assertSame(self::BLUE, $this->pixelAt($bytes, 10, 10));
    }

    public function test_a_half_turn_keeps_the_size_but_moves_the_corner(): void
    {
        $document = $this->photoDocument(null, ['rotation' => 180]);
        $this->mockDownload($this->photoBytes());

        $bytes = $this->actingAs(User::factory()->create())
            ->get(route('api.work_order_documents.download', $document))
            ->getContent();

        $info = getimagesizefromstring($bytes);
        $this->assertSame([200, 100], [$info[0], $info[1]]);
        $this->assertSame(self::RED, $this->pixelAt($bytes, 190, 90));
        $this->assertSame(self::BLUE, $this->pixelAt($bytes, 10, 10));
    }

    public function test_an_unrotated_document_streams_the_original_bytes(): void
    {
        $document = $this->photoDocument();
        $original = $this->photoBytes();
        $this->mockDownload($original);

        $bytes = $this->actingAs(User::factory()->create())
            ->get(route('api.work_order_documents.download', $document))
            ->getContent();

        $this->assertSame($original, $bytes);
    }

    public function test_an_octet_stream_photo_is_still_turned_by_its_extension(): void
    {
        // PropertyWare reports octet-stream for most files; the extension decides.
        $document = $this->photoDocument(null, ['file_type' => 'application/octet-stream', 'rotation' => 90]);
        $this->mockDownload($this->photoBytes(), 'application/octet-stream');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('api.work_order_documents.download', $document));

        $response->assertHeader('Content-Type', 'image/png');
        $info = getimagesizefromstring($response->getContent());
        $this->assertSame([100, 200], [$info[0], $info[1]]);
    }

    public function test_undecodable_bytes_are_served_as_they_arrived(): void
    {
        $document = $this->photoDocument(null, ['rotation' => 90]);
        $this->mockDownload('not really a png');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('api.work_order_documents.download', $document));

        $response->assertOk();
        $this->assertSame('not really a png', $response->getContent());
    }

    public function test_a_pdf_cannot_be_rotated(): void
    {
        $document = $this->photoDocument(null, ['file_name' => 'Scope.pdf', 'file_type' => 'application/pdf']);

        $this->rotate($document, 'clockwise')->assertSessionHasErrors('direction');

        $this->assertSame(0, $document->fresh()->rotation);
    }

    public function test_an_unknown_direction_is_rejected(): void
    {
        $document = $this->photoDocument();

        $this->rotate($document, 'sideways')->assertSessionHasErrors('direction');

        $this->assertSame(0, $document->fresh()->rotation);
    }

    public function test_a_tenant_without_access_to_the_work_order_gets_404(): void
    {
        Role::findOrCreate('tenant', 'web');
        $tenantUser = User::factory()->create();
        $tenantUser->assignRole('tenant');
        Tenants::factory()->create([
            'user_id' => $tenantUser->id,
            'first_name' => 'Tess',
            'last_name' => 'Tenant',
        ]);

        $document = $this->photoDocument(WorkOrder::factory()->create(['tenant_id' => null]));

        $this->rotate($document, 'clockwise', $tenantUser)->assertNotFound();

        $this->assertSame(0, $document->fresh()->rotation);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $document = $this->photoDocument();

        $this->post(route('api.work_order_documents.rotate', $document), ['direction' => 'clockwise'])
            ->assertRedirect(route('login'));
    }

    public function test_the_attachments_tab_payload_carries_the_rotation(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $this->photoDocument($workOrder, ['rotation' => 270]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('api.attachments.show', $workOrder))
            ->assertOk()
            ->assertJsonPath('documents.0.rotation', 270);
    }
}
