<?php

namespace Tests\Feature;

use App\Models\Attachments;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Images\ThumbnailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Rotating a photo in the work order viewer. The test photo is 200x100 with a
 * red block in its top-left corner, so a turn is checked by where the block
 * ends up — not just by the swapped dimensions.
 */
class AttachmentRotateTest extends TestCase
{
    use RefreshDatabase;

    private const RED = [255, 0, 0];

    private const BLUE = [0, 0, 255];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * A 200x100 photo, blue with a red 20x20 block at the top-left, written
     * through the real thumbnail service so thumb_path is populated.
     */
    private function photo(string $format = 'png', string $type = 'attachment'): Attachments
    {
        $image = imagecreatetruecolor(200, 100);
        imagefilledrectangle($image, 0, 0, 199, 99, imagecolorallocate($image, ...self::BLUE));
        imagefilledrectangle($image, 0, 0, 19, 19, imagecolorallocate($image, ...self::RED));

        ob_start();
        $format === 'jpeg' ? imagejpeg($image, null, 100) : imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        $extension = $format === 'jpeg' ? 'jpg' : 'png';
        $path = 'attachments/original.'.$extension;
        Storage::disk('public')->put($path, $bytes);

        $attachment = Attachments::create([
            'title' => 'Receipt',
            'filename' => $path,
            'filetype' => 'image/'.$format,
            'type' => $type,
            'work_order_id' => WorkOrder::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
        ]);

        app(ThumbnailService::class)->generate($attachment);

        return $attachment->fresh();
    }

    private function rotate(Attachments $attachment, string $direction, ?User $as = null)
    {
        return $this->actingAs($as ?? User::factory()->create())
            ->from('/work-order')
            ->post(route('api.attachments.rotate', $attachment), ['direction' => $direction]);
    }

