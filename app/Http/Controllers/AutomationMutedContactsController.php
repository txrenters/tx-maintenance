<?php

namespace App\Http\Controllers;

use App\Models\Owner;
use App\Models\Tenants;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Do Not Message tab on IT Tools → Automated Messages: the owners and
 * tenants who never receive automated maintenance texts or emails (e.g. an
 * owner whose property someone else handles). Staff search for a person and
 * mute or unmute them. Manual sends from a conversation tab are unaffected.
 * Admin + WOC, like the rest of the page.
 */
class AutomationMutedContactsController extends Controller
{
    private const SEARCH_LIMIT = 15;

    /**
     * Everyone currently muted, owners first, most recently muted first.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $owners = Owner::query()->automationsMuted()->latest('automations_muted_at')->get()
            ->map(fn (Owner $owner): array => $this->presentOwner($owner));

        $tenants = Tenants::query()->automationsMuted()->latest('automations_muted_at')->get()
            ->map(fn (Tenants $tenant): array => $this->presentTenant($tenant));

        return response()->json(['contacts' => $owners->concat($tenants)->values()]);
    }

    /**
     * Owners and tenants matching a name, email, or phone number, so staff can
     * find the person to mute.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $term = trim($validated['q']);

        $owners = $this->applySearch(Owner::query(), $term, ['name', 'first_name', 'last_name', 'company', 'email'], ['phone', 'mobile'])
            ->orderBy('last_name')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->map(fn (Owner $owner): array => $this->presentOwner($owner));

        $tenants = $this->applySearch(Tenants::query(), $term, ['first_name', 'last_name', 'email'], ['mobile_phone', 'home_phone'])
            ->orderBy('last_name')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->map(fn (Tenants $tenant): array => $this->presentTenant($tenant));

        return response()->json(['contacts' => $owners->concat($tenants)->values()]);
    }

    /**
     * Mute or unmute one owner or tenant.
     */
    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorizeStaff($request);

        abort_unless(in_array($type, ['owner', 'tenant'], true), 404);

        $validated = $request->validate([
            'muted' => ['required', 'boolean'],
        ]);

        $contact = $type === 'owner'
            ? Owner::query()->findOrFail($id)
            : Tenants::query()->findOrFail($id);

        $contact->setAutomationsMuted($validated['muted']);

        activity()
            ->performedOn($contact)
            ->causedBy($request->user())
            ->log($validated['muted'] ? 'automations muted' : 'automations unmuted');

        return response()->json([
            'contact' => $contact instanceof Owner ? $this->presentOwner($contact) : $this->presentTenant($contact),
        ]);
    }

    /**
     * Every word must match one of the text columns. A query with four or more
     * digits also matches phone numbers in any formatting: the digits are
     * joined with wildcards, so "7135698343" finds "(713) 569-8343".
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $textColumns
     * @param  list<string>  $phoneColumns
     * @return Builder<TModel>
     */
    private function applySearch(Builder $query, string $term, array $textColumns, array $phoneColumns): Builder
    {
        $digits = preg_replace('/\D+/', '', $term) ?? '';
        $words = preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $query->where(function (Builder $outer) use ($digits, $words, $textColumns, $phoneColumns) {
            $outer->where(function (Builder $byName) use ($words, $textColumns) {
                foreach ($words as $word) {
                    $byName->whereAny($textColumns, 'like', '%'.addcslashes($word, '%_\\').'%');
                }
            });

            if (strlen($digits) >= 4) {
                $outer->orWhereAny($phoneColumns, 'like', '%'.implode('%', str_split($digits)).'%');
            }
        });
    }

    /**
     * @return array{type: string, id: int, name: string, detail: ?string, phones: list<string>, email: ?string, muted: bool, muted_at: ?string}
     */
    private function presentOwner(Owner $owner): array
    {
        $name = trim($owner->first_name.' '.$owner->last_name) ?: (string) $owner->name;

        return [
            'type' => 'owner',
            'id' => $owner->id,
            'name' => $name !== '' ? $name : 'Owner #'.$owner->id,
            'detail' => filled($owner->company) ? $owner->company : null,
            'phones' => array_values(array_unique(array_filter([$owner->mobile, $owner->phone]))),
            'email' => filled($owner->email) ? $owner->email : null,
            'muted' => $owner->automationsMuted(),
            'muted_at' => $owner->automations_muted_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{type: string, id: int, name: string, detail: ?string, phones: list<string>, email: ?string, muted: bool, muted_at: ?string}
     */
    private function presentTenant(Tenants $tenant): array
    {
        $name = trim($tenant->first_name.' '.$tenant->last_name);

        return [
            'type' => 'tenant',
            'id' => $tenant->id,
            'name' => $name !== '' ? $name : 'Tenant #'.$tenant->id,
            'detail' => filled($tenant->address) ? $tenant->address : null,
            'phones' => array_values(array_unique(array_filter([$tenant->mobile_phone, $tenant->home_phone]))),
            'email' => filled($tenant->email) ? $tenant->email : null,
            'muted' => $tenant->automationsMuted(),
            'muted_at' => $tenant->automations_muted_at?->toIso8601String(),
        ];
    }

    private function authorizeStaff(Request $request): void
    {
        $user = $request->user();

        abort_unless((bool) ($user?->hasRole('admin') || $user?->hasRole('woc')), 403);
    }
}
