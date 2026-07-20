#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly CONFIG_FILE="${LORA_BACKUP_CONFIG:-/etc/lora-backup/restic.env}"
readonly APP_PATH="${LORA_APP_PATH:-/var/www/villamucho}"
readonly APP_USER="${LORA_APP_USER:-www-data}"
readonly STATE_PATH="${LORA_BACKUP_STATE_PATH:-/var/lib/lora-backup}"
readonly QUEUE_SERVICE="${LORA_QUEUE_SERVICE:-villamucho-queue.service}"
readonly SCHEDULER_FILE="${LORA_SCHEDULER_FILE:-/etc/cron.d/villamucho-scheduler}"
readonly SCHEDULER_HOLD="${STATE_PATH}/villamucho-scheduler.backup-paused"
readonly MYSQL_CONFIG_FILE="${LORA_BACKUP_MYSQL_CONFIG:-/etc/lora-backup/mysql.cnf}"
readonly RESTIC_CACHE_PATH="${LORA_RESTIC_CACHE_PATH:-/var/cache/restic}"
readonly WRITER_FENCE_SENTINEL="/run/lora-backup-writers-enabled"
readonly RELEASE_HANDOFF_REQUEST="${STATE_PATH}/release-handoff.request"
readonly RELEASE_HANDOFF_READY="${STATE_PATH}/release-handoff.ready"
readonly RELEASE_LOCK_FILE="${STATE_PATH}/production-release.lock"
readonly RELEASE_WRITER_FENCE_SENTINEL="${STATE_PATH}/release-writers-enabled"
readonly RELEASE_WRITER_FENCE_HOLD="${STATE_PATH}/release-writers-enabled.handoff"
readonly RELEASE_WRITER_FENCE_NAME="lora-release-writer-fence.conf"
sensitive_paths=()
work_dir=''
php_fpm_service=''
php_fpm_socket=''
php_fpm_fence_file=''
queue_fence_file="/run/systemd/system/${QUEUE_SERVICE}.d/lora-backup-writer-fence.conf"
cron_service=''
quiesce_started=false
php_fpm_fence_owned=false
queue_fence_owned=false
last_success_tmp=''
mysql_event_scheduler_state=''
private_storage_pin=''
public_storage_pin=''
private_storage_pin_mounted=false
public_storage_pin_mounted=false
release_handoff_requested=false
release_handoff_committed=false
release_handoff_nonce=''
release_handoff_candidate=''
release_handoff_created_utc=''
release_handoff_request_fingerprint=''
release_php_fpm_fence_file=''
release_queue_fence_file="/etc/systemd/system/${QUEUE_SERVICE}.d/${RELEASE_WRITER_FENCE_NAME}"
release_cron_fence_file=''
release_handoff_ready_tmp=''
restic_json=''
snapshot_id=''
snapshot_created_utc=''
upload_completed_utc=''
initial_storage_sync_duration=1h
final_storage_sync_duration=30m
restic_backup_duration=90m
restic_check_duration=20m
storage_scan_duration=5m
snapshot_capacity_duration=10m
integrity_check_duration=10m
database_export_duration=30m
checksum_duration=30m

fail() {
    printf 'Lora backup failed: %s\n' "$*" >&2
    exit 1
}