    /**
     * @return array{int, int, int}
     */
    private function pixelAt(string $path, int $x, int $y): array
    {
        $image = imagecreatefromstring(Storage::disk('public')->get($path));
        $rgb = imagecolorat($image, $x, $y);
        imagedestroy($image);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    /**
     * @return array{int, int}
     */
    private function sizeOf(string $path): array
    {
        $info = getimagesizefromstring(Storage::disk('public')->get($path));

        return [$info[0], $info[1]];
    }

    public function test_rotating_clockwise_moves_the_top_left_corner_to_the_top_right(): void
    {
        $attachment = $this->photo();
        $originalPath = $attachment->filename;

        $response = $this->rotate($attachment, 'clockwise');

        $response->assertRedirect('/work-order');
        $response->assertSessionHasNoErrors();

        $rotated = $attachment->fresh();

        $this->assertSame([100, 200], $this->sizeOf($rotated->filename));
        $this->assertSame(self::RED, $this->pixelAt($rotated->filename, 90, 10));
        $this->assertSame(self::BLUE, $this->pixelAt($rotated->filename, 10, 10));
        $this->assertSame(self::BLUE, $this->pixelAt($rotated->filename, 10, 190));

        $this->assertNotSame($originalPath, $rotated->filename);
        Storage::disk('public')->assertMissing($originalPath);
    }

    public function test_rotating_counterclockwise_moves_the_top_left_corner_to_the_bottom_left(): void
    {
        $attachment = $this->photo();

        $this->rotate($attachment, 'counterclockwise')->assertSessionHasNoErrors();

        $rotated = $attachment->fresh();

        $this->assertSame([100, 200], $this->sizeOf($rotated->filename));
        $this->assertSame(self::RED, $this->pixelAt($rotated->filename, 10, 190));
        $this->assertSame(self::BLUE, $this->pixelAt($rotated->filename, 10, 10));
        $this->assertSame(self::BLUE, $this->pixelAt($rotated->filename, 90, 10));
    }

    public function test_the_rotated_file_keeps_its_folder_extension_and_format(): void
    {
        $attachment = $this->photo('jpeg');

        $this->rotate($attachment, 'clockwise')->assertSessionHasNoErrors();

        $rotated = $attachment->fresh();

        $this->assertMatchesRegularExpression('#^attachments/[A-Za-z0-9]{40}\.jpg$#', $rotated->filename);
        $this->assertSame('image/jpeg', getimagesizefromstring(Storage::disk('public')->get($rotated->filename))['mime']);
        $this->assertSame([100, 200], $this->sizeOf($rotated->filename));
        $this->assertSame('image/jpeg', $rotated->filetype);
    }

    public function test_the_thumbnail_is_rebuilt_from_the_rotated_photo(): void
    {
        $attachment = $this->photo();
        $oldThumb = $attachment->thumb_path;

        $this->assertNotNull($oldThumb);
        $this->assertSame([200, 100], $this->sizeOf($oldThumb));

        $this->rotate($attachment, 'clockwise')->assertSessionHasNoErrors();

        $rotated = $attachment->fresh();

        $this->assertNotNull($rotated->thumb_path);
        $this->assertNotSame($oldThumb, $rotated->thumb_path);
        Storage::disk('public')->assertMissing($oldThumb);
        Storage::disk('public')->assertExists($rotated->thumb_path);
        $this->assertSame([100, 200], $this->sizeOf($rotated->thumb_path));
    }

    public function test_the_urls_change_so_nothing_serves_a_cached_sideways_copy(): void
    {
        $attachment = $this->photo();
        $oldUrl = $attachment->attachment_url;
        $oldThumbUrl = $attachment->thumbnail_url;

        $this->rotate($attachment, 'clockwise')->assertSessionHasNoErrors();

        $rotated = $attachment->fresh();

        $this->assertNotSame($oldUrl, $rotated->attachment_url);
        $this->assertNotSame($oldThumbUrl, $rotated->thumbnail_url);
    }

    public function test_two_quarter_turns_make_a_half_turn(): void
    {
        $attachment = $this->photo();

        $this->rotate($attachment, 'clockwise')->assertSessionHasNoErrors();
        $this->rotate($attachment->fresh(), 'clockwise')->assertSessionHasNoErrors();

        $rotated = $attachment->fresh();

        $this->assertSame([200, 100], $this->sizeOf($rotated->filename));
        $this->assertSame(self::RED, $this->pixelAt($rotated->filename, 190, 90));
        $this->assertSame(self::BLUE, $this->pixelAt($rotated->filename, 10, 10));
    }

    public function test_an_unknown_direction_is_rejected(): void
    {
        $attachment = $this->photo();
        $path = $attachment->filename;

        $this->rotate($attachment, 'upside-down')->assertSessionHasErrors('direction');

        $this->assertSame($path, $attachment->fresh()->filename);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_pdf_cannot_be_rotated(): void
    {
        Storage::disk('public')->put('attachments/scope.pdf', '%PDF-1.4 fake');

        $attachment = Attachments::create([
            'title' => 'Scope',
            'filename' => 'attachments/scope.pdf',
            'filetype' => 'application/pdf',
            'type' => 'attachment',
            'work_order_id' => WorkOrder::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->rotate($attachment, 'clockwise')->assertSessionHasErrors('direction');

        $this->assertSame('attachments/scope.pdf', $attachment->fresh()->filename);
        Storage::disk('public')->assertExists('attachments/scope.pdf');
    }

    public function test_a_photo_whose_file_is_missing_fails_without_touching_the_row(): void
    {
        $attachment = $this->photo();
        Storage::disk('public')->delete($attachment->filename);
        $path = $attachment->filename;
        $thumb = $attachment->thumb_path;

        $this->rotate($attachment, 'clockwise')->assertSessionHasErrors('direction');

        $fresh = $attachment->fresh();
        $this->assertSame($path, $fresh->filename);
        $this->assertSame($thumb, $fresh->thumb_path);
        Storage::disk('public')->assertExists($thumb);
    }

    public function test_a_vendor_cannot_rotate_another_users_photo(): void
    {
        Role::findOrCreate('vendor');

        $attachment = $this->photo();
        $path = $attachment->filename;
        $vendor = User::factory()->create();
        $vendor->assignRole('vendor');

        $this->rotate($attachment, 'clockwise', $vendor)->assertNotFound();

        $this->assertSame($path, $attachment->fresh()->filename);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $attachment = $this->photo();

        $this->post(route('api.attachments.rotate', $attachment), ['direction' => 'clockwise'])
            ->assertRedirect(route('login'));
    }
}
