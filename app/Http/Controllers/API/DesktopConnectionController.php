<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Desktop\DesktopConnectRequest;
use App\Notifications\DesktopTestNotification;
use App\Services\Desktop\DesktopConnectionService;
use App\Services\Desktop\DesktopTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DesktopConnectionController extends Controller
{
    public function __construct(
        private readonly DesktopTokenService $tokens,
        private readonly DesktopConnectionService $connection,
    ) {}

    /**
     * Complete the desktop handshake: swap the setup code for a long-lived
     * device token and hand back everything the client needs to subscribe.
     */
    public function store(DesktopConnectRequest $request): JsonResponse
    {
        $user = $request->user();
        $deviceName = $request->validated('device_name');

        $deviceToken = $this->tokens->issueDeviceToken($user, $deviceName);

        // The setup code is single-use: it has done its job the moment the
        // device token exists.
        $request->user()->currentAccessToken()?->delete();

        Log::info('Desktop client connected', [
            'user_id' => $user->id,
            'device_name' => $deviceName,
            'client' => $request->validated('client'),
            'client_version' => $request->validated('client_version'),
            'platform' => $request->validated('platform'),
            'ip' => $request->ip(),
        ]);

        return response()->json(
            $this->connection->payload($user, $deviceToken->plainTextToken)
        );
    }

    /**
     * Broadcast a test notification down the user's real private channel.
     */
    public function testNotification(Request $request): JsonResponse
    {
        $request->user()->notify(new DesktopTestNotification);

        return response()->json(['message' => 'Test notification sent.']);
    }
}
