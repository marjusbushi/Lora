#!/usr/bin/env bash

set -Eeuo pipefail
umask 077
set +x

readonly MODE="${1:-${LORA_REHEARSAL_MODE:-}}"
readonly CANDIDATE_SHA="${2:-${LORA_REHEARSAL_CANDIDATE:-}}"
readonly PR_NUMBER="${LORA_REHEARSAL_PR_NUMBER:-}"
readonly EXPECTED_BASELINE_SHA="${LORA_REHEARSAL_BASELINE_SHA:-}"
readonly BASELINE_VENDOR_SHA256="${LORA_REHEARSAL_BASELINE_VENDOR_SHA256:-}"
readonly CANDIDATE_VENDOR_SHA256="${LORA_REHEARSAL_CANDIDATE_VENDOR_SHA256:-}"
readonly CANDIDATE_ASSETS_SHA256="${LORA_REHEARSAL_CANDIDATE_ASSETS_SHA256:-}"

readonly APP_PATH="${LORA_APP_PATH:-/var/www/villamucho}"
readonly APP_USER="${LORA_APP_USER:-www-data}"
readonly STATE_PATH="${LORA_BACKUP_STATE_PATH:-/var/lib/lora-backup}"
readonly CONFIG_FILE="${LORA_BACKUP_CONFIG:-/etc/lora-backup/restic.env}"
readonly MYSQL_CONFIG_FILE="${LORA_BACKUP_MYSQL_CONFIG:-/etc/lora-backup/mysql.cnf}"
readonly PASSPORT_KEY_DIR="/etc/lora-passport"
readonly PASSPORT_PRIVATE_KEY="${PASSPORT_KEY_DIR}/oauth-private.key"
readonly PASSPORT_PUBLIC_KEY="${PASSPORT_KEY_DIR}/oauth-public.key"
readonly PASSPORT_PRIVATE_URI="file://${PASSPORT_PRIVATE_KEY}"
readonly PASSPORT_PUBLIC_URI="file://${PASSPORT_PUBLIC_KEY}"
readonly LEGACY_PASSPORT_PRIVATE_KEY="${APP_PATH}/storage/oauth-private.key"
readonly LEGACY_PASSPORT_PUBLIC_KEY="${APP_PATH}/storage/oauth-public.key"
readonly LOCK_PATH="${STATE_PATH}/production-release.lock"
readonly REHEARSAL_STATE_PATH="${STATE_PATH}/rehearsals"
readonly MYSQL_IMAGE="mysql:8.0.46@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b"
readonly APP_RUNTIME_IMAGE="serversideup/php:8.4-cli@sha256:7b669c4fbb70ca392cdbfa61b0aee6f95530445a67f2a814c0692c992971de2c"
readonly REHEARSAL_DATABASE="lora_rehearsal"
readonly REHEARSAL_DB_USER="lora_rehearsal"
readonly MINIMUM_DOCKER_VERSION="28.0.0"
readonly LUKS_KEYFILE_BYTES=64
readonly LUKS_PBKDF_MEMORY_KIB=131072
readonly LUKS_PBKDF_PARALLEL=1
readonly LUKS_PBKDF_TIME_COST=5
readonly LUKS_MINIMUM_FREE_MEMORY_KIB=524288
readonly BACKUP_SCRIPT_SHA="a15ddb3014899d9982b2d6c1e7462b7f8f51b562dbc87e8213062ae876b5b5f0"
readonly BACKUP_SERVICE_SHA="324ac6ee746ec39aa5d97e9e71381cad62be001bc77fce25130a0f6200435682"
readonly INSTALLED_BACKUP_SCRIPT="/usr/local/sbin/lora-offsite-backup"
readonly INSTALLED_BACKUP_SERVICE="/etc/systemd/system/lora-backup.service"

exec 3>&1

fail() {
    printf 'Release rehearsal failed: %s\n' "$*" >&2
    printf 'REHEARSAL_ERROR=%s\n' "$*" >&3 || true
    exit 1
}

valid_sha() {
    [[ "$1" =~ ^[0-9a-f]{40}$ ]]
}

[[ "${EUID}" -eq 0 ]] || fail 'must run as root'
[[ "${MODE}" =~ ^(preflight|run|cleanup)$ ]] || fail 'mode must be preflight, run, or cleanup'
valid_sha "${CANDIDATE_SHA}" || fail 'candidate must be an exact lowercase 40-character commit SHA'

readonly RESOURCE_SUFFIX="${CANDIDATE_SHA}"
readonly CONTAINER_NAME="lora-rr-${RESOURCE_SUFFIX}"
readonly APP_CONTAINER_NAME="lora-rr-app-${RESOURCE_SUFFIX}"
readonly NETWORK_NAME="lora-rr-${RESOURCE_SUFFIX}"
readonly MAPPER_NAME="lora-rr-${RESOURCE_SUFFIX}"
readonly MOUNT_PATH="/run/lora-release-rehearsal-${RESOURCE_SUFFIX}"
readonly KEY_PATH="/run/lora-release-rehearsal-${RESOURCE_SUFFIX}.key"
readonly LUKS_IMAGE="${REHEARSAL_STATE_PATH}/.${RESOURCE_SUFFIX}.luks"
readonly MARKER_PATH="${REHEARSAL_STATE_PATH}/${CANDIDATE_SHA}.ok"
readonly VENDOR_INBOX="${STATE_PATH}/rehearsal-inbox/${CANDIDATE_SHA}"
readonly RELEASE_ARTIFACT_ROOT="/var/lib/lora-release/artifacts"
readonly RELEASE_ARTIFACT_PATH="${RELEASE_ARTIFACT_ROOT}/${CANDIDATE_SHA}"
readonly CURRENT_RELEASE_FILE="/var/lib/lora-release/current"

CLEANUP_COMPLETE=false
WORKSPACE=''
MYSQL_CLIENT_FILE=''
PRODUCTION_DB_UUID=''
PRODUCTION_DB_FINGERPRINT=''
PRODUCTION_DB_PARITY_FINGERPRINT=''
PRODUCTION_DB_SQL_MODE=''
PRODUCTION_DB_CHARACTER_SET=''
PRODUCTION_DB_COLLATION=''
PRODUCTION_DB_LOWER_CASE_TABLE_NAMES=''
PRODUCTION_DB_CONNECTION_CHARACTER_SET=''
PRODUCTION_DB_CONNECTION_COLLATION=''
BACKUP_BOOTSTRAP_DIR=''
CANDIDATE_REQUIRES_PASSPORT=false
PASSPORT_PUBLIC_FINGERPRINT=not-required

require_root_only_file() {
    local path="$1"
    local label="$2"
    local owner
    local mode

    [[ -f "${path}" && ! -L "${path}" && -r "${path}" ]] \
        || fail "${label} is not a readable regular file: ${path}"
    owner="$(stat -c '%U' "${path}")"
    mode="$(stat -c '%a' "${path}")"
    [[ "${owner}" == root ]] || fail "${label} must be owned by root"
    (( (8#${mode} & 8#077) == 0 )) || fail "${label} must not be accessible by group/others"
}

passport_pair_fingerprint() {
    local private_key="$1"
    local public_key="$2"
    local private_fingerprint
    local public_fingerprint

    [[ -f "${private_key}" && ! -L "${private_key}" ]] \
        || fail "Passport private key is not a regular file: ${private_key}"
    [[ -f "${public_key}" && ! -L "${public_key}" ]] \
        || fail "Passport public key is not a regular file: ${public_key}"
    (( $(stat -c %s "${private_key}") >= 512 \
        && $(stat -c %s "${private_key}") <= 32768 )) \
        || fail 'Passport private key size is outside the accepted range'
    (( $(stat -c %s "${public_key}") >= 256 \
        && $(stat -c %s "${public_key}") <= 32768 )) \
        || fail 'Passport public key size is outside the accepted range'
    openssl pkey -in "${private_key}" -check -noout >/dev/null 2>&1 \
        || fail 'Passport private key is invalid'
    openssl pkey -pubin -in "${public_key}" -noout >/dev/null 2>&1 \
        || fail 'Passport public key is invalid'
    private_fingerprint="$(
        openssl pkey -in "${private_key}" -pubout -outform DER 2>/dev/null \
            | sha256sum | awk '{print $1}'
    )" || fail 'Passport private-key fingerprint could not be calculated'
    public_fingerprint="$(
        openssl pkey -pubin -in "${public_key}" -pubout -outform DER 2>/dev/null \
            | sha256sum | awk '{print $1}'
    )" || fail 'Passport public-key fingerprint could not be calculated'
    [[ "${private_fingerprint}" =~ ^[0-9a-f]{64}$ \
        && "${private_fingerprint}" == "${public_fingerprint}" ]] \
        || fail 'Passport private/public keys do not form the same key pair'
    printf '%s\n' "${public_fingerprint}"
}

candidate_requires_passport() {
    local state

    state="$(
        git -C "${APP_PATH}" show "${CANDIDATE_SHA}:composer.lock" \
            | php -r '
                $lock = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
                $packages = array_merge($lock["packages"] ?? [], $lock["packages-dev"] ?? []);
                foreach ($packages as $package) {
                    if (($package["name"] ?? null) === "laravel/passport") {
                        echo "required";
                        exit(0);
                    }
                }
                echo "not-required";
            '
    )" || fail 'candidate Passport dependency state could not be verified'
    [[ "${state}" == required ]]
}

assert_live_passport_key_pair() {
    local app_group
    local fingerprint

    app_group="$(id -gn "${APP_USER}")"
    [[ -n "${app_group}" ]] || fail 'application primary group could not be determined'
    [[ -d "${PASSPORT_KEY_DIR}" && ! -L "${PASSPORT_KEY_DIR}" ]] \
        || fail 'Passport key directory is missing or unsafe'
    [[ "$(stat -c '%U:%G:%a' "${PASSPORT_KEY_DIR}")" == "root:${app_group}:750" ]] \
        || fail "Passport key directory must be root:${app_group} 0750"
    [[ "$(stat -c '%U:%G:%a' "${PASSPORT_PRIVATE_KEY}")" == "root:${app_group}:440" ]] \
        || fail "Passport private key must be root:${app_group} 0440"
    [[ "$(stat -c '%U:%G:%a' "${PASSPORT_PUBLIC_KEY}")" == "root:${app_group}:440" ]] \
        || fail "Passport public key must be root:${app_group} 0440"
    fingerprint="$(passport_pair_fingerprint \
        "${PASSPORT_PRIVATE_KEY}" "${PASSPORT_PUBLIC_KEY}")"
    [[ "${fingerprint}" =~ ^[0-9a-f]{64}$ ]] \
        || fail 'Passport key-pair fingerprint is invalid'
    printf '%s\n' "${fingerprint}"
}

legacy_passport_pair_fingerprint() {
    local path
    local owner
    local mode

    for path in "${LEGACY_PASSPORT_PRIVATE_KEY}" "${LEGACY_PASSPORT_PUBLIC_KEY}"; do
        [[ -f "${path}" && ! -L "${path}" ]] \
            || fail "legacy Passport key is not a regular file: ${path}"
        owner="$(stat -c %U "${path}")"
        mode="$(stat -c %a "${path}")"
        [[ "${owner}" == root || "${owner}" == "${APP_USER}" ]] \
            || fail "legacy Passport key has an unexpected owner: ${path}"
        case "${mode}" in
            400|440|600|640|660) ;;
            *) fail "legacy Passport key permissions are unsafe: ${path}" ;;
        esac
    done
    passport_pair_fingerprint \
        "${LEGACY_PASSPORT_PRIVATE_KEY}" "${LEGACY_PASSPORT_PUBLIC_KEY}"
}

assert_live_passport_environment() {
    local env_file="${APP_PATH}/.env"
    local app_group
    local private_count
    local public_count

    app_group="$(id -gn "${APP_USER}")"
    [[ -f "${env_file}" && ! -L "${env_file}" ]] \
        || fail 'production .env is missing or unsafe'
    [[ "$(stat -c '%U:%G:%a' "${env_file}")" == "root:${app_group}:640" ]] \
        || fail "production .env must be root:${app_group} 0640"
    private_count="$(grep -c '^PASSPORT_PRIVATE_KEY=' "${env_file}" || true)"
    public_count="$(grep -c '^PASSPORT_PUBLIC_KEY=' "${env_file}" || true)"
    [[ "${private_count}" == 1 && "${public_count}" == 1 ]] \
        || fail 'production .env must define each Passport key URI exactly once'
    grep -Fqx "PASSPORT_PRIVATE_KEY=${PASSPORT_PRIVATE_URI}" "${env_file}" \
        || fail 'production Passport private-key URI is not pinned'
    grep -Fqx "PASSPORT_PUBLIC_KEY=${PASSPORT_PUBLIC_URI}" "${env_file}" \
        || fail 'production Passport public-key URI is not pinned'
}

