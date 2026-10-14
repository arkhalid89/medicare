# ADR-004: One WinForms codebase for net48 and net10.0-windows
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
The owner requires clients on Windows 7 and Windows 10/11. .NET 10 does not support Windows 7.

## Options
Two separate apps; .NET Framework 4.8 only (old runtime everywhere); **multi-target one SDK-style project**; web client in a browser (rejected: BRD requires WinForms).

## Decision
`MediCare.Client` targets `net48;net10.0-windows`. Shared logic lives in `netstandard2.0` libraries (Contracts, Core). C# latest language version with PolySharp polyfills. Runtime-specific code is limited to `#if NET48` blocks in the bootstrap (DPI mode, HttpClient handler, TLS setup) and is listed in conventions.md.

## Consequences
The test matrix doubles: CI builds both targets, and every release runs the smoke suite on a Win7 VM and a Win11 VM. Some newer WinForms APIs (dark mode, `ApplicationConfiguration`) cannot be used. Windows 7 is unsupported by Microsoft, so this is a time-limited bridge: when the last Win7 PC is retired, drop `net48` with no other change.
