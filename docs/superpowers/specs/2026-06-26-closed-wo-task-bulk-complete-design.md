# Closed Work Order — Bulk-Complete Incomplete Tasks

**Date:** 2026-06-26
**Status:** Approved (design)

## Problem

When a work order is closed (`WorkOrderController::close()` sets `status = 'Closed'`), any
tasks that were never marked complete are left orphaned. The Task board
(`Task/Index.vue`) deliberately excludes closed work orders (`where('status', '!=', 'closed')`),
so there is currently **no place in the app to see or finish those leftover tasks**.

Staff need a cleanup tool: pick a closed work order that still has unfinished tasks, see
them, and mark them all complete at once.

## Goal

Add a "Closed cleanup" view to the Task page where a user can:

1. Pick a Closed work order (from a searchable list of all closed WOs, each showing how many
   tasks are still unfinished).
2. See that work order's incomplete tasks as a checklist.
3. Check all (or a subset) and complete them in one action.

## Non-Goals (YAGNI)

- No bulk-complete for **open** work orders (existing board handles those one-by-one).
- No "undo all" (existing per-task undo via `api.task.undo` still works).
- No reopening of closed work orders.
- No editing/reassigning tasks from this view — complete only.

## Key Decisions (from brainstorming)

| Decision | Choice |
|----------|--------|
| How to pick the WO | Searchable dropdown of **all** Closed WOs, each showing an unfinished-count badge (count may be 0) |
| Where it lives | A **separate tab/toggle** on the Task page: "Active board" \| "Closed cleanup" (full-width view) |
| Completion behavior | **Just mark complete — no cascade.** Set `status = 'completed'` only; do NOT advance service status, do NOT regenerate tasks, leave WO `status = 'Closed'` |
| What counts as incomplete | Any task with `status != 'completed'` (i.e. `pending` + `processing`) |
| Optional (Yes/No) tasks | Included; bulk-complete just sets them `completed` with no Yes/No prompt |

## Data Facts (verified against code)

- `WorkOrder.status` — closed value is the string `'Closed'` (written by `WorkOrderController::close()`,
  app/Http/Controllers/WorkOrderController.php:983). MySQL default collation is case-insensitive.
- `WorkOrder` `tasks(): HasMany(WorkOrderTask)` (app/Models/WorkOrder.php:139).
- `WorkOrderTask.status` — `'pending'` (default / not complete), `'processing'`, `'completed'`.
  Uses SoftDeletes. Has `work_order_id`, `assigned_user_id`, `due_date`.
- Normal single-task completion (`API\TaskController::update()`) can cascade service-status
  transitions and regenerate sibling tasks via `TaskService::createTasksForWorkOrder`.
  **Bulk-complete bypasses all of this.**

## Architecture

### Backend

**1. Dropdown source — all closed WOs**

Add to `TaskController::index()` a prop `closedWorkOrders` (loaded normally or via
`Inertia::optional` — load eagerly since the list is small and used to populate the tab).
List **all** closed work orders (do NOT gate on having incomplete tasks); the
`unfinished_count` badge may be 0:

```php
$closedWorkOrders = WorkOrder::where('status', 'Closed')
    ->withCount(['tasks as unfinished_count' => fn ($q) => $q->where('status', '!=', 'completed')])
    ->orderByDesc('completed_date')
    ->get(['id', 'work_order_no', 'completed_date']);
```

Returned shape per item: `{ id, work_order_no, completed_date, unfinished_count }`.

**2. Load a selected WO's incomplete tasks**

New JSON endpoint (keeps the existing `api.work_order.tasks` untouched, which returns all tasks):

- Route: `GET /tasks/{workOrder}/incomplete` → name `tasks.incomplete`
- `TaskController::incompleteTasks(WorkOrder $workOrder)`:
  - Guard: `abort_unless($workOrder->status === 'Closed', 404)` (or a 422 with message).
  - Return tasks where `status != 'completed'`, eager-loading `assigned_user` and `task` (for the name),
    shaped as `{ id, name, status, due_date, assigned_user, is_optional }`.

**3. Bulk-complete endpoint**

