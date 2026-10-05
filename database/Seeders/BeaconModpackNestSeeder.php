<?php

namespace Database\Seeders;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\EggVariable;

class BeaconModpackNestSeeder extends Seeder
{
    private const INSTALLER_IMAGE = 'debian:bookworm-slim';
    private const RUNTIME_EGGS = ['Vanilla Minecraft', 'Paper', 'Forge Minecraft'];

    public function run(): void
    {
        DB::transaction(function () {
            $nestName = config('beacon.modpacks.nest.name');
            $nestAuthor = config('beacon.modpacks.nest.author');
            $installerName = config('beacon.modpacks.nest.installer_egg');

            // Prefer the existing Beacon runtime nest. Older Beacon installs can
            // use a different author, and creating a second nest with the same
            // visible name makes the administrator UI ambiguous.
            $nest = Nest::query()
                ->where('name', $nestName)
                ->whereHas('eggs', fn ($query) => $query->where('name', '!=', $installerName))
                ->orderBy('id')
                ->first()
                ?? Nest::query()->where('name', $nestName)->where('author', '!=', $nestAuthor)->orderBy('id')->first()
                ?? Nest::query()->where('name', $nestName)->where('author', $nestAuthor)->orderBy('id')->first()
                ?? new Nest();
            if (!$nest->exists) {
                $nest->uuid = Uuid::uuid4()->toString();
                $nest->forceFill([
                    'author' => $nestAuthor,
                    'name' => $nestName,
                    'description' => 'Beacon Minecraft server and internal installation templates.',
                ]);
                $nest->save();
            }

            $egg = Egg::query()
                ->where('author', $nestAuthor)
                ->where('name', $installerName)
                ->first() ?? new Egg();
            if (!$egg->exists) {
                $egg->uuid = Uuid::uuid4()->toString();
            }
            $egg->forceFill([
                'nest_id' => $nest->id,
                'author' => $nestAuthor,
                'name' => $installerName,
                'description' => 'Internal transaction-safe installer used by Beacon Panel modpack operations.',
                'features' => [],
                'docker_images' => ['Installer' => self::INSTALLER_IMAGE],
                'file_denylist' => [],
                'config_files' => '{}',
                'config_startup' => '{"done":"BEACON_INSTALL_COMPLETE"}',
                'config_logs' => '{}',
                'config_stop' => '^C',
                'startup' => 'echo BEACON_INSTALLER_ONLY',
                'script_is_privileged' => false,
                'script_install' => $this->installScript(),
                'script_entry' => 'bash',
                'script_container' => self::INSTALLER_IMAGE,
                'copy_script_from' => null,
                'config_from' => null,
                'force_outgoing_ip' => false,
            ])->save();

            EggVariable::query()->updateOrCreate([
                'egg_id' => $egg->id,
                'env_variable' => 'BEACON_MANIFEST_PATH',
            ], [
                'name' => 'Beacon Manifest Path',
                'description' => 'Internal path written by Beacon Panel before the installer runs.',
                'default_value' => '.beacon/modpack-install.json',
                'user_viewable' => false,
                'user_editable' => false,
                'rules' => 'required|string|in:.beacon/modpack-install.json',
            ]);

            $this->syncRuntimeEggs($nest);

            // Remove only empty duplicate nests previously created by this
            // seeder. Never delete an administrator-owned or populated nest.
            Nest::query()
                ->where('name', $nestName)
                ->where('author', $nestAuthor)
                ->where('id', '!=', $nest->id)
                ->doesntHave('eggs')
                ->delete();
        });
    }