update_live_passport_environment() {
    local env_file="${APP_PATH}/.env"
    local app_group

    app_group="$(id -gn "${APP_USER}")"
    [[ -f "${env_file}" && ! -L "${env_file}" ]] \
        || fail 'production .env is missing or unsafe'
    [[ "$(stat -c '%U:%G:%a' "${env_file}")" == "root:${app_group}:640" ]] \
        || fail "production .env must be root:${app_group} 0640"
    (
        env_tmp=''
        cleanup_passport_env_tmp() {
            [[ -z "${env_tmp}" ]] || rm -f -- "${env_tmp}"
        }
        trap cleanup_passport_env_tmp EXIT

        env_tmp="$(mktemp "${APP_PATH}/.env.passport.XXXXXXXX")" \
            || fail 'temporary Passport environment file could not be created'
        if ! awk -v private_uri="${PASSPORT_PRIVATE_URI}" -v public_uri="${PASSPORT_PUBLIC_URI}" '
            BEGIN { private_count = 0; public_count = 0 }
            /^PASSPORT_PRIVATE_KEY=/ {
                private_count++
                if (private_count == 1) print "PASSPORT_PRIVATE_KEY=" private_uri
                next
            }
            /^PASSPORT_PUBLIC_KEY=/ {
                public_count++
                if (public_count == 1) print "PASSPORT_PUBLIC_KEY=" public_uri
                next
            }
            { print }
            END {
                if (private_count == 0) print "PASSPORT_PRIVATE_KEY=" private_uri
                if (public_count == 0) print "PASSPORT_PUBLIC_KEY=" public_uri
                if (private_count > 1 || public_count > 1) exit 42
            }
        ' "${env_file}" > "${env_tmp}"; then
            fail 'production .env contains duplicate Passport key settings'
        fi
        chown root:"${app_group}" "${env_tmp}"
        chmod 0640 "${env_tmp}"
        sync "${env_tmp}"
        mv --no-target-directory "${env_tmp}" "${env_file}"
        env_tmp=''
        sync "${env_file}" "${APP_PATH}"
    )
    assert_live_passport_environment
}

ensure_live_passport_key_pair() {
    local app_group
    local legacy_fingerprint=''
    local private_exists=false
    local public_exists=false
    local legacy_private_exists=false
    local legacy_public_exists=false

    app_group="$(id -gn "${APP_USER}")"
    [[ -n "${app_group}" ]] || fail 'application primary group could not be determined'
    if [[ -e "${PASSPORT_KEY_DIR}" || -L "${PASSPORT_KEY_DIR}" ]]; then
        [[ -d "${PASSPORT_KEY_DIR}" && ! -L "${PASSPORT_KEY_DIR}" \
            && "$(stat -c '%U:%G:%a' "${PASSPORT_KEY_DIR}")" == "root:${app_group}:750" ]] \
            || fail "Passport key directory must be root:${app_group} 0750"
    else
        install -d -o root -g "${app_group}" -m 0750 "${PASSPORT_KEY_DIR}"
    fi

    [[ -e "${PASSPORT_PRIVATE_KEY}" || -L "${PASSPORT_PRIVATE_KEY}" ]] \
        && private_exists=true
    [[ -e "${PASSPORT_PUBLIC_KEY}" || -L "${PASSPORT_PUBLIC_KEY}" ]] \
        && public_exists=true
    [[ "${private_exists}" == "${public_exists}" ]] \
        || fail 'partial Passport key state refuses automatic regeneration'
    [[ -e "${LEGACY_PASSPORT_PRIVATE_KEY}" || -L "${LEGACY_PASSPORT_PRIVATE_KEY}" ]] \
        && legacy_private_exists=true
    [[ -e "${LEGACY_PASSPORT_PUBLIC_KEY}" || -L "${LEGACY_PASSPORT_PUBLIC_KEY}" ]] \
        && legacy_public_exists=true
    [[ "${legacy_private_exists}" == "${legacy_public_exists}" ]] \
        || fail 'partial legacy Passport key state refuses automatic regeneration'
    if [[ "${legacy_private_exists}" == true ]]; then
        legacy_fingerprint="$(legacy_passport_pair_fingerprint)"
        [[ "${legacy_fingerprint}" =~ ^[0-9a-f]{64}$ ]] \
            || fail 'legacy Passport key-pair fingerprint is invalid'
    fi

    if [[ "${private_exists}" == false ]]; then
        (
            stage_dir=''
            private_stage="${PASSPORT_KEY_DIR}/.oauth-private.key.$$"
            public_stage="${PASSPORT_KEY_DIR}/.oauth-public.key.$$"
            private_installed=false
            pair_installed=false
            cleanup_passport_key_stage() {
                [[ -z "${stage_dir}" ]] || rm -rf -- "${stage_dir}"
                [[ -z "${private_stage}" ]] || rm -f -- "${private_stage}"
                [[ -z "${public_stage}" ]] || rm -f -- "${public_stage}"
                if [[ "${private_installed}" == true && "${pair_installed}" != true ]]; then
                    rm -f -- "${PASSPORT_PRIVATE_KEY}"
                fi
            }
            trap cleanup_passport_key_stage EXIT

            [[ ! -e "${private_stage}" && ! -L "${private_stage}" \
                && ! -e "${public_stage}" && ! -L "${public_stage}" ]] \
                || fail 'stale Passport key staging files already exist'
            stage_dir="$(mktemp -d /run/lora-passport-bootstrap.XXXXXXXX)" \
                || fail 'Passport key staging directory could not be created'
            chmod 0700 "${stage_dir}"
            if [[ "${legacy_private_exists}" == true ]]; then
                install -o root -g root -m 0600 \
                    "${LEGACY_PASSPORT_PRIVATE_KEY}" "${stage_dir}/oauth-private.key"
                install -o root -g root -m 0600 \
                    "${LEGACY_PASSPORT_PUBLIC_KEY}" "${stage_dir}/oauth-public.key"
            else
                openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 \
                    -out "${stage_dir}/oauth-private.key"
                openssl pkey -in "${stage_dir}/oauth-private.key" -pubout \
                    -out "${stage_dir}/oauth-public.key"
            fi
            staged_fingerprint="$(passport_pair_fingerprint \
                "${stage_dir}/oauth-private.key" "${stage_dir}/oauth-public.key")"
            [[ "${staged_fingerprint}" =~ ^[0-9a-f]{64}$ ]] \
                || fail 'staged Passport key pair is invalid'
            if [[ "${legacy_private_exists}" == true ]]; then
                [[ "${staged_fingerprint}" == "${legacy_fingerprint}" ]] \
                    || fail 'legacy Passport key pair changed while it was being adopted'
                [[ "$(legacy_passport_pair_fingerprint)" == "${legacy_fingerprint}" ]] \
                    || fail 'legacy Passport key pair changed while it was being adopted'
            fi
            install -o root -g "${app_group}" -m 0440 \
                "${stage_dir}/oauth-private.key" "${private_stage}"
            install -o root -g "${app_group}" -m 0440 \
                "${stage_dir}/oauth-public.key" "${public_stage}"
            sync "${private_stage}" "${public_stage}" "${PASSPORT_KEY_DIR}"
            mv --no-target-directory "${private_stage}" "${PASSPORT_PRIVATE_KEY}"
            private_stage=''
            private_installed=true
            mv --no-target-directory "${public_stage}" "${PASSPORT_PUBLIC_KEY}"
            public_stage=''
            pair_installed=true
            sync "${PASSPORT_PRIVATE_KEY}" "${PASSPORT_PUBLIC_KEY}" "${PASSPORT_KEY_DIR}"
            rm -rf -- "${stage_dir}"
            stage_dir=''
        )
    fi

    PASSPORT_PUBLIC_FINGERPRINT="$(assert_live_passport_key_pair)"
    if [[ "${legacy_private_exists}" == true ]]; then
        [[ "$(legacy_passport_pair_fingerprint)" == "${legacy_fingerprint}" \
            && "${PASSPORT_PUBLIC_FINGERPRINT}" == "${legacy_fingerprint}" ]] \
            || fail 'canonical and legacy Passport key pairs do not match'
    fi
    update_live_passport_environment
}

require_commands() {
    local binary
    for binary in \
        awk bash certbot chmod chown cmp cryptsetup cut date df docker fallocate find findmnt \
        flock git grep head hostname install mkfs.ext4 mktemp mount mountpoint mysql mysqldump nginx openssl php restic \
        rsync runuser sed sha256sum sleep sort stat sync systemctl tar tr umount wc xargs; do
        command -v "${binary}" >/dev/null 2>&1 || fail "required binary is missing: ${binary}"
    done
}

run_as_app() {
    runuser --user "${APP_USER}" -- \
        env -i \
            HOME="${APP_PATH}/storage/framework" \
            PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
            "$@"
}

assert_production_repo_security() {
    [[ -d "${APP_PATH}/.git" && ! -L "${APP_PATH}/.git" ]] \
        || fail 'production .git must be a real directory'
    if find "${APP_PATH}/.git" -xdev \
        \( ! -user root -o -perm /022 \) -print -quit | grep -q .; then
        fail 'production Git metadata must be root-owned and non-writable by group/others'
    fi
    if find "${APP_PATH}" -xdev \
        \( -path "${APP_PATH}/.git" -o -path "${APP_PATH}/vendor" \
           -o -path "${APP_PATH}/node_modules" -o -path "${APP_PATH}/storage" \
           -o -path "${APP_PATH}/bootstrap/cache" -o -path "${APP_PATH}/public/build" \
           -o -path "${APP_PATH}/public/storage" \) -prune \
        -o \( -type l -o ! -user root -o -perm /022 \) -print -quit \
        | grep -q .; then
        fail 'production source boundary must be root-owned and immutable'
    fi
    if [[ -e "${APP_PATH}/public/storage" || -L "${APP_PATH}/public/storage" ]]; then
        [[ -L "${APP_PATH}/public/storage" \
            && "$(readlink -f "${APP_PATH}/public/storage")" == "${APP_PATH}/storage/app/public" ]] \
            || fail 'public storage link does not target storage/app/public'
    fi
}

installed_backup_harness_is_current() {
    [[ -f "${INSTALLED_BACKUP_SCRIPT}" && ! -L "${INSTALLED_BACKUP_SCRIPT}" ]] \
        && [[ "$(stat -c '%U:%G:%a' "${INSTALLED_BACKUP_SCRIPT}" 2>/dev/null)" == 'root:root:700' ]] \
        && [[ "$(sha256sum "${INSTALLED_BACKUP_SCRIPT}" | awk '{print $1}')" == "${BACKUP_SCRIPT_SHA}" ]] \
        && [[ -f "${INSTALLED_BACKUP_SERVICE}" && ! -L "${INSTALLED_BACKUP_SERVICE}" ]] \
        && [[ "$(stat -c '%U:%G:%a' "${INSTALLED_BACKUP_SERVICE}" 2>/dev/null)" == 'root:root:644' ]] \
        && [[ "$(sha256sum "${INSTALLED_BACKUP_SERVICE}" | awk '{print $1}')" == "${BACKUP_SERVICE_SHA}" ]]
}

install_trusted_backup_harness() {
    local fetched_sha
    local source_script
    local source_service

    if [[ -n "${PR_NUMBER}" ]]; then
        git -C "${APP_PATH}" fetch --quiet --no-tags origin "refs/pull/${PR_NUMBER}/head"
    else
        git -C "${APP_PATH}" fetch --quiet --no-tags origin refs/heads/main
    fi
    fetched_sha="$(git -C "${APP_PATH}" rev-parse FETCH_HEAD)"
    [[ "${fetched_sha}" == "${CANDIDATE_SHA}" ]] \
        || fail 'remote source no longer matches the approved candidate SHA'

    BACKUP_BOOTSTRAP_DIR="$(mktemp -d /run/lora-backup-harness.XXXXXXXX)"
    chmod 0700 "${BACKUP_BOOTSTRAP_DIR}"
    source_script="${BACKUP_BOOTSTRAP_DIR}/run-offsite-backup.sh"
    source_service="${BACKUP_BOOTSTRAP_DIR}/lora-backup.service"
    git -C "${APP_PATH}" show "${CANDIDATE_SHA}:ops/backup/run-offsite-backup.sh" > "${source_script}"
    git -C "${APP_PATH}" show "${CANDIDATE_SHA}:ops/backup/lora-backup.service" > "${source_service}"
    [[ "$(sha256sum "${source_script}" | awk '{print $1}')" == "${BACKUP_SCRIPT_SHA}" ]] \
        || fail 'candidate backup script does not match the trusted release harness'
    [[ "$(sha256sum "${source_service}" | awk '{print $1}')" == "${BACKUP_SERVICE_SHA}" ]] \
        || fail 'candidate backup service does not match the trusted release harness'
    bash -n "${source_script}"

    install -o root -g root -m 0700 "${source_script}" "${INSTALLED_BACKUP_SCRIPT}"
    install -o root -g root -m 0644 "${source_service}" "${INSTALLED_BACKUP_SERVICE}"
    systemctl daemon-reload
    [[ "$(systemctl show --property=FragmentPath --value lora-backup.service)" == "${INSTALLED_BACKUP_SERVICE}" ]] \
        || fail 'lora-backup.service does not use the trusted unit file'
    [[ -z "$(systemctl show --property=DropInPaths --value lora-backup.service)" ]] \
        || fail 'lora-backup.service must not have systemd drop-ins'
    installed_backup_harness_is_current \
        || fail 'installed backup harness failed its post-install verification'
    rm -rf -- "${BACKUP_BOOTSTRAP_DIR}"
    BACKUP_BOOTSTRAP_DIR=''
}