- Route: `POST /tasks/{workOrder}/bulk-complete` → name `tasks.bulk_complete`
- `TaskController::bulkComplete(BulkCompleteTasksRequest $request, WorkOrder $workOrder)`:
  - Validates `task_ids` is a non-empty array; each id `integer`.
  - Authorization / integrity guards:
    - `abort_unless($workOrder->status === 'Closed', 422)`.
    - Constrain the update to ids that **belong to this work order** so callers can't
      complete tasks from another WO:
      ```php
      $updated = $workOrder->tasks()
          ->whereIn('id', $request->validated('task_ids'))
          ->where('status', '!=', 'completed')
          ->update(['status' => 'completed']);
      ```
  - **No cascade**: does not call `TaskService`, does not touch `service_status_id`,
    does not change `WorkOrder.status`.
  - Returns `{ completed_count: $updated }` (JSON) so the front end can refresh.

**Form request:** `app/Http/Requests/BulkCompleteTasksRequest.php`
```php
public function rules(): array
{
    return [
        'task_ids'   => ['required', 'array', 'min:1'],
        'task_ids.*' => ['integer'],
    ];
}
```
(Ownership is enforced in the controller via the `$workOrder->tasks()` scope rather than an
`exists` rule, so cross-WO ids are silently ignored rather than completed.)

### Frontend (`resources/js/Pages/Task/Index.vue`)

- Add a tab/toggle at the top: **"Active board"** (existing 3 columns) | **"Closed cleanup"**.
  Use the existing tab/toggle UI primitives already in the project (Radix/shadcn-vue) — match
  sibling pages.
- **Closed cleanup view:**
  - Searchable `<Select>`/combobox populated from the `closedWorkOrders` prop. Each item label:
    `WO-{work_order_no} · {unfinished_count} unfinished`.
  - On select: fetch `tasks.incomplete` for that WO (Inertia partial or `axios`/`router` GET).
    Show a **pulsing skeleton** while loading (per Inertia deferred-prop guidance).
  - Render the returned tasks as a checklist:
    - Header row with a **"Select all"** checkbox.
    - Each row: checkbox + task name + due date + assignee badge.
  - **"Complete selected (N)"** button — disabled when `N === 0`. Clicking opens a confirm
    dialog ("Mark N tasks complete? This won't reopen or change the work order."), then POSTs
    `task_ids` to `tasks.bulk_complete`.
  - On success: toast, remove completed rows, refresh the WO's `unfinished_count` badge to its
    new value (it stays in the dropdown even when the count reaches 0).
  - **Empty state:** if a selected WO has no incomplete tasks (count is 0, all already done, or
    no tasks at all) show "No unfinished tasks 🎉" with no checklist/button.

## Routes summary

| Method | URI | Name | Controller |
|--------|-----|------|-----------|
| GET | `/tasks/{workOrder}/incomplete` | `tasks.incomplete` | `TaskController@incompleteTasks` |
| POST | `/tasks/{workOrder}/bulk-complete` | `tasks.bulk_complete` | `TaskController@bulkComplete` |

Both inside the existing authenticated web route group, alongside `tasks.index`.

## Testing (Feature tests, PHPUnit)

`tests/Feature/ClosedWorkOrderBulkCompleteTest.php`:

1. **Dropdown listing** — `index` returns **all** closed WOs in `closedWorkOrders` with the
   correct `unfinished_count`, including a closed WO whose tasks are all completed (count 0) and
   one with no tasks at all (count 0); excludes open WOs.
2. **Incomplete endpoint** — returns only `status != 'completed'` tasks for a closed WO;
   404/422 for a non-closed WO.
3. **Bulk-complete happy path** — given task ids, those tasks become `completed`; response
   `completed_count` matches.
4. **No cascade** — after bulk-complete, the WO's `status` is still `'Closed'`,
   `service_status_id` unchanged, and **no new tasks were generated** (task count for the WO
   is unchanged aside from status flips).
5. **Cross-WO protection** — passing a task id from a different WO does not complete it
   (`completed_count` excludes it; that task stays `pending`).
6. **Optional task** — an `is_optional` task is completed by bulk-complete with no Yes/No value
   and no error.
7. **Validation** — empty `task_ids` → 422.

Use model factories (`WorkOrder`, `WorkOrderTask`, `Task`) with appropriate states. Confirm
factories exist / add minimal states as needed.

## Open questions / risks

- **WorkOrder factory / WorkOrderTask factory** may need a `closed` state and `pending`/`completed`
  status states for clean tests — verify during implementation.
- Confirm the project's tab UI primitive (Radix `Tabs` vs a simple toggle) by checking a sibling
  page before building, to stay consistent.