    /**
     * Keep trusted copies of the upstream Minecraft runtime Eggs inside the
     * Beacon nest. Beacon provisioning never binds a server to the upstream
     * Minecraft nest directly.
     */
    private function syncRuntimeEggs(Nest $beaconNest): void
    {
        $minecraftNest = Nest::query()
            ->where('name', 'Minecraft')
            ->where('author', 'support@pterodactyl.io')
            ->first();
        if (!$minecraftNest instanceof Nest) {
            throw new \RuntimeException('The upstream Minecraft nest is required to seed trusted Beacon runtime Eggs.');
        }

        $runtimeEggs = [];
        foreach (self::RUNTIME_EGGS as $name) {
            $source = Egg::query()
                ->with('variables')
                ->where('nest_id', $minecraftNest->id)
                ->where('name', $name)
                ->first();
            if (!$source instanceof Egg) {
                throw new \RuntimeException("The upstream Minecraft Egg '{$name}' is required to seed Beacon runtimes.");
            }

            $target = Egg::query()
                ->where('nest_id', $beaconNest->id)
                ->where('name', $name)
                ->first() ?? new Egg();
            if (!$target->exists) {
                $target->uuid = Uuid::uuid4()->toString();
            }
            $target->forceFill([
                'nest_id' => $beaconNest->id,
                'author' => $source->author,
                'name' => $source->name,
                'description' => $source->description,
                'features' => $source->inherit_features,
                'docker_images' => $source->docker_images,
                'file_denylist' => $source->inherit_file_denylist,
                'config_files' => $source->inherit_config_files,
                'config_startup' => $source->inherit_config_startup,
                'config_logs' => $source->inherit_config_logs,
                'config_stop' => $source->inherit_config_stop,
                'config_from' => null,
                'startup' => $source->startup,
                'script_is_privileged' => $source->script_is_privileged,
                'script_install' => $source->copy_script_install,
                'script_entry' => $source->copy_script_entry,
                'script_container' => $source->copy_script_container,
                'copy_script_from' => null,
                'force_outgoing_ip' => $source->force_outgoing_ip,
                'update_url' => $source->update_url,
            ])->save();

            foreach ($source->variables as $variable) {
                EggVariable::query()->updateOrCreate([
                    'egg_id' => $target->id,
                    'env_variable' => $variable->env_variable,
                ], [
                    'name' => $variable->name,
                    'description' => $variable->description,
                    'default_value' => $variable->default_value,
                    'user_viewable' => $variable->user_viewable,
                    'user_editable' => $variable->user_editable,
                    'rules' => $variable->rules,
                ]);
            }

            $runtimeEggs[$name] = $target;
        }

        $applicationId = DB::table('beacon_catalog_applications')
            ->where('slug', 'minecraft-java')
            ->value('id');
        if (!is_null($applicationId)) {
            foreach (['vanilla' => 'Vanilla Minecraft', 'paper' => 'Paper'] as $profile => $eggName) {
                DB::table('beacon_catalog_profiles')
                    ->where('application_id', (int) $applicationId)
                    ->where('code', $profile)
                    ->update(['egg_id' => $runtimeEggs[$eggName]->id, 'updated_at' => now()]);
            }
        }
    }

