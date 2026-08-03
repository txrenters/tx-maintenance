<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Thumbnail dimensions
    |--------------------------------------------------------------------------
    |
    | Generated thumbnails are scaled to fit inside this box while preserving
    | the aspect ratio, and are never upscaled. 400px covers 2x DPI on the
    | 128px (w-32 h-32) tiles in the work order Files tab.
    |
    */

    'max_width' => (int) env('THUMBNAIL_MAX_WIDTH', 400),

    'max_height' => (int) env('THUMBNAIL_MAX_HEIGHT', 400),

    /*
    |--------------------------------------------------------------------------
    | WebP quality
    |--------------------------------------------------------------------------
    |
    | Thumbnails are always encoded as WebP. Production GD is compiled
    | --with-webp by startup.sh, but without AVIF, so AVIF is never emitted.
    |
    */

    'quality' => (int) env('THUMBNAIL_QUALITY', 70),

    /*
    |--------------------------------------------------------------------------
    | Decode guards
    |--------------------------------------------------------------------------
    |
    | GD decodes to a truecolor bitmap costing roughly width x height x 4 bytes,
    | so a 100MP phone photo needs ~400MB. Uploads are capped at 50MB, which is
    | well past the 256MB memory_limit, so oversized images are refused before
    | any allocation happens and the limit is raised only for the decode itself.
    |
    */

    'max_pixels' => (int) env('THUMBNAIL_MAX_PIXELS', 40000000),

    'max_memory' => env('THUMBNAIL_MAX_MEMORY', '512M'),

    /*
    |--------------------------------------------------------------------------
    | Framework image driver
    |--------------------------------------------------------------------------
    |
    | Flipped on once laravel/framework reaches >= 13.20 and intervention/image
    | is installed, swapping GdImageProcessor for LaravelImageProcessor. Until
    | then the GD implementation is the only one bound.
    |
    */

    'use_framework_driver' => (bool) env('THUMBNAIL_USE_FRAMEWORK_DRIVER', false),

];
