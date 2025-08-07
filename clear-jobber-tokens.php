<?php

// Quick script to clear Jobber tokens
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\JobberToken;

JobberToken::truncate();
echo "All Jobber tokens have been cleared.\n";
echo 'Please reconnect at: '.env('APP_URL')."/jobber-connect\n";
