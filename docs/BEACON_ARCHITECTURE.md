# Beacon Panel Architecture

Beacon Panel is a public fork of Pterodactyl Panel. Beacon features extend the native Laravel,
React, Eloquent, queue, permission, backup, and Wings layers; they do not introduce a second web
application or duplicate Panel-owned data.

## Current capabilities

- Curated Minecraft Vanilla and Paper catalog records mapped to native Eggs.
- Trusted, queued server provisioning at `/api/beacon/v1` using Application API server ACLs.
- Native server lifecycle operations and persisted operation status.
- A native Minecraft `server.properties` screen limited to approved keys with file permissions,
  stopped-server enforcement, and hash-based lost-update protection.
- A Modrinth-backed Paper plugin manager with exact-version preview, dependency bounds, approved
  CDN and filename policies, queue-backed install/remove operations, safety backups, and manifests.
- Native Modpack and Mod Installer pages with persisted operations, automatic power handling,
  managed dependencies, rollback protection, and Beacon-only runtime Egg selection.
- Shared Minecraft software capabilities enforce Vanilla, Paper, Forge, Fabric, NeoForge, and
  Quilt installer boundaries in both the Client API and server navigation.
- An owner/root-admin-only RCON & Query Manager embedded in the native server Settings page for
  Beacon Minecraft servers. It reserves native allocations, synchronizes Wings, edits only the
  required `server.properties` keys, generates an encrypted RCON password, and restores the
  server's previous power state.

## Installation and upgrade

Normal Panel deployment requirements still apply. After code is deployed, an operator must run:

```bash
php artisan migrate --force
php artisan p:beacon:catalog:seed-minecraft
php artisan optimize:clear
```

At least one `standard` Laravel queue worker must be running because provisioning and content
mutations, including RCON and Query changes, are asynchronous. Database seeding synchronizes trusted Vanilla, Paper, and Forge runtime
Eggs into the `Beacon` nest. Beacon provisioning and runtime switching never assign servers to the
upstream `Minecraft` nest. Configure `BEACON_MINECRAFT_VERSIONS` with exact game versions that the
installation supports; `latest` is intentionally not used for content compatibility.

Do not run these commands against production without the normal database and file backup process.

## Beacon API contract

All endpoints are rooted at `/api/beacon/v1`. Authenticate with a native Pterodactyl Application
API key that has the required server ACL. Mutation requests require:

- `X-Correlation-ID`: a UUID;
- `Idempotency-Key`: 16-128 safe characters for asynchronous create/content operations.

Server create requests select an application, profile, exact version, and preset. They cannot
provide a Docker image, startup command, install script, arbitrary environment, or raw resources.
Poll the returned operation UUID until it is `succeeded` or `failed`.

## Modrinth and Wings trust boundary

Provider responses are size-bounded and normalized. Downloads must be HTTPS URLs on the approved
Modrinth CDN, use a safe `.jar` basename, and satisfy per-file, graph-depth, file-count, and total
size limits. The immutable plan stored on the operation is the plan consumed by the worker. Wings
performs foreground pulls; Panel records Modrinth's SHA-512 metadata without downloading plugin
binaries into the Panel container.

Content changes require a confirmed stopped state from Wings by default; a missing or malformed
state fails closed. A native backup is completed before the file mutation when
`BEACON_CONTENT_BACKUP_BEFORE_MUTATION=true`. Installation failures remove files downloaded by that
operation. If automatic cleanup also fails, the operation reports
`content_install_rollback_failed` with the affected destination and filenames for manual recovery.
Operations and safe activity metadata remain available for audit.

## Minecraft software capabilities

Vanilla keeps the Mod and Modpack Installer navigation visible so owners can see why each feature
is unavailable, but both mutation and provider APIs fail closed. Paper alone exposes the Plugin
Installer. Forge, Fabric, NeoForge, and Quilt expose the Mod and Modpack Installers without exposing
Paper plugins, and a selected modpack release must match the server's loader. An existing managed
modpack supplies its active loader to these checks so update, recovery, and add-on Mod operations
remain available.

## RCON and Query safety boundary

The Client API never returns the RCON password and operation payloads, results, history, and
activity records never contain it. The encrypted database field is used only to track whether a
Beacon-managed password exists; the active value is also required by Minecraft in
`server.properties`. Disable removes the managed password and port keys from that file.

Each service receives a dedicated native allocation. Its database ID is recorded before use, and
disable can release only that exact non-primary allocation while it still belongs to the same
server. These internal service ports do not consume the user-facing additional-allocation quota;
they are selected from existing free allocations first, then from the administrator-configured
`BEACON_MINECRAFT_SERVICES_PORT_RANGE_START` to `BEACON_MINECRAFT_SERVICES_PORT_RANGE_END` range.
The native Network API refuses to delete or promote a tracked service allocation. Manually
configured RCON or Query settings are reported as drift and are not adopted, overwritten, or
released. A running server is stopped gracefully before mutation and restarted only if it was
running beforehand.

## Deliberately gated work

- Fabric, Forge, NeoForge, and Quilt require dedicated, reviewed creation contracts before their
  catalog profiles can be enabled directly during server creation. Modpack installation may reuse
  the Beacon Vanilla or Forge runtime Egg after its guarded installer has created the loader files.
- SFTP aliases remain disabled. If retained, they must bind to native Panel Users and permissions
  and pass real `/api/remote/sftp/auth` validation with Wings. Legacy duplicated identities or
  special CUSTOMER/STAFF headers must not be ported.
- Billing, payment, wallet, subscription, order, and pricing systems are outside this repository.

## Validation gates

Before a release, run PHP CS Fixer, PHPUnit unit and integration suites against an isolated test
database, ESLint, TypeScript, the production frontend build, and focused native Server/File/Backup/
SFTP regression tests. Live Wings install/remove and backup rollback are release gates; source-only
tests cannot replace them.
