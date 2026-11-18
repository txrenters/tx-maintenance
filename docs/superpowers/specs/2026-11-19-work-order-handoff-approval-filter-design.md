# Work-order hand-off: only what is ready for a crew

Date: 2026-11-19. Branch: `feature/inspection-app-handoff`. Status: design, awaiting review.

## The problem

The hand-off (`HandoffService`, `HandoffRule`) sends a work order to the
technicians' inspection app the moment an in-house crew is on it. That is too
early for repairs that need the owner's say-so: the crew's visit gets
scheduled for work the owner has not approved, or has approved only part of.

The office's rule, as it does it by hand today:

- Under $300, the office does not ask the owner. The crew can go.
- $300 and over, the owner must approve. The approval is sometimes entered in
  PropertyWare's approval fields, and sometimes it only exists in what the
  owner wrote back: an email, a text, a line in the Notes tab. Sometimes the
  owner approves one of the problems on the work order and not the others,
  and the office has to read the thread to know which.

This design makes the hand-off apply that rule to every work order it sweeps,
with an AI reading the thread where the approval fields are empty, and a
coordinator confirming what the AI found before anything $300+ is sent on the
strength of a message alone.

## Rules

Evaluated in `HandoffRule`, after the existing checks (an in-house vendor is
on it, no skipped vendor, still open), for every work order on every sweep and
on every assignment:

| # | Condition | Outcome |
| --- | --- | --- |
| 0 | `is_emergency` is true | Hand off now, whatever the cost. |
| 1 | Estimate under $300 | Hand off now. Reason `under_threshold`. |
| 2 | $300+, approval recorded (`approved_by` or `approved_date` set) | Hand off now. Reason `owner_approved_recorded`. |
| 3 | $300+, nothing recorded, no estimate, or estimate unknown | Hold. The AI reads the work order (below). |
| 3a | …AI finds an approval with confidence ≥ 80 | Coordinator queue: "Owner approved in messages, not entered yet". Hand off when a coordinator confirms. Reason `owner_approved_in_messages`, with the confirming user. |
| 3b | …AI finds no approval, or is not confident | Stays held. Re-read when a new message, note or description change arrives. |

The estimate is the work order's `cost_estimate`. When that is empty, the
in-house vendor's pivot `cost_estimate` is used. When both are empty the work
order is treated as $300+ with nothing recorded (rule 3), never as under the
threshold: a missing number is not a small number.

The threshold is `services.inspection_app.handoff_approval_threshold`, default
300, so the office can move it without a deploy.

A work order already handed off is not pulled back by these rules. They decide
the first send. Later changes still go as updates, as today.

## The AI reading

**Question.** "Which of the problems on this work order has the owner approved
for repair?" Not a yes or no: the owner often approves one item and not the
rest, and the crew needs to know which.

**What it reads.** In this order, all of it, as one prompt:

1. The description and `specific_location`.
2. The Notes tab (`work_order_notes`).
3. The approval comment, if any, even when the approval fields are otherwise
   blank (PropertyWare has wiped the fields and left the comment before).
4. Every owner-party message on the work order (`work_order_conversations`
   with party type `owner` or `vendor_owner`): the owner's and the
   coordinator's sides, email and SMS, oldest first, with who wrote each and
   when.

Tenant and vendor threads are not read: a tenant cannot approve a charge and
a vendor's "ok" is not the owner's.

**What it returns.** Structured, validated before it is stored:

```json
{
  "problems": [
    { "item": "hallway light fixture", "decision": "approved",
      "evidence": "Go ahead with the light. — owner, 2026-11-10 14:02 (SMS)" },
    { "item": "front door", "decision": "declined",
      "evidence": "Hold off on the door for now. — owner, 2026-11-10 14:02 (SMS)" },
    { "item": "lawn", "decision": "not_mentioned", "evidence": null }
  ],
  "approved": true,
  "confidence": 92,
  "summary": "Owner approved the hallway light on 2026-11-10 by text; declined the door; did not mention the lawn."
}
```

`approved` is true when at least one problem is approved. Every `approved`
or `declined` decision must carry a verbatim quote; a decision without one is
rejected and the reading is stored as failed, never as an approval.

