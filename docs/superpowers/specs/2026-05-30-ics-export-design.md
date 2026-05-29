# ICS Export for Personal Calendar Subscription

## Goal

Provide a direct, token-based calendar subscription URL for members so they can add all current and future visible events to external calendar apps like Google Calendar.

## Scope

- Public `GET /events/export/{token}.ics` endpoint
- No login required for the endpoint itself
- Authenticated token only access
- Deliver current and future events that the member may see, including events for future projects the member does not yet participate in
- Use existing visibility rules as the source of which events are relevant
- Generate standard iCalendar content suitable for calendar subscriptions
- Provide a copyable personal subscription link on the event page via a button that opens a miniature modal with a clipboard copy icon

## Requirements

1. The URL must be usable as an external subscription link in Google Calendar, Outlook, etc.
2. The exported feed must contain only future-or-current events.
3. The feed must include events the member can see now, including future projects where membership is not yet decided.
4. The feed must be revocable via token invalidation.
5. Event metadata must include a stable UID, start/end timestamps, title, location, description, and an optional event URL.
6. The implementation must avoid exposing more than the intended event set.

## Design

### Token model

Add a lightweight token model/table for ICS export subscriptions:

- `user_id`
- `selector` or `token`
- `token_hash` (or equivalent secure validator)
- `expires_at` (optional)
- `is_active`
- `created_at`
- `last_used_at` (optional)

This token is used to identify the member and authorize the subscription request.

### Endpoint

Register a route such as:

- `GET /events/export/{token}.ics`

Behavior:

- validate token
- resolve the member
- gather visible events for that member
- filter to current + future events
- render ICS with `Content-Type: text/calendar`

Return 404 / 403 for invalid or revoked tokens.

### UI integration

On the event detail page, add a button to copy the member's personal subscription link. The button opens a small modal with the full URL and a clipboard icon button for copying the link to the clipboard.

### Event selection rules

For a given member, include:

- all events currently visible under the existing member visibility rules
- future events belonging to projects that the member can already see or that are explicitly visible to them
- exclude past events before today

### ICS generation

Each exported event should map to a `VEVENT` entry with:

- `UID`: derived from event ID
- `DTSTAMP`: feed generation timestamp
- `DTSTART` / `DTEND`
- `SUMMARY`: event title
- `LOCATION`: event location if present
- `DESCRIPTION`: optional project name, event type, and relevant details
- `URL`: direct link to `/events/{id}` in the app if the event is visible

### Security

- Keep the token secret and unguessable
- Allow easy revocation by deactivating or deleting the token
- Avoid using a user ID directly in the public URL
- Validate event visibility per member before export

## Future extension

This design leaves room for later improvements:

- multiple export tokens per member
- optional per-token expiration
- limit event feeds by project, type, or date range
- UI for generating and revoking tokens from the member profile

## Acceptance criteria

- A valid token URL returns a calendar subscription feed
- The feed contains only current and future visible events
- Invalid or revoked tokens do not return event data
- The feed is consumable by Google Calendar and similar apps