require_root_only_file() {
    local path="$1"
    local label="$2"
    local owner
    local mode

    [[ -f "${path}" && ! -L "${path}" && -r "${path}" ]] \
        || fail "${label} is not a readable regular file: ${path}"
    owner="$(stat -c '%U' "${path}")"
    mode="$(stat -c '%a' "${path}")"
    [[ "${owner}" == "root" ]] || fail "${label} must be owned by root"
    (( (8#${mode} & 8#077) == 0 )) || fail "${label} must not be accessible by group/others"
}

root_0600_file_is_safe() {
    local path="$1"

    [[ -f "${path}" && ! -L "${path}" && -r "${path}" ]] \
        && [[ "$(stat -c '%U:%G:%a' "${path}" 2>/dev/null || true)" == root:root:600 ]]
}

release_lock_is_held() {
    local status

    if flock -n 8; then
        status=1
        flock -u 8 || return 1
    else
        status=$?
        (( status == 1 )) || return 1
        status=0
    fi
    return "${status}"
}

release_handoff_request_is_unchanged() {
    local fingerprint

    root_0600_file_is_safe "${RELEASE_HANDOFF_REQUEST}" || return 1
    fingerprint="$(stat -c '%d:%i:%s:%Y' "${RELEASE_HANDOFF_REQUEST}"):$(sha256sum "${RELEASE_HANDOFF_REQUEST}" | awk '{print $1}')" \
        || return 1
    [[ "${fingerprint}" == "${release_handoff_request_fingerprint}" ]]
}

detect_release_handoff_request() {
    local -a lines=()
    local created_epoch
    local now_epoch

    if [[ ! -e "${RELEASE_HANDOFF_REQUEST}" && ! -L "${RELEASE_HANDOFF_REQUEST}" ]]; then
        return 0
    fi
    root_0600_file_is_safe "${RELEASE_HANDOFF_REQUEST}" \
        || fail "release handoff request must be root:root 0600"
    [[ ! -e "${RELEASE_HANDOFF_READY}" && ! -L "${RELEASE_HANDOFF_READY}" ]] \
        || fail "a release handoff ready marker already exists"
    [[ ! -e "${RELEASE_WRITER_FENCE_HOLD}" && ! -L "${RELEASE_WRITER_FENCE_HOLD}" ]] \
        || fail "a release handoff sentinel hold already exists"
    mapfile -t lines < "${RELEASE_HANDOFF_REQUEST}"
    [[ "$(wc -l < "${RELEASE_HANDOFF_REQUEST}" | tr -d ' ')" == 4 \
        && "${#lines[@]}" -eq 4 \
        && "${lines[0]}" == version=1 \
        && "${lines[1]}" =~ ^nonce=([0-9a-f]{64})$ \
        && "${lines[2]}" =~ ^candidate_sha=([0-9a-f]{40})$ \
        && "${lines[3]}" =~ ^created_utc=([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z)$ ]] \
        || fail "release handoff request has an invalid or non-exact format"
    release_handoff_nonce="${lines[1]#nonce=}"
    release_handoff_candidate="${lines[2]#candidate_sha=}"
    release_handoff_created_utc="${lines[3]#created_utc=}"
    created_epoch="$(date --date="${release_handoff_created_utc}" +%s)" \
        || fail "release handoff request timestamp is invalid"
    now_epoch="$(date --utc +%s)"
    (( created_epoch <= now_epoch + 30 && now_epoch - created_epoch <= 300 )) \
        || fail "release handoff request is stale or from the future"
    release_handoff_request_fingerprint="$(stat -c '%d:%i:%s:%Y' "${RELEASE_HANDOFF_REQUEST}"):$(sha256sum "${RELEASE_HANDOFF_REQUEST}" | awk '{print $1}')"
    release_lock_is_held \
        || fail "release handoff request requires an independently held production release lock"
    # GitHub-hosted release jobs have a hard six-hour ceiling. These release-only
    # limits keep the fail-closed maintenance handoff below the 350-minute SSH
    # budget even when every bounded retry is consumed. Timer backups retain the
    # more generous defaults above.
    initial_storage_sync_duration=20m
    final_storage_sync_duration=10m
    restic_backup_duration=45m
    restic_check_duration=5m
    storage_scan_duration=2m
    snapshot_capacity_duration=5m
    integrity_check_duration=5m
    database_export_duration=15m
    checksum_duration=15m
    release_handoff_requested=true
}

run_as_app_bounded() {
    local duration="$1"
    shift

    timeout --signal=TERM --kill-after=30s "${duration}" \
        runuser --user "${APP_USER}" -- \
            env -i \
                HOME="${APP_PATH}/storage/framework" \
                PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
                "$@"
}

assert_repository_security() {
    local unsafe_path

    [[ -d "${APP_PATH}/.git" && ! -L "${APP_PATH}/.git" ]] \
        || fail "production .git must be a real directory"
    if ! unsafe_path="$(find "${APP_PATH}/.git" -xdev \
        \( ! -user root -o -perm /022 \) -print -quit)"; then
        fail "production Git metadata could not be inspected safely"
    fi
    if [[ -n "${unsafe_path}" ]]; then
        fail "production Git metadata must be root-owned and non-writable by group/others"
    fi
    if ! unsafe_path="$(find "${APP_PATH}" -xdev \
        \( -path "${APP_PATH}/.git" -o -path "${APP_PATH}/vendor" \
           -o -path "${APP_PATH}/node_modules" -o -path "${APP_PATH}/storage" \
           -o -path "${APP_PATH}/bootstrap/cache" -o -path "${APP_PATH}/public/build" \
           -o -path "${APP_PATH}/public/storage" \) -prune \
        -o \( -type l -o ! -user root -o -perm /022 \) -print -quit)"; then
        fail "production source boundary could not be inspected safely"
    fi
    if [[ -n "${unsafe_path}" ]]; then
        fail "production source boundary must be root-owned and immutable"
    fi
}

service_is_stopped() {
    local service="$1"
    local active_state
    local main_pid

    active_state="$(timeout 5s systemctl show --property=ActiveState --value \
        "${service}" 2>/dev/null || true)"
    main_pid="$(timeout 5s systemctl show --property=MainPID --value \
        "${service}" 2>/dev/null || true)"
    case "${active_state}" in inactive|failed) ;; *) return 1 ;; esac
    [[ "${main_pid}" == 0 ]]
}

service_is_running() {
    local service="$1"
    local active_state
    local main_pid

    active_state="$(timeout 5s systemctl show --property=ActiveState --value \
        "${service}" 2>/dev/null || true)"
    main_pid="$(timeout 5s systemctl show --property=MainPID --value \
        "${service}" 2>/dev/null || true)"
    [[ "${active_state}" == active && "${main_pid}" =~ ^[1-9][0-9]*$ ]]
}

hard_stop_service() {
    local service="$1"

    timeout 30s systemctl stop "${service}" >/dev/null 2>&1 || true
    if ! service_is_stopped "${service}"; then
        timeout 10s systemctl kill --kill-who=all --signal=KILL \
            "${service}" >/dev/null 2>&1 || true
        timeout 15s systemctl stop "${service}" >/dev/null 2>&1 || true
    fi
    service_is_stopped "${service}"
}

detect_php_fpm_service() {
    local -a services=()

    mapfile -t services < <(
        systemctl list-units --type=service --state=running \
            'php*-fpm.service' --no-legend --plain | awk 'NF {print $1}'
    )
    (( ${#services[@]} == 1 )) || fail "exactly one running PHP-FPM service is required"
    php_fpm_service="${services[0]}"
    [[ "${php_fpm_service}" =~ ^php[0-9]+([.][0-9]+)?-fpm[.]service$ ]] \
        || fail "active PHP-FPM service name is invalid"
    local version="${php_fpm_service#php}"
    version="${version%-fpm.service}"
    php_fpm_socket="/run/php/php${version}-fpm.sock"
    php_fpm_fence_file="/run/systemd/system/${php_fpm_service}.d/lora-backup-writer-fence.conf"
    [[ "${php_fpm_socket}" =~ ^/run/php/php[0-9]+([.][0-9]+)?-fpm[.]sock$ ]] \
        || fail "active PHP-FPM socket path is invalid"
    service_is_running "${php_fpm_service}" \
        || fail "active PHP-FPM service has no running main process"
    [[ -S "${php_fpm_socket}" ]] || fail "active PHP-FPM socket is missing"
}

scheduler_path_is_safe() {
    local path="$1"

    [[ -f "${path}" && ! -L "${path}" ]] \
        && [[ "$(stat -c '%U:%G:%a' "${path}" 2>/dev/null || true)" == root:root:644 ]]
}

queue_process_is_absent() {
    local status

    if pgrep -f '[a]rtisan (queue:(work|listen)|horizon)([[:space:]]|$)' >/dev/null; then
        return 1
    else
        status=$?
        (( status == 1 ))
    fi
}

scheduler_process_is_absent() {
    local status

    if pgrep -f '[a]rtisan schedule:(run|work|finish-command)([[:space:]]|$)' >/dev/null; then
        return 1
    else
        status=$?
        (( status == 1 ))
    fi
}

php_fpm_process_is_absent() {
    local status

    if pgrep -f '(^|/)[p]hp-fpm([0-9.]*)?(:|[[:space:]]|$)' >/dev/null; then
        return 1
    else
        status=$?
        (( status == 1 ))
    fi
}

php_fpm_listener_is_absent() {
    local listeners
    local status

    listeners="$(timeout 10s ss -H -xl)" || return 1
    if awk -v target="${php_fpm_socket}" '
        { for (field = 1; field <= NF; field++) if ($field == target) found = 1 }
        END { exit found ? 0 : 1 }
    ' <<< "${listeners}"; then
        return 1
    else
        status=$?
        (( status == 1 ))
    fi
}

runtime_fence_file_is_safe() {
    local path="$1"
    local expected

    expected=$'[Unit]\nRefuseManualStart=yes\nConditionPathExists=/run/lora-backup-writers-enabled'
    [[ -f "${path}" && ! -L "${path}" ]] \
        && [[ "$(stat -c '%U:%G:%a' "${path}" 2>/dev/null || true)" == root:root:644 ]] \
        && [[ "$(<"${path}")" == "${expected}" ]]
}

release_runtime_fence_file_is_safe() {
    local path="$1"
    local expected

    expected="$(printf '[Unit]\nConditionPathExists=%s' \
        "${RELEASE_WRITER_FENCE_SENTINEL}")"
    [[ -f "${path}" && ! -L "${path}" ]] \
        && [[ "$(stat -c '%U:%G:%a' "${path}" 2>/dev/null || true)" == root:root:644 ]] \
        && [[ "$(<"${path}")" == "${expected}" ]]
}

release_handoff_sentinel_is_safe() {
    local path="$1"

    root_0600_file_is_safe "${path}" \
        && [[ "$(wc -l < "${path}" | tr -d ' ')" == 1 ]] \
        && [[ "$(<"${path}")" == "nonce=${release_handoff_nonce}" ]]
}

service_uses_runtime_fence() {
    local service="$1"
    local fence_file="$2"
    local dropins

    if ! dropins="$(timeout 10s systemctl show --property=DropInPaths --value \
        "${service}" 2>/dev/null)"; then
        return 2
    fi
    [[ " ${dropins} " == *" ${fence_file} "* ]]
}

service_does_not_use_runtime_fence() {
    local status

    if service_uses_runtime_fence "$@"; then
        return 1
    else
        status=$?
        (( status == 1 ))
    fi
}

release_handoff_fences_are_loaded() {
    release_runtime_fence_file_is_safe "${release_php_fpm_fence_file}" \
        && release_runtime_fence_file_is_safe "${release_queue_fence_file}" \
        && release_runtime_fence_file_is_safe "${release_cron_fence_file}" \
        && service_uses_runtime_fence "${php_fpm_service}" "${release_php_fpm_fence_file}" \
        && service_uses_runtime_fence "${QUEUE_SERVICE}" "${release_queue_fence_file}" \
        && service_uses_runtime_fence "${cron_service}" "${release_cron_fence_file}"
}

assert_release_handoff_prearmed() {
    release_handoff_request_is_unchanged \
        && release_lock_is_held \
        && release_handoff_sentinel_is_safe "${RELEASE_WRITER_FENCE_SENTINEL}" \
        && [[ ! -e "${RELEASE_WRITER_FENCE_HOLD}" && ! -L "${RELEASE_WRITER_FENCE_HOLD}" ]] \
        && release_handoff_fences_are_loaded \
        || fail "release handoff was not pre-armed exactly or the release lock was lost"
}

runtime_writer_fence_is_installed() {
    [[ "${php_fpm_fence_owned}" == true && "${queue_fence_owned}" == true ]] \
        && [[ ! -e "${WRITER_FENCE_SENTINEL}" && ! -L "${WRITER_FENCE_SENTINEL}" ]] \
        && runtime_fence_file_is_safe "${php_fpm_fence_file}" \
        && runtime_fence_file_is_safe "${queue_fence_file}" \
        && service_uses_runtime_fence "${php_fpm_service}" "${php_fpm_fence_file}" \
        && service_uses_runtime_fence "${QUEUE_SERVICE}" "${queue_fence_file}"
}

runtime_writer_fence_is_clear() {
    [[ ! -e "${WRITER_FENCE_SENTINEL}" && ! -L "${WRITER_FENCE_SENTINEL}" ]] \
        && [[ ! -e "${php_fpm_fence_file}" && ! -L "${php_fpm_fence_file}" ]] \
        && [[ ! -e "${queue_fence_file}" && ! -L "${queue_fence_file}" ]] \
        && service_does_not_use_runtime_fence "${php_fpm_service}" "${php_fpm_fence_file}" \
        && service_does_not_use_runtime_fence "${QUEUE_SERVICE}" "${queue_fence_file}"
}

try_install_runtime_writer_fence() {
    local fence_file
    local fence_owned

    [[ ! -e "${WRITER_FENCE_SENTINEL}" && ! -L "${WRITER_FENCE_SENTINEL}" ]] \
        || return 1
    if [[ "${php_fpm_fence_owned}" == false && "${queue_fence_owned}" == false ]]; then
        runtime_writer_fence_is_clear || return 1
    fi
    for fence_file in "${php_fpm_fence_file}" "${queue_fence_file}"; do
        if [[ "${fence_file}" == "${php_fpm_fence_file}" ]]; then
            fence_owned="${php_fpm_fence_owned}"
        else
            fence_owned="${queue_fence_owned}"
        fi
        if [[ -e "${fence_file}" || -L "${fence_file}" ]]; then
            [[ "${fence_owned}" == true ]] \
                && runtime_fence_file_is_safe "${fence_file}" \
                || return 1
            continue
        fi
        install -d -o root -g root -m 0755 "${fence_file%/*}" || return 1
        [[ -d "${fence_file%/*}" && ! -L "${fence_file%/*}" ]] \
            || return 1
        if ! install -o root -g root -m 0644 /dev/null "${fence_file}"; then
            # Preflight proved the path absent. If install left a regular file,
            # claim it so cleanup can remove only this run's partial artifact.
            if [[ -f "${fence_file}" && ! -L "${fence_file}" ]] \
                && [[ "$(stat -c '%U:%G:%a' "${fence_file}" 2>/dev/null || true)" == root:root:644 ]]; then
                if [[ "${fence_file}" == "${php_fpm_fence_file}" ]]; then
                    php_fpm_fence_owned=true
                else
                    queue_fence_owned=true
                fi
            fi
            return 1
        fi
        if [[ "${fence_file}" == "${php_fpm_fence_file}" ]]; then
            php_fpm_fence_owned=true
        else
            queue_fence_owned=true
        fi
        printf '[Unit]\nRefuseManualStart=yes\nConditionPathExists=%s\n' \
            "${WRITER_FENCE_SENTINEL}" > "${fence_file}" || return 1
    done
    timeout 30s systemctl daemon-reload || return 1
    runtime_writer_fence_is_installed
}

install_runtime_writer_fence() {
    try_install_runtime_writer_fence \
        || fail "runtime writer fence could not be installed and verified"
}

remove_runtime_writer_fence() {
    local failed=0
    local changed=false
    local php_dir="${php_fpm_fence_file%/*}"
    local queue_dir="${queue_fence_file%/*}"

    if [[ "${php_fpm_fence_owned}" == true ]]; then
        rm -f -- "${php_fpm_fence_file}" || failed=1
        changed=true
    fi
    if [[ "${queue_fence_owned}" == true ]]; then
        rm -f -- "${queue_fence_file}" || failed=1
        changed=true
    fi
    if [[ "${changed}" == false ]]; then
        return 0
    fi
    rmdir "${php_dir}" >/dev/null 2>&1 || true
    rmdir "${queue_dir}" >/dev/null 2>&1 || true
    timeout 30s systemctl daemon-reload || failed=1
    if [[ "${php_fpm_fence_owned}" == true ]]; then
        [[ ! -e "${php_fpm_fence_file}" && ! -L "${php_fpm_fence_file}" ]] || failed=1
        service_does_not_use_runtime_fence "${php_fpm_service}" "${php_fpm_fence_file}" \
            || failed=1
    fi
    if [[ "${queue_fence_owned}" == true ]]; then
        [[ ! -e "${queue_fence_file}" && ! -L "${queue_fence_file}" ]] || failed=1
        service_does_not_use_runtime_fence "${QUEUE_SERVICE}" "${queue_fence_file}" \
            || failed=1
    fi
    if (( failed == 0 )); then
        php_fpm_fence_owned=false
        queue_fence_owned=false
    fi
    (( failed == 0 ))
}

writer_fence_is_intact() {
    runtime_writer_fence_is_installed \
        && service_is_stopped "${php_fpm_service}" \
        && service_is_stopped "${QUEUE_SERVICE}" \
        && service_is_stopped "${cron_service}" \
        && php_fpm_process_is_absent \
        && php_fpm_listener_is_absent \
        && queue_process_is_absent \
        && scheduler_process_is_absent \
        && [[ ! -e "${SCHEDULER_FILE}" && ! -L "${SCHEDULER_FILE}" ]] \
        && scheduler_path_is_safe "${SCHEDULER_HOLD}"
}

assert_writer_fence() {
    writer_fence_is_intact || fail "production writer fence was lost"
}

release_handoff_writer_boundary_is_intact() {
    [[ ! -e "${RELEASE_WRITER_FENCE_SENTINEL}" && ! -L "${RELEASE_WRITER_FENCE_SENTINEL}" ]] \
        && release_handoff_sentinel_is_safe "${RELEASE_WRITER_FENCE_HOLD}" \
        && release_handoff_fences_are_loaded \
        && service_is_stopped "${php_fpm_service}" \
        && service_is_stopped "${QUEUE_SERVICE}" \
        && service_is_stopped "${cron_service}" \
        && php_fpm_process_is_absent \
        && php_fpm_listener_is_absent \
        && queue_process_is_absent \
        && scheduler_process_is_absent \
        && [[ ! -e "${SCHEDULER_FILE}" && ! -L "${SCHEDULER_FILE}" ]] \
        && scheduler_path_is_safe "${SCHEDULER_HOLD}"
}

rollback_release_handoff_activation() {
    if release_handoff_sentinel_is_safe "${RELEASE_WRITER_FENCE_HOLD}" \
        && [[ ! -e "${RELEASE_WRITER_FENCE_SENTINEL}" && ! -L "${RELEASE_WRITER_FENCE_SENTINEL}" ]]; then
        mv --no-target-directory "${RELEASE_WRITER_FENCE_HOLD}" \
            "${RELEASE_WRITER_FENCE_SENTINEL}" || return 1
    fi
    release_handoff_sentinel_is_safe "${RELEASE_WRITER_FENCE_SENTINEL}" \
        && [[ ! -e "${RELEASE_WRITER_FENCE_HOLD}" && ! -L "${RELEASE_WRITER_FENCE_HOLD}" ]]
}

commit_release_handoff() {
    local ready_payload

    [[ "${release_handoff_requested}" == true ]] || return 0
    assert_writer_fence
    assert_release_handoff_prearmed
    release_handoff_request_is_unchanged \
        || fail "release handoff request changed before commit"

    # Moving the sentinel atomically activates the already-loaded release
    # start-fences. Until the durable ready marker exists, this move is
    # reversible so cleanup can bring the old release back online.
    mv --no-target-directory "${RELEASE_WRITER_FENCE_SENTINEL}" \
        "${RELEASE_WRITER_FENCE_HOLD}" \
        || fail "release handoff sentinel could not be activated"
    if ! release_handoff_writer_boundary_is_intact; then
        rollback_release_handoff_activation || true
        fail "release handoff writer boundary was not intact after activation"
    fi
    if ! remove_runtime_writer_fence; then
        rollback_release_handoff_activation || true
        fail "backup writer fences could not be replaced by release fences"
    fi
    if ! release_handoff_writer_boundary_is_intact; then
        rollback_release_handoff_activation || true
        fail "release writer fences were not intact after backup fence removal"
    fi

    ready_payload="$(printf '%s\n' \
        'version=1' \
        "nonce=${release_handoff_nonce}" \
        "candidate_sha=${release_handoff_candidate}" \
        "snapshot_created_utc=${snapshot_created_utc}" \
        "upload_completed_utc=${upload_completed_utc}" \
        "snapshot_id=${snapshot_id}" \
        "php_fpm_service=${php_fpm_service}" \
        "php_fpm_socket=${php_fpm_socket}" \
        "queue_service=${QUEUE_SERVICE}" \
        "cron_service=${cron_service}" \
        "scheduler_hold=${SCHEDULER_HOLD}" \
        "sentinel_hold=${RELEASE_WRITER_FENCE_HOLD}")"
    release_handoff_ready_tmp="${STATE_PATH}/.release-handoff.ready.$$"
    [[ ! -e "${release_handoff_ready_tmp}" && ! -L "${release_handoff_ready_tmp}" ]] \
        || fail "release handoff temporary ready marker already exists"
    install -o root -g root -m 0600 /dev/null "${release_handoff_ready_tmp}"
    printf '%s\n' "${ready_payload}" > "${release_handoff_ready_tmp}"
    sync "${release_handoff_ready_tmp}"
    [[ ! -e "${RELEASE_HANDOFF_READY}" && ! -L "${RELEASE_HANDOFF_READY}" ]] \
        || fail "release handoff ready marker appeared during commit"
    mv --no-target-directory "${release_handoff_ready_tmp}" "${RELEASE_HANDOFF_READY}"
    release_handoff_ready_tmp=''
    sync "${RELEASE_HANDOFF_READY}"
    root_0600_file_is_safe "${RELEASE_HANDOFF_READY}" \
        && [[ "$(<"${RELEASE_HANDOFF_READY}")" == "${ready_payload}" ]] \
        && release_handoff_writer_boundary_is_intact \
        || fail "release handoff ready marker or writer boundary is not durable and exact"

    release_handoff_committed=true
    quiesce_started=false
}

wait_for_service() {
    local service="$1"
    local socket_path="${2:-}"
    local attempt

    for attempt in $(seq 1 30); do
        if service_is_running "${service}" \
            && { [[ -z "${socket_path}" ]] || [[ -S "${socket_path}" ]]; }; then
            return 0
        fi
        (( attempt < 30 )) || break
        sleep 1
    done
    return 1
}

reclose_writer_boundary() {
    local failed=0
    local attempt

    # Reinstall both start fences before stopping services so no automatic or
    # manual activation can reopen a service after it has been stopped.
    try_install_runtime_writer_fence || failed=1
    hard_stop_service "${php_fpm_service}" || failed=1
    hard_stop_service "${QUEUE_SERVICE}" || failed=1
    hard_stop_service "${cron_service}" || failed=1

    if [[ -e "${SCHEDULER_FILE}" || -L "${SCHEDULER_FILE}" ]]; then
        if scheduler_path_is_safe "${SCHEDULER_FILE}" \
            && [[ ! -e "${SCHEDULER_HOLD}" && ! -L "${SCHEDULER_HOLD}" ]]; then
            mv -- "${SCHEDULER_FILE}" "${SCHEDULER_HOLD}" || failed=1
        else
            failed=1
        fi
    elif ! scheduler_path_is_safe "${SCHEDULER_HOLD}"; then
        failed=1
    fi

    run_as_app_bounded 1m php artisan schedule:interrupt --quiet || failed=1
    for attempt in $(seq 1 30); do
        if scheduler_process_is_absent; then
            break
        fi
        if (( attempt == 30 )); then
            failed=1
            break
        fi
        sleep 1
    done
    run_as_app_bounded 2m php artisan down --retry=15 --quiet || failed=1
    writer_fence_is_intact || failed=1

    if (( failed != 0 )); then
        printf 'CRITICAL: failed production resume could not be returned to a verified writer fence.\n' >&2
        return 1
    fi

    printf 'CRITICAL: production resume failed; production remains in maintenance with writers fenced.\n' >&2
    return 0
}

resume_production() {
    local failed=0
    local services_ready=true

    [[ "$(timeout 10s systemctl show --property=LoadState --value \
        "${php_fpm_service}" 2>/dev/null || true)" == loaded ]] \
        || return 1
    [[ "$(timeout 10s systemctl show --property=LoadState --value \
        "${QUEUE_SERVICE}" 2>/dev/null || true)" == loaded ]] \
        || return 1
    [[ -n "${cron_service}" ]] || return 1
    if [[ -e "${SCHEDULER_FILE}" || -L "${SCHEDULER_FILE}" ]]; then
        scheduler_path_is_safe "${SCHEDULER_FILE}" \
            && [[ ! -e "${SCHEDULER_HOLD}" && ! -L "${SCHEDULER_HOLD}" ]] \
            || return 1
    else
        scheduler_path_is_safe "${SCHEDULER_HOLD}" || return 1
    fi

    # Never reopen any writer boundary unless every fence owned by this run was
    # removed and systemd proved that the drop-ins are no longer loaded.
    if ! remove_runtime_writer_fence; then
        reclose_writer_boundary || true
        return 1
    fi

    timeout 30s systemctl start "${php_fpm_service}" >/dev/null 2>&1 || failed=1
    wait_for_service "${php_fpm_service}" "${php_fpm_socket}" \
        || { failed=1; services_ready=false; }
    timeout 30s systemctl start "${QUEUE_SERVICE}" >/dev/null 2>&1 || failed=1
    wait_for_service "${QUEUE_SERVICE}" \
        || { failed=1; services_ready=false; }

    if [[ -n "${cron_service}" ]]; then
        timeout 30s systemctl start "${cron_service}" >/dev/null 2>&1 || failed=1
        timeout 10s systemctl is-active --quiet "${cron_service}" \
            || { failed=1; services_ready=false; }
    else
        failed=1
        services_ready=false
    fi

    if [[ "${services_ready}" == true ]]; then
        if [[ -e "${SCHEDULER_FILE}" || -L "${SCHEDULER_FILE}" ]]; then
            scheduler_path_is_safe "${SCHEDULER_FILE}" || failed=1
            [[ ! -e "${SCHEDULER_HOLD}" && ! -L "${SCHEDULER_HOLD}" ]] || failed=1
        elif scheduler_path_is_safe "${SCHEDULER_HOLD}"; then
            mv -- "${SCHEDULER_HOLD}" "${SCHEDULER_FILE}" || failed=1
        else
            failed=1
        fi
    fi

    service_is_running "${php_fpm_service}" || failed=1
    [[ -S "${php_fpm_socket}" ]] || failed=1
    service_is_running "${QUEUE_SERVICE}" || failed=1
    [[ -n "${cron_service}" ]] \
        && timeout 10s systemctl is-active --quiet "${cron_service}" || failed=1
    scheduler_path_is_safe "${SCHEDULER_FILE}" || failed=1
    [[ ! -e "${SCHEDULER_HOLD}" && ! -L "${SCHEDULER_HOLD}" ]] || failed=1

    if (( failed == 0 )); then
        if run_as_app_bounded 2m php artisan up --quiet; then
            quiesce_started=false
            return 0
        fi
    fi

    reclose_writer_boundary || true
    return 1
}

quiesce_production() {
    local attempt

    scheduler_path_is_safe "${SCHEDULER_FILE}" \
        || fail "production scheduler file is missing or unsafe"
    [[ ! -e "${SCHEDULER_HOLD}" && ! -L "${SCHEDULER_HOLD}" ]] \
        || fail "a previous backup scheduler hold exists"
    service_is_running "${QUEUE_SERVICE}" \
        || fail "production queue is not active with a running main process"
    detect_php_fpm_service
    if timeout 10s systemctl is-active --quiet cron.service; then
        cron_service=cron.service
    elif timeout 10s systemctl is-active --quiet crond.service; then
        cron_service=crond.service
    else
        fail "production cron service is not active"
    fi
    release_php_fpm_fence_file="/etc/systemd/system/${php_fpm_service}.d/${RELEASE_WRITER_FENCE_NAME}"
    release_cron_fence_file="/etc/systemd/system/${cron_service}.d/${RELEASE_WRITER_FENCE_NAME}"
    if [[ "${release_handoff_requested}" == true ]]; then
        assert_release_handoff_prearmed
    fi

    runtime_writer_fence_is_clear \
        || fail "production already has a stale or loaded runtime writer fence"
    quiesce_started=true
    run_as_app_bounded 2m php artisan down --retry=15 --quiet
    install_runtime_writer_fence
    hard_stop_service "${php_fpm_service}" || fail "PHP-FPM could not be hard-fenced"
    hard_stop_service "${QUEUE_SERVICE}" || fail "production queue could not be stopped"
    hard_stop_service "${cron_service}" || fail "production cron could not be stopped"
    mv -- "${SCHEDULER_FILE}" "${SCHEDULER_HOLD}"
    run_as_app_bounded 1m php artisan schedule:interrupt --quiet
    for attempt in $(seq 1 30); do
        if scheduler_process_is_absent; then
            break
        fi
        (( attempt < 30 )) || fail "scheduler is still active after 30 seconds"
        sleep 1
    done
    assert_writer_fence
}

assert_snapshot_capacity() {
    local storage_bytes
    local database_bytes
    local available
    local total
    local reserve=$((10 * 1024 * 1024 * 1024))
    local required

    storage_bytes="$(timeout --signal=TERM --kill-after=30s \
        "${snapshot_capacity_duration}" du --summarize --bytes \
        "${APP_PATH}/storage/app/private" "${APP_PATH}/storage/app/public" \
        | awk '{total += $1} END {print total + 0}')"
    database_bytes="$(run_as_app_bounded 2m php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $row = Illuminate\Support\Facades\DB::selectOne(
            "SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE()"
        );
        echo (int) ($row->bytes ?? 0);
    ')"
    [[ "${storage_bytes}" =~ ^[0-9]+$ && "${database_bytes}" =~ ^[0-9]+$ ]] \
        || fail "snapshot size could not be determined"
    available="$(df --output=avail -B1 "${STATE_PATH}" | awk 'NR == 2 {print $1}')"
    total="$(df --output=size -B1 "${STATE_PATH}" | awk 'NR == 2 {print $1}')"
    [[ "${available}" =~ ^[0-9]+$ && "${total}" =~ ^[0-9]+$ ]] \
        || fail "snapshot filesystem capacity could not be determined"
    (( total / 5 > reserve )) && reserve=$((total / 5))
    required=$((storage_bytes + database_bytes * 2 + reserve))
    (( available > required )) \
        || fail "insufficient disk reserve for an application-consistent local snapshot"
}

assert_storage_path_safe() {
    local storage_path="$1"
    local mount_targets
    local mount_target
    local unsafe_node

    [[ -d "${storage_path}" && ! -L "${storage_path}" ]] \
        || fail "required storage directory is missing or unsafe: ${storage_path}"
    if ! unsafe_node="$(timeout --signal=TERM --kill-after=30s \
        "${storage_scan_duration}" \
        find "${storage_path}" -xdev \
        ! -type d ! -type f -print -quit)"; then
        fail "storage tree could not be inspected safely: ${storage_path}"
    fi
    [[ -z "${unsafe_node}" ]] \
        || fail "storage snapshot refuses links or special nodes: ${unsafe_node}"
    if ! mount_targets="$(timeout --signal=TERM --kill-after=10s 30s \
        findmnt --list --noheadings --raw --output TARGET)"; then
        fail "mounted filesystems could not be inspected safely"
    fi
    while IFS= read -r mount_target; do
        [[ -n "${mount_target}" ]] || continue
        if [[ "${mount_target}" == "${storage_path}/"* ]]; then
            fail "storage snapshot refuses nested mounts: ${mount_target}"
        fi
    done <<< "${mount_targets}"
}

assert_storage_sources_safe() {
    assert_storage_path_safe "${private_storage}"
    assert_storage_path_safe "${public_storage}"
}

mount_read_only_storage_pin() {
    local source="$1"
    local target="$2"
    local label="$3"
    local source_identity
    local target_identity
    local mount_record
    local mount_target
    local mount_options

    source_identity="$(stat -c '%d:%i' -- "${source}")" \
        || fail "${label} storage identity could not be captured"
    [[ "${source_identity}" =~ ^[0-9]+:[0-9]+$ ]] \
        || fail "${label} storage identity is invalid"
    install -d -o root -g root -m 0700 "${target}"
    mount --bind "${source}" "${target}"
    if [[ "${label}" == private ]]; then
        private_storage_pin_mounted=true
    else
        public_storage_pin_mounted=true
    fi
    mount -o remount,bind,ro,nodev,nosuid,noexec "${target}"
    target_identity="$(stat -c '%d:%i' -- "${target}")" \
        || fail "${label} pinned storage identity could not be read"
    [[ "${target_identity}" == "${source_identity}" ]] \
        || fail "${label} storage changed while its snapshot source was pinned"
    mount_record="$(findmnt --noheadings --raw --mountpoint "${target}" \
        --output TARGET,OPTIONS)" \
        || fail "${label} storage pin is not a verified mountpoint"
    read -r mount_target mount_options <<< "${mount_record}"
    [[ "${mount_target}" == "${target}" && ",${mount_options}," == *,ro,* ]] \
        || fail "${label} storage pin is not read-only"
    assert_storage_path_safe "${target}"
}

pin_storage_sources() {
    local pin_root="${work_dir}/storage-pins"

    [[ -n "${work_dir}" && -d "${work_dir}" && ! -L "${work_dir}" ]] \
        || fail "storage pin workspace is unavailable"
    install -d -o root -g root -m 0700 "${pin_root}"
    private_storage_pin="${pin_root}/private"
    public_storage_pin="${pin_root}/public"
    mount_read_only_storage_pin "${private_storage}" "${private_storage_pin}" private
    mount_read_only_storage_pin "${public_storage}" "${public_storage_pin}" public
}

release_storage_pins() {
    local failed=0
    local status

    if [[ "${public_storage_pin_mounted}" == true ]]; then
        timeout 30s umount "${public_storage_pin}" || failed=1
        if mountpoint -q "${public_storage_pin}"; then
            failed=1
        else
            status=$?
            if (( status == 32 )); then
                public_storage_pin_mounted=false
            else
                failed=1
            fi
        fi
    fi
    if [[ "${private_storage_pin_mounted}" == true ]]; then
        timeout 30s umount "${private_storage_pin}" || failed=1
        if mountpoint -q "${private_storage_pin}"; then
            failed=1
        else
            status=$?
            if (( status == 32 )); then
                private_storage_pin_mounted=false
            else
                failed=1
            fi
        fi
    fi
    (( failed == 0 ))
}

sync_storage_snapshot() {
    local source="$1"
    local destination="$2"
    local allow_vanished="${3:-false}"
    local duration="${4:-1h}"
    local status

    set +e
    timeout --signal=TERM --kill-after=5m "${duration}" \
        rsync -aH --delete --numeric-ids --one-file-system \
            "${source}/" "${destination}/"
    status=$?
    set -e
    if (( status == 0 )) || [[ "${allow_vanished}" == true && "${status}" -eq 24 ]]; then
        return 0
    fi
    return "${status}"
}

production_database_identity() {
    run_as_app_bounded 2m php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $connection = Illuminate\Support\Facades\DB::connection();
        if ($connection->getDriverName() !== "mysql") {
            throw new RuntimeException("MySQL connection required");
        }
        $database = (string) $connection->getDatabaseName();
        $row = $connection->selectOne("SELECT LOWER(@@server_uuid) AS uuid");
        $uuid = (string) ($row->uuid ?? "");
        if (preg_match("/^[A-Za-z0-9_]+$/", $database) !== 1
            || preg_match("/^[0-9a-f-]{36}$/", $uuid) !== 1) {
            throw new RuntimeException("Invalid production database identity");
        }
        echo $database."|".$uuid;
    '
}

export_recovery_app_key() {
    local output_path="$1"

    run_as_app_bounded 2m php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $configured = (string) config("app.key");
        $cipher = (string) config("app.cipher");
        $key = str_starts_with($configured, "base64:")
            ? base64_decode(substr($configured, 7), true)
            : $configured;
        if ($key === false
            || ! Illuminate\Encryption\Encrypter::supported($key, $cipher)
            || str_contains($configured, "\n")
            || str_contains($configured, "\r")) {
            throw new RuntimeException("Application encryption key is invalid");
        }
        fwrite(STDOUT, $configured."\n");
    ' > "${output_path}"
    chown root:root "${output_path}"
    chmod 0600 "${output_path}"
    [[ -f "${output_path}" && ! -L "${output_path}" && -s "${output_path}" ]] \
        || fail "application recovery key export is missing"
    [[ "$(stat -c '%U:%G:%a' "${output_path}")" == root:root:600 ]] \
        || fail "application recovery key export permissions are unsafe"
    [[ "$(wc -l < "${output_path}" | tr -d ' ')" == 1 ]] \
        || fail "application recovery key export must contain one line"
    (( $(stat -c %s "${output_path}") <= 512 )) \
        || fail "application recovery key export is unexpectedly large"
}

assert_backup_mysql_account() {
    local database="$1"
    local expected_uuid="$2"
    local backup_uuid
    local grants

    backup_uuid="$(timeout --signal=TERM --kill-after=10s 30s \
        mysql --defaults-extra-file="${MYSQL_CONFIG_FILE}" \
        --batch --skip-column-names -e 'SELECT LOWER(@@server_uuid)')"
    [[ "${backup_uuid}" == "${expected_uuid}" ]] \
        || fail "backup MySQL credentials do not target the production server"

    grants="$(timeout --signal=TERM --kill-after=10s 30s \
        mysql --defaults-extra-file="${MYSQL_CONFIG_FILE}" \
        --batch --skip-column-names -e 'SHOW GRANTS FOR CURRENT_USER()')"
    if ! awk -v database="${database}" '
        BEGIN {
            db_scope = toupper(database) ".*"
        }
        {
            line = toupper($0)
            if (line !~ /^GRANT / || line !~ / ON / || line ~ / WITH GRANT OPTION/) {
                invalid = 1
                next
            }
            privileges = line
            sub(/^GRANT /, "", privileges)
            sub(/ ON .*/, "", privileges)
            scope = line
            sub(/^.* ON /, "", scope)
            sub(/ TO .*/, "", scope)
            gsub(/`/, "", scope)
            count = split(privileges, entries, /, */)
            for (item = 1; item <= count; item++) {
                privilege = entries[item]
                if (scope == "*.*") {
                    if (privilege == "USAGE") {
                        continue
                    }
                    if (privilege == "SHOW_ROUTINE") {
                        has_show_routine = 1
                        continue
                    }
                    invalid = 1
                } else if (scope == db_scope) {
                    if (privilege == "SELECT") has_select = 1
                    else if (privilege == "SHOW VIEW") has_show_view = 1
                    else if (privilege == "TRIGGER") has_trigger = 1
                    else if (privilege == "EVENT") has_event = 1
                    else invalid = 1
                } else {
                    invalid = 1
                }
            }
        }
        END {
            exit (! invalid && has_show_routine && has_select && has_show_view \
                && has_trigger && has_event) ? 0 : 1
        }
    ' <<< "${grants}"; then
        fail "backup MySQL account grants differ from the required least-privilege allowlist"
    fi
}

assert_database_event_scheduler_disabled() {
    if ! mysql_event_scheduler_state="$(timeout --signal=TERM --kill-after=10s 30s \
        mysql --defaults-extra-file="${MYSQL_CONFIG_FILE}" \
        --batch --skip-column-names \
        -e 'SELECT LOWER(@@GLOBAL.event_scheduler)')"; then
        fail "global MySQL event scheduler state could not be read"
    fi
    case "${mysql_event_scheduler_state}" in
        off|disabled) ;;
        *) fail "global MySQL event scheduler must remain OFF or DISABLED" ;;
    esac
}

export_database() {
    local output_path="$1"
    local database="$2"

    timeout --signal=TERM --kill-after=5m "${database_export_duration}" \
        mysqldump --defaults-extra-file="${MYSQL_CONFIG_FILE}" \
            --single-transaction --quick --skip-lock-tables \
            --routines --triggers --events --hex-blob --no-tablespaces \
            --set-gtid-purged=OFF --default-character-set=utf8mb4 \
            -- "${database}" > "${output_path}"
}

run_bounded_twice() {
    local duration="$1"
    shift
    local attempt

    for attempt in 1 2; do
        if timeout --signal=TERM --kill-after=5m "${duration}" "$@"; then
            return 0
        fi
        (( attempt < 2 )) || break
        sleep 30
    done
    return 1
}

run_restic_backup_and_capture_snapshot() {
    local attempt
    local backup_host
    local output_tmp
    local -a backup_args=()

    backup_host="$(hostname -f 2>/dev/null || hostname)"
    restic_json="${work_dir}/restic-backup.json"
    output_tmp="${work_dir}/.restic-backup.json.$$"
    backup_args=(
        backup --json
        --host "${backup_host}"
        --tag lora-production
        --tag database-and-storage
    )
    if [[ "${release_handoff_requested}" == true ]]; then
        backup_args+=(--tag release-handoff)
    fi
    backup_args+=("${run_dir}")

    for attempt in 1 2; do
        rm -f -- "${output_tmp}"
        if timeout --signal=TERM --kill-after=5m "${restic_backup_duration}" \
            restic "${backup_args[@]}" > "${output_tmp}"; then
            mv --no-target-directory "${output_tmp}" "${restic_json}"
            break
        fi
        (( attempt < 2 )) || return 1
        sleep 30
    done

    snapshot_id="$(php -r '
        $snapshotId = "";
        $lines = file($argv[1], FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            exit(1);
        }
        foreach ($lines as $line) {
            $event = json_decode($line, true);
            if (is_array($event) && isset($event["snapshot_id"])) {
                $snapshotId = (string) $event["snapshot_id"];
            }
        }
        if (preg_match("/^[0-9a-f]{64}$/", $snapshotId) !== 1) {
            exit(1);
        }
        echo $snapshotId;
    ' "${restic_json}")" \
        || return 1
    [[ "${snapshot_id}" =~ ^[0-9a-f]{64}$ ]]
}

[[ "${EUID}" -eq 0 ]] || fail "must run as root"
[[ "${QUEUE_SERVICE}" =~ ^[A-Za-z0-9_.@:-]+[.]service$ ]] \
    || fail "queue service name is invalid"
require_root_only_file "${CONFIG_FILE}" "config"
require_root_only_file "${MYSQL_CONFIG_FILE}" "backup MySQL config"

# shellcheck source=/dev/null
set -a
source "${CONFIG_FILE}"
set +a

: "${RESTIC_REPOSITORY:?RESTIC_REPOSITORY is required}"
: "${RESTIC_PASSWORD_FILE:?RESTIC_PASSWORD_FILE is required}"
: "${AWS_ACCESS_KEY_ID:?AWS_ACCESS_KEY_ID is required}"
: "${AWS_SECRET_ACCESS_KEY:?AWS_SECRET_ACCESS_KEY is required}"

[[ -d "${APP_PATH}" ]] || fail "application path is missing: ${APP_PATH}"
id "${APP_USER}" >/dev/null 2>&1 || fail "application user does not exist: ${APP_USER}"
require_root_only_file "${RESTIC_PASSWORD_FILE}" "Restic password file"

for binary in \
    awk bash chmod chown date df du env find findmnt flock git grep hostname id install mktemp mv mysql \
    mount mountpoint mysqldump pgrep php restic rm rmdir rsync runuser seq sha256sum sleep sort ss stat sync systemctl timeout tr umount wc xargs; do
    command -v "${binary}" >/dev/null 2>&1 || fail "required binary is missing: ${binary}"
done
app_group="$(id -gn "${APP_USER}")"
[[ -n "${app_group}" ]] || fail "application primary group could not be determined"
assert_repository_security

[[ ! -L "${STATE_PATH}" ]] || fail "backup state path must not be a symbolic link"
install -d -o root -g root -m 0700 "${STATE_PATH}"
[[ "$(stat -c '%U:%G:%a' "${STATE_PATH}")" == root:root:700 ]] \
    || fail "backup state path must be root:root 0700"
[[ ! -L "${RESTIC_CACHE_PATH}" ]] || fail "Restic cache path must not be a symbolic link"
install -d -o root -g root -m 0700 "${RESTIC_CACHE_PATH}"
[[ "$(stat -c '%U:%G:%a' "${RESTIC_CACHE_PATH}")" == root:root:700 ]] \
    || fail "Restic cache path must be root:root 0700"
export RESTIC_CACHE_DIR="${RESTIC_CACHE_PATH}"

exec 9>"${STATE_PATH}/backup.lock"
flock -n 9 || fail "another backup is already running"
if [[ -e "${RELEASE_HANDOFF_REQUEST}" || -L "${RELEASE_HANDOFF_REQUEST}" ]]; then
    root_0600_file_is_safe "${RELEASE_LOCK_FILE}" \
        || fail "production release lock file must be root:root 0600"
    exec 8<>"${RELEASE_LOCK_FILE}"
fi
detect_release_handoff_request

run_dir="$(mktemp -d "${STATE_PATH}/run.XXXXXXXX")"
cleanup() {
    local status=$?

    trap - EXIT HUP INT TERM
    set +e
    cd "${APP_PATH}" 2>/dev/null || true
    if [[ "${release_handoff_requested}" == true \
        && "${release_handoff_committed}" != true ]]; then
        if [[ -n "${release_handoff_ready_tmp}" ]]; then
            rm -f -- "${release_handoff_ready_tmp}" || status=1
        fi
        if root_0600_file_is_safe "${RELEASE_HANDOFF_READY}" \
            && grep -Fqx "nonce=${release_handoff_nonce}" "${RELEASE_HANDOFF_READY}"; then
            rm -f -- "${RELEASE_HANDOFF_READY}" || status=1
        fi
        if ! rollback_release_handoff_activation; then
            printf 'CRITICAL: uncommitted release handoff sentinel could not be reopened.\n' >&2
            status=1
        fi
    fi
    if [[ "${quiesce_started}" == true ]]; then
        if ! release_storage_pins; then
            printf 'CRITICAL: production stayed in maintenance because read-only storage pins could not be released.\n' >&2
            status=1
        elif ! resume_production; then
            printf 'CRITICAL: production stayed in maintenance because writer recovery was incomplete.\n' >&2
            status=1
        fi
    fi
    if (( ${#sensitive_paths[@]} > 0 )); then
        rm -f -- "${sensitive_paths[@]}" || status=1
    fi
    if [[ -n "${work_dir}" ]]; then
        rm -rf -- "${work_dir}" || status=1
    fi
    if [[ -n "${last_success_tmp}" ]]; then
        rm -f -- "${last_success_tmp}" || status=1
    fi
    rm -rf -- "${run_dir}" || status=1
    exit "${status}"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

cd "${APP_PATH}"

private_storage="${APP_PATH}/storage/app/private"
public_storage="${APP_PATH}/storage/app/public"
assert_storage_sources_safe
assert_snapshot_capacity
install -d -o root -g root -m 0700 \
    "${run_dir}/storage/app/private" "${run_dir}/storage/app/public"
sync_storage_snapshot "${private_storage}" "${run_dir}/storage/app/private" \
    true "${initial_storage_sync_duration}"
sync_storage_snapshot "${public_storage}" "${run_dir}/storage/app/public" \
    true "${initial_storage_sync_duration}"

work_dir="$(mktemp -d /run/lora-backup-work.XXXXXXXX)"
chmod 0711 "${work_dir}"
integrity_dir="${work_dir}/app"
install -d -o "${APP_USER}" -g "${app_group}" -m 0700 "${integrity_dir}"
[[ "$(stat -c '%U:%G:%a' "${integrity_dir}")" == "${APP_USER}:${app_group}:700" ]] \
    || fail "integrity work directory permissions are unsafe"
integrity_work="${integrity_dir}/integrity.json"
install -o "${APP_USER}" -g "${app_group}" -m 0600 /dev/null "${integrity_work}"

if ! artisan_commands="$(run_as_app_bounded 2m php artisan list --raw)"; then
    fail "artisan command inventory could not be read"
fi
grep -q '^tenants:verify-integrity ' <<< "${artisan_commands}" \
    || fail "required tenants:verify-integrity command is unavailable"
integrity_options=(--snapshot="${integrity_work}")
storage_verification=bootstrap-unavailable
if ! integrity_help="$(run_as_app_bounded 2m php artisan help tenants:verify-integrity --no-ansi)"; then
    fail "tenants:verify-integrity help could not be read"
fi
if grep -q -- '--verify-storage' <<< "${integrity_help}"; then
    integrity_options+=(--verify-storage)
    storage_verification=required
else
    if ! release_control_plane="$(git ls-tree --name-only HEAD -- \
        .github/workflows/production-release-rehearsal.yml)"; then
        fail "production release control-plane state could not be read"
    fi
    [[ -z "${release_control_plane}" ]] \
        || fail "post-bootstrap production requires tenants:verify-integrity --verify-storage"
fi

database_identity="$(production_database_identity)"
database="${database_identity%%|*}"
production_db_uuid="${database_identity#*|}"
[[ "${database}" =~ ^[A-Za-z0-9_]+$ && "${production_db_uuid}" =~ ^[0-9a-f-]{36}$ ]] \
    || fail "production database identity is invalid"
assert_backup_mysql_account "${database}" "${production_db_uuid}"
assert_database_event_scheduler_disabled

quiesce_production
assert_writer_fence
assert_storage_sources_safe
pin_storage_sources
assert_writer_fence
sync_storage_snapshot "${private_storage_pin}" "${run_dir}/storage/app/private" \
    false "${final_storage_sync_duration}"
sync_storage_snapshot "${public_storage_pin}" "${run_dir}/storage/app/public" \
    false "${final_storage_sync_duration}"
assert_storage_sources_safe
assert_storage_path_safe "${private_storage_pin}"
assert_storage_path_safe "${public_storage_pin}"
assert_storage_path_safe "${run_dir}/storage/app/private"
assert_storage_path_safe "${run_dir}/storage/app/public"
assert_writer_fence

assert_database_event_scheduler_disabled

run_as_app_bounded "${integrity_check_duration}" \
    php artisan tenants:verify-integrity "${integrity_options[@]}"
[[ -f "${integrity_work}" && ! -L "${integrity_work}" ]] \
    || fail "integrity snapshot output is not a regular file"
[[ "$(stat -c '%U:%G:%a' "${integrity_work}")" == "${APP_USER}:${app_group}:600" ]] \
    || fail "integrity snapshot output permissions are unsafe"
[[ -s "${integrity_work}" ]] || fail "integrity snapshot output is empty"
install -o root -g root -m 0600 "${integrity_work}" "${run_dir}/integrity.json"
export_recovery_app_key "${run_dir}/application-key.txt"
export_database "${run_dir}/database.sql" "${database}"
[[ -s "${run_dir}/database.sql" ]] || fail "database export is empty"
assert_database_event_scheduler_disabled
assert_writer_fence
snapshot_created_utc="$(date --utc +'%Y-%m-%dT%H:%M:%SZ')"

release_storage_pins || fail "read-only storage snapshot pins could not be released"
if [[ "${release_handoff_requested}" != true ]]; then
    resume_production || fail "production writers could not be restored after the snapshot"
fi

timeout --signal=TERM --kill-after=2m "${checksum_duration}" \
    bash -c '
        set -Eeuo pipefail
        cd "$1"
        find app -xdev -type f -print0 \
            | sort -z \
            | xargs -0 -r sha256sum > "$2"
    ' _ "${run_dir}/storage" "${run_dir}/storage-SHA256SUMS"

{
    printf 'created_utc=%s\n' "${snapshot_created_utc}"
    printf 'hostname=%s\n' "$(hostname -f 2>/dev/null || hostname)"
    printf 'git_commit=%s\n' "$(git rev-parse HEAD)"
    printf 'git_branch=%s\n' "$(git branch --show-current)"
    printf 'storage_verification=%s\n' "${storage_verification}"
    printf 'mysql_event_scheduler=%s\n' "${mysql_event_scheduler_state}"
    printf 'application_key_escrow=restic-encrypted\n'
} > "${run_dir}/metadata.txt"

checksum_files=(application-key.txt database.sql integrity.json metadata.txt storage-SHA256SUMS)
(
    cd "${run_dir}"
    sha256sum "${checksum_files[@]}" > SHA256SUMS
)

run_restic_backup_and_capture_snapshot \
    || fail "Restic backup did not return an exact snapshot ID"

# Metadata verification is quick enough for every run. Full data reads belong
# to the scheduled restore drill, not the production backup window.
run_bounded_twice "${restic_check_duration}" restic check
upload_completed_utc="$(date --utc +'%Y-%m-%dT%H:%M:%SZ')"

last_success_tmp="${STATE_PATH}/.last-success.$$"
printf '%s\n' "${upload_completed_utc}" > "${last_success_tmp}"
chown root:root "${last_success_tmp}"
chmod 0600 "${last_success_tmp}"
mv -- "${last_success_tmp}" "${STATE_PATH}/last-success"
last_success_tmp=''
sync "${STATE_PATH}/last-success"

if [[ "${release_handoff_requested}" == true ]]; then
    commit_release_handoff
fi
printf 'Lora offsite backup completed successfully as snapshot %s.\n' "${snapshot_id}"
