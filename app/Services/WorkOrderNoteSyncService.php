<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reconciles a work order's notes with the set PropertyWare reports for it.
 *
 * PropertyWare owns only the rows it created (user_id null). Notes written on
 * the dashboard (user_id set) are never deleted or overwritten here. The
 * importers used to wipe every note on the work order and re-insert
 * PropertyWare's copy, which silently destroyed technician notes whose push
 * to PropertyWare had failed and re-created the rest without their author.
 *
 * Shared by the scheduled SOAP import, the board "Import Work Order" button,
 * the queued importer and the REST status sync so all four behave the same.
 * Callers already run inside a transaction; none is opened here.
 */
class WorkOrderNoteSyncService
{
    /**
     * @param  array<int|string, mixed>  $propertyWareNotes  PropertyWare note records
     *                                                       (SOAP keys ID/clientData/subject/body/private/date/default;
     *                                                       the REST shape uses a lowercase id)
     */
    public function syncFromPropertyWare(int $workOrderId, array $propertyWareNotes): void
    {
        $incoming = $this->normalizeIncoming($propertyWareNotes);

        $existing = DB::table('work_order_notes')
            ->where('work_order_id', $workOrderId)
            ->orderBy('id')
            ->get(['id', 'propertyware_id', 'user_id', 'subject', 'body', 'is_private', 'date', 'client_data', 'is_default']);

        /** @var array<string, object> $byPropertyWareId lowest id wins for a duplicated PropertyWare id */
        $byPropertyWareId = [];
        /** @var array<string, list<object>> $unlinked rows with no PropertyWare id yet, by content */
        $unlinked = [];
        /** @var array<int, true> $keepIds */
        $keepIds = [];

        foreach ($existing as $row) {
            if ($row->user_id !== null) {
                // Dashboard-authored: never in scope for deletion.
                $keepIds[$row->id] = true;
            }

            if ($row->propertyware_id !== null && $row->propertyware_id !== '') {
                $byPropertyWareId[(string) $row->propertyware_id] ??= $row;
            } else {
                $unlinked[self::fingerprint($row->subject, $row->body)][] = $row;
            }
        }

        $now = now();
        $inserts = [];
        $seenPropertyWareIds = [];

        foreach ($incoming as $note) {
            $propertyWareId = $note['propertyware_id'];

            if ($propertyWareId !== null) {
                if (isset($seenPropertyWareIds[$propertyWareId])) {
                    continue;
                }
                $seenPropertyWareIds[$propertyWareId] = true;
            }

            $match = $propertyWareId !== null ? ($byPropertyWareId[$propertyWareId] ?? null) : null;

            if ($match === null) {
                // A dashboard note that round-tripped through PropertyWare comes
                // back with the same text and a new id: link it to its local
                // row instead of duplicating it (and losing the author).
                $fingerprint = self::fingerprint($note['subject'], $note['body']);

                if (! empty($unlinked[$fingerprint])) {
                    $match = array_shift($unlinked[$fingerprint]);

                    if ($propertyWareId !== null) {
                        DB::table('work_order_notes')
                            ->where('id', $match->id)
                            ->update(['propertyware_id' => $propertyWareId, 'updated_at' => $now]);
                        $match->propertyware_id = $propertyWareId;
                        $byPropertyWareId[$propertyWareId] = $match;
                    }
                }
            }

            if ($match === null) {
                $inserts[] = $note + [
                    'work_order_id' => $workOrderId,
                    'user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                continue;
            }

            $keepIds[$match->id] = true;

            if ($match->user_id === null) {
                $changes = $this->changedColumns($match, $note);

                if ($changes !== []) {
                    DB::table('work_order_notes')
                        ->where('id', $match->id)
                        ->update($changes + ['updated_at' => $now]);
                }
            }
        }

        $staleIds = $existing
            ->filter(fn (object $row) => $row->user_id === null && ! isset($keepIds[$row->id]))
            ->pluck('id')
            ->all();

        if ($staleIds !== []) {
            DB::table('work_order_notes')->whereIn('id', $staleIds)->delete();
        }

        if ($inserts !== []) {
            DB::table('work_order_notes')->insert($inserts);
        }
    }

    /**
     * Map PropertyWare's note records onto our columns, coercing the NOT NULL
     * booleans (PropertyWare may omit them) so a bad record can never abort the
     * caller's whole work order transaction.
     *
     * @param  array<int|string, mixed>  $notes
     * @return list<array{propertyware_id: ?string, client_data: ?string, subject: ?string, body: ?string, is_private: bool, date: string, is_default: bool}>
     */
    private function normalizeIncoming(array $notes): array
    {
        // A lone note can decode as one associative record instead of a list.
        if (isset($notes['ID']) || isset($notes['id']) || isset($notes['subject']) || isset($notes['body'])) {
            $notes = [$notes];
        }

        $normalized = [];

        foreach ($notes as $note) {
            if (is_object($note)) {
                $note = (array) $note;
            }

            if (! is_array($note)) {
                continue;
            }

            $propertyWareId = $note['ID'] ?? $note['id'] ?? null;
            $propertyWareId = is_numeric($propertyWareId) ? (string) (int) $propertyWareId : null;

            $clientData = $note['clientData'] ?? null;
            if (is_array($clientData) || is_object($clientData)) {
                $clientData = json_encode($clientData);
            }

            $normalized[] = [
                'propertyware_id' => $propertyWareId,
                'client_data' => $clientData === null ? null : (string) $clientData,
                'subject' => isset($note['subject']) ? Str::limit((string) $note['subject'], 255, '') : null,
                'body' => isset($note['body']) ? (string) $note['body'] : null,
                'is_private' => filter_var($note['private'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'date' => (string) ($note['date'] ?? ''),
                'is_default' => filter_var($note['default'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $normalized;
    }

    /**
     * Columns on a PropertyWare-owned row that differ from the incoming record.
     *
     * @param  array{propertyware_id: ?string, client_data: ?string, subject: ?string, body: ?string, is_private: bool, date: string, is_default: bool}  $note
     * @return array<string, mixed>
     */
    private function changedColumns(object $row, array $note): array
    {
        $changes = [];

        foreach (['subject', 'body', 'date', 'client_data'] as $column) {
            $current = $row->{$column} === null ? null : (string) $row->{$column};
            $incoming = $note[$column] === null ? null : (string) $note[$column];

            if ($current !== $incoming) {
                $changes[$column] = $note[$column];
            }
        }

        foreach (['is_private', 'is_default'] as $column) {
            if ((bool) $row->{$column} !== $note[$column]) {
                $changes[$column] = $note[$column];
            }
        }

        return $changes;
    }

    /**
     * Whitespace-insensitive identity of a note's text: PropertyWare returns a
     * textarea's newlines as \r\n, and trailing spaces are not a different note.
     */
    private static function fingerprint(?string $subject, ?string $body): string
    {
        $normalize = fn (?string $text): string => trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return $normalize($subject)."\n".$normalize($body);
    }
}