acquire_release_lock() {
    install -d -m 0700 -o root -g root "${STATE_PATH}" "${REHEARSAL_STATE_PATH}"
    exec 8>"${LOCK_PATH}"
    chmod 0600 "${LOCK_PATH}"

    if [[ "${MODE}" == cleanup ]]; then
        flock -w 300 8 || fail 'timed out waiting for the production release lock'
    else
        flock -n 8 || fail 'another production deploy or rehearsal owns the release lock'
    fi
}

load_restic_environment() {
    require_root_only_file "${CONFIG_FILE}" 'Restic config'
    require_root_only_file "${MYSQL_CONFIG_FILE}" 'backup MySQL config'
    # shellcheck source=/dev/null
    set -a
    source "${CONFIG_FILE}"
    set +a

    : "${RESTIC_REPOSITORY:?RESTIC_REPOSITORY is required}"
    : "${RESTIC_PASSWORD_FILE:?RESTIC_PASSWORD_FILE is required}"
    : "${AWS_ACCESS_KEY_ID:?AWS_ACCESS_KEY_ID is required}"
    : "${AWS_SECRET_ACCESS_KEY:?AWS_SECRET_ACCESS_KEY is required}"
    require_root_only_file "${RESTIC_PASSWORD_FILE}" 'Restic password file'
}

latest_snapshot_id() {
    local backup_host="$1"
    restic snapshots --host "${backup_host}" --tag lora-production --json 2>/dev/null \
        | php -r '
            $data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || $data === []) {
                exit(0);
            }
            $latestId = "";
            $latestSeconds = null;
            $latestNanoseconds = null;
            foreach ($data as $snapshot) {
                if (! is_array($snapshot)) {
                    fwrite(STDERR, "Invalid Restic snapshot entry.\n");
                    exit(1);
                }
                $id = $snapshot["id"] ?? "";
                $time = $snapshot["time"] ?? "";
                if (! is_string($id) || preg_match("/^[0-9a-f]{64}$/", $id) !== 1
                    || ! is_string($time)
                    || preg_match(
                        "/^(\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2})(?:\\.(\\d{1,9}))?(Z|[+-](?:[01]\\d|2[0-3]):[0-5]\\d)$/D",
                        $time,
                        $matches,
                    ) !== 1) {
                    fwrite(STDERR, "Invalid Restic snapshot metadata.\n");
                    exit(1);
                }
                $zone = $matches[3] === "Z" ? "+00:00" : $matches[3];
                $dateTime = DateTimeImmutable::createFromFormat(
                    "!Y-m-d\\TH:i:sP",
                    $matches[1].$zone,
                );
                $dateErrors = DateTimeImmutable::getLastErrors();
                if ($dateTime === false
                    || ($dateErrors !== false
                        && ($dateErrors["warning_count"] !== 0 || $dateErrors["error_count"] !== 0))
                    || $dateTime->format("Y-m-d\\TH:i:sP") !== $matches[1].$zone) {
                    fwrite(STDERR, "Invalid Restic snapshot time.\n");
                    exit(1);
                }
                $seconds = (int) $dateTime->format("U");
                $nanoseconds = (int) str_pad((string) ($matches[2] ?? ""), 9, "0");
                if ($latestSeconds === null || $seconds > $latestSeconds
                    || ($seconds === $latestSeconds && $nanoseconds > $latestNanoseconds)
                    || ($seconds === $latestSeconds && $nanoseconds === $latestNanoseconds
                        && strcmp($id, $latestId) > 0)) {
                    $latestId = $id;
                    $latestSeconds = $seconds;
                    $latestNanoseconds = $nanoseconds;
                }
            }
            echo $latestId;
        '
}

verify_luks_header() {
    local image="$1"

    cryptsetup isLuks --type luks2 "${image}" \
        || fail 'encrypted rehearsal workspace is not LUKS2'
    cryptsetup luksDump --dump-json-metadata "${image}" 2>/dev/null \
        | env \
            EXPECTED_MEMORY_KIB="${LUKS_PBKDF_MEMORY_KIB}" \
            EXPECTED_PARALLEL="${LUKS_PBKDF_PARALLEL}" \
            EXPECTED_TIME_COST="${LUKS_PBKDF_TIME_COST}" \
            php -r '
                $metadata = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
                $keyslots = $metadata["keyslots"] ?? null;
                if (! is_array($keyslots) || array_keys($keyslots) !== [0]) {
                    exit(1);
                }
                $keyslot = $keyslots[0];
                $kdf = is_array($keyslot) ? ($keyslot["kdf"] ?? null) : null;
                if (! is_array($kdf)
                    || ($keyslot["type"] ?? null) !== "luks2"
                    || ($keyslot["key_size"] ?? null) !== 64
                    || ($kdf["type"] ?? null) !== "argon2id"
                    || ($kdf["memory"] ?? null) !== (int) getenv("EXPECTED_MEMORY_KIB")
                    || ($kdf["cpus"] ?? null) !== (int) getenv("EXPECTED_PARALLEL")
                    || ($kdf["time"] ?? null) !== (int) getenv("EXPECTED_TIME_COST")
                    || ! is_string($kdf["salt"] ?? null)
                    || strlen($kdf["salt"]) < 16) {
                    exit(1);
                }
            ' \
        || fail 'encrypted rehearsal workspace has unexpected LUKS2 keyslot parameters'
}

snapshot_restore_bytes() {
    local snapshot_id="$1"
    restic stats "${snapshot_id}" --mode restore-size --json 2>/dev/null \
        | php -r '
            $data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
            $size = $data["total_size"] ?? null;
            if (! is_int($size) || $size < 1) {
                fwrite(STDERR, "Invalid Restic restore size.\n");
                exit(1);
            }
            echo $size;
        '
}

production_git_sha() {
    git -C "${APP_PATH}" rev-parse HEAD
}

backup_hostname() {
    hostname -f 2>/dev/null || hostname
}

production_db_uuid() {
    (
        cd "${APP_PATH}"
        run_as_app php -r '
            require "vendor/autoload.php";
            $app = require "bootstrap/app.php";
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $row = Illuminate\Support\Facades\DB::selectOne("SELECT @@server_uuid AS uuid");
            $uuid = (string) ($row->uuid ?? "");
            if (preg_match("/^[0-9a-f-]{36}$/i", $uuid) !== 1) {
                throw new RuntimeException("Invalid production database UUID");
            }
            echo strtolower($uuid);
        '
    )
}

production_db_fingerprint() {
    (
        cd "${APP_PATH}"
        run_as_app php -r '
            require "vendor/autoload.php";
            $app = require "bootstrap/app.php";
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $connection = Illuminate\Support\Facades\DB::connection();
            $database = (string) $connection->getDatabaseName();
            $row = $connection->selectOne(
                "SELECT LOWER(@@server_uuid) AS server_uuid, @@version AS version, "
                ."@@session.sql_mode AS sql_mode, "
                ."@@global.character_set_server AS character_set_server, "
                ."@@global.collation_server AS collation_server, "
                ."@@global.lower_case_table_names AS lower_case_table_names, "
                ."@@session.character_set_connection AS character_set_connection, "
                ."@@session.collation_connection AS collation_connection"
            );
            printf("database=%s\n", $database);
            printf("server_uuid=%s\n", (string) $row->server_uuid);
            printf("version=%s\n", (string) $row->version);
            printf("sql_mode=%s\n", (string) $row->sql_mode);
            printf("character_set_server=%s\n", (string) $row->character_set_server);
            printf("collation_server=%s\n", (string) $row->collation_server);
            printf("lower_case_table_names=%s\n", (string) $row->lower_case_table_names);
            printf("character_set_connection=%s\n", (string) $row->character_set_connection);
            printf("collation_connection=%s\n", (string) $row->collation_connection);
        '
    )
}

load_production_db_fingerprint() {
    local line_count

    PRODUCTION_DB_FINGERPRINT="$(production_db_fingerprint)"
    line_count="$(wc -l <<< "${PRODUCTION_DB_FINGERPRINT}" | tr -d ' ')"
    [[ "${line_count}" == 9 ]] || fail 'production database fingerprint is incomplete'
    grep -Eq '^database=[A-Za-z0-9_]+$' <<< "${PRODUCTION_DB_FINGERPRINT}" \
        || fail 'production database schema identity is invalid'
    grep -Eq '^server_uuid=[0-9a-f-]{36}$' <<< "${PRODUCTION_DB_FINGERPRINT}" \
        || fail 'production database server UUID fingerprint is invalid'
    grep -Eq '^version=[0-9A-Za-z._+-]+$' <<< "${PRODUCTION_DB_FINGERPRINT}" \
        || fail 'production database version fingerprint is invalid'
    grep -Eq '^sql_mode=[A-Za-z0-9_,]*$' <<< "${PRODUCTION_DB_FINGERPRINT}" \
        || fail 'production database sql_mode fingerprint is invalid'
    grep -Eq '^character_set_server=[a-z0-9_]+$' <<< "${PRODUCTION_DB_FINGERPRINT}" \
        || fail 'production database character set fingerprint is invalid'
    grep -Eq '^collation_server=[a-z0-9_]+$' <<< "${PRODUCTION_DB_FINGERPRINT}" \
        || fail 'production database collation fingerprint is invalid'
    grep -Eq '^lower_case_table_names=[012]$' <<< "${PRODUCTION_DB_FINGERPRINT}" \
        || fail 'production database lower_case_table_names fingerprint is invalid'
    grep -Eq '^character_set_connection=[a-z0-9_]+$' <<< "${PRODUCTION_DB_FINGERPRINT}" \
        || fail 'production database connection character set fingerprint is invalid'
    grep -Eq '^collation_connection=[a-z0-9_]+$' <<< "${PRODUCTION_DB_FINGERPRINT}" \
        || fail 'production database connection collation fingerprint is invalid'
    [[ "$(sed -n 's/^server_uuid=//p' <<< "${PRODUCTION_DB_FINGERPRINT}")" == "${PRODUCTION_DB_UUID}" ]] \
        || fail 'production database UUID checks disagree'
    PRODUCTION_DB_PARITY_FINGERPRINT="$(sed -n '3,$p' <<< "${PRODUCTION_DB_FINGERPRINT}")"
    PRODUCTION_DB_SQL_MODE="$(sed -n 's/^sql_mode=//p' <<< "${PRODUCTION_DB_FINGERPRINT}")"
    PRODUCTION_DB_CHARACTER_SET="$(sed -n 's/^character_set_server=//p' <<< "${PRODUCTION_DB_FINGERPRINT}")"
    PRODUCTION_DB_COLLATION="$(sed -n 's/^collation_server=//p' <<< "${PRODUCTION_DB_FINGERPRINT}")"
    PRODUCTION_DB_LOWER_CASE_TABLE_NAMES="$(sed -n 's/^lower_case_table_names=//p' <<< "${PRODUCTION_DB_FINGERPRINT}")"
    PRODUCTION_DB_CONNECTION_CHARACTER_SET="$(sed -n 's/^character_set_connection=//p' <<< "${PRODUCTION_DB_FINGERPRINT}")"
    PRODUCTION_DB_CONNECTION_COLLATION="$(sed -n 's/^collation_connection=//p' <<< "${PRODUCTION_DB_FINGERPRINT}")"
}

assert_production_configuration() {
    [[ -f "${APP_PATH}/.env" && ! -L "${APP_PATH}/.env" ]] \
        || fail 'production .env must be a regular file'
    [[ "$(stat -c '%U:%G:%a' "${APP_PATH}/.env")" == 'root:www-data:640' ]] \
        || fail 'production .env must be root:www-data 0640'
    [[ "$(stat -c '%U:%G:%a' "${APP_PATH}")" == 'root:root:755' ]] \
        || fail 'production application root must be root:root 0755'

    (
        cd "${APP_PATH}"
        run_as_app php -r '
            require "vendor/autoload.php";
            $app = require "bootstrap/app.php";
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $failures = [];
            $url = (string) config("app.url");
            if (config("app.env") !== "production") {
                $failures[] = "APP_ENV must be production";
            }
            if ((bool) config("app.debug")) {
                $failures[] = "APP_DEBUG must be false";
            }
            if (parse_url($url, PHP_URL_SCHEME) !== "https"
                || ! is_string(parse_url($url, PHP_URL_HOST))
                || parse_url($url, PHP_URL_HOST) === "") {
                $failures[] = "APP_URL must be an absolute HTTPS URL";
            }
            if (config("session.secure") !== true) {
                $failures[] = "SESSION_SECURE_COOKIE must be true";
            }
            if (config("session.http_only") !== true) {
                $failures[] = "SESSION_HTTP_ONLY must be true";
            }
            if ($failures !== []) {
                fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL);
                exit(1);
            }
        '
    ) || fail 'production security configuration is not release-ready'
}