**Where it lives.** A new `WorkOrderApprovalReader` service, built the way
`TenantEasyFixReplyJudge` is: one reading stored as an `AiInsight` of a new
type `owner_approval` on the work order, keyed by a hash of the text it read,
so the same text is never sent twice and a newer message produces a new
reading that replaces the old one. It uses the app's configured AI provider
(`config/ai.php`) and the same validation discipline: a malformed answer is a
failed reading, not a guess.

**When it runs.** From the hand-off sweep (every five minutes) and from
`consider()` on assignment, for every held work order whose text hash has
changed since its last reading. Bounded: at most N readings per sweep
(config, default 20) so a backlog cannot run up a bill in one pass, and a
reading is never repeated for unchanged text.

## The coordinator queue

A section on the existing work-orders view (the same place the hand-off outbox
is reported) listing work orders in rule 3a: number, property, estimate, the
AI's summary, each problem with its decision and quote, and two buttons:

- **Confirm and send**: records who confirmed, marks the insight resolved,
  and hands the work order off with reason `owner_approved_in_messages`.
- **Not approved**: marks the insight resolved as rejected; the work order
  stays held until the text changes.

Entering the approval in PropertyWare also works, as it does today: the next
sweep sees `approved_by` set and rule 2 sends it. The queue is for the office's
speed, not a second system of record.

The AI never writes the approval fields and never sends a $300+ work order by
itself. Every automatic send is rule 0, 1 or 2.

## What the inspection app receives

The envelope (`HandoffPayload`) grows one block, always present:

```json
"approval": {
  "rule": "owner_approved_in_messages",
  "threshold": 300,
  "estimate": 450.00,
  "recorded_by": null,
  "recorded_at": null,
  "confirmed_by": "Maria (coordinator)",
  "confirmed_at": "2026-11-19T15:04:00+00:00",
  "summary": "Owner approved the hallway light on 2026-11-10 by text; declined the door; did not mention the lawn.",
  "problems": [ …as above… ]
}
```

together with the fields the crew needs that the envelope does not carry yet:
`notes` (the Notes tab, newest first, each with author and time) and
`cost_estimate`.

On the inspection side (`texasrenters-inspection-app`, `feature/work-order-intake`),
the intake service reads the block and adds it to the visit's `internalNotes`
under an "Owner approval" heading, so the technician reads "Hallway light
fixture: approved. Front door: declined. Lawn: not mentioned." on the job.
Unknown keys are dropped today, so the sender can ship first; the reader
follows. No new permission, no schema change beyond the notes text.

## Records and audit

- Every `InspectionAppHandoff` row gets a `rule` column naming which rule let
  it through, and `confirmed_by_user_id` for rule 3a.
- The `AiInsight` row keeps the full reading, the text hash, the model, and the
  confidence, so a wrong send can be traced to what the AI saw.
- A held work order shows why on the work order page: "Waiting for owner
  approval ($450 estimate)" or "AI found no approval in 6 messages".

## Not in this design

- No change to what counts as an in-house crew, or to the skip list.
- No reading of tenant or vendor threads.
- No automatic send above the threshold on the AI's word alone. If the office
  later wants that, it is one config switch on rule 3a, and the evidence is
  already on the envelope; it is left off deliberately.
- No splitting of a work order into approved and declined visits. The whole
  work order goes, with the reading attached.
- No change to the retry, idempotency or signing already in the hand-off.

## Testing

- `HandoffRule`: a table test over estimate, approval fields, emergency flag
  and missing estimate, asserting the rule and outcome for each.
- `WorkOrderApprovalReader`: the prompt built from fixtures with notes, an
  approval comment and a mixed owner thread; a fake provider returning a
  valid, a partial and a malformed answer; the hash guard; the per-sweep cap.
- Queue: confirm and reject, who is recorded, and that confirm sends with the
  right reason and the approval block on the payload.
- Intake (inspection app): the block lands in `internalNotes`, and an envelope
  without it still files as today.

## Build order

1. Rules 0, 1 and 2 with the `rule` column and tests. Sends start flowing for
   the easy cases on their own.
2. `WorkOrderApprovalReader`, the insight, the sweep cap, the held-state
   display.
3. The coordinator queue.
4. The `approval`, `notes` and `cost_estimate` fields on the envelope, then the
   inspection-side reader.
