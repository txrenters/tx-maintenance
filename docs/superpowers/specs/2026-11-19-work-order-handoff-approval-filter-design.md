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
with an AI reading the thread where the approval fields are empty. The office
chose (2026-11-19) that a confident reading sends the work order on its own,
with no coordinator step: the point is that nobody has to check every work
order by hand. The trade is made visible instead: every such send carries the
owner's quoted words, names the rule that let it through, and is listed for
the office to look over after the fact.

## Rules

Evaluated in `HandoffRule`, after the existing checks (an in-house vendor is
on it, no skipped vendor, still open), for every work order on every sweep and
on every assignment:

| # | Condition | Outcome |
| --- | --- | --- |
| 0 | `is_emergency` is true | Hand off now, whatever the cost, quoted or not. |
| 0b | Our crew has not entered an estimate yet | Hold: "Waiting for the crew's estimate". The AI does not read it. Saving the estimate re-decides it at once. |
| 1 | Crew's estimate $300 or below ($0 counts as a quote) | Hand off now. Reason `under_threshold`. |
| 2 | Over $300, approval recorded (`approved_by` or `approved_date` set) | Hand off now. Reason `owner_approved_recorded`. |
| 3 | Over $300, nothing recorded | Hold. The AI reads the work order (below). |
| 3a | …AI finds an approval with confidence ≥ 80 | Hand off now. Reason `owner_approved_in_messages`, with the reading attached. |
| 3b | …AI finds no approval, or is not confident | Stays held. Re-read when a new message, note or description change arrives. |

