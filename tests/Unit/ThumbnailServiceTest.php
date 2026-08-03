<?php

namespace Tests\Unit;

use App\Services\Images\GdImageProcessor;
use App\Services\Images\ThumbnailService;
use Tests\TestCase;

class ThumbnailServiceTest extends TestCase
{
    private ThumbnailService $thumbnails;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thumbnails = new ThumbnailService(new GdImageProcessor);
    }

    public function test_it_places_the_thumbnail_in_a_sibling_thumbs_directory(): void
    {
        $this->assertSame(
            'attachments/thumbs/9fA2xK.webp',
            $this->thumbnails->thumbPathFor('attachments/9fA2xK.jpg'),
        );
    }

    public function test_it_normalizes_the_extension_case(): void
    {
        $this->assertSame(
            'attachments/thumbs/photo.webp',
            $this->thumbnails->thumbPathFor('attachments/photo.JPG'),
        );
    }

    public function test_it_keeps_dots_inside_the_filename(): void
    {
        $this->assertSame(
            'attachments/thumbs/my.photo.v2.webp',
            $this->thumbnails->thumbPathFor('attachments/my.photo.v2.png'),
        );
    }

    public function test_it_handles_the_store_as_naming_used_for_invoices(): void
    {
        $this->assertSame(
            'invoices/thumbs/Roof_Repair_68a1b2.webp',
            $this->thumbnails->thumbPathFor('invoices/Roof_Repair_68a1b2.png'),
        );
    }

    public function test_it_handles_a_nested_directory(): void
    {
        $this->assertSame(
            'message_media/1234/thumbs/abc.webp',
            $this->thumbnails->thumbPathFor('message_media/1234/abc.jpg'),
        );
    }

    public function test_it_handles_a_file_at_the_disk_root(): void
    {
        $this->assertSame('thumbs/loose.webp', $this->thumbnails->thumbPathFor('loose.jpg'));
    }
}
