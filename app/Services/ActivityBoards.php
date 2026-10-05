<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The boards that carry "new activity" counters (badge, bell dropdown, dismiss,
 * Mark all seen), and where each keeps its state.
 *
 * Every staff login gets the counters (Earl, 2026-10-01: "all staff, not just
 * the WOC"). Each board has its own switch so a noisy wave can be silenced from
 * an App Setting without a deploy.
 */
class ActivityBoards
{
    public const HVAC = 'hvac';

    public const EASY_FIX = 'easy_fix';

    /**
     * @var array<string, array{scope: string, seen_column: string, reads_table: string, enabled: string}>
     */
    private const BOARDS = [
        self::HVAC => [
            'scope' => 'hvac',
            'seen_column' => 'hvac_board_seen_at',
            'reads_table' => 'hvac_board_reads',
            'enabled' => 'services.hvac_board.badges_enabled',
        ],
        self::EASY_FIX => [
            'scope' => 'tenantEasyFix',
            'seen_column' => 'easy_fix_board_seen_at',
            'reads_table' => 'easy_fix_board_reads',
            'enabled' => 'services.easy_fix_board.badges_enabled',
        ],
    ];

    /**
     * @return array{scope: string, seen_column: string, reads_table: string, enabled: string}
     */
    public static function get(string $board): array
    {
        return self::BOARDS[$board] ?? throw new InvalidArgumentException("Unknown activity board [{$board}].");
    }

    /**
     * Where this user's "new since" line sits on this board: their own seen
     * mark, or, for a login created after the board's migration backfilled
     * everyone, the moment the account was made. Without that fallback a new
     * staff login would never see a badge — and Mark all seen only shows once
     * there is one — so it could never switch the counters on.
     */
    public static function seenAt(User $user, string $board): ?CarbonInterface
    {
        return $user->{self::get($board)['seen_column']} ?? $user->created_at;
    }

    /**
     * Whether this user gets the counters on this board: any staff login, while
     * the board's switch is on. Vendors, tenants and owners never do.
     */
    public static function sees(?User $user, string $board): bool
    {
        return $user !== null
            && (bool) config(self::get($board)['enabled'])
            && $user->isStaff();
    }
}
