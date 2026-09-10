# Design: the hours leaf, its bundle, and the running timer

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Why |
|---|---|---|
| The object's hours total and the caller's share | **declarative** | Both are read client-side from one OpenRegister query on `domainObjectType` + `domainObjectRef` and summed in the tile. No service, no stored total, nothing to go stale. |
| The parent timesheet's total while an entry is open | **declarative** | `TimesheetAggregateListener` already recomputes from truth on every entry write. A running entry carries `hours: 0`, so the existing aggregation needs no new branch. |
| Start / stop / resolve the running timer | **imperative** | ADR-031's lifecycle-guard exception. "At most one running timer per user" is a constraint across rows the caller does not send, decided from the caller's identity. A declarative rule on the schema cannot express it, and a client-side check is defeated by a second tab. |
| Accepting an entry with no end | **imperative** | It is a change to an existing guard, `TimeEntryStampListener::deriveHours()`, which is already imperative because it refuses impossible input with a message a person reads. |

## Why the open entry, and not the alternatives

Considered and rejected:

- **A separate `RunningTimer` schema.** Keeps `TimeEntry` and the approval
  process untouched, at the cost of a second place hours-shaped state lives and a
  stop that has to write one row and delete another. Two rows and one intent is
  how a stop that half-fails leaves a timer that is neither running nor booked.
- **Per-user app config.** Cheapest and survives a reload, but invisible to every
  other surface and to the user's other devices. "Is this case being worked on"
  becomes a question only one browser can answer.

The open entry costs two guard changes and answers the question everywhere.

## Where the three defects were

`LeafScriptListener` (OpenRegister) enqueues `js/<app>-leaves.js` for every
render-surface leaf whose providing app is not the current app, and only on pages
of apps that themselves ship a register descriptor. It skips an app with no such
artifact rather than enqueuing a 404. planninq builds the entry; Humaniq does
not, so the skip was silent and total.

The parity gate compares the two halves of the descriptor against each other. It
cannot see that neither half is on the page, which is why both halves were green
while the surface was absent.

That is NOT ADR-113, and it is worth being precise about which one it is.
ADR-113 decides that a widget leaning on another app declares `requiredApp`,
renders a set-up state rather than a number, and issues no request. It covers the
tile that read `0`, which is the OTHER half of this change. It does not cover a
check that compares two things to each other without asking whether either is
present, which is what happened here and has no decision recorded against it
yet.

## Endpoint shape

Three routes, all `#[NoAdminRequired]`, all resolving the entry from the CALLER
rather than from an id the caller sends, so there is no object reference to
tamper with:

| Route | Answers |
|---|---|
| `GET /api/time-entries/timer` | The caller's running entry, or none. |
| `POST /api/time-entries/timer/start` | Writes the open entry, or refuses because one is already running and names the object it belongs to. |
| `POST /api/time-entries/timer/stop` | Writes `endedAt` on the caller's running entry, or refuses because there is none. |

`start` takes `domainObjectType` and `domainObjectRef` and nothing else the
employee could have typed. `stop` takes nothing at all.

## Seed data

No new schema, so no new seed objects. The existing `hr-seed.json` time entries
stay as they are: every one of them is a finished booking, which is what makes
them useful as the contrast case for a running one.

The e2e journey seeds its own open entry through the endpoint rather than through
the fixture, because a fixture-written open entry would bypass the very guard the
journey exists to exercise.
