# Conventions

## Repository layout
See the code map in `CLAUDE.md`. One project per deployable plus `Contracts` (shared DTOs) and `Core` (pure logic).

## C#
- `LangVersion latest`, nullable enabled, warnings are fixed, not suppressed (only designer-analyzer WFO1000/WFO0003 and CA1416 are off).
- File-scoped namespaces; one area per file in the host (`XxxModule` maps endpoints, `XxxService` holds the logic).
- Names: `PascalCase` types and members, `_camel` private fields, SQL columns `snake_case`. Dapper maps with `MatchNamesWithUnderscores`.
- Host services are DI singletons with primary constructors; no static state except caches guarded for thread safety.
- Contracts and Core target netstandard2.0 and use PolySharp; keep them free of host or UI dependencies.

## Money, quantity, time
- Database: integer paisa (`*_paisa`), unit cost ×10,000 (`*_e4`), basis points (`*_bp`), quantities in base units (tablets).
- API and UI: `decimal` rupees with 2 places. Rounding: half away from zero (`Money.Round2`).
- Time: stored as UTC ISO-8601 text; displayed in Pakistan Standard Time (`Clock.ToPkt`). Business date comes from the host (`Clock.BusinessDate`). Dates in queries are `yyyy-MM-dd`.
- Expiry `MM/YY` means the last day of that month. A batch is saleable while expiry > business date.

## Host rules
- Endpoint metadata declares the permission: `.Perm(Perm.X)`, `.Authenticated()`, `.Anonymous()`. The middleware enforces it; services re-check sensitive cases.
- Errors: throw `AppException(status, ErrorCodes.X, message, details)`. The message is shown to the person as written.
- Postings: idempotency key + request hash (`Idempotency.Find/Save`), number from `DocNumbers.Next`, a balanced `Journal`, stock via `StockLedger.Move`, an `Audit.Write` row. All inside one `Db.Write`.
- Approvals: `ApprovalService.Require(...)` throws `APPROVAL_REQUIRED` with `action`, `contextKey`, `value` details; tokens arrive in the body or the `X-Approval-Token` header.
- Never edit a shipped migration. Triggers protect posted rows; corrections are reversals.

## Client rules
- Must build for `net48` and `net10.0-windows`. Use `Compat` helpers for APIs missing on .NET Framework.
- Screens: `AppScreen` subclasses, created once and cached by the shell; load in `OnFirstShow`, refresh in `OnReshow`; expose keys through `FKeys`.
- Dialogs: `DialogForm` with `AddOk(text, Func<Task<bool>>)`; errors show in the inline message bar.
- Async: event handlers call `UiRun.Fire` / `Run`. Never `.Result` or `.Wait()` on the UI thread.
- Postings that may need approval go through `Approvals.Run(owner, tokens => api call)`.
- Every posting dialog creates one idempotency key when it opens and reuses it on retry.
- Layout: docked panels with `Stack(...)` (it keeps the visual order); sizes through `Theme.S(px)`; fonts and colours only from `Theme`.

## Tests
- Core: xUnit unit tests with the vectors in `docs/04-development/testing.md`.
- Host: end-to-end scenarios over HTTP with `WebApplicationFactory`, a temp data directory, PBKDF2 iterations lowered, and the clock injectable via `Clock.UtcNowFn`. Tests run serially.
- A bug fix comes with a test that fails before the fix.

## Commits
Imperative subject (≤ 72 chars), body says why. One slice or fix per commit.