    private function installScript(): string
    {
        return <<<'BASH'
#!/bin/bash
set -Eeuo pipefail

SERVER_ROOT=/mnt/server
MANIFEST_RELATIVE="${BEACON_MANIFEST_PATH:-.beacon/modpack-install.json}"
MANIFEST="${SERVER_ROOT}/${MANIFEST_RELATIVE}"
RESULT="${SERVER_ROOT}/.beacon/install-result.json"
WORK="${SERVER_ROOT}/.beacon/installer-work"
ARCHIVE="${WORK}/archive"
STAGING="${WORK}/staging"

fail() {
    trap - ERR
    mkdir -p "${SERVER_ROOT}/.beacon"
    # All failure messages are constants controlled by this script, so this
    # remains valid JSON even when jq is the dependency that failed to install.
    printf '{"status":"failed","message":"%s"}\n' "$1" > "${RESULT}"
    echo "BEACON_INSTALL_FAILED: $1" >&2
    exit 1
}

trap 'fail "The installer stopped unexpectedly at line ${LINENO}."' ERR

export DEBIAN_FRONTEND=noninteractive
DEPENDENCIES_READY=false
for attempt in 1 2 3; do
    # The official installer image can contain package indexes that reference
    # versions already rotated out of the Debian security mirror.
    rm -rf /var/lib/apt/lists/*
    if apt-get update -o Acquire::Retries=3 \
        && apt-get install -y --no-install-recommends ca-certificates curl jq python3; then
        DEPENDENCIES_READY=true
        break
    fi
    sleep $((attempt * 2))
done
[[ "${DEPENDENCIES_READY}" == "true" ]] || fail "Installer dependency setup failed."
rm -rf /var/lib/apt/lists/*

[[ "${MANIFEST_RELATIVE}" == ".beacon/modpack-install.json" ]] || fail "Invalid manifest path."
[[ -f "${MANIFEST}" ]] || fail "Installation manifest is missing."
jq -e 'type == "object" and (.archive.url | type == "string") and (.install_mode | type == "string")' "${MANIFEST}" >/dev/null || fail "Installation manifest is invalid."

URL="$(jq -r '.archive.url' "${MANIFEST}")"
MODE="$(jq -r '.install_mode' "${MANIFEST}")"
MAX_BYTES="$(jq -r '.max_archive_bytes' "${MANIFEST}")"
MAX_EXTRACTED_BYTES="$(jq -r '.max_extracted_bytes' "${MANIFEST}")"
MINECRAFT_VERSION="$(jq -r '.minecraft_version // empty' "${MANIFEST}")"
LOADER="$(jq -r '.loader // empty' "${MANIFEST}")"
LOADER_VERSION="$(jq -r '.loader_version // empty' "${MANIFEST}")"
[[ "${URL}" == https://* ]] || fail "Only HTTPS downloads are allowed."
[[ "${MAX_BYTES}" =~ ^[0-9]+$ ]] || fail "Invalid archive size policy."
[[ "${MAX_EXTRACTED_BYTES}" =~ ^[0-9]+$ ]] || fail "Invalid extracted size policy."
[[ "${MODE}" == "archive" || "${MODE}" == "modrinth" || "${MODE}" == "ftb" ]] || fail "Unsupported installation mode."

rm -rf "${WORK}"
mkdir -p "${WORK}" "${STAGING}"
curl --fail --location --silent --show-error --proto '=https' --proto-redir '=https' --tlsv1.2 \
    --connect-timeout 30 --max-time 1800 --max-filesize "${MAX_BYTES}" --output "${ARCHIVE}" "${URL}" \
    || fail "The provider download failed."
SIZE="$(stat -c%s "${ARCHIVE}")"
(( SIZE > 0 && SIZE <= MAX_BYTES )) || fail "The downloaded archive exceeds the configured size policy."

if jq -e '.archive.sha512 != null' "${MANIFEST}" >/dev/null; then
    EXPECTED="$(jq -r '.archive.sha512' "${MANIFEST}")"
    ACTUAL="$(sha512sum "${ARCHIVE}" | cut -d' ' -f1)"
    [[ "${ACTUAL}" == "${EXPECTED}" ]] || fail "The archive SHA-512 hash did not match."
elif jq -e '.archive.sha1 != null' "${MANIFEST}" >/dev/null; then
    EXPECTED="$(jq -r '.archive.sha1' "${MANIFEST}")"
    ACTUAL="$(sha1sum "${ARCHIVE}" | cut -d' ' -f1)"
    [[ "${ACTUAL}" == "${EXPECTED}" ]] || fail "The archive SHA-1 hash did not match."
fi

safe_extract() {
    python3 - "$1" "$2" "${MAX_EXTRACTED_BYTES}" <<'PY'
import os
import pathlib
import stat
import sys
import zipfile

archive, destination, maximum_size = sys.argv[1:]
maximum_size = int(maximum_size)
root = pathlib.Path(destination).resolve()
with zipfile.ZipFile(archive) as source:
    if len(source.infolist()) > 100000:
        raise RuntimeError("archive contains too many entries")
    if sum(item.file_size for item in source.infolist()) > maximum_size:
        raise RuntimeError("archive expands beyond the safety limit")
    for item in source.infolist():
        target = (root / item.filename).resolve()
        if root != target and root not in target.parents:
            raise RuntimeError("archive contains an unsafe path")
        mode = item.external_attr >> 16
        if stat.S_ISLNK(mode):
            raise RuntimeError("archive contains a symbolic link")
        if item.file_size > 2 * 1024 * 1024 * 1024:
            raise RuntimeError("archive entry exceeds the safety limit")
    source.extractall(root)
PY
}

copy_staging() {
    local root="${STAGING}"
    shopt -s dotglob nullglob
    local entries=("${STAGING}"/*)
    if [[ ${#entries[@]} -eq 1 && -d "${entries[0]}" ]]; then
        root="${entries[0]}"
    fi
    cp -a "${root}/." "${SERVER_ROOT}/"
}

if [[ "${MODE}" == "ftb" ]]; then
    chmod 0755 "${ARCHIVE}"
    PACK_ID="$(jq -r '.project_id' "${MANIFEST}")"
    VERSION_ID="$(jq -r '.version_id' "${MANIFEST}")"
    "${ARCHIVE}" -pack "${PACK_ID}" -version "${VERSION_ID}" -dir "${SERVER_ROOT}" -auto -force -no-java -no-colours || fail "The FTB server installer failed."
elif [[ "${MODE}" == "modrinth" ]]; then
    safe_extract "${ARCHIVE}" "${STAGING}" || fail "The Modrinth archive failed safety validation."
    INDEX="${STAGING}/modrinth.index.json"
    [[ -f "${INDEX}" ]] || fail "The Modrinth index is missing."
    jq -e '.formatVersion == 1 and (.files | type == "array")' "${INDEX}" >/dev/null || fail "The Modrinth index is invalid."
    MINECRAFT_VERSION="$(jq -r '.dependencies.minecraft // empty' "${INDEX}")"
    for candidate in neoforge fabric-loader quilt-loader forge; do
        candidate_version="$(jq -r --arg candidate "${candidate}" '.dependencies[$candidate] // empty' "${INDEX}")"
        if [[ -n "${candidate_version}" ]]; then
            LOADER="${candidate%-loader}"
            LOADER_VERSION="${candidate_version}"
            break
        fi
    done
    DOWNLOADED_BYTES=0
    while IFS= read -r item; do
        PATH_VALUE="$(jq -r '.path' <<<"${item}")"
        DOWNLOAD_URL="$(jq -r '.downloads[0]' <<<"${item}")"
        ENVIRONMENT="$(jq -r '.env.server // "required"' <<<"${item}")"
        [[ "${ENVIRONMENT}" != "unsupported" ]] || continue
        [[ "${DOWNLOAD_URL}" == https://cdn.modrinth.com/* ]] || fail "The Modrinth index contains an unapproved download host."
        python3 - "${PATH_VALUE}" <<'PY' || fail "The Modrinth index contains an unsafe file path."
import pathlib, sys
p = pathlib.PurePosixPath(sys.argv[1])
if p.is_absolute() or '..' in p.parts or not p.parts:
    raise SystemExit(1)
PY
        TARGET="${SERVER_ROOT}/${PATH_VALUE}"
        mkdir -p "$(dirname "${TARGET}")"
        curl --fail --location --silent --show-error --proto '=https' --proto-redir '=https' --tlsv1.2 \
            --connect-timeout 30 --max-time 1800 --max-filesize "${MAX_BYTES}" --output "${TARGET}.part" "${DOWNLOAD_URL}" \
            || fail "A Modrinth file download failed."
        if jq -e '.hashes.sha512 != null' <<<"${item}" >/dev/null; then
            EXPECTED="$(jq -r '.hashes.sha512' <<<"${item}")"
            ACTUAL="$(sha512sum "${TARGET}.part" | cut -d' ' -f1)"
            [[ "${ACTUAL}" == "${EXPECTED}" ]] || fail "A Modrinth file hash did not match."
        fi
        FILE_BYTES="$(stat -c%s "${TARGET}.part")"
        DOWNLOADED_BYTES=$((DOWNLOADED_BYTES + FILE_BYTES))
        (( DOWNLOADED_BYTES <= MAX_EXTRACTED_BYTES )) || fail "The Modrinth files exceed the extracted size policy."
        mv "${TARGET}.part" "${TARGET}"
    done < <(jq -c '.files[]' "${INDEX}")
    [[ ! -d "${STAGING}/overrides" ]] || cp -a "${STAGING}/overrides/." "${SERVER_ROOT}/"
    [[ ! -d "${STAGING}/server-overrides" ]] || cp -a "${STAGING}/server-overrides/." "${SERVER_ROOT}/"
else
    safe_extract "${ARCHIVE}" "${STAGING}" || fail "The server archive failed safety validation."
    copy_staging
fi

if [[ -z "${LOADER}" ]]; then
    if find "${SERVER_ROOT}/libraries/net/neoforged/neoforge" -type f -print -quit 2>/dev/null | grep -q .; then
        LOADER=neoforge
    elif [[ -f "${SERVER_ROOT}/fabric-server-launch.jar" ]]; then
        LOADER=fabric
    elif [[ -f "${SERVER_ROOT}/quilt-server-launch.jar" ]]; then
        LOADER=quilt
    elif find "${SERVER_ROOT}/libraries/net/minecraftforge/forge" -type f -print -quit 2>/dev/null | grep -q . \
        || find "${SERVER_ROOT}" -maxdepth 1 -type f -iname '*forge*.jar' -print -quit | grep -q .; then
        LOADER=forge
    elif [[ -f "${SERVER_ROOT}/server.jar" ]]; then
        LOADER=vanilla
    fi
fi

[[ "${MINECRAFT_VERSION}" =~ ^1\.[0-9]+(\.[0-9]+)?$ ]] || fail "The server pack did not identify a valid Minecraft version."
case "${LOADER}" in
    vanilla|paper|fabric|forge|neoforge|quilt) ;;
    *) fail "The server pack did not identify a supported loader." ;;
esac

if [[ "${LOADER}" == "forge" ]]; then
    FORGE_ROOT="$(find "${SERVER_ROOT}/libraries/net/minecraftforge/forge" -mindepth 1 -maxdepth 1 -type d -print -quit 2>/dev/null || true)"
    if [[ -n "${FORGE_ROOT}" ]]; then
        [[ -n "${LOADER_VERSION}" ]] || LOADER_VERSION="$(basename "${FORGE_ROOT}")"
        [[ ! -f "${FORGE_ROOT}/unix_args.txt" ]] || ln -sf "${FORGE_ROOT}/unix_args.txt" "${SERVER_ROOT}/unix_args.txt"
    fi
    if [[ ! -f "${SERVER_ROOT}/unix_args.txt" && ! -f "${SERVER_ROOT}/server.jar" ]]; then
        FORGE_JAR="$(find "${SERVER_ROOT}" -maxdepth 1 -type f -iname 'forge-*.jar' ! -iname '*installer*' -print -quit)"
        [[ -z "${FORGE_JAR}" ]] || ln -sf "$(basename "${FORGE_JAR}")" "${SERVER_ROOT}/server.jar"
    fi
elif [[ "${LOADER}" == "neoforge" ]]; then
    NEOFORGE_ROOT="$(find "${SERVER_ROOT}/libraries/net/neoforged/neoforge" -mindepth 1 -maxdepth 1 -type d -print -quit 2>/dev/null || true)"
    if [[ -n "${NEOFORGE_ROOT}" ]]; then
        [[ -n "${LOADER_VERSION}" ]] || LOADER_VERSION="$(basename "${NEOFORGE_ROOT}")"
        [[ ! -f "${NEOFORGE_ROOT}/unix_args.txt" ]] || ln -sf "${NEOFORGE_ROOT}/unix_args.txt" "${SERVER_ROOT}/unix_args.txt"
    fi
fi

printf 'eula=true\n' > "${SERVER_ROOT}/eula.txt"
rm -rf "${WORK}"
jq -n \
    --arg provider "$(jq -r '.provider' "${MANIFEST}")" \
    --arg version "$(jq -r '.version_id' "${MANIFEST}")" \
    --arg minecraftVersion "${MINECRAFT_VERSION}" \
    --arg loader "${LOADER}" \
    --arg loaderVersion "${LOADER_VERSION}" \
    '{status:"succeeded",provider:$provider,version_id:$version,minecraft_version:$minecraftVersion,loader:$loader,loader_version:(if $loaderVersion == "" then null else $loaderVersion end)}' \
    > "${RESULT}"
echo BEACON_INSTALL_COMPLETE
BASH;
    }
}
