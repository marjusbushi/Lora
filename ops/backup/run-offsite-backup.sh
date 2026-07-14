#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly CONFIG_FILE="${LORA_BACKUP_CONFIG:-/etc/lora-backup/restic.env}"
readonly APP_PATH="${LORA_APP_PATH:-/var/www/villamucho}"
readonly STATE_PATH="${LORA_BACKUP_STATE_PATH:-/var/lib/lora-backup}"
sensitive_paths=()

fail() {
    printf 'Lora backup failed: %s\n' "$*" >&2
    exit 1
}

require_root_only_file() {
    local path="$1"
    local label="$2"
    local owner
    local mode

    [[ -r "${path}" ]] || fail "${label} is not readable: ${path}"
    owner="$(stat -c '%U' "${path}")"
    mode="$(stat -c '%a' "${path}")"
    [[ "${owner}" == "root" ]] || fail "${label} must be owned by root"
    (( (8#${mode} & 8#077) == 0 )) || fail "${label} must not be accessible by group/others"
}

export_database_fallback() {
    local output_path="$1"
    local mysql_cnf
    local database_file
    local database

    command -v mysqldump >/dev/null 2>&1 || fail "required binary is missing: mysqldump"

    mysql_cnf="$(mktemp /run/lora-backup-mysql.XXXXXXXX.cnf)"
    database_file="$(mktemp /run/lora-backup-database.XXXXXXXX)"
    sensitive_paths+=("${mysql_cnf}" "${database_file}")

    MYSQL_CNF="${mysql_cnf}" DB_NAME_FILE="${database_file}" php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $name = (string) config("database.default");
        $config = config("database.connections.".$name);
        if (! is_array($config) || ($config["driver"] ?? null) !== "mysql") {
            throw new RuntimeException("MySQL connection required");
        }
        $quote = static fn ($value) => "\"".addcslashes((string) $value, "\\\"\n\r\t")."\"";
        $lines = [
            "[client]",
            "user=".$quote($config["username"] ?? ""),
            "password=".$quote($config["password"] ?? ""),
            "default-character-set=utf8mb4",
        ];
        if (($config["unix_socket"] ?? "") !== "") {
            $lines[] = "socket=".$quote($config["unix_socket"]);
        } else {
            $lines[] = "host=".$quote($config["host"] ?? "127.0.0.1");
            $lines[] = "port=".(int) ($config["port"] ?? 3306);
        }
        if (file_put_contents(getenv("MYSQL_CNF"), implode(PHP_EOL, $lines).PHP_EOL, LOCK_EX) === false
            || file_put_contents(getenv("DB_NAME_FILE"), (string) ($config["database"] ?? ""), LOCK_EX) === false) {
            throw new RuntimeException("Could not prepare MySQL backup credentials");
        }
        chmod(getenv("MYSQL_CNF"), 0600);
        chmod(getenv("DB_NAME_FILE"), 0600);
    '

    database="$(<"${database_file}")"
    [[ -n "${database}" ]] || fail "database name is empty"

    mysqldump --defaults-extra-file="${mysql_cnf}" \
        --single-transaction --quick --skip-lock-tables \
        --routines --triggers --events --hex-blob --no-tablespaces \
        --default-character-set=utf8mb4 "${database}" > "${output_path}"

    rm -f -- "${mysql_cnf}" "${database_file}"
}

[[ "${EUID}" -eq 0 ]] || fail "must run as root"
require_root_only_file "${CONFIG_FILE}" "config"

# shellcheck source=/dev/null
set -a
source "${CONFIG_FILE}"
set +a

: "${RESTIC_REPOSITORY:?RESTIC_REPOSITORY is required}"
: "${RESTIC_PASSWORD_FILE:?RESTIC_PASSWORD_FILE is required}"
: "${AWS_ACCESS_KEY_ID:?AWS_ACCESS_KEY_ID is required}"
: "${AWS_SECRET_ACCESS_KEY:?AWS_SECRET_ACCESS_KEY is required}"

[[ -d "${APP_PATH}" ]] || fail "application path is missing: ${APP_PATH}"
require_root_only_file "${RESTIC_PASSWORD_FILE}" "Restic password file"

for binary in flock php restic sha256sum; do
    command -v "${binary}" >/dev/null 2>&1 || fail "required binary is missing: ${binary}"
done

mkdir -p "${STATE_PATH}"
chmod 0700 "${STATE_PATH}"

exec 9>"${STATE_PATH}/backup.lock"
flock -n 9 || fail "another backup is already running"

run_dir="$(mktemp -d "${STATE_PATH}/run.XXXXXXXX")"
cleanup() {
    if (( ${#sensitive_paths[@]} > 0 )); then
        rm -f -- "${sensitive_paths[@]}"
    fi
    rm -rf -- "${run_dir}"
}
trap cleanup EXIT

cd "${APP_PATH}"

artisan_commands="$(php artisan list --raw)"
checksum_files=(database.sql metadata.txt)

if grep -q '^tenants:verify-integrity ' <<< "${artisan_commands}"; then
    php artisan tenants:verify-integrity --snapshot="${run_dir}/integrity.json"
    checksum_files+=(integrity.json)
fi

if grep -q '^backups:export-database ' <<< "${artisan_commands}"; then
    php artisan backups:export-database "${run_dir}/database.sql"
else
    export_database_fallback "${run_dir}/database.sql"
fi

[[ -s "${run_dir}/database.sql" ]] || fail "database export is empty"

{
    printf 'created_utc=%s\n' "$(date --utc +'%Y-%m-%dT%H:%M:%SZ')"
    printf 'hostname=%s\n' "$(hostname -f 2>/dev/null || hostname)"
    printf 'git_commit=%s\n' "$(git rev-parse HEAD)"
    printf 'git_branch=%s\n' "$(git branch --show-current)"
} > "${run_dir}/metadata.txt"

(
    cd "${run_dir}"
    sha256sum "${checksum_files[@]}" > SHA256SUMS
)

backup_paths=("${run_dir}")
for storage_path in "${APP_PATH}/storage/app/private" "${APP_PATH}/storage/app/public"; do
    [[ -d "${storage_path}" ]] && backup_paths+=("${storage_path}")
done

restic backup \
    --host "$(hostname -f 2>/dev/null || hostname)" \
    --tag lora-production \
    --tag database-and-storage \
    "${backup_paths[@]}"

# Metadata verification is quick enough for every run. Full data reads belong
# to the scheduled restore drill, not the production backup window.
restic check

date --utc +'%Y-%m-%dT%H:%M:%SZ' > "${STATE_PATH}/last-success"
printf 'Lora offsite backup completed successfully.\n'
