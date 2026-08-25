<?php

namespace App\Http\Controllers;

use App\Models\Jobber;
use App\Models\JobberVisit;
use App\Services\AutomatedMessageTemplates;
use App\Services\PropertyWareTenantReport;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The "Send notification" button on a visit in the Scheduled Visits calendar:
 * the Tenant Benefit Package visit notice, filled in with this visit's date,
 * plus the phone numbers we can find for the property so staff tick who gets
 * it. The text itself goes out through the Messages tab's own endpoint
 * (jobber-text-messages.store), so it lands in the visit's message history
 * like any staff-sent text.
 *
 * Exists because the automated 14/7/3/1-day reminders skip a visit when the
 * PropertyWare building name does not match the Jobber client name exactly
 * (e.g. "17710 Winnower" vs "17710 Winnower Ln"); this is the manual fallback.
 */
class TbpVisitNoticeController extends Controller
{
    public const TEMPLATE_KEY = 'tenant_tbp_visit_notice';

    /**
     * The longest body jobber-text-messages.store accepts, which is also
     * Twilio's cap for one message.
     */
    public const MAX_SMS_LENGTH = 1600;

    public function show(Request $request, JobberVisit $visit, PropertyWareTenantReport $report): JsonResponse
    {
        $this->authorizeStaff($request);

        $visit->load(['job.client', 'job.clientContacts']);
        $job = $visit->job;

        abort_if($job === null, 404, 'This visit has no Jobber job.');

        if ($visit->start_at === null) {
            return response()->json([
                'message' => 'This visit has no scheduled date yet, so the notice cannot name one.',
            ], 422);
        }

        // jobber_visits stores the Chicago wall-clock time the sync received,
        // so the calendar date is read straight off the stored value.
        $scheduledDate = Carbon::parse($visit->start_at)->format('l, F j, Y');

        $message = AutomatedMessageTemplates::text(self::TEMPLATE_KEY, [
            'SCHEDULED_DATE' => $scheduledDate,
        ]);

        return response()->json([
            'visit_id' => $visit->id,
            'is_tbp' => (bool) preg_match('/tenant benefit|tbp/i', $visit->title.' '.$job->title),
            'scheduled_date' => $scheduledDate,
            'message' => $message,
            'length' => mb_strlen($message),
            'max_length' => self::MAX_SMS_LENGTH,
            'template_key' => self::TEMPLATE_KEY,
            'recipients' => $this->recipients($job, $report),
            'reminders' => [
                '14_day' => (bool) $visit->notified_14_days,
                '7_day' => (bool) $visit->notified_7_days,
                '3_day' => (bool) $visit->notified_3_days,
                '1_day' => (bool) $visit->notified_1_days,
            ],
        ]);
    }

    /**
     * Who the notice can go to, PropertyWare lease contacts first, then the
     * Jobber client's own phone, then contacts saved on the job - one entry
     * per phone number. A PropertyWare tenant is pre-ticked only when the
     * lease is active, they are enrolled in the package, and the building is
     * unambiguous (an exact name match, or the only building that matched).
     *
     * @return list<array{name: string, phone: string|null, source: string, detail: string, selected: bool}>
     */
    private function recipients(Jobber $job, PropertyWareTenantReport $report): array
    {
        $clientName = (string) ($job->client->name ?? '');
        $contacts = $clientName !== '' ? $report->contactsForBuilding($clientName) : [];
        $buildings = collect($contacts)->pluck('building')->unique()->count();

        $recipients = [];
        $seen = [];

        $add = function (string $name, ?string $phone, string $source, string $detail, bool $selected) use (&$recipients, &$seen): void {
            $key = self::phoneKey($phone);

            if ($key !== null) {
                if (isset($seen[$key])) {
                    return;
                }

                $seen[$key] = true;
            }

            $recipients[] = [
                'name' => $name,
                'phone' => $phone,
                'source' => $source,
                'detail' => $detail,
                'selected' => $selected && $phone !== null,
            ];
        };

        foreach ($contacts as $contact) {
            $activeLease = str_starts_with(strtolower($contact['status']), 'active')
                || str_starts_with(strtolower($contact['status']), 'going mtm');
            $unambiguous = $contact['exact'] || $buildings === 1;

            $detail = trim(implode(' - ', array_filter([
                $contact['building'],
                $contact['status'] !== '' ? $contact['status'] : null,
                $contact['enrolled'] ? 'Enrolled in TBP' : 'Not enrolled in TBP',
                $contact['phone'] === null ? 'no phone in PropertyWare' : null,
            ])));

            $add(
                $contact['name'] !== '' ? $contact['name'] : 'PropertyWare tenant',
                $contact['phone'],
                'propertyware',
                $detail,
                $activeLease && $contact['enrolled'] && $unambiguous,
            );
        }

        $anySelected = collect($recipients)->contains(fn (array $recipient): bool => $recipient['selected']);

        $clientPhone = trim((string) ($job->client->phone ?? ''));

        if ($clientPhone !== '') {
            $add($clientName !== '' ? $clientName : 'Jobber client', $clientPhone, 'jobber', 'Phone on the Jobber client', ! $anySelected);
        }

        foreach ($job->clientContacts as $contact) {
            $phone = trim((string) $contact->phone);

            if ($phone === '') {
                continue;
            }

            $add((string) ($contact->name ?: $phone), $phone, 'contact', 'Saved contact on this job', false);
        }

        return $recipients;
    }

    /**
     * Digits only, with the US country code assumed for 10-digit numbers, so
     * "(346) 368-4607" and "+13463684607" count as one recipient.
     */
    private static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        return strlen($digits) === 10 ? '1'.$digits : $digits;
    }

    private function authorizeStaff(Request $request): void
    {
        $user = $request->user();

        abort_unless((bool) ($user?->hasRole('admin') || $user?->hasRole('woc')), 403);
    }
}