assert_production_toolchain() {
    [[ "$(run_as_app php -r 'echo PHP_VERSION;')" == '8.4.23' ]] \
        || fail 'production CLI PHP must be exactly 8.4.23'
}

assert_production_runtime() {
    systemctl is-active --quiet villamucho-queue.service \
        || fail 'production queue service is not active'
    if ! systemctl is-active --quiet cron.service \
        && ! systemctl is-active --quiet crond.service; then
        fail 'production cron service is not active'
    fi
    [[ -f /etc/cron.d/villamucho-scheduler ]] \
        || fail 'production scheduler file is missing'
    [[ ! -e "${STATE_PATH}/villamucho-scheduler.deploy-paused" ]] \
        || fail 'a previous production scheduler hold still exists'
}

assert_isolated_network_support() {
    local docker_version
    local oldest

    docker_version="$(docker version --format '{{.Server.Version}}')"
    [[ -n "${docker_version}" ]] || fail 'Docker server version could not be read'
    oldest="$(printf '%s\n%s\n' "${MINIMUM_DOCKER_VERSION}" "${docker_version}" | sort -V | head -n 1)"
    [[ "${oldest}" == "${MINIMUM_DOCKER_VERSION}" ]] \
        || fail "Docker ${MINIMUM_DOCKER_VERSION}+ is required for an isolated bridge gateway"
}

assert_production_migrations_current() {
    local status

    if ! status="$(cd "${APP_PATH}" && run_as_app php artisan migrate:status --no-ansi)"; then
        fail 'production migration status could not be read'
    fi
    if grep -q 'Pending' <<< "${status}"; then
        fail 'production has pending migrations'
    fi
}

