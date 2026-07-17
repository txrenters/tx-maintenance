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
