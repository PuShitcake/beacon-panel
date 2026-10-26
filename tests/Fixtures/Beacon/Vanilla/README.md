# Vanilla configuration evidence

Verified on 2026-10-22 for the exact Vanilla Minecraft Egg in the Beacon nest.
The pinned Mojang index contains 97 stable server artifacts, from 1.2.5 to 26.3.
This does not include every historical launcher release: earlier entries without an
official server download, snapshots, custom JARs and unindexed releases are not supported.

## Evidence and limits

- All 97 official JARs were checked against Mojang's SHA-1 and byte size.
- All 97 generated configuration files are included here, with timestamps removed
  and generated credentials cleared. No JAR, decompiled source or private log is included.
- Before 1.14, 49 releases reached the actual game `Done` message in disposable,
  network-isolated containers. The other 48 used `--initSettings`, which exits
  after writing settings without accepting the EULA, opening a game port or testing gameplay.
  Four previously verified initSettings captures were reused, not represented as fresh runs.
- Some old captures required bounded Docker shutdown after Console stop (exit 137/143).
  Reaching Done and capturing a file is not a clean-shutdown or gameplay stability guarantee.
- Exact property getter sites in each official JAR were checked as well as generated keys.
  Some old properties, such as spawn protection, are read lazily and may be absent until used.
- Backend tests exercise all 97 files: parser round trips, per-field changes, preservation
  of unrelated values, bounds, removed/stale properties and exclusion of credentials.
- A production React/Chromium fixture run rendered all 97 schemas and exercised dirty-only
  Unicode saves, numeric bounds and enum options. Its API is mocked; it is not 97 live Panel boots.
- Live Panel/Wings create/save/restart verification is separate and covers boundary releases,
  not every release. Results are recorded below after the run completes.
- No player login, multiplayer load, in-game effects of every setting, or security certification
  of obsolete Minecraft versions is claimed. Use currently supported releases for production.

`evidence.json` records artifact identity, sanitized fixture hash, generation mode,
actual generator Java, reuse/shutdown/readiness information and the properties getter class.
These files are regression evidence, not runtime templates. Configuration discovers fields
and values from each instance's actual `server.properties`; it never copies a fixture.
The pinned JAR index identifies the installed runtime for encoding and safety validation only.

## Editable policy

Only an approved subset (up to 24 fields) is considered; it is intersected with keys already
present in the actual server file. Missing keys are never invented. Networking bindings,
management/RCON secrets, advanced generator JSON and unknown settings are not exposed.
Unknown records, comments, duplicate records and unmodified bytes remain intact.

The old blanket view-distance maximum of 32 was incorrect: official releases through
1.7.2 throw above 15, 1.7.3–1.7.10 clamp to 20, and 1.8–1.13.2 clamp to 32.
The UI follows these verified limits and retains a conservative 3–32 policy for modern releases.
This is a safe editor policy, not an exhaustive statement of every value the game accepts.

File discovery does not replace encoding/type rules: modes/difficulty are numeric
before 1.14, adventure is unavailable before 1.3.1 and spectator before 1.8.
Properties use Latin-1 before 1.20; modern releases read UTF-8 with whole-file Latin-1 fallback.
Writes use Java escapes so Unicode is safe in both families. Missing, invalid or removed
values are never silently coerced into a different setting.

`VanillaPropertyPolicy` contains only approved field types, version boundaries and bounds.
Absent fields are not added; known obsolete keys left over from a different version are
not adopted. Unknown JARs remain unavailable rather than guessing validation rules.
The old property-set profile table and its schema class have been removed.
The exact release list, hashes and getter-only properties remain in `evidence.json`.

## World and resource-pack settings

Existing supported fields may include a root world-folder name, a text seed and basic generator
presets, plus a public HTTPS resource-pack URL, SHA-1 and required-pack toggle. Missing fields
are never inserted. Uploaded maps must be extracted with a regular `level.dat` directly inside
the selected folder. Folder names use 1–64 ASCII letters, digits, hyphens or underscores;
runtime directories, symlinks and traversal paths are rejected. Selecting a map never renames
or deletes old maps. A changed seed/generator requires an unused folder, not an existing world.

World saves require the server remain offline and a recent native locked full backup, completed
successfully before any properties write. Native backup permissions, quota and cooldown apply;
no automatic backup deletion or backup-POST retry is added. Active `.pteroignore` exclusions
block world edits. The full backup remains in Backups; the existing exact private properties
restore point is retained too. Files/SFTP and external power writers do not share a filesystem
compare-and-swap lock, so keep other writers stopped during preparation and save.

Resource-pack edits do not fetch URLs through Panel. URLs are visible to joining players:
do not use private credentials or tokens. Editing a URL clears its previous hash in the form.
Saving these settings does not prove that a real client downloaded or accepted the pack.

## Reproduction

Download only the official server artifact identified by Mojang's manifest and verify
its hash/size before executing it. From 1.14 onward, run
`java -Xms64M -Xmx512M -jar server.jar --initSettings` with a compatible Java runtime.
For older releases, use a disposable network-isolated container with no published ports,
accept the EULA only with the operator's approval, wait for Done, then stop and retain
the generated file. A header-only file or file appearing before startup finishes is not evidence.
Clear generated credentials and timestamps before adding a fixture, then record its SHA-256.

The Configuration PHP tests use `--no-configuration --bootstrap vendor/autoload.php`
with migrations disabled and an isolated SQLite in-memory connection where needed.
Do not use the repository's destructive default test bootstrap against an application database.

## Live boundary verification

On 2026-10-23 the file-first replacement passed native Panel/Wings tests for
1.8 (Java 8), 1.20.1 (Java 17) and 26.3 (Java 25). Each temporary instance was created
through Admin, displayed the ordinary missing-file explanation, and reached Done twice.
The real Configuration page saved Unicode MOTD, player limit, flight, game mode and difficulty.
Exact file readback, unchanged bytes, private restore points and persistence after the game's
own rewrite were checked. Game status handshakes returned the matching MOTD and player limit.
Each boot had a 15-second running observation; no unexpected page or Panel API errors occurred.
Temporary servers and sessions were removed and their allocations released afterward.

These are three live boundary cases, not live coverage of all 97 artifacts. They used a root
administrator and test-only EULA/whitelist preparation; no player login, gameplay load,
separate non-admin session or full database-recovery drill was performed.

The world-settings extension also passed a native 1.20.1/Java 17 test: selecting an uploaded
map preserved both old map folders and booted successfully. A second completed full backup
preceded a new world with a text-entered 64-bit seed and Superflat generator; the server reached
Done and independent reading of its actual `level.dat` confirmed the exact seed and generator.
Resource-pack URL, hash and required flag were saved through the real page, but client pack
download/acceptance was not tested. Separate native browser regressions covered numeric folder
names (`1` versus `01`) and unrelated saves with pre-existing invalid pack settings. All temporary
instances and their sessions were removed afterward; no Egg or Wings configuration was edited.

## Primary references

- [Mojang release manifest](https://piston-meta.mojang.com/mc/game/version_manifest_v2.json)
- [1.14 named modes and initSettings](https://feedback.minecraft.net/hc/en-us/articles/360027306551-Minecraft-Village-Pillage-1-14-0-Java)
- [1.20 UTF-8 properties change](https://feedback.minecraft.net/hc/en-us/articles/15649443624973-Minecraft-Java-Edition-1-20-Pre-release-1)
- [1.21.9 property-to-gamerule changes](https://www.minecraft.net/en-us/article/minecraft-java-edition-1-21-9)
