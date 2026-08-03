<?php

namespace Tests\Unit;

use App\Services\Images\GdImageProcessor;
use Tests\TestCase;

class GdImageProcessorTest extends TestCase
{
    private GdImageProcessor $processor;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->processor = new GdImageProcessor;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    private function makeImage(int $width, int $height, string $format = 'jpeg'): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 120, 160, 200));

        $path = tempnam(sys_get_temp_dir(), 'gdtest').'.'.$format;
        $this->tempFiles[] = $path;

        match ($format) {
            'png' => imagepng($image, $path),
            'gif' => imagegif($image, $path),
            'webp' => imagewebp($image, $path),
            default => imagejpeg($image, $path, 90),
        };

        imagedestroy($image);

        return $path;
    }

    private function writeTempFile(string $contents, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gdtest').'.'.$extension;
        $this->tempFiles[] = $path;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensionsOf(string $bytes): array
    {
        $image = imagecreatefromstring($bytes);
        $this->assertNotFalse($image, 'Thumbnail bytes were not a decodable image.');

        $dimensions = [imagesx($image), imagesy($image)];
        imagedestroy($image);

        return $dimensions;
    }

    public function test_it_supports_only_the_mime_types_gd_can_decode(): void
    {
        foreach (['image/jpeg', 'image/png', 'image/gif', 'image/webp'] as $mime) {
            $this->assertTrue($this->processor->supports($mime), "Expected {$mime} to be supported.");
        }

        // UploadedMediaFile accepts these, but GD cannot decode them and user
        // supplied SVG must never reach a rasterizer.
        foreach ([
            'image/heic', 'image/heif', 'image/svg+xml', 'image/bmp',
            'video/mp4', 'video/3gpp', 'application/pdf', 'application/octet-stream',
        ] as $mime) {
            $this->assertFalse($this->processor->supports($mime), "Expected {$mime} to be rejected.");
        }
    }

    public function test_it_ignores_charset_parameters_on_the_mime_type(): void
    {
        $this->assertTrue($this->processor->supports('image/jpeg; charset=binary'));
        $this->assertTrue($this->processor->supports('IMAGE/PNG'));
    }

    public function test_it_scales_down_preserving_the_aspect_ratio(): void
    {
        $bytes = $this->processor->thumbnail($this->makeImage(1600, 1200), 400, 400);

        $this->assertNotNull($bytes);
        $this->assertSame([400, 300], $this->dimensionsOf($bytes));
    }

    public function test_it_never_upscales_a_small_image(): void
    {
        $bytes = $this->processor->thumbnail($this->makeImage(100, 100), 400, 400);

        $this->assertNotNull($bytes);
        $this->assertSame([100, 100], $this->dimensionsOf($bytes));
    }

    public function test_it_encodes_thumbnails_as_webp(): void
    {
        $bytes = $this->processor->thumbnail($this->makeImage(800, 800, 'png'), 400, 400);

        $this->assertNotNull($bytes);

        $info = getimagesizefromstring($bytes);
        $this->assertNotFalse($info);
        $this->assertSame('image/webp', $info['mime']);
    }

    public function test_probe_returns_null_for_corrupt_bytes(): void
    {
        $this->assertNull($this->processor->probe($this->writeTempFile('this is not an image', 'jpg')));
    }

    public function test_it_returns_null_for_corrupt_bytes_without_throwing(): void
    {
        $this->assertNull($this->processor->thumbnail($this->writeTempFile('this is not an image', 'jpg'), 400, 400));
    }

    public function test_it_returns_null_for_a_truncated_image(): void
    {
        $path = $this->makeImage(600, 400);
        $contents = (string) file_get_contents($path);
        $truncated = $this->writeTempFile(substr($contents, 0, 40), 'jpg');

        $this->assertNull($this->processor->thumbnail($truncated, 400, 400));
    }

    public function test_it_returns_null_for_a_missing_file(): void
    {
        $this->assertNull($this->processor->probe(sys_get_temp_dir().'/definitely-not-here.jpg'));
        $this->assertNull($this->processor->thumbnail(sys_get_temp_dir().'/definitely-not-here.jpg', 400, 400));
    }

    public function test_it_refuses_images_over_the_pixel_budget(): void
    {
        config(['thumbnails.max_pixels' => 100]);

        $this->assertNull($this->processor->thumbnail($this->makeImage(400, 400), 400, 400));
    }

    public function test_it_bakes_exif_orientation_into_the_thumbnail(): void
    {
        // A landscape 800x400 JPEG tagged Orientation=6 ("rotate 90 clockwise to
        // display") must come out portrait, because GD drops EXIF on re-encode
        // and the browser would otherwise render the tile sideways.
        $path = $this->writeTempFile($this->jpegWithOrientation(800, 400, 6), 'jpg');

        $bytes = $this->processor->thumbnail($path, 400, 400);

        $this->assertNotNull($bytes);
        $this->assertSame([200, 400], $this->dimensionsOf($bytes));
    }

    public function test_orientation_one_is_left_untouched(): void
    {
        $path = $this->writeTempFile($this->jpegWithOrientation(800, 400, 1), 'jpg');

        $bytes = $this->processor->thumbnail($path, 400, 400);

        $this->assertNotNull($bytes);
        $this->assertSame([400, 200], $this->dimensionsOf($bytes));
    }

    /**
     * Build a JPEG carrying an EXIF APP1 segment with the given Orientation.
     * GD cannot write EXIF, so the segment is spliced in after the SOI marker.
     */
    private function jpegWithOrientation(int $width, int $height, int $orientation): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 10, 20, 30));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        // TIFF header (big endian) + a single-entry IFD0 holding tag 0x0112.
        $tiff = "MM\x00\x2A".pack('N', 8)
            .pack('n', 1)
            .pack('n', 0x0112).pack('n', 3).pack('N', 1).pack('n', $orientation)."\x00\x00"
            .pack('N', 0);

        $payload = "Exif\x00\x00".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        // Splice between the SOI marker and the rest of the stream.
        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }
}
