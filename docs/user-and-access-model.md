# User and Access Model

## User Types

- **Visitor (anonymous):** can browse the Screener, view Candidate lists and charts.
- **Registered User:** an authenticated Visitor who can additionally save Screeners and maintain a Watchlist.
- **Admin (Operator):** controls and supervises Ingestion Runs and the Instrument universe.

## Roles

- `visitor` (implicit, no account)
- `registered` (user account)
- `admin` (privileged account)

## Permissions

| Capability | Visitor | Registered User | Admin |
| --- | --- | --- | --- |
| Browse Screener and results | yes | yes | yes |
| View interactive chart of a Candidate | yes | yes | yes |
| Save / apply Saved Screeners | no | yes (own) | yes |
| Maintain Watchlist | no | yes (own) | yes |
| Trigger / re-run Ingestion Runs | no | no | yes |
| Manage Instrument universe | no | no | yes |
| View ingestion logs and telemetry | no | no | yes |

## Ownership Boundaries

- Saved Screeners and Watchlist Entries are private: a Registered User only sees and modifies their own.
- Registered Users never own or edit Instruments, Daily Bars, Snapshots, Signals or Ingestion Runs; those are system/Admin-owned.
- Admin actions are global (affect all users' Candidates on the next run).

## Access Rules

- Browsing requires no session.
- Saving a Screener, editing a Watchlist, or running ingestion requires authentication.
- Only Admin-role accounts see the Admin panel; hiding it in the UI is not sufficient, the API must enforce the role.
- Registration is email-based; no paid plan gating in the MVP.

## Revocation / Expiry

- Sessions expire after a configured idle/absolute timeout and can be revoked by the user logging out.
- Deactivating or deleting an account revokes access to owned Saved Screeners and Watchlists (cascade delete acceptable in the MVP).
- Admin role is granted out of band (seed/manual), not self-service in the MVP.

## Edge Cases

- A Visitor tries a protected action: redirect to registration/sign-in, preserve the attempted action when feasible.
- A user attempts to read another user's Saved Screener by id: must be denied (ownership check on every request).
- The last Admin account must not be deletable/demotable through the UI in a way that locks the system (manual recovery acceptable, but documented).
- Anonymous users may hit rate limits on Screener queries; the MVP should keep at least a basic throttle.
