# Paper Configuration

The exact `Paper` Egg in the Beacon nest uses the file-first Java Configuration writer.
The form is an allowlisted intersection with the installed `server.properties`, never a
release template. Missing fields/defaults are not inserted. The exact combined
`Paper + Geyser + Floodgate` Egg uses a separate adapter sharing the same Paper verifier,
property policy and world safety checks. Other Eggs retain their existing adapters.

The installed root `SERVER_JARFILE` is bounded, streamed and SHA-256 hashed. Its bounded
`version.json` (or legacy Paperclip's bounded `patch.properties`) is only a candidate version:
the entire file hash and size must match a build
returned by PaperMC's fixed HTTPS Downloads Service endpoint. JARs are not executed or
extracted. Temporary verification files are closed/removed after inspection. Metadata reads
have time/size limits, no redirects/retries and a five-minute checksum cache. Mutations always
rehash the installed JAR; build identity participates in optimistic concurrency. Provider
failure, custom JARs, snapshots and unrecognized releases never fall back to Startup/latest.
Legacy metadata is accepted only before Minecraft 1.18 and only when modern metadata is absent;
malformed modern metadata never falls through to the legacy reader. Legacy `sourceUrl` and
other download/patch instructions are ignored, not fetched or executed.

The initial policy covers indexed stable Minecraft releases from 1.16.5 onwards, and only
settings actually present. It is not a claim that every Paper build/version was boot-tested.
Java encoding, field types and semantic boundaries are shared with Vanilla; Paper-specific
storage checks and explanatory notes are separate. The page does not edit Paper YAML,
`spigot.yml`, plugins or their world-specific overrides. Bindings and credentials are omitted.

World changes need an offline server and a recent successful locked full native backup.
The exact configuration restore/readback and concurrency checks remain shared with Vanilla.
Paper worlds must use the server root; custom world/config launch flags and unsafe or
non-root Bukkit world-container settings block world edits. Existing matching Nether/End
folders cannot be adopted as fresh seeded worlds or followed through symlinks. Selecting an
uploaded map never renames/deletes/converts its dimensions; administrators must upload the
complete layout matching their Paper release. Resource links are stored for clients, not
downloaded by Panel; saving them does not prove in-game delivery.

The isolated `PaperConfigurationTest` inherits the Vanilla mutation regression contract and
adds exact-Egg, build-change and dimension/storage cases. `PaperConfigurationRuntimeTest`
uses synthetic non-executable ZIPs and mocked HTTP checksums to test verification failures.
Use `--no-configuration --bootstrap vendor/autoload.php` and the documented guarded test
environment; never run the default destructive database bootstrap against Panel data.

## Native validation

On 2026-10-26, Paper 1.21.1/Java 21 passed native Admin creation and real Client
Configuration/Console tests on a temporary instance. Before first boot, Wings had a properties
stub containing only its port and bind address; the form added no fields/defaults. After boot,
the page discovered the actual game-generated fields. Uploaded-map selection and a subsequent
fresh-seeded Superflat world each waited for a completed locked full backup. Both reached Done
with 15-second running checks. Independent `level.dat` reading confirmed the exact 64-bit seed
and flat generator. Old maps and unrelated properties bytes remained intact, and settings
persisted after game rewrite. Resource-pack fields were saved, not downloaded by a player.
Screenshots, native file readbacks and private restore copies were checked; no page exceptions
or Panel HTTP errors occurred. The temporary server/session were removed and allocations freed.

After adding explicit legacy Paperclip metadata support, the final code passed native
1.16.5/Java 8, 1.21.1/Java 21 and 26.3/Java 25 read/save/reboot cases. Each reached Done twice,
preserved unrelated bytes and an exact private restore point, and retained Unicode MOTD,
player limit and flight changes after game rewrite. Version-specific fields, real browser
controls and screenshots were checked with no page exceptions or Panel HTTP errors. Cleanup
verified all exact temporary instances absent from Panel/Wings and their allocations freed.

This is not every Paper version/build, a separate non-admin owner session, gameplay/load,
plugin-override behavior, full disaster recovery or client resource-pack delivery validation.

## Paper + Geyser + Floodgate

`PaperGeyserConfigurationService` is scoped to that exact Egg name in Beacon. The
installed Paper JAR, not Geyser's version or declared Startup version, determines Java
properties validation. Its full inherited writer regression contract covers offline saves,
runtime drift, permissions, backups, dimension safety and lossless readback. Unsupported
Eggs cannot acquire the adapter by a partial name match.

This remains a Java `server.properties` editor, not a Geyser/Floodgate YAML editor or native
Bedrock configuration editor. It never reads/writes plugin configuration or authentication
keys, changes allocations or automatically disables authentication. Notes distinguish
Java resource packs from separately configured Bedrock packs and warn that Bedrock display,
authentication and connection behavior are not validated by saving Java properties.
No automatic plugin upgrade, world conversion, Egg or Wings modification is included.

On 2026-10-26, the combined Egg on Paper 1.21.1/Java 21 passed native Admin creation and
real browser Configuration/Console testing after a backed-up local Panel rebuild. Initial,
uploaded-map and fresh-seeded-world boots reached Done with 15-second running checks.
Each world change waited for a completed locked full backup. Independent `level.dat` reading
confirmed seed `1234567890123456789` and `minecraft:flat`; old maps remained intact.
Resource-pack fields saved without another world backup. Plugin JAR/config/key hashes
checked before and after configuration mutations matched. Unrelated properties bytes,
exact private restore points and settings across game rewrites were verified. No page
exceptions or Panel HTTP errors occurred, and the temporary instance was removed.

Geyser and Floodgate enabling messages were observed, but this is not a Bedrock login or
resource-pack delivery test. The unchanged Egg downloads current Geyser/Floodgate releases:
on the older 1.21.1 game Geyser warned that ViaVersion is needed for its current target
protocol, and that NetherNet was not enabled. Those plugin/network compatibility warnings
were recorded, not repaired by changing the Egg or installing extra plugins.

A second fresh temporary instance on Paper 26.3/Java 25 passed real browser read/save/reboot.
MOTD with Thai text, maximum players and flight settings survived game rewrite; unrelated
bytes and the exact private restore point were verified. Both boots reached Done with
15-second running observations and both crossplay plugins emitted enabling messages.
The correct 26.3 field set and saved/ready screenshots were inspected. No page exceptions
or Panel HTTP errors occurred. Native game/plugin warnings (including missing NetherNet,
deprecated Java API use and a spark statistics timeout) were not treated as Configuration
failures or repaired in this change. These results do not prove Bedrock connectivity.

Independent cleanup verified both exact temporary UUIDs absent from Panel and Wings, freed
allocations and removed only their own backups/operations. Their captured sessions read
back absent. Private properties restores were copied/verified before removing orphan test
copies from the application volume. SQL checksums and Egg/variable contracts were unchanged;
only normal startup variable timestamps changed in the first run. No Egg, Wings, environment
or schema edit, production deployment, commit or push was performed. Local Panel/worker
source hashes match. This is two sampled releases, not an all-version compatibility claim.

## Primary references

- [Paper server.properties](https://docs.papermc.io/paper/reference/server-properties/)
- [PaperMC Downloads Service](https://docs.papermc.io/misc/downloads-service/)
- [World migration and dimension layout](https://docs.papermc.io/paper/migration/)
- [Spigot world-specific overrides](https://docs.papermc.io/paper/reference/spigot-configuration/)
- [Geyser setup and separate listener configuration](https://geysermc.org/wiki/geyser/setup/)
- [Floodgate setup and private authentication keys](https://geysermc.org/wiki/floodgate/setup/)
- [Geyser Bedrock resource packs](https://geysermc.org/wiki/geyser/packs/)