cleanup_resources() {
    local preserve_vendor_inbox="${1:-false}"
    local failed=0
    local docker_ready=false
    local -a rehearsal_containers=()

    set +e

    if command -v docker >/dev/null 2>&1; then
        if docker info >/dev/null 2>&1; then
            docker_ready=true
            mapfile -t rehearsal_containers < <(
                docker ps -aq \
                    --filter 'label=com.lora.release-rehearsal=true' \
                    --filter "label=com.lora.candidate=${CANDIDATE_SHA}"
            )
            if (( ${#rehearsal_containers[@]} > 0 )); then
                docker rm -f "${rehearsal_containers[@]}" >/dev/null 2>&1 || failed=1
            fi
            docker network rm "${NETWORK_NAME}" >/dev/null 2>&1 || true
        else
            failed=1
        fi
    fi

    sync >/dev/null 2>&1 || true
    if mountpoint -q "${MOUNT_PATH}"; then
        umount "${MOUNT_PATH}" >/dev/null 2>&1 || failed=1
    fi

    if cryptsetup status "${MAPPER_NAME}" >/dev/null 2>&1; then
        cryptsetup close "${MAPPER_NAME}" >/dev/null 2>&1 || failed=1
    fi

    # The only plaintext LUKS key lives in /run. Delete it even when teardown
    # cannot finish; after a reboot the remaining image is cryptographically inert.
    rm -f -- "${KEY_PATH}"

    if ! cryptsetup status "${MAPPER_NAME}" >/dev/null 2>&1; then
        rm -f -- "${LUKS_IMAGE}"
    fi

    rmdir "${MOUNT_PATH}" >/dev/null 2>&1 || true
    rm -f -- "${MARKER_PATH}.tmp."* >/dev/null 2>&1 || true
    if [[ -n "${BACKUP_BOOTSTRAP_DIR}" ]]; then
        rm -rf -- "${BACKUP_BOOTSTRAP_DIR}" >/dev/null 2>&1 || failed=1
        BACKUP_BOOTSTRAP_DIR=''
    fi
    if [[ "${preserve_vendor_inbox}" != true && -e "${VENDOR_INBOX}" ]]; then
        if [[ -d "${VENDOR_INBOX}" && ! -L "${VENDOR_INBOX}" \
            && "$(stat -c '%U:%G:%a' "${VENDOR_INBOX}" 2>/dev/null)" == 'root:root:700' ]]; then
            rm -rf -- "${VENDOR_INBOX}" >/dev/null 2>&1 || failed=1
        else
            failed=1
        fi
    fi

    if [[ "${docker_ready}" == true ]]; then
        if docker ps -aq \
            --filter 'label=com.lora.release-rehearsal=true' \
            --filter "label=com.lora.candidate=${CANDIDATE_SHA}" \
            | grep -q .; then
            failed=1
        fi
        docker network inspect "${NETWORK_NAME}" >/dev/null 2>&1 && failed=1
    fi
    mountpoint -q "${MOUNT_PATH}" && failed=1
    cryptsetup status "${MAPPER_NAME}" >/dev/null 2>&1 && failed=1
    [[ -e "${KEY_PATH}" ]] && failed=1
    [[ -e "${LUKS_IMAGE}" ]] && failed=1

    set -e
    (( failed == 0 ))
}

on_exit() {
    local status=$?
    trap - EXIT INT TERM HUP

    exec 1>&3 2>&3

    if [[ "${MODE}" == run && "${CLEANUP_COMPLETE}" != true ]]; then
        cleanup_resources || status=1
    fi

    if (( status != 0 )); then
        printf 'REHEARSAL=failed\n' >&3
    fi

    exit "${status}"
}

php_container() {
    local checkout="$1"
    shift

    docker run --rm --interactive \
        --name "${APP_CONTAINER_NAME}" \
        --label com.lora.release-rehearsal=true \
        --label "com.lora.candidate=${CANDIDATE_SHA}" \
        --network "${NETWORK_NAME}" \
        --read-only \
        --cap-drop ALL \
        --security-opt no-new-privileges \
        --pids-limit 256 \
        --memory 1g \
        --memory-swap 1g \
        --cpus 2 \
        --user 33:33 \
        --log-driver none \
        --tmpfs /tmp:rw,nosuid,nodev,noexec,size=268435456 \
        --env HOME=/tmp \
        --env SHOW_WELCOME_MESSAGE=false \
        --mount "type=bind,source=${checkout},target=/var/www/html" \
        --workdir /var/www/html \
        --entrypoint /usr/local/bin/php \
        "${APP_RUNTIME_IMAGE}" -d memory_limit=512M "$@"
}

validate_release_archive() {
    local archive="$1"
    local prefix="$2"
    local maximum_bytes="$3"
    local label="$4"
    local entry
    local normalized

    require_root_only_file "${archive}" "${label}"
    [[ "$(stat -c %s "${archive}")" =~ ^[1-9][0-9]*$ ]] \
        || fail "${label} is empty"
    (( $(stat -c %s "${archive}") <= maximum_bytes )) \
        || fail "${label} exceeds its size limit"
    while IFS= read -r entry; do
        [[ -n "${entry}" && "${entry}" != *$'\r'* ]] \
            || fail "${label} contains an invalid path"
        normalized="${entry%/}"
        [[ "${normalized}" == "${prefix}" || "${normalized}" == "${prefix}/"* ]] \
            || fail "${label} escapes ${prefix}"
        [[ "${normalized}" != /* && ! "${normalized}" =~ (^|/)[.][.](/|$) ]] \
            || fail "${label} contains path traversal"
    done < <(tar -tzf "${archive}")
    if tar -tvzf "${archive}" \
        | awk 'substr($1, 1, 1) != "-" && substr($1, 1, 1) != "d" { found = 1; exit } END { exit found ? 0 : 1 }'; then
        fail "${label} contains links or special files"
    fi
}

install_dependency_artifact() {
    local checkout="$1"
    local kind="$2"
    local expected_sha="$3"
    local archive="${VENDOR_INBOX}/${kind}-vendor.tar.gz"

    [[ "${kind}" =~ ^(baseline|candidate)$ ]] || fail 'dependency artifact kind is invalid'
    [[ "${expected_sha}" =~ ^[0-9a-f]{64}$ ]] || fail "${kind} dependency artifact hash is invalid"
    validate_release_archive "${archive}" vendor 1073741824 "${kind} dependency artifact"
    [[ "$(sha256sum "${archive}" | awk '{print $1}')" == "${expected_sha}" ]] \
        || fail "${kind} dependency artifact hash does not match the trusted runner output"
    [[ ! -e "${checkout}/vendor" && ! -L "${checkout}/vendor" ]] \
        || fail "${kind} checkout unexpectedly contains vendor"
    tar --extract --gzip --no-same-owner --no-same-permissions \
        --directory="${checkout}" --file="${archive}"
    [[ -d "${checkout}/vendor" && ! -L "${checkout}/vendor" ]] \
        || fail "${kind} dependency artifact did not create a regular vendor directory"
    if find "${checkout}/vendor" -type l -print -quit | grep -q .; then
        fail "${kind} dependency artifact contains symbolic links"
    fi
    chown -R 33:33 "${checkout}/vendor"
}

install_assets_artifact() {
    local checkout="$1"
    local archive="${VENDOR_INBOX}/candidate-assets.tar.gz"

    [[ "${CANDIDATE_ASSETS_SHA256}" =~ ^[0-9a-f]{64}$ ]] \
        || fail 'candidate assets artifact hash is invalid'
    validate_release_archive "${archive}" public/build 536870912 'candidate assets artifact'
    [[ "$(sha256sum "${archive}" | awk '{print $1}')" == "${CANDIDATE_ASSETS_SHA256}" ]] \
        || fail 'candidate assets artifact hash does not match the trusted runner output'
    [[ ! -e "${checkout}/public/build" && ! -L "${checkout}/public/build" ]] \
        || fail 'candidate checkout unexpectedly contains public/build'
    tar --extract --gzip --no-same-owner --no-same-permissions \
        --directory="${checkout}" --file="${archive}"
    [[ -d "${checkout}/public/build" && ! -L "${checkout}/public/build" ]] \
        || fail 'candidate assets artifact did not create public/build'
    if find "${checkout}/public/build" -type l -print -quit | grep -q .; then
        fail 'candidate assets artifact contains symbolic links'
    fi
    chown -R 33:33 "${checkout}/public/build"
}

verify_vite_manifest() {
    local checkout="$1"
    local build_id="${CANDIDATE_SHA:0:12}"

    env -i \
        PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
        EXPECTED_BUILD_ID="${build_id}" \
        RELEASE_ROOT="${checkout}" \
        php -d memory_limit=64M -r '
        $buildId = (string) getenv("EXPECTED_BUILD_ID");
        $root = (string) getenv("RELEASE_ROOT");
        $manifestPath = $root."/public/build/manifest.json";
        if (preg_match("/^[0-9a-f]{12}$/D", $buildId) !== 1
            || ! is_file($manifestPath)
            || filesize($manifestPath) > 10485760) {
            throw new RuntimeException("Invalid Vite manifest input.");
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        foreach (["resources/css/app.css", "resources/js/app.js"] as $required) {
            if (! isset($manifest[$required]) || ! is_array($manifest[$required])) {
                throw new RuntimeException("Missing Vite entry: ".$required);
            }
        }
        $pattern = "/-".preg_quote($buildId, "/")."(?:[0-9]+)?\\.[^\\/]+$/D";
        $outputs = [];
        foreach ($manifest as $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException("Invalid Vite manifest entry.");
            }
            if (isset($entry["file"])) {
                $outputs[] = $entry["file"];
            }
            foreach (["css", "assets"] as $key) {
                foreach (($entry[$key] ?? []) as $output) {
                    $outputs[] = $output;
                }
            }
        }
        if ($outputs === []) {
            throw new RuntimeException("Vite manifest has no outputs.");
        }
        foreach (array_unique($outputs) as $output) {
            if (! is_string($output)
                || str_starts_with($output, "/")
                || preg_match("#(?:^|/)\\.\\.(?:/|$)#", $output) === 1
                || preg_match($pattern, $output) !== 1
                || ! is_file($root."/public/build/".$output)) {
                throw new RuntimeException("Unexpected Vite output: ".json_encode($output));
            }
        }
    '
}

artisan() {
    local checkout="$1"
    shift
    php_container "${checkout}" artisan "$@"
}

isolated_db_identity() {
    local checkout="$1"
    php_container "${checkout}" -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $database = (string) Illuminate\Support\Facades\DB::connection()->getDatabaseName();
        $row = Illuminate\Support\Facades\DB::selectOne("SELECT @@server_uuid AS uuid");
        echo $database."|".strtolower((string) ($row->uuid ?? ""));
    '
}

assert_isolated_database() {
    local checkout="$1"
    local identity
    local database
    local uuid

    identity="$(isolated_db_identity "${checkout}")"
    database="${identity%%|*}"
    uuid="${identity#*|}"
    [[ "${database}" == "${REHEARSAL_DATABASE}" ]] || fail 'candidate is not connected to the rehearsal database'
    [[ "${uuid}" =~ ^[0-9a-f-]{36}$ ]] || fail 'isolated database returned an invalid UUID'
    [[ "${uuid}" != "${PRODUCTION_DB_UUID}" ]] || fail 'candidate resolved to the production database server'
}

assert_isolated_database_fingerprint() {
    local checkout="$1"
    local actual

    actual="$(php_container "${checkout}" -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $row = Illuminate\Support\Facades\DB::selectOne(
            "SELECT @@version AS version, @@session.sql_mode AS sql_mode, "
            ."@@global.character_set_server AS character_set_server, "
            ."@@global.collation_server AS collation_server, "
            ."@@global.lower_case_table_names AS lower_case_table_names, "
            ."@@session.character_set_connection AS character_set_connection, "
            ."@@session.collation_connection AS collation_connection"
        );
        printf("version=%s\n", (string) $row->version);
        printf("sql_mode=%s\n", (string) $row->sql_mode);
        printf("character_set_server=%s\n", (string) $row->character_set_server);
        printf("collation_server=%s\n", (string) $row->collation_server);
        printf("lower_case_table_names=%s\n", (string) $row->lower_case_table_names);
        printf("character_set_connection=%s\n", (string) $row->character_set_connection);
        printf("collation_connection=%s\n", (string) $row->collation_connection);
    ')"
    [[ "${actual}" == "${PRODUCTION_DB_PARITY_FINGERPRINT}" ]] \
        || fail 'isolated Laravel DB session differs from the production connection fingerprint'
}

mysql_exec() {
    docker exec "${CONTAINER_NAME}" mysql \
        --defaults-extra-file=/run/secrets/mysql-client.cnf \
        "$@" "${REHEARSAL_DATABASE}"
}

dump_schema() {
    local checkout="$1"
    local output="$2"

    docker exec "${CONTAINER_NAME}" mysqldump \
        --defaults-extra-file=/run/secrets/mysql-client.cnf \
        --no-data --routines --triggers --events --hex-blob --no-tablespaces \
        --skip-comments --skip-dump-date --compact "${REHEARSAL_DATABASE}" \
        | php_container "${checkout}" scripts/canonicalize-mysql-schema.php > "${output}"
}

dump_table_checksums() {
    local output="$1"
    local checksum_sql

    checksum_sql="$(mysql_exec -N -e \
        "SET SESSION group_concat_max_len = 1000000; SELECT CONCAT('CHECKSUM TABLE ', GROUP_CONCAT(CONCAT('${REHEARSAL_DATABASE}.', table_name) ORDER BY table_name SEPARATOR ', '), ';') FROM information_schema.tables WHERE table_schema = '${REHEARSAL_DATABASE}' AND table_type = 'BASE TABLE'")"
    [[ "${checksum_sql}" == 'CHECKSUM TABLE '* ]] || fail 'could not build the table checksum statement'
    mysql_exec -N -e "${checksum_sql}" > "${output}"
    [[ -s "${output}" ]] || fail 'table checksum output is empty'
    if awk -F '\t' '$2 == "NULL" { found = 1 } END { exit found ? 0 : 1 }' "${output}"; then
        fail 'at least one table does not provide a deterministic checksum'
    fi
}

dump_storage_checksums() {
    local checkout="$1"
    local output="$2"
    local storage_root="${checkout}/storage"
    local path_list="${output}.paths"
    local path
    local type
    local mode
    local uid
    local gid
    local topology
    local content_hash

    for root in app/private app/public; do
        [[ -d "${storage_root}/${root}" && ! -L "${storage_root}/${root}" ]] \
            || fail "storage checksum root is invalid: ${root}"
    done
    if find "${storage_root}/app/private" "${storage_root}/app/public" -xdev \
        ! -type d ! -type f -print -quit | grep -q .; then
        fail 'rehearsal storage contains links or special files'
    fi
    (
        cd "${storage_root}"
        find app/private app/public -xdev -print0 > "${path_list}"
        LC_ALL=C sort -z -o "${path_list}" "${path_list}"
        : > "${output}"
        while IFS= read -r -d '' path; do
            mode="$(stat -c %a -- "${path}")"
            uid="$(stat -c %u -- "${path}")"
            gid="$(stat -c %g -- "${path}")"
            if [[ -d "${path}" && ! -L "${path}" ]]; then
                type=directory
                topology=-
                content_hash=-
            elif [[ -f "${path}" && ! -L "${path}" ]]; then
                type=file
                topology="$(stat -c '%d:%i:%h' -- "${path}")"
                content_hash="$(sha256sum -- "${path}" | awk '{print $1}')"
                [[ "${content_hash}" =~ ^[0-9a-f]{64}$ ]] \
                    || fail 'storage content hash is invalid'
            else
                fail "unsupported storage entry encountered: ${path}"
            fi
            printf 'path\0%s\0type\0%s\0mode\0%s\0uid\0%s\0gid\0%s\0topology\0%s\0sha256\0%s\0' \
                "${path}" "${type}" "${mode}" "${uid}" "${gid}" \
                "${topology}" "${content_hash}" >> "${output}"
        done < "${path_list}"
        rm -f -- "${path_list}"
    )
}

persist_release_artifacts() {
    local staging="${RELEASE_ARTIFACT_ROOT}/.${CANDIDATE_SHA}.tmp.$$"
    local vendor_source="${VENDOR_INBOX}/candidate-vendor.tar.gz"
    local assets_source="${VENDOR_INBOX}/candidate-assets.tar.gz"
    local vendor_target="${staging}/candidate-vendor.tar.gz"
    local assets_target="${staging}/candidate-assets.tar.gz"
    local vendor_bytes
    local assets_bytes
    local available_bytes
    local filesystem_bytes
    local reserve_bytes=$((5 * 1024 * 1024 * 1024))
    local required_bytes
    local current_release=''
    local mount_targets
    local directory
    local name
    local kept=1
    local count=0
    local -a artifact_directories=()
    local -A keep=( ["${CANDIDATE_SHA}"]=1 )

    validate_release_archive "${vendor_source}" vendor 1073741824 'candidate dependency artifact'
    validate_release_archive "${assets_source}" public/build 536870912 'candidate assets artifact'
    [[ "$(sha256sum "${vendor_source}" | awk '{print $1}')" == "${CANDIDATE_VENDOR_SHA256}" ]] \
        || fail 'candidate dependency artifact changed before persistence'
    [[ "$(sha256sum "${assets_source}" | awk '{print $1}')" == "${CANDIDATE_ASSETS_SHA256}" ]] \
        || fail 'candidate assets artifact changed before persistence'

    vendor_bytes="$(stat -c %s "${vendor_source}")"
    assets_bytes="$(stat -c %s "${assets_source}")"
    install -d -o root -g "${APP_USER}" -m 0750 /var/lib/lora-release
    [[ -d /var/lib/lora-release && ! -L /var/lib/lora-release \
        && "$(stat -c '%U:%G:%a' /var/lib/lora-release)" == "root:${APP_USER}:750" ]] \
        || fail 'release state directory permissions are invalid'
    available_bytes="$(df --output=avail -B1 /var/lib/lora-release | awk 'NR == 2 {print $1}')"
    filesystem_bytes="$(df --output=size -B1 /var/lib/lora-release | awk 'NR == 2 {print $1}')"
    [[ "${vendor_bytes}" =~ ^[1-9][0-9]*$ && "${assets_bytes}" =~ ^[1-9][0-9]*$ \
        && "${available_bytes}" =~ ^[0-9]+$ && "${filesystem_bytes}" =~ ^[0-9]+$ ]] \
        || fail 'release artifact persistence capacity could not be determined'
    (( filesystem_bytes / 5 > reserve_bytes )) && reserve_bytes=$((filesystem_bytes / 5))
    required_bytes=$((vendor_bytes + assets_bytes + reserve_bytes))
    (( available_bytes > required_bytes )) \
        || fail 'insufficient disk reserve to persist release artifacts'

    install -d -o root -g "${APP_USER}" -m 0750 /var/lib/lora-release
    [[ -d /var/lib/lora-release && ! -L /var/lib/lora-release \
        && "$(stat -c '%U:%G:%a' /var/lib/lora-release)" == "root:${APP_USER}:750" ]] \
        || fail 'release state directory permissions are invalid'
    install -d -o root -g root -m 0700 "${RELEASE_ARTIFACT_ROOT}"
    [[ -d "${RELEASE_ARTIFACT_ROOT}" && ! -L "${RELEASE_ARTIFACT_ROOT}" \
        && "$(stat -c '%U:%G:%a' "${RELEASE_ARTIFACT_ROOT}")" == 'root:root:700' ]] \
        || fail 'release artifact root permissions are invalid'
    if [[ -e "${RELEASE_ARTIFACT_PATH}" || -L "${RELEASE_ARTIFACT_PATH}" ]]; then
        [[ -d "${RELEASE_ARTIFACT_PATH}" && ! -L "${RELEASE_ARTIFACT_PATH}" \
            && "$(stat -c '%U:%G:%a' "${RELEASE_ARTIFACT_PATH}")" == 'root:root:700' ]] \
            || fail 'existing release artifact path is unsafe'
        rm -rf -- "${RELEASE_ARTIFACT_PATH}"
    fi
    [[ ! -e "${staging}" && ! -L "${staging}" ]] \
        || fail 'release artifact staging path already exists'
    install -d -o root -g root -m 0700 "${staging}"
    install -o root -g root -m 0600 "${vendor_source}" "${vendor_target}"
    install -o root -g root -m 0600 "${assets_source}" "${assets_target}"
    [[ "$(sha256sum "${vendor_target}" | awk '{print $1}')" == "${CANDIDATE_VENDOR_SHA256}" ]] \
        || fail 'persisted dependency artifact hash mismatch'
    [[ "$(sha256sum "${assets_target}" | awk '{print $1}')" == "${CANDIDATE_ASSETS_SHA256}" ]] \
        || fail 'persisted assets artifact hash mismatch'
    sync "${vendor_target}" "${assets_target}" "${staging}"
    mv -- "${staging}" "${RELEASE_ARTIFACT_PATH}"
    sync "${RELEASE_ARTIFACT_ROOT}"
    [[ "$(stat -c '%U:%G:%a' "${RELEASE_ARTIFACT_PATH}")" == 'root:root:700' ]] \
        || fail 'persisted release artifact directory permissions are invalid'
    [[ "$(stat -c '%U:%G:%a' "${RELEASE_ARTIFACT_PATH}/candidate-vendor.tar.gz")" == 'root:root:600' \
        && "$(stat -c '%U:%G:%a' "${RELEASE_ARTIFACT_PATH}/candidate-assets.tar.gz")" == 'root:root:600' ]] \
        || fail 'persisted release artifact permissions are invalid'

    if [[ -e "${CURRENT_RELEASE_FILE}" || -L "${CURRENT_RELEASE_FILE}" ]]; then
        [[ -f "${CURRENT_RELEASE_FILE}" && ! -L "${CURRENT_RELEASE_FILE}" \
            && "$(stat -c '%U:%G:%a' "${CURRENT_RELEASE_FILE}")" == "root:${APP_USER}:640" ]] \
            || fail 'current release identity file is unsafe for artifact retention'
        current_release="$(tr -d '\r\n' < "${CURRENT_RELEASE_FILE}")"
        valid_sha "${current_release}" || fail 'current release identity is invalid for artifact retention'
        if [[ "${current_release}" != "${CANDIDATE_SHA}" \
            && -d "${RELEASE_ARTIFACT_ROOT}/${current_release}" \
            && ! -L "${RELEASE_ARTIFACT_ROOT}/${current_release}" ]]; then
            keep["${current_release}"]=1
            kept=2
        fi
    fi

    mount_targets="$(findmnt -rn -o TARGET)" \
        || fail 'release artifact mount inventory could not be read'

    while IFS= read -r -d '' directory; do
        name="${directory##*/}"
        valid_sha "${name}" \
            || fail "unexpected entry in release artifact root: ${name}"
        [[ -d "${directory}" && ! -L "${directory}" \
            && "$(stat -c '%U:%G:%a' "${directory}")" == 'root:root:700' ]] \
            || fail "release artifact directory is unsafe: ${name}"
        if awk -v path="${directory}" '
            $0 == path || index($0, path "/") == 1 { found = 1 }
            END { exit found ? 0 : 1 }
        ' <<< "${mount_targets}"; then
            fail "release artifact directory contains a mount: ${name}"
        fi
        artifact_directories+=("${directory}")
        count=$((count + 1))
    done < <(find "${RELEASE_ARTIFACT_ROOT}" -mindepth 1 -maxdepth 1 -print0)
    (( count <= 100 )) || fail 'release artifact retention inventory is unexpectedly large'

    while IFS= read -r name; do
        [[ -n "${name}" ]] || continue
        if [[ -z "${keep[${name}]+x}" && ${kept} -lt 3 ]]; then
            keep["${name}"]=1
            kept=$((kept + 1))
        fi
    done < <(
        for directory in "${artifact_directories[@]}"; do
            printf '%s %s\n' "$(stat -c %Y "${directory}")" "${directory##*/}"
        done | sort -rn | awk '{print $2}'
    )
    for directory in "${artifact_directories[@]}"; do
        name="${directory##*/}"
        if [[ -z "${keep[${name}]+x}" ]]; then
            rm -rf -- "${directory}"
        fi
    done
    sync "${RELEASE_ARTIFACT_ROOT}"
    count="$(find "${RELEASE_ARTIFACT_ROOT}" -mindepth 1 -maxdepth 1 -type d ! -type l | wc -l | tr -d ' ')"
    [[ "${count}" =~ ^[1-3]$ ]] || fail 'release artifact retention did not converge to at most three SHAs'
}

write_isolated_env() {
    local checkout="$1"
    local password="$2"
    local app_key="$3"
    local env_path="${checkout}/.env"

    [[ ! -e "${env_path}" && ! -L "${env_path}" ]] \
        || fail 'release source must not contain a tracked .env path'
    install -o 33 -g 33 -m 0600 /dev/null "${env_path}"
    {
        printf 'APP_NAME=Lora-Rehearsal\n'
        printf 'APP_ENV=production\n'
        printf 'APP_KEY=%s\n' "${app_key}"
        printf 'APP_DEBUG=false\n'
        printf 'APP_URL=https://rehearsal.invalid\n'
        printf 'DB_CONNECTION=mysql\n'
        printf 'DB_HOST=%s\n' "${CONTAINER_NAME}"
        printf 'DB_PORT=3306\n'
        printf 'DB_DATABASE=%s\n' "${REHEARSAL_DATABASE}"
        printf 'DB_USERNAME=%s\n' "${REHEARSAL_DB_USER}"
        printf 'DB_PASSWORD=%s\n' "${password}"
        printf 'DB_CHARSET=%s\n' "${PRODUCTION_DB_CONNECTION_CHARACTER_SET}"
        printf 'DB_COLLATION=%s\n' "${PRODUCTION_DB_CONNECTION_COLLATION}"
        printf 'CACHE_STORE=array\n'
        printf 'SESSION_DRIVER=array\n'
        printf 'SESSION_SECURE_COOKIE=true\n'
        printf 'QUEUE_CONNECTION=sync\n'
        printf 'MAIL_MAILER=array\n'
        printf 'BROADCAST_CONNECTION=null\n'
        printf 'FILESYSTEM_DISK=local\n'
        printf 'LOG_CHANNEL=single\n'
        printf 'BEDS24_BASE_URL=https://beds24.rehearsal.invalid\n'
        printf 'CHANNEX_BASE_URL=https://channex.rehearsal.invalid\n'
        printf 'ANTHROPIC_BASE_URL=https://anthropic.rehearsal.invalid\n'
        printf 'GEMINI_BASE_URL=https://gemini.rehearsal.invalid\n'
        printf 'LORA_CONTROL_PANEL_URL=https://control.rehearsal.invalid\n'
    } > "${env_path}"
    if [[ -e "${checkout}/bootstrap/cache/config.php" ]]; then
        [[ -f "${checkout}/bootstrap/cache/config.php" \
            && ! -L "${checkout}/bootstrap/cache/config.php" ]] \
            || fail 'bootstrap cache config path is not a regular file'
        rm -f -- "${checkout}/bootstrap/cache/config.php"
    fi
}

validate_isolated_app_key() {
    local checkout="$1"

    php_container "${checkout}" -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $configured = (string) config("app.key");
        $cipher = (string) config("app.cipher");
        $key = str_starts_with($configured, "base64:")
            ? base64_decode(substr($configured, 7), true)
            : $configured;
        if ($key === false || ! Illuminate\Encryption\Encrypter::supported($key, $cipher)) {
            throw new RuntimeException("Rehearsal APP_KEY is incompatible with the configured cipher.");
        }
    '
}

validate_isolated_passport_runtime() {
    local checkout="$1"

    php_container "${checkout}" -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (config("passport.private_key") !== null || config("passport.public_key") !== null) {
            throw new RuntimeException("Rehearsal Passport must use its restored local key files.");
        }
        $app->make(League\OAuth2\Server\AuthorizationServer::class);
        $app->make(League\OAuth2\Server\ResourceServer::class);
    '
}

preflight_mode() {
    local production_sha
    local backup_host
    local latest_snapshot
    local restore_bytes=0
    local available_bytes
    local mysql_image_state=missing
    local app_image_state=missing
    local backup_harness_state=upgrade-required

    require_commands
    id "${APP_USER}" >/dev/null 2>&1 || fail "application user does not exist: ${APP_USER}"
    [[ -d "${APP_PATH}/.git" || -f "${APP_PATH}/.git" ]] || fail "application is not a Git checkout: ${APP_PATH}"
    [[ -x /usr/local/sbin/lora-offsite-backup ]] || fail 'installed offsite backup script is missing'
    systemctl cat lora-backup.service >/dev/null 2>&1 || fail 'lora-backup.service is not installed'
    docker info >/dev/null 2>&1 || fail 'Docker daemon is unavailable'
    assert_isolated_network_support
    assert_production_repo_security

    acquire_release_lock
    load_restic_environment
    restic cat config >/dev/null

    production_sha="$(production_git_sha)"
    valid_sha "${production_sha}" || fail 'production checkout has an invalid commit SHA'
    [[ -z "$(git -C "${APP_PATH}" status --porcelain --untracked-files=normal)" ]] \
        || fail 'production working tree is not clean'
    assert_production_configuration
    assert_production_toolchain
    assert_production_runtime
    assert_production_migrations_current
    backup_host="$(backup_hostname)"
    latest_snapshot="$(latest_snapshot_id "${backup_host}")"
    if [[ -n "${latest_snapshot}" ]]; then
        restore_bytes="$(snapshot_restore_bytes "${latest_snapshot}")"
    fi
    available_bytes="$(df --output=avail -B1 "${REHEARSAL_STATE_PATH}" | awk 'NR == 2 {print $1}')"
    [[ "${available_bytes}" =~ ^[0-9]+$ ]] || fail 'could not determine available rehearsal disk space'
    docker image inspect "${MYSQL_IMAGE}" >/dev/null 2>&1 && mysql_image_state=present
    docker image inspect "${APP_RUNTIME_IMAGE}" >/dev/null 2>&1 && app_image_state=present
    installed_backup_harness_is_current && backup_harness_state=ready

    printf 'PREFLIGHT=passed\n' >&3
    printf 'CANDIDATE_SHA=%s\n' "${CANDIDATE_SHA}" >&3
    printf 'PRODUCTION_SHA=%s\n' "${production_sha}" >&3
    printf 'LATEST_RESTORE_BYTES=%s\n' "${restore_bytes}" >&3
    printf 'AVAILABLE_BYTES=%s\n' "${available_bytes}" >&3
    printf 'PINNED_MYSQL_IMAGE=%s\n' "${mysql_image_state}" >&3
    printf 'PINNED_APP_IMAGE=%s\n' "${app_image_state}" >&3
    printf 'BACKUP_HARNESS=%s\n' "${backup_harness_state}" >&3
}

cleanup_mode() {
    require_commands
    acquire_release_lock
    cleanup_resources || fail 'rehearsal resources could not be removed completely'
    printf 'CLEANUP=passed\n' >&3
    printf 'CANDIDATE_SHA=%s\n' "${CANDIDATE_SHA}" >&3
}

run_mode() {
    local production_sha
    local production_sha_after_backup
    local backup_host
    local snapshot_before
    local snapshot_id
    local restore_bytes
    local luks_bytes
    local available_bytes
    local filesystem_bytes
    local reserve_bytes=$((10 * 1024 * 1024 * 1024))
    local minimum_bytes=$((8 * 1024 * 1024 * 1024))
    local free_memory_kib
    local restored_root
    local restored_run
    local restored_passport_fingerprint
    local metadata_sha
    local metadata_passport_state
    local metadata_passport_fingerprint
    local source_repo
    local baseline_checkout
    local candidate_checkout
    local origin_url
    local fetched_sha
    local mysql_password
    local mysql_app_password
    local rehearsal_app_key
    local mysql_data
    local mysql_files
    local baseline_batch
    local release_batch
    local rolled_back_batch
    local schema_before
    local schema_after
    local checksums_before
    local checksums_after
    local storage_checksums_before
    local storage_checksums_after
    local integrity_before
    local baseline_integrity
    local schema_hash
    local checksum_hash
    local mysql_fingerprint_hash
    local table_count
    local marker_tmp
    local attempt
    local baseline_migration_status

    require_commands
    id "${APP_USER}" >/dev/null 2>&1 || fail "application user does not exist: ${APP_USER}"
    if [[ -n "${PR_NUMBER}" && ! "${PR_NUMBER}" =~ ^[1-9][0-9]*$ ]]; then
        fail 'LORA_REHEARSAL_PR_NUMBER must be empty or identify the source pull request'
    fi
    valid_sha "${EXPECTED_BASELINE_SHA}" || fail 'trusted dependency baseline SHA is invalid'
    [[ "${BASELINE_VENDOR_SHA256}" =~ ^[0-9a-f]{64}$ ]] \
        || fail 'baseline dependency artifact hash is invalid'
    [[ "${CANDIDATE_VENDOR_SHA256}" =~ ^[0-9a-f]{64}$ ]] \
        || fail 'candidate dependency artifact hash is invalid'
    [[ "${CANDIDATE_ASSETS_SHA256}" =~ ^[0-9a-f]{64}$ ]] \
        || fail 'candidate assets artifact hash is invalid'
    [[ -d "${VENDOR_INBOX}" && ! -L "${VENDOR_INBOX}" ]] \
        || fail 'dependency artifact inbox is missing'
    [[ "$(stat -c '%U:%G:%a' "${VENDOR_INBOX}")" == 'root:root:700' ]] \
        || fail 'dependency artifact inbox permissions are invalid'
    [[ -d "${APP_PATH}/.git" || -f "${APP_PATH}/.git" ]] || fail "application is not a Git checkout: ${APP_PATH}"
    [[ -x /usr/local/sbin/lora-offsite-backup ]] || fail 'installed offsite backup script is missing'
    docker info >/dev/null 2>&1 || fail 'Docker daemon is unavailable'
    assert_isolated_network_support
    assert_production_repo_security

    acquire_release_lock
    if [[ -e "${MARKER_PATH}" ]]; then
        [[ -f "${MARKER_PATH}" && ! -L "${MARKER_PATH}" ]] \
            || fail 'existing rehearsal marker is not a regular file'
        [[ "$(stat -c '%U:%G:%a' "${MARKER_PATH}")" == 'root:root:600' ]] \
            || fail 'existing rehearsal marker permissions are invalid'
        rm -f -- "${MARKER_PATH}.previous"
        mv -- "${MARKER_PATH}" "${MARKER_PATH}.previous"
    fi
    cleanup_resources true || fail 'stale rehearsal resources could not be removed safely'
    trap on_exit EXIT
    trap 'exit 130' INT TERM HUP

    production_sha="$(production_git_sha)"
    valid_sha "${production_sha}" || fail 'production checkout has an invalid commit SHA'
    [[ "${production_sha}" == "${EXPECTED_BASELINE_SHA}" ]] \
        || fail 'production changed after trusted dependency artifacts were built'
    [[ -z "$(git -C "${APP_PATH}" status --porcelain --untracked-files=normal)" ]] || fail 'production working tree is not clean'
    assert_production_configuration
    assert_production_toolchain
    assert_production_runtime
    assert_production_migrations_current
    PRODUCTION_DB_UUID="$(production_db_uuid)"
    load_production_db_fingerprint

    install_trusted_backup_harness
    if candidate_requires_passport; then
        CANDIDATE_REQUIRES_PASSPORT=true
        ensure_live_passport_key_pair
        [[ "${PASSPORT_PUBLIC_FINGERPRINT}" =~ ^[0-9a-f]{64}$ ]] \
            || fail 'live Passport key fingerprint was not established'
    fi

    load_restic_environment
    backup_host="$(backup_hostname)"
    snapshot_before="$(latest_snapshot_id "${backup_host}")"
    systemctl is-active --quiet lora-backup.service && fail 'the production backup service is already active'
    systemctl reset-failed lora-backup.service >/dev/null 2>&1 || true
    systemctl start --wait lora-backup.service
    [[ "$(systemctl show --property=Result --value lora-backup.service)" == success ]] \
        || fail 'fresh production backup service did not finish successfully'
    exec 9>"${STATE_PATH}/backup.lock"
    flock -n 9 || fail 'the fresh backup lock was not released cleanly'
    snapshot_id="$(latest_snapshot_id "${backup_host}")"
    [[ "${snapshot_id}" =~ ^[0-9a-f]{64}$ ]] || fail 'fresh backup did not return an exact Restic snapshot id'
    [[ "${snapshot_id}" != "${snapshot_before}" ]] || fail 'backup did not create a fresh Restic snapshot'
    production_sha_after_backup="$(production_git_sha)"
    [[ "${production_sha_after_backup}" == "${production_sha}" ]] || fail 'production code changed while the backup was running'
    [[ "$(production_db_fingerprint)" == "${PRODUCTION_DB_FINGERPRINT}" ]] \
        || fail 'production database settings changed while the backup was running'

    restic check --read-data
    restore_bytes="$(snapshot_restore_bytes "${snapshot_id}")"
    luks_bytes=$((restore_bytes * 3 + 4 * 1024 * 1024 * 1024))
    (( luks_bytes >= minimum_bytes )) || luks_bytes="${minimum_bytes}"
    if [[ -n "${LORA_REHEARSAL_LUKS_BYTES:-}" ]]; then
        [[ "${LORA_REHEARSAL_LUKS_BYTES}" =~ ^[0-9]+$ ]] || fail 'LORA_REHEARSAL_LUKS_BYTES must be numeric'
        (( LORA_REHEARSAL_LUKS_BYTES >= restore_bytes * 2 )) || fail 'requested LUKS workspace is too small'
        luks_bytes="${LORA_REHEARSAL_LUKS_BYTES}"
    fi
    available_bytes="$(df --output=avail -B1 "${REHEARSAL_STATE_PATH}" | awk 'NR == 2 {print $1}')"
    filesystem_bytes="$(df --output=size -B1 "${REHEARSAL_STATE_PATH}" | awk 'NR == 2 {print $1}')"
    [[ "${available_bytes}" =~ ^[0-9]+$ ]] || fail 'could not determine available rehearsal disk space'
    [[ "${filesystem_bytes}" =~ ^[0-9]+$ ]] || fail 'could not determine rehearsal filesystem size'
    (( filesystem_bytes / 5 > reserve_bytes )) && reserve_bytes=$((filesystem_bytes / 5))
    (( available_bytes > luks_bytes + reserve_bytes )) || fail 'insufficient disk space for encrypted restore and isolated MySQL'

    docker pull "${MYSQL_IMAGE}" >/dev/null
    docker pull "${APP_RUNTIME_IMAGE}" >/dev/null
    docker image inspect "${MYSQL_IMAGE}" >/dev/null
    docker image inspect "${APP_RUNTIME_IMAGE}" >/dev/null

    free_memory_kib="$(awk '$1 == "MemFree:" {print $2}' /proc/meminfo)"
    [[ "${free_memory_kib}" =~ ^[1-9][0-9]*$ ]] \
        || fail 'could not determine free physical memory for LUKS2'
    (( free_memory_kib >= LUKS_MINIMUM_FREE_MEMORY_KIB )) \
        || fail 'insufficient free physical memory for bounded LUKS2 Argon2id'

    fallocate -l "${luks_bytes}" "${LUKS_IMAGE}"
    head -c "${LUKS_KEYFILE_BYTES}" /dev/urandom > "${KEY_PATH}"
    chmod 0600 "${KEY_PATH}"
    [[ "$(stat -c %s "${KEY_PATH}")" == "${LUKS_KEYFILE_BYTES}" ]] \
        || fail 'LUKS2 key file has an unexpected size'
    # The key contains 512 bits from /dev/urandom. Bound Argon2id explicitly so
    # cryptsetup does not benchmark against a 1 GiB default on this no-swap host.
    cryptsetup luksFormat \
        --type luks2 \
        --batch-mode \
        --cipher aes-xts-plain64 \
        --key-size 512 \
        --pbkdf argon2id \
        --pbkdf-memory "${LUKS_PBKDF_MEMORY_KIB}" \
        --pbkdf-parallel "${LUKS_PBKDF_PARALLEL}" \
        --pbkdf-force-iterations "${LUKS_PBKDF_TIME_COST}" \
        --key-file "${KEY_PATH}" \
        --keyfile-size "${LUKS_KEYFILE_BYTES}" \
        "${LUKS_IMAGE}" \
        || fail 'could not format the bounded LUKS2 rehearsal workspace'
    verify_luks_header "${LUKS_IMAGE}"
    cryptsetup open \
        --type luks2 \
        --key-slot 0 \
        --disable-external-tokens \
        --key-file "${KEY_PATH}" \
        --keyfile-size "${LUKS_KEYFILE_BYTES}" \
        "${LUKS_IMAGE}" "${MAPPER_NAME}" \
        || fail 'could not open the bounded LUKS2 rehearsal workspace'
    mkfs.ext4 -q -F "/dev/mapper/${MAPPER_NAME}"
    install -d -m 0700 "${MOUNT_PATH}"
    mount -o nodev,nosuid,noexec "/dev/mapper/${MAPPER_NAME}" "${MOUNT_PATH}"
    chmod 0700 "${MOUNT_PATH}"

    WORKSPACE="${MOUNT_PATH}/workspace"
    install -d -m 0700 "${WORKSPACE}" "${WORKSPACE}/home" "${WORKSPACE}/tmp" "${WORKSPACE}/restic-cache"
    install -m 0600 /dev/null "${WORKSPACE}/rehearsal.log"
    exec >>"${WORKSPACE}/rehearsal.log" 2>&1
    export RESTIC_CACHE_DIR="${WORKSPACE}/restic-cache"
    restored_root="${WORKSPACE}/restore"
    restic restore "${snapshot_id}" --target "${restored_root}"

    mapfile -t restored_runs < <(find "${restored_root}${STATE_PATH}" -mindepth 1 -maxdepth 1 -type d -name 'run.*' -print)
    (( ${#restored_runs[@]} == 1 )) || fail 'the exact snapshot must contain one backup run directory'
    restored_run="${restored_runs[0]}"
    [[ -s "${restored_run}/database.sql" ]] || fail 'restored database dump is empty'
    [[ -s "${restored_run}/integrity.json" ]] || fail 'restored PII-free integrity snapshot is missing'
    require_root_only_file "${restored_run}/application-key.txt" 'restored application recovery key'
    [[ "$(wc -l < "${restored_run}/application-key.txt" | tr -d ' ')" == 1 ]] \
        || fail 'restored application recovery key must contain one line'
    if grep -q $'\r' "${restored_run}/application-key.txt"; then
        fail 'restored application recovery key contains a carriage return'
    fi
    (( $(stat -c %s "${restored_run}/application-key.txt") <= 512 )) \
        || fail 'restored application recovery key is unexpectedly large'
    rehearsal_app_key="$(tr -d '\r\n' < "${restored_run}/application-key.txt")"
    [[ -n "${rehearsal_app_key}" && "${rehearsal_app_key}" != *$'\n'* \
        && "${rehearsal_app_key}" != *$'\r'* ]] \
        || fail 'restored application recovery key is invalid'
    [[ -d "${restored_run}/storage/app/private" ]] || fail 'private storage was not restored'
    [[ -d "${restored_run}/storage/app/public" ]] || fail 'public storage was not restored'
    if [[ "${CANDIDATE_REQUIRES_PASSPORT}" == true ]]; then
        require_root_only_file \
            "${restored_run}/passport/oauth-private.key" \
            'restored Passport private key'
        require_root_only_file \
            "${restored_run}/passport/oauth-public.key" \
            'restored Passport public key'
        [[ "$(stat -c '%U:%G:%a' "${restored_run}/passport/oauth-private.key")" == root:root:600 \
            && "$(stat -c '%U:%G:%a' "${restored_run}/passport/oauth-public.key")" == root:root:600 ]] \
            || fail 'restored Passport keys must be root:root 0600'
        restored_passport_fingerprint="$(passport_pair_fingerprint \
            "${restored_run}/passport/oauth-private.key" \
            "${restored_run}/passport/oauth-public.key")"
        [[ "${restored_passport_fingerprint}" == "${PASSPORT_PUBLIC_FINGERPRINT}" ]] \
            || fail 'restored Passport key pair differs from the live production pair'
    fi
    (
        cd "${restored_run}"
        sha256sum --quiet -c SHA256SUMS
    )
    (
        cd "${restored_run}/storage"
        if [[ -s ../storage-SHA256SUMS ]]; then
            sha256sum --quiet -c ../storage-SHA256SUMS
        elif find app -xdev -type f -print -quit | grep -q .; then
            fail 'storage checksum manifest is empty but restored files exist'
        fi
    )
    metadata_sha="$(sed -n 's/^git_commit=//p' "${restored_run}/metadata.txt")"
    [[ "${metadata_sha}" == "${production_sha}" ]] || fail 'backup metadata does not match the production commit'
    metadata_passport_state="$(sed -n 's/^passport_keys=//p' "${restored_run}/metadata.txt")"
    metadata_passport_fingerprint="$(sed -n 's/^passport_public_key_sha256=//p' \
        "${restored_run}/metadata.txt")"
    if [[ "${CANDIDATE_REQUIRES_PASSPORT}" == true ]]; then
        [[ "${metadata_passport_state}" == present \
            && "${metadata_passport_fingerprint}" == "${PASSPORT_PUBLIC_FINGERPRINT}" \
            && "${metadata_passport_fingerprint}" == "${restored_passport_fingerprint}" ]] \
            || fail 'backup metadata does not bind the restored Passport key pair'
    else
        [[ "${metadata_passport_state}" == not-required \
            && "${metadata_passport_fingerprint}" == not-required ]] \
            || [[ "${metadata_passport_state}" == present \
                && "${metadata_passport_fingerprint}" =~ ^[0-9a-f]{64}$ ]] \
            || fail 'backup metadata contains an invalid Passport key state'
    fi
    source_repo="${WORKSPACE}/source"
    baseline_checkout="${WORKSPACE}/baseline"
    candidate_checkout="${WORKSPACE}/candidate"
    git clone --quiet --no-hardlinks --no-checkout "${APP_PATH}" "${source_repo}"
    origin_url="$(git -C "${APP_PATH}" remote get-url origin)"
    git -C "${source_repo}" remote set-url origin "${origin_url}"
    if [[ -n "${PR_NUMBER}" ]]; then
        git -C "${source_repo}" fetch --quiet --no-tags origin "refs/pull/${PR_NUMBER}/head"
    else
        git -C "${source_repo}" fetch --quiet --no-tags origin refs/heads/main
    fi
    fetched_sha="$(git -C "${source_repo}" rev-parse FETCH_HEAD)"
    [[ "${fetched_sha}" == "${CANDIDATE_SHA}" ]] || fail 'remote source no longer matches the approved candidate SHA'
    git -C "${source_repo}" merge-base --is-ancestor "${production_sha}" "${CANDIDATE_SHA}" \
        || fail 'candidate is not a descendant of the current production commit'
    git -C "${source_repo}" worktree add --quiet --detach "${baseline_checkout}" "${production_sha}"
    git -C "${source_repo}" worktree add --quiet --detach "${candidate_checkout}" "${CANDIDATE_SHA}"
    if find "${baseline_checkout}" "${candidate_checkout}" -type l -print -quit | grep -q .; then
        fail 'release source worktrees must not contain symbolic links'
    fi

    mysql_password="$(head -c 32 /dev/urandom | sha256sum | cut -d' ' -f1)"
    mysql_app_password="$(head -c 32 /dev/urandom | sha256sum | cut -d' ' -f1)"
    printf '%s\n' "${mysql_password}" > "${WORKSPACE}/mysql-root-password"
    MYSQL_CLIENT_FILE="${WORKSPACE}/mysql-client.cnf"
    {
        printf '[client]\n'
        printf 'user=root\n'
        printf 'password=%s\n' "${mysql_password}"
        printf 'default-character-set=utf8mb4\n'
    } > "${MYSQL_CLIENT_FILE}"
    chmod 0600 "${WORKSPACE}/mysql-root-password" "${MYSQL_CLIENT_FILE}"
    mysql_data="${WORKSPACE}/mysql-data"
    mysql_files="${WORKSPACE}/mysql-files"
    install -d -m 0700 "${mysql_data}"
    install -d -o 999 -g 999 -m 0700 "${mysql_files}"

    docker network create --driver bridge --internal \
        --opt com.docker.network.bridge.gateway_mode_ipv4=isolated \
        "${NETWORK_NAME}" >/dev/null
    [[ "$(docker network inspect "${NETWORK_NAME}" --format '{{.Internal}}')" == true ]] \
        || fail 'rehearsal network did not retain internal mode'
    [[ "$(docker network inspect "${NETWORK_NAME}" --format '{{index .Options "com.docker.network.bridge.gateway_mode_ipv4"}}')" == isolated ]] \
        || fail 'rehearsal network did not retain isolated gateway mode'
    [[ "$(docker network inspect "${NETWORK_NAME}" --format '{{range .IPAM.Config}}{{json .Gateway}}{{end}}')" == '""' ]] \
        || fail 'rehearsal network unexpectedly allocated an IPv4 gateway'
    docker run --detach \
        --name "${CONTAINER_NAME}" \
        --label com.lora.release-rehearsal=true \
        --label "com.lora.candidate=${CANDIDATE_SHA}" \
        --network "${NETWORK_NAME}" \
        --restart no \
        --read-only \
        --log-driver none \
        --security-opt no-new-privileges \
        --pids-limit 512 \
        --memory 2g \
        --memory-swap 2g \
        --cpus 2 \
        --ulimit nofile=4096:4096 \
        --ulimit nproc=512:512 \
        --mount "type=bind,source=${mysql_data},target=/var/lib/mysql" \
        --mount "type=bind,source=${mysql_files},target=/var/lib/mysql-files" \
        --mount "type=bind,source=${WORKSPACE}/mysql-root-password,target=/run/secrets/mysql-root-password,readonly" \
        --mount "type=bind,source=${MYSQL_CLIENT_FILE},target=/run/secrets/mysql-client.cnf,readonly" \
        --tmpfs /tmp:rw,nosuid,nodev,noexec,size=268435456 \
        --tmpfs /var/run/mysqld:rw,nosuid,nodev,noexec,size=67108864 \
        --env MYSQL_ROOT_PASSWORD_FILE=/run/secrets/mysql-root-password \
        --env "MYSQL_DATABASE=${REHEARSAL_DATABASE}" \
        "${MYSQL_IMAGE}" \
        --sql-mode="${PRODUCTION_DB_SQL_MODE}" \
        --character-set-server="${PRODUCTION_DB_CHARACTER_SET}" \
        --collation-server="${PRODUCTION_DB_COLLATION}" \
        --lower-case-table-names="${PRODUCTION_DB_LOWER_CASE_TABLE_NAMES}" >/dev/null

    # The official MySQL entrypoint starts a temporary --skip-networking server
    # before it creates MYSQL_DATABASE and execs the final server. A socket-level
    # mysqladmin ping can therefore succeed too early. Require an authenticated
    # TCP query against the target database so only the final server is accepted.
    for attempt in $(seq 1 120); do
        if docker exec "${CONTAINER_NAME}" mysql \
            --defaults-extra-file=/run/secrets/mysql-client.cnf \
            --protocol=TCP \
            --host=127.0.0.1 \
            --connect-timeout=2 \
            --database="${REHEARSAL_DATABASE}" \
            --batch \
            --skip-column-names \
            --execute='SELECT 1' >/dev/null 2>&1; then
            break
        fi
        [[ "$(docker inspect "${CONTAINER_NAME}" --format '{{.State.Running}}')" == true ]] \
            || fail 'isolated MySQL exited before becoming ready'
        (( attempt < 120 )) || fail 'isolated MySQL did not become ready'
        sleep 1
    done
    docker exec -i "${CONTAINER_NAME}" mysql \
        --defaults-extra-file=/run/secrets/mysql-client.cnf \
        --protocol=TCP \
        --host=127.0.0.1 \
        --connect-timeout=5 \
        --database="${REHEARSAL_DATABASE}" \
        < "${restored_run}/database.sql" \
        || fail 'restored database could not be imported into isolated MySQL'

    mysql_exec -e \
        "CREATE USER '${REHEARSAL_DB_USER}'@'%' IDENTIFIED BY '${mysql_app_password}'; GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER ON ${REHEARSAL_DATABASE}.* TO '${REHEARSAL_DB_USER}'@'%'; FLUSH PRIVILEGES;"

    write_isolated_env "${baseline_checkout}" "${mysql_app_password}" "${rehearsal_app_key}"
    write_isolated_env "${candidate_checkout}" "${mysql_app_password}" "${rehearsal_app_key}"
    for checkout in "${baseline_checkout}" "${candidate_checkout}"; do
        install -d -m 0700 "${checkout}/storage/app/private" "${checkout}/storage/app/public"
        rsync -aH --delete --numeric-ids --one-file-system \
            "${restored_run}/storage/app/private/" "${checkout}/storage/app/private/"
        rsync -aH --delete --numeric-ids --one-file-system \
            "${restored_run}/storage/app/public/" "${checkout}/storage/app/public/"
        if [[ "${CANDIDATE_REQUIRES_PASSPORT}" == true ]]; then
            install -o 33 -g 33 -m 0400 \
                "${restored_run}/passport/oauth-private.key" \
                "${checkout}/storage/oauth-private.key"
            install -o 33 -g 33 -m 0400 \
                "${restored_run}/passport/oauth-public.key" \
                "${checkout}/storage/oauth-public.key"
        fi
    done
    chown -R 33:33 "${baseline_checkout}" "${candidate_checkout}"
    install_dependency_artifact "${baseline_checkout}" baseline "${BASELINE_VENDOR_SHA256}"
    install_dependency_artifact "${candidate_checkout}" candidate "${CANDIDATE_VENDOR_SHA256}"
    install_assets_artifact "${candidate_checkout}"
    verify_vite_manifest "${candidate_checkout}"
    validate_isolated_app_key "${baseline_checkout}"

    [[ -r "${baseline_checkout}/scripts/canonicalize-mysql-schema.php" ]] \
        || fail 'trusted baseline schema canonicalizer is missing'
    schema_before="${WORKSPACE}/schema-before.sql"
    schema_after="${WORKSPACE}/schema-after.sql"
    checksums_before="${WORKSPACE}/checksums-before.tsv"
    checksums_after="${WORKSPACE}/checksums-after.tsv"
    storage_checksums_before="${WORKSPACE}/storage-checksums-before.bin"
    storage_checksums_after="${WORKSPACE}/storage-checksums-after.bin"
    baseline_integrity='storage/framework/rehearsal-baseline-integrity.json'
    integrity_before='storage/framework/rehearsal-integrity.json'

    # Capture every rollback baseline before any candidate PHP command boots.
    artisan "${baseline_checkout}" package:discover --ansi
    assert_isolated_database "${baseline_checkout}"
    assert_isolated_database_fingerprint "${baseline_checkout}"
    artisan "${baseline_checkout}" tenants:verify-integrity --snapshot="${baseline_integrity}"
    baseline_batch="$(mysql_exec -N -e 'SELECT COALESCE(MAX(batch), 0) FROM migrations')"
    [[ "${baseline_batch}" =~ ^[0-9]+$ ]] || fail 'baseline migration batch is invalid'
    dump_schema "${baseline_checkout}" "${schema_before}"
    dump_table_checksums "${checksums_before}"
    dump_storage_checksums "${candidate_checkout}" "${storage_checksums_before}"

    validate_isolated_app_key "${candidate_checkout}"
    artisan "${candidate_checkout}" package:discover --ansi
    if [[ "${CANDIDATE_REQUIRES_PASSPORT}" == true ]]; then
        validate_isolated_passport_runtime "${candidate_checkout}"
    fi
    assert_isolated_database "${candidate_checkout}"
    assert_isolated_database_fingerprint "${candidate_checkout}"
    artisan "${candidate_checkout}" tenants:verify-integrity \
        --verify-storage --snapshot="${integrity_before}"
    artisan "${candidate_checkout}" migrate --force
    release_batch="$(mysql_exec -N -e 'SELECT COALESCE(MAX(batch), 0) FROM migrations')"
    [[ "${release_batch}" =~ ^[0-9]+$ ]] || fail 'release migration batch is invalid'
    (( release_batch >= baseline_batch )) || fail 'candidate removed an existing migration batch'
    artisan "${candidate_checkout}" tenants:verify-integrity \
        --verify-storage --compare="${integrity_before}" \
        --allow-additive-schema --allow-additive-settings

    assert_isolated_database "${candidate_checkout}"
    if (( release_batch > baseline_batch )); then
        artisan "${candidate_checkout}" migrate:rollback --batch="${release_batch}" --force
    fi
    rolled_back_batch="$(mysql_exec -N -e 'SELECT COALESCE(MAX(batch), 0) FROM migrations')"
    [[ "${rolled_back_batch}" == "${baseline_batch}" ]] || fail 'rollback did not restore the baseline migration batch'
    artisan "${candidate_checkout}" tenants:verify-integrity \
        --verify-storage --compare="${integrity_before}"
    artisan "${baseline_checkout}" tenants:verify-integrity --compare="${baseline_integrity}"
    artisan "${baseline_checkout}" route:list --json >/dev/null
    if ! baseline_migration_status="$(artisan "${baseline_checkout}" migrate:status --no-ansi)"; then
        fail 'baseline migration status could not be read after rollback'
    fi
    if grep -q 'Pending' <<< "${baseline_migration_status}"; then
        fail 'baseline code reports pending migrations after rollback'
    fi

    dump_schema "${baseline_checkout}" "${schema_after}"
    dump_table_checksums "${checksums_after}"
    dump_storage_checksums "${candidate_checkout}" "${storage_checksums_after}"
    cmp --silent "${schema_before}" "${schema_after}" || fail 'database schema differs after rollback'
    cmp --silent "${checksums_before}" "${checksums_after}" || fail 'table checksums differ after rollback'
    cmp --silent "${storage_checksums_before}" "${storage_checksums_after}" \
        || fail 'storage files differ after candidate migration and rollback'
    schema_hash="$(sha256sum "${schema_after}" | cut -d' ' -f1)"
    checksum_hash="$(sha256sum "${checksums_after}" | cut -d' ' -f1)"
    mysql_fingerprint_hash="$(printf '%s' "${PRODUCTION_DB_FINGERPRINT}" | sha256sum | cut -d' ' -f1)"
    table_count="$(wc -l < "${checksums_after}" | tr -d ' ')"
    [[ "${table_count}" =~ ^[1-9][0-9]*$ ]] || fail 'no database tables were verified'

    persist_release_artifacts
    exec 1>&3 2>&3
    cleanup_resources || fail 'successful rehearsal could not be cleaned up completely'
    CLEANUP_COMPLETE=true

    marker_tmp="${MARKER_PATH}.tmp.$$"
    {
        printf 'candidate_sha=%s\n' "${CANDIDATE_SHA}"
        printf 'production_sha=%s\n' "${production_sha}"
        printf 'snapshot_id=%s\n' "${snapshot_id}"
        printf 'mysql_image=%s\n' "${MYSQL_IMAGE}"
        printf 'app_image=%s\n' "${APP_RUNTIME_IMAGE}"
        printf 'verified_tables=%s\n' "${table_count}"
        printf 'schema_sha256=%s\n' "${schema_hash}"
        printf 'checksums_sha256=%s\n' "${checksum_hash}"
        printf 'mysql_fingerprint_sha256=%s\n' "${mysql_fingerprint_hash}"
        printf 'vendor_sha256=%s\n' "${CANDIDATE_VENDOR_SHA256}"
        printf 'assets_sha256=%s\n' "${CANDIDATE_ASSETS_SHA256}"
        printf 'passport_public_key_sha256=%s\n' "${PASSPORT_PUBLIC_FINGERPRINT}"
        printf 'completed_utc=%s\n' "$(date --utc +'%Y-%m-%dT%H:%M:%SZ')"
    } > "${marker_tmp}"
    chown root:root "${marker_tmp}"
    chmod 0600 "${marker_tmp}"
    mv "${marker_tmp}" "${MARKER_PATH}"
    rm -f -- "${MARKER_PATH}.previous"
    [[ "$(stat -c '%U:%G:%a' "${MARKER_PATH}")" == 'root:root:600' ]] || fail 'success marker permissions are not root:root 0600'

    trap - EXIT INT TERM HUP
    printf 'REHEARSAL=passed\n' >&3
    printf 'CANDIDATE_SHA=%s\n' "${CANDIDATE_SHA}" >&3
    printf 'PRODUCTION_SHA=%s\n' "${production_sha}" >&3
    printf 'SNAPSHOT_ID=%s\n' "${snapshot_id}" >&3
    printf 'VERIFIED_TABLES=%s\n' "${table_count}" >&3
    printf 'MARKER=%s\n' "${MARKER_PATH}" >&3
}

case "${MODE}" in
    preflight) preflight_mode ;;
    run) run_mode ;;
    cleanup) cleanup_mode ;;
esac
