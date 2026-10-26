# Fabric Configuration

The exact `Fabric` Egg in the Beacon nest uses the shared file-first Java properties writer.
Only supported keys already in the real `server.properties` are exposed. No template or
missing default is inserted; no mod configuration, mod installation or Egg change is included.

## Runtime identity

The imported Egg uses Fabric Installer's manual server layout: `SERVER_JARFILE` names a
small generated launcher, and `fabric-server-launcher.properties` explicitly names the real
Minecraft server JAR via `serverJar`. Minecraft and Fabric Loader versions are not the same.
The adapter never guesses a game version from Startup/latest or a loader/mapping filename.

The launcher is bounded to 1 MiB and inspected as a ZIP in a private, automatically closed
temporary file. Nothing is executed or extracted. Only the two-file, unshaded installer
layout and recognized Fabric launcher/Knot entrypoints are accepted. Manifest continuations
are decoded; unknown/duplicate attributes, embedded executable classes, network classpaths,
traversal and overridden startup/argfiles are rejected. Relative library paths are bounded;
each directory segment and final file must be regular, never a symlink. No classpath URL is
fetched. Shaded old loaders, custom launchers and automatic-download launchers remain unavailable.

The explicit game target must be a regular bounded root JAR. Its full streamed SHA-1 and
size must match the existing pinned official Mojang release index. A mapping path, if present,
must agree with that verified game. The initial policy allows indexed stable releases from
1.14 onwards. Unknown/custom game JARs and snapshots remain read-only. This verifies the base
Minecraft artifact and a reviewed launcher layout, **not** the authenticity or compatibility
of every Fabric library/mod, or the effects of code supplied by mods.

Fabric intentionally bypasses launcher-only runtime caching: the external launch properties
or game JAR may change without touching the launcher. Every read and save verifies them again.
Launcher/property identity, game file metadata, bounded library metadata and Startup participate
in the runtime hash; drift is rejected before writing. Missing properties produce the ordinary
waiting UI, without starting the game or generating any file automatically.

## Writes and world safety

Native permissions, offline/active-operation checks, row locking, lossless patching, exact
private restore/readback and optimistic concurrency are shared with Vanilla. World selection,
seed and generator changes require a completed locked full backup and safe root world paths.
No map is converted, moved, deleted or regenerated. Preserve matching mods, data packs and
custom dimension data with uploaded worlds. Mods can override normal game behavior; this
page does not discover every mod-defined setting or guarantee that a map is compatible.
Resource fields remain Java-client settings; saving them is not proof of client delivery.

## Validation

`FabricConfigurationTest` inherits the full Vanilla writer/permissions/backup/race contract.
`FabricConfigurationRuntimeTest` uses synthetic non-executable launcher ZIPs, mocked Wings
and a deterministic game-hash lookup. It covers safe targets, symlinks, traversal, startup
overrides, unknown launchers, mapping mismatch, malformed metadata, byte limits and drift.
Use the guarded isolated SQLite invocation with `--no-configuration --bootstrap vendor/autoload.php`;
the default database bootstrap must never run against application data.

Native validation exposed and corrected `BEACON-ERR-010`: the initial relative-path policy
rejected the literal `+` in the official `sponge-mixin` Maven build version. A new isolated
regression failed against the original source. The fix permits `+` inside safe relative
components, not percent/form decoding or URLs. Independent parsing of the actual generated
launcher showed the original regex rejected exactly that library; the corrected parser
accepted the same artifact. No Egg or library file was changed to bypass validation.

On 2026-10-26, a post-fix temporary Fabric 1.20.1/Java 17 instance with Loader 0.20.1 passed
real Admin Create, Client Configuration and websocket Console. Initial, uploaded-map and
fresh-seeded-map boots reached Done with 15-second running observations. Each world change
waited for a completed locked full backup. Independent `level.dat` reading verified the exact
64-bit seed `1234567890123456789` and `minecraft:flat`; old maps stayed intact. Resource fields
saved without an extra world backup. Unrelated bytes, exact private restore points and settings
after game rewrite were verified. No page exception or Panel HTTP error occurred in that pass.

Two earlier failed cases remain recorded privately: the first private Paper-derived harness
tried to read an absent preboot properties file despite the app's correct waiting response;
the second booted but the app correctly stayed unavailable under the too-strict Maven path
policy. Neither is called a pass. Both exact servers were removed and independently audited.
The failed missing-file read was reproduced separately with a controlled Wings 500; only the
private harness needed a file-existence guard for that issue. The actual path-policy bug was
then repaired in application code and the same game/version rerun, not replaced with a new sample.

Cleanup of the passing instance verified native absence/Wings 404 and no assigned test
allocations/backups/operations. Its captured session read back absent. Exact properties
restores were copied/verified before removing only orphan test copies. SQL and Egg contracts
remained intact; only native startup variable timestamps changed.

A second fresh temporary Fabric 26.3/Java 25 instance with Loader 0.20.1 passed real browser
read/save/reboot testing on the final code. The correct 26.3 field set was displayed. Thai
MOTD, maximum players and flight settings survived a game rewrite; unrelated bytes and the
exact private restore point were verified. Both boots reached Done with 15-second running
observations. Saved/ready screenshots were inspected, with no page exceptions or Panel HTTP
errors. Native Java restricted/deprecated API warnings were recorded, not repaired in this
Configuration change. The test does not prove mod compatibility or future Java compatibility.

Independent audits verified all four exact temporary UUIDs (including both failed attempts)
absent from Panel and Wings, no assigned test allocations/backups/operations, and all four
captured sessions absent. Four orphan private properties restores from the passing cases
were copied/verified before removing only their application-volume copies. SQL/Egg contracts
remained intact. Thirteen principal source/runtime hashes match and local Panel/worker are
healthy. No Egg, Wings, environment, schema, Mod Installer policy or production deployment
was changed; no commit or push was performed. The full world/resource workflow was live-tested
on 1.20.1; 26.3 was read/save/reboot. No all-version, real-mod compatibility, player login,
gameplay/load, separate non-admin session, client resource-pack delivery or full restored-database
validation is claimed.

## Primary references

- [Fabric manual and simple server installation](https://wiki.fabricmc.net/install)
- [Installer-generated launcher structure](https://github.com/FabricMC/fabric-installer/blob/master/src/main/java/net/fabricmc/installer/server/ServerInstaller.java)
- [Fabric launcher game JAR selection](https://github.com/FabricMC/fabric-loader/blob/master/src/main/java/net/fabricmc/loader/impl/launch/server/FabricServerLauncher.java)
- [Fabric runtime override properties](https://github.com/FabricMC/fabric-loader/blob/master/src/main/java/net/fabricmc/loader/impl/util/SystemProperties.java)
