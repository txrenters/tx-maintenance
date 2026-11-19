<?php

/*
| The TexasRenters app owners and tenants sign in to (the tx-client project).
| These links go into the emails we send them; tx-client's config/mobile.php
| and its landing page hold the same ones.
*/

return [

    'app_store_url' => env('CLIENT_APP_STORE_URL', 'https://apps.apple.com/us/app/texasrenters-app/id6818547029'),

    'play_store_url' => env('CLIENT_APP_PLAY_STORE_URL', 'https://play.google.com/store/apps/details?id=com.texasrenters.portal'),

    // The one-minute introduction to the mobile app, on the TexasRenters YouTube channel.
    'app_video_url' => env('CLIENT_APP_VIDEO_URL', 'https://youtu.be/8oDalAMczQs'),

    // The tutorial on the TexasRenters YouTube channel that walks through signing up.
    'signup_video_url' => env('CLIENT_APP_SIGNUP_VIDEO_URL', 'https://www.youtube.com/watch?v=Xp0__SI4WgY'),

];
