# S02 - Auth users permissions
Status: done      Size: M      Depends on: S01

## Goal
Staff log in, lock and unlock, and see only what their role allows; the host enforces every permission; the first admin is created by the setup wizard.

## Covers
FR-001, FR-002, FR-003, FR-004, FR-005, FR-006, FR-007, FR-008, FR-011, NFR-009   Screens: SCR-01, SCR-02, SCR-29, SCR-34   ADRs: ADR-009

## Data
users, roles, role_permissions, user_permission_overrides, sessions; seed roles and permission catalogue (db/seed/roles.sql)

## Interfaces
`POST /auth/login|logout|unlock|password`, users and roles CRUD (no delete), `POST /setup/admin` (only when no admin)

## Acceptance
- [ ] PBKDF2-SHA256 600k; salts 16 bytes; constant-time compare
- [ ] Lockout after configured failures; unlock by admin; every attempt audited (audit writer from S03 stub ok, wired fully in S03)
- [ ] Server idle timeout per user; client lock screen after the same timeout keeps the current form state
- [ ] Every endpoint declares a permission; test `AllEndpointsHavePermission` fails otherwise
- [ ] Forbidden call returns 403 problem+json `PERMISSION_DENIED`
- [ ] Menu built from the user's effective permissions
- [ ] Fresh DB: login screen redirects to setup wizard; after admin exists, `/setup/admin` returns 409
- [ ] Password policy: min 10, not username, not last 3; reset forces change

## Files expected
src/MediCare.Host/Auth/*, Endpoints/Users*, src/MediCare.Client/Screens/Login*, Lock*, Users*, Setup*

## Tests
Host.Tests for login, lockout, timeout, permission filter, setup; Client: login form validation unit tests

## Out of scope
Approvals (S11)

## Notes
Never log the request body of auth endpoints.

## Implementation
Host `Security/Auth.cs`, `Modules/SetupAuthModule.cs`, `Modules/AdminModule.cs`; client `Shell/LoginForms.cs`, `Screens/AdminScreens.cs` (UsersScreen, RoleDialog).
