# ADR-003: Protocol: HTTPS/1.1 + JSON with long-poll events and a pinned certificate
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
Clients run on .NET Framework 4.8 (Windows 7) and .NET 10. Research ([tech.md](../../01-idea/research/tech.md) T2): Grpc.Net.Client needs WinHttpHandler, which is fully supported only on Windows 11; Grpc.Core is deprecated; WCF has no .NET 10 server.

## Options
gRPC; gRPC-Web; raw TCP + MessagePack; **HTTPS/1.1 REST-style JSON**; WCF/CoreWCF.

## Decision
ASP.NET Core minimal APIs on Kestrel over HTTPS/1.1, JSON via System.Text.Json (NuGet on net48). Server-to-client signals use `GET /events?after=` long-poll (25 s). TLS 1.2+ with a self-signed certificate generated at install; clients pin its SHA-256 fingerprint at pairing (FR-012). Commands that post use an `Idempotency-Key` header.

## Consequences
Works identically on both runtimes and is easy to debug with any HTTP tool. Windows 7 needs TLS 1.2 enabled in SChannel (installer step, ADR-016). Long-poll adds up to 25 s latency for non-urgent events; urgent flows (approvals) reply synchronously.
