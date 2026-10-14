# ADR-009: Authentication, sessions, permissions and inline approvals
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
Offline, no directory service. BRD s4/s12: salted hashes, server-side enforcement, approvals for sensitive actions, session lock.

## Options
Windows accounts/AD (not present); JWT (needs revocation list anyway); **opaque server sessions**.

## Decision
Passwords and PINs: PBKDF2-HMAC-SHA256, 600,000 iterations, 16-byte salt, verified only on the host. Session token: 32 random bytes, stored as SHA-256 hash; idle timeout per user enforced on the host. Permissions: named strings, roles + user overrides; each endpoint declares one permission, and a test fails when one is missing. Inline approvals: approver + PIN + reason → single-use token bound to the request hash (5 min). Lockout after configurable failures. Setup wizard creates the first admin; no default credentials.

## Consequences
Simple to reason about and audit. PIN entry at counters is visible to bystanders: PIN field masked; approvers can change PINs; audit shows approver and PC.
