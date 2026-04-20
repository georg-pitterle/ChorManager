# Mail Delivery Status Separation Design

Date: 2026-04-20
Status: Approved for planning
Scope: Mail queue delivery lifecycle and provider feedback handling

## 1. Goal

Implement a delivery model that clearly separates transport acceptance from real delivery outcomes.

Required outcomes:
- Add explicit delivery states accepted, delivered, and bounced (plus complained) in addition to current queue states.
- Persist provider feedback from both DSN and webhooks, including provider_message_id.
- Use skipped for DISABLE_MAIL_SEND instead of marking as sent.
- Add a watchdog that resets stale sending entries older than 15 minutes.

## 2. Chosen Approach

Selected approach: Event-oriented delivery tracking.

Reason:
- Provides an auditable, provider-agnostic trail.
- Supports smtp2go first and brevo fallback without redesign.
- Keeps queue processing concerns separate from delivery truth.

## 3. Decisions Captured

- Success model: Hybrid.
  - accepted is shown immediately after SMTP handoff.
  - delivered is set only from real provider feedback.
- Feedback channels: DSN and webhooks in parallel.
- Provider path: smtp2go first, brevo as fallback.
- Fallback rule when provider feedback is unavailable: strict.
  - accepted does not auto-transition to delivered.
- Watchdog policy:
  - sending older than 15 minutes transitions to failed.
  - existing retry and backoff remains in control.
- Reporting:
  - skipped is a dedicated metric and is not counted as sent.

## 4. Architecture

### 4.1 Separation of concerns

- Queue lifecycle remains operational:
  - queued, sending, failed, dead, skipped.
- Delivery lifecycle is tracked as business truth:
  - pending, accepted, delivered, bounced, complained, skipped.

### 4.2 Processing pipeline

1. Sender worker picks due queue entries and performs transport send.
2. On SMTP acceptance, system stores provider_message_id and marks accepted.
3. DSN and webhook inputs are persisted as raw delivery events.
4. An idempotent mapper normalizes events and updates delivery status.
5. Watchdog periodically repairs stale sending rows.

## 5. Data Model

### 5.1 Extend mail_queue

Add fields:
- delivery_status (pending, accepted, delivered, bounced, complained, skipped)
- provider_name
- provider_message_id
- accepted_at
- delivered_at
- bounced_at
- complained_at
- last_event_at
- last_event_type

Notes:
- delivered, bounced, complained, skipped are terminal delivery statuses.
- Queue status and delivery status are not the same thing and must be reported separately.

### 5.2 New table mail_delivery_events

Columns:
- id
- mail_queue_id
- provider_name
- provider_message_id
- source_channel (dsn, webhook)
- event_type_normalized
- event_type_raw
- idempotency_key (unique)
- occurred_at (provider time)
- received_at (app ingest time)
- raw_payload (verbatim source payload)

Behavior:
- Every DSN or webhook is stored before mapping.
- Duplicate events are ignored by idempotency key.

## 6. State Transition Rules

### 6.1 Primary transitions

- pending -> accepted when transport accepts message.
- accepted -> delivered when delivery confirmation event arrives.
- accepted -> bounced when bounce event arrives.
- accepted or delivered -> complained when complaint event arrives.
- any dev-disabled send -> skipped.

### 6.2 Priority on conflicting events

- complained has highest priority and overrides delivered.
- bounced overrides accepted.
- duplicated or older events do not downgrade terminal states unless explicitly allowed by policy.

## 7. Watchdog Rules

- Trigger: queue status is sending and last update age is greater than 15 minutes.
- Action:
  - set queue status to failed
  - set error_code to stale_sending_timeout
  - preserve retryability for normal backoff path
- Goal: avoid indefinite sending lock after worker crash.

## 8. Dev Mode Semantics

- If DISABLE_MAIL_SEND is enabled:
  - do not report as sent.
  - mark queue and delivery as skipped.
  - skipped is terminal and reported separately.

## 9. Error Handling and Security

- Webhook handlers must validate provider signatures where available.
- DSN ingest must only accept trusted internal channels.
- Persist raw payloads as received for audit, but avoid storing secrets.
- Mapping must be idempotent and resilient to replay.

## 10. Reporting and Admin Visibility

Dashboard and queue admin should expose separate counts for:
- queued
- sending
- accepted
- delivered
- bounced
- complained
- failed
- dead
- skipped

Filtering should support source_channel, provider_name, delivery_status, and event time windows.

## 11. Testing Strategy

Feature tests:
- DISABLE_MAIL_SEND marks skipped and not sent.
- SMTP acceptance marks accepted and stores provider_message_id.
- Delivery webhook updates accepted to delivered.
- Bounce and complaint events update to bounced and complained.
- Duplicate feedback events are idempotent.
- Watchdog transitions stale sending to failed at 15 minutes.

Regression tests:
- Existing queue retry/backoff behavior remains intact.
- Existing admin queue listing continues to function with new statuses.

## 12. Non-Goals

- No provider-specific deep optimization in this phase.
- No automatic accepted-to-delivered timeout promotion.
- No implementation changes in this document; planning only.

## 13. Open Implementation Notes for Planning

- Define a provider adapter interface for smtp2go and brevo event normalization.
- Define canonical idempotency key format across DSN and webhook channels.
- Keep migration steps backward-compatible for existing rows.
