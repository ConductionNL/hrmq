---
kind: feature
---

## Why

Hours belong to Humaniq. ADR-107 decision 6 says so plainly: "hours logged on a
case are humaniq time entries carrying the case reference." Ownership settled;
rendering did not.

dossiq wanted to show hours on a case, so it did the obvious thing and
aggregated Humaniq's register from its own manifest:

```json
{ "id": "case-kpis-hours", "type": "stats-block",
  "content": { "entries": [{ "register": "humaniq", "schema": "TimeEntry",
                             "metric": "sum" }] } }
```

On an install without Humaniq that endpoint 404s and the tile renders **`0`**.

`0` is what a real zero renders. A case with no hours booked and a case whose
hours cannot be read are the same pixels — no error, no empty state, nothing a
reader would notice. It looked correct on every case, in every such install, for
as long as it shipped.

That is one instance of a class ADR-113 now names, and the class was measured
four times on that single page in a week. The general half is solved in the
library: a widget declaring `requiredApp` renders chrome and a set-up state
instead of a number. This change closes the specific half — the reason dossiq
had to reach into another app's register at all.

## What this change does

Humaniq supplies the hours surface as an OpenRegister integration leaf,
`humaniq-hours`. A consuming app PLACES the leaf and passes the object context;
it never queries Humaniq's register.

The failure mode disappears rather than being handled. A leaf whose app is
absent is not registered, so there is nothing to render wrongly.

The widget answers, for any object: how many hours are booked against it, the
recent bookings that explain the total, and the two ways to add more — log
hours, or start and stop a timer. It shows a dash rather than a zero when it
cannot read, which is the same reasoning one level down.

Both halves are declared: the JS half mounts the surface, the PHP half makes the
descriptor visible to server-side consumers that never load an app bundle
(ADR-066 decision 1). Registering only one is an orphan registration.

## What this change does not do

It does not move dossiq onto the leaf — that is dossiq's own change. Until then
dossiq's tile declares `requiredApp: humaniq`, which makes it honest but still
leaves it reading another app's register.

It does not add a timesheet UI for the leaf to link to beyond the existing
Timesheets page.

## Continued 2026-09-10: the leaf was dark, and the surface was a stub

The first pass shipped both halves of the leaf, the parity gate went green, and
the surface rendered nowhere. Three separate reasons, each sufficient on its own:

1. **Nothing loaded the client half.** OpenRegister enqueues `js/<app>-leaves.js`
   on consuming pages and skips an app that ships no such artifact, so that no
   page can enqueue a 404. Humaniq's webpack config declares one entry, `main`,
   which is loaded only on Humaniq's own pages. `humaniq-hours` has been dark on
   every dossiq case page since it shipped.
2. **dossiq never moved onto it.** Its `case-kpis-hours` tile still sums
   `humaniq/TimeEntry` from its own manifest. That tile is what a reader sees,
   and it reads `0`.
3. **The timer posted into a 404.** `toggleTimer` calls
   `/apps/humaniq/api/time-entries/timer`, and there is no such route, no
   `TimeEntryController`, and no route for either to live beside. The button
   caught its own failure and set an error line, so the surface never claimed to
   have started anything — but nothing could ever start.

This continuation closes all three and fills in what the surface was always
supposed to be: a KPI tile with the object's total over the caller's own share,
a booking dialog rather than a navigation away, a link into the hour
administration for the object, and a timer that is still running when you come
back to the page.

### The timer is a time entry with no end

A running timer is one open `TimeEntry`: `startedAt` set, `endedAt` absent,
carrying the host object's reference. Nothing else stores it, so nothing else can
disagree with it, and any surface that can read Humaniq's register can see that
the object is being worked on right now.

Two things have to give for that row to exist. `endedAt` is `required` on the
schema, and `TimeEntryStampListener::deriveHours()` refuses a write whose end it
cannot parse. Both are correct for a finished booking and wrong for a running
one, so both learn the distinction rather than being relaxed: an entry with no
end is stamped like any other, contributes zero hours to its parent timesheet,
and skips the span checks that only mean something once there is a span.

### One timer per user, decided by the server

The server resolves the caller's running entry from the caller. A widget-side
guard is not a guard here: two tabs, two objects, or a reload mid-request each
get past it, and each writes a second open row that no stop will ever close.