**Changed 2026-11-20 (the office):** the estimate is our crew's own, entered on
the work order in the vendor portal or on the Vendor Edit tab. PropertyWare's
estimate is ignored (it is $0 on every open work order), and so is an outside
vendor's quote on the same work order. $300 itself now goes; $301 needs an
approval. A work order with no estimate from our crew waits for one ("Hold
until there's an estimate"); the vendor portal and the Vendor Edit tab call
the hand-off the moment the estimate is saved.

The confidence bar is `services.inspection_app.handoff_ai_confidence`, default
80. `services.inspection_app.handoff_ai_requires_confirmation`, default false,
turns rule 3a into a held state: the reading shows on the work order, and
entering the approval in PropertyWare sends it through rule 2. It is off
because the office asked for the send to be automatic.

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
      "evidence": "Go ahead with the light.", "source": "owner, 2026-11-10 14:02 (SMS)" },
    { "item": "front door", "decision": "declined",
      "evidence": "Hold off on the door for now.", "source": "owner, 2026-11-10 14:02 (SMS)" },
    { "item": "lawn", "decision": "not_mentioned", "evidence": null, "source": null }
  ],
  "approved": true,
  "confidence": 92,
  "summary": "Owner approved the hallway light on 2026-11-10 by text; declined the door; did not mention the lawn."
}
```

`approved` is true when at least one problem is approved. Every `approved`
or `declined` decision must carry a verbatim quote in `evidence`, with who
wrote it, when and where in `source`; a decision without one is rejected and
the reading is stored as failed, never as an approval. An `approved` quote is
also checked against the thread (case, spacing and quote marks collapsed): it
must appear in a message the owner wrote, not in the office's, a note or the
approval comment, or the reading is invalid. The model is not trusted to have
copied the right person.

**What counts as approved.** Only a clear go-ahead from the owner, in the
owner's own words: "go ahead", "approved", "yes, fix the light", "do it".
The reading is told, in the prompt, to answer *not approved* for anything
short of that, and the office's instruction (2026-11-19) is the rule: "if
you feel the owner approved it then go; if the message is like not yet, or
still unsure, do not send it." So these hold:

- "not yet", "hold off", "let me think about it", "I'll get back to you".
- A question back to the office ("how much would the door be?") with no
  answer from the owner after it.
- A condition the thread does not show met ("ok if it's under $400" with no
  estimate given, or an estimate above it).
- An approval of a *different* work order or an earlier, separate repair at
  the same property.
- Approval by anyone who is not the owner: tenant, vendor, the coordinator
  writing "owner says ok" without the owner's own message. The coordinator's
  own note is still read, and a coordinator who was told by phone enters the
  approval in PropertyWare, which sends through rule 2.
- Silence: a thread where the office asked and the owner never answered.

When the owner approved some items and not others, the approved ones send
the work order (rule 3a) and the reading says which, so the crew knows what
not to touch. When the only approved item is one the thread later withdraws
("actually, leave the light too"), the later message wins.

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

## What the office sees

No queue to work through; that is the point. Two things instead:

- **Sent by AI reading.** `php artisan inspection-app:handoffs
  --rule=owner_approved_in_messages` lists every work order sent under rule
  3a: number, status, estimate, the AI's confidence and summary, and when it
  went; the work order's Recommendation tab carries the same line with the
  reading. The office looks here after the fact, and a wrong one can be
  traced to exactly what the AI read.
- **Held.** On the work order page, why a work order has not gone: "Waiting
  for owner approval ($450 estimate, no approval recorded)" or "AI read 6
  owner messages and found no approval". A coordinator entering the approval
  in PropertyWare clears it on the next sweep through rule 2, as today.

The AI never writes the approval fields in PropertyWare. What it does is let
the hand-off go; the work order's own record is untouched.

## What the inspection app receives

The envelope (`HandoffPayload`) grows one block, always present:

```json
"approval": {
  "rule": "owner_approved_in_messages",
  "threshold": 300,
  "estimate": 450.00,
  "recorded_by": null,
  "recorded_at": null,
  "ai_confidence": 92,
  "ai_read_at": "2026-11-19T15:04:00+00:00",
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
  it through, and `ai_insight_id` for rule 3a, pointing at the reading that
  did.
- The `AiInsight` row keeps the full reading, the text hash, the model, and the
  confidence, so a wrong send can be traced to what the AI saw.
- A held work order shows why on the work order page: "Waiting for owner
  approval ($450 estimate)" or "AI found no approval in 6 messages".

## Not in this design

- No change to what counts as an in-house crew, or to the skip list.
- No reading of tenant or vendor threads.
- No coordinator confirmation before an AI-read send. The office asked for
  the send to be automatic; the `handoff_ai_requires_confirmation` switch
  exists so that can be reversed without a code change.
- No splitting of a work order into approved and declined visits. The whole
  work order goes, with the reading attached.
- No change to the retry, idempotency or signing already in the hand-off.

## Before go-live

`php artisan inspection-app:evaluate-approval-reader --since=2026-01-01 --limit=50`
reads closed work orders of our crews over the threshold and compares the
AI's answer with the approval on record. It stores nothing and sends nothing.
Run it on the VPS against production data until the "would have sent without
a recorded approval" count is understood, then set
`INSPECTION_APP_HANDOFF_SINCE` to the go-live date so nothing older is
backfilled, and switch the hand-off on.

## Testing

- `HandoffRule`: a table test over estimate, approval fields, emergency flag
  and missing estimate, asserting the rule and outcome for each.
- `WorkOrderApprovalReader`: the prompt built from fixtures with notes, an
  approval comment and a mixed owner thread; a fake provider returning a
  valid, a partial and a malformed answer; the hash guard; the per-sweep cap.
- Rule 3a end to end: a confident reading sends with reason
  `owner_approved_in_messages`, the insight id on the outbox row and the
  approval block on the payload; a reading under the bar holds; the
  confirmation switch, when on, holds instead of sending.
- The "Sent by AI reading" filter lists exactly those rows.
- Intake (inspection app): the block lands in `internalNotes`, and an envelope
  without it still files as today.

## Build order

1. Rules 0, 1 and 2 with the `rule` column and tests. Sends start flowing for
   the easy cases on their own.
2. `WorkOrderApprovalReader`, the insight, the sweep cap, rule 3a sending,
   the held-state display.
3. The "Sent by AI reading" filter on the outbox view.
4. The `approval`, `notes` and `cost_estimate` fields on the envelope, then the
   inspection-side reader.
