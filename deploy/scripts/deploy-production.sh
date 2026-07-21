#!/usr/bin/env bash

# Root-only production release program. The workflow extracts this file from
# the exact tested main commit and verifies its pinned SHA256 before execution.

set -Eeuo pipefail

app_dir=/var/www/villamucho
app_user=www-data
server_lock=/var/lib/lora-backup/production-release.lock
queue_service=villamucho-queue.service
cron_service=
writer_fence_sentinel=/var/lib/lora-backup/release-writers-enabled
writer_fence_sentinel_hold=/var/lib/lora-backup/release-writers-enabled.handoff
writer_fence_name=lora-release-writer-fence.conf
scheduler_file=/etc/cron.d/villamucho-scheduler
scheduler_hold=/var/lib/lora-backup/villamucho-scheduler.deploy-paused
backup_scheduler_hold=/var/lib/lora-backup/villamucho-scheduler.backup-paused
backup_handoff_request=/var/lib/lora-backup/release-handoff.request
backup_handoff_ready=/var/lib/lora-backup/release-handoff.ready
rehearsal_dir=/var/lib/lora-backup/rehearsals
rehearsal_mysql_image='mysql:8.0.46@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b'
rehearsal_app_image='serversideup/php:8.4-cli@sha256:7b669c4fbb70ca392cdbfa61b0aee6f95530445a67f2a814c0692c992971de2c'
backup_success_file=/var/lib/lora-backup/last-success
backup_script=/usr/local/sbin/lora-offsite-backup
backup_service=/etc/systemd/system/lora-backup.service
backup_script_sha='a15ddb3014899d9982b2d6c1e7462b7f8f51b562dbc87e8213062ae876b5b5f0'
backup_service_sha='324ac6ee746ec39aa5d97e9e71381cad62be001bc77fce25130a0f6200435682'
passport_key_dir=/etc/lora-passport
passport_private_key="$passport_key_dir/oauth-private.key"
passport_public_key="$passport_key_dir/oauth-public.key"
passport_private_uri="file://$passport_private_key"
passport_public_uri="file://$passport_public_key"
state_dir=/var/lib/lora-backup/releases
release_dir=/var/lib/lora-release
release_file="$release_dir/current"
release_artifact_root="$release_dir/artifacts"
control_panel_script_sha='9c5e065918a9f04761ff0fa36fd76ab8af0e543adbb41ff4e2df3f66179c80b4'
control_panel_template_sha='ab966d6f9f7402b5fa8c2446de4996e50558682bde03ff7532457205ac204999'
fastcgi_buffers_sha='0b3156320c5e3076ec300d6abaad7005650ebfae0cce835df1c7ac167c1775dd'

fail() {
  echo "Production deploy refused: $*" >&2
  exit 1
}

validate_sha() {
  local sha="$1"
  local label="$2"

  case "$sha" in
    ''|*[!0-9a-f]*) fail "$label is not a lowercase hexadecimal commit SHA." ;;
  esac
  [ "${#sha}" -eq 40 ] || fail "$label must contain exactly 40 characters."
}

run_as_app() {
  runuser --user "$app_user" -- \
    env -i \
      HOME="$app_dir/storage/framework" \
      PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
      "$@"
}

assert_root_0600() {
  local path="$1"
  local label="$2"

  [ -f "$path" ] || fail "$label is missing: $path"
  [ ! -L "$path" ] || fail "$label must not be a symbolic link: $path"
  [ "$(stat -c '%U:%G:%a' -- "$path")" = "root:root:600" ] \
    || fail "$label must be owned by root:root with mode 0600."
}

assert_fresh_timestamp() {
  local value="$1"
  local maximum_age="$2"
  local label="$3"
  local timestamp_epoch
  local now_epoch
  local age

  if ! printf '%s\n' "$value" \
    | grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$'; then
    fail "$label is not an exact UTC RFC3339 timestamp."
  fi
  if ! timestamp_epoch="$(date --date="$value" +%s)"; then
    fail "$label could not be parsed."
  fi
  now_epoch="$(date --utc +%s)"
  age=$((now_epoch - timestamp_epoch))
  if [ "$age" -lt 0 ] || [ "$age" -gt "$maximum_age" ]; then
    fail "$label age is ${age}s; required range is 0-${maximum_age}s."
  fi
}

assert_no_pending_migrations() {
  run_as_app php artisan tinker --execute='
    $migrator = app("migrator");
    if (! $migrator->repositoryExists()) {
        throw new RuntimeException("The migration repository does not exist.");
    }
    $files = $migrator->getMigrationFiles(database_path("migrations"));
    $pending = array_values(array_diff(
        array_keys($files),
        $migrator->getRepository()->getRan(),
    ));
    if ($pending !== []) {
        throw new RuntimeException("Pending migrations: ".implode(", ", $pending));
    }
  '
}

assert_production_database_privileges() {
  run_as_app php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $database = (string) Illuminate\Support\Facades\DB::connection()->getDatabaseName();
    $required = ["SELECT", "INSERT", "UPDATE", "DELETE", "CREATE", "ALTER", "DROP", "INDEX", "REFERENCES", "TRIGGER"];
    $effective = [];
    $global = [];
    foreach (Illuminate\Support\Facades\DB::select("SHOW GRANTS FOR CURRENT_USER") as $row) {
        $grant = (string) array_values((array) $row)[0];
        if (preg_match("/^GRANT (.+) ON (.+) TO /i", $grant, $parts) !== 1) {
            continue;
        }
        $scope = str_replace("`", "", trim($parts[2]));
        if ($scope !== "*.*" && strcasecmp($scope, $database.".*") !== 0) {
            continue;
        }
        foreach (explode(",", strtoupper($parts[1])) as $privilege) {
            $effective[trim($privilege)] = true;
            if ($scope === "*.*") {
                $global[trim($privilege)] = true;
            }
        }
    }
    if (isset($effective["ALL PRIVILEGES"]) || isset($effective["ALL"])) {
        $missing = [];
    } else {
        $missing = array_values(array_filter($required, fn ($privilege) => ! isset($effective[$privilege])));
    }
    if ($missing !== []) {
        throw new RuntimeException("Production DB account lacks schema privileges: ".implode(", ", $missing));
    }
    if ((int) Illuminate\Support\Facades\DB::selectOne("SELECT @@global.log_bin AS enabled")->enabled === 1
        && (int) Illuminate\Support\Facades\DB::selectOne("SELECT @@global.log_bin_trust_function_creators AS trusted")->trusted === 0
        && ! isset($global["SUPER"])
        && ! isset($global["ALL PRIVILEGES"])
        && ! isset($global["ALL"])) {
        throw new RuntimeException("Binary logging policy may reject CREATE TRIGGER.");
    }
  '
}

production_database_fingerprint() {
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
        ."@@global.log_bin AS log_bin, "
        ."@@global.log_bin_trust_function_creators AS log_bin_trust_function_creators, "
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
    printf("log_bin=%d\n", (int) $row->log_bin);
    printf("log_bin_trust_function_creators=%d\n", (int) $row->log_bin_trust_function_creators);
    printf("character_set_connection=%s\n", (string) $row->character_set_connection);
    printf("collation_connection=%s\n", (string) $row->collation_connection);
  '
}

assert_production_config() {
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
}

assert_app_filesystem_security() {
  [ -f "$app_dir/.env" ] && [ ! -L "$app_dir/.env" ] \
    || fail "production .env must be a regular file."
  [ "$(stat -c '%U:%G:%a' "$app_dir/.env")" = 'root:www-data:640' ] \
    || fail "production .env must be root:www-data 0640."
  [ "$(stat -c '%U:%G:%a' "$app_dir")" = 'root:root:755' ] \
    || fail "production application root must be root:root 0755."
}

candidate_requires_passport() {
  local candidate_sha="$1"
  local state

  state="$(
    git show "${candidate_sha}:composer.lock" \
      | php -r '
        $lock = json_decode(
            stream_get_contents(STDIN),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $packages = array_merge(
            $lock["packages"] ?? [],
            $lock["packages-dev"] ?? [],
        );
        foreach ($packages as $package) {
            if (($package["name"] ?? null) === "laravel/passport") {
                echo "required";
                exit(0);
            }
        }
        echo "not-required";
      '
  )" || fail "candidate Passport dependency state could not be verified."
  case "$state" in
    required) return 0 ;;
    not-required) return 1 ;;
    *) fail "candidate Passport dependency state is invalid." ;;
  esac
}

passport_pair_fingerprint() {
  local private_fingerprint
  local public_fingerprint

  [ -f "$passport_private_key" ] && [ ! -L "$passport_private_key" ] \
    || fail "Passport private key must be a regular non-symlink file."
  [ -f "$passport_public_key" ] && [ ! -L "$passport_public_key" ] \
    || fail "Passport public key must be a regular non-symlink file."
  [ "$(stat -c %s "$passport_private_key")" -ge 512 ] \
    && [ "$(stat -c %s "$passport_private_key")" -le 32768 ] \
    || fail "Passport private key size is outside the accepted range."
  [ "$(stat -c %s "$passport_public_key")" -ge 256 ] \
    && [ "$(stat -c %s "$passport_public_key")" -le 32768 ] \
    || fail "Passport public key size is outside the accepted range."
  openssl pkey -in "$passport_private_key" -check -noout >/dev/null 2>&1 \
    || fail "Passport private key is invalid."
  openssl pkey -pubin -in "$passport_public_key" -noout >/dev/null 2>&1 \
    || fail "Passport public key is invalid."
  private_fingerprint="$(
    openssl pkey -in "$passport_private_key" -pubout -outform DER 2>/dev/null \
      | sha256sum | awk '{print $1}'
  )" || fail "Passport private-key fingerprint could not be calculated."
  public_fingerprint="$(
    openssl pkey -pubin -in "$passport_public_key" \
      -pubout -outform DER 2>/dev/null \
      | sha256sum | awk '{print $1}'
  )" || fail "Passport public-key fingerprint could not be calculated."
  [[ "$private_fingerprint" =~ ^[0-9a-f]{64}$ ]] \
    && [ "$private_fingerprint" = "$public_fingerprint" ] \
    || fail "Passport private/public keys do not form the same key pair."
  printf '%s\n' "$public_fingerprint"
}

assert_live_passport_key_pair() {
  local fingerprint

  [ -d "$passport_key_dir" ] && [ ! -L "$passport_key_dir" ] \
    || fail "Passport key directory is missing or unsafe."
  [ "$(stat -c '%U:%G:%a' "$passport_key_dir")" = 'root:www-data:750' ] \
    || fail "Passport key directory must be root:www-data 0750."
  [ "$(stat -c '%U:%G:%a' "$passport_private_key")" = 'root:www-data:440' ] \
    || fail "Passport private key must be root:www-data 0440."
  [ "$(stat -c '%U:%G:%a' "$passport_public_key")" = 'root:www-data:440' ] \
    || fail "Passport public key must be root:www-data 0440."
  fingerprint="$(passport_pair_fingerprint)" \
    || fail "Passport key-pair fingerprint could not be verified."
  [[ "$fingerprint" =~ ^[0-9a-f]{64}$ ]] \
    || fail "Passport key-pair fingerprint is invalid."
  printf '%s\n' "$fingerprint"
}

assert_live_passport_environment() {
  local private_count
  local public_count

  [ -f "$app_dir/.env" ] && [ ! -L "$app_dir/.env" ] \
    || fail "production .env is missing or unsafe."
  [ "$(stat -c '%U:%G:%a' "$app_dir/.env")" = 'root:www-data:640' ] \
    || fail "production .env must be root:www-data 0640."
  private_count="$(grep -c '^PASSPORT_PRIVATE_KEY=' "$app_dir/.env" || true)"
  public_count="$(grep -c '^PASSPORT_PUBLIC_KEY=' "$app_dir/.env" || true)"
  [ "$private_count" = 1 ] && [ "$public_count" = 1 ] \
    || fail "production .env must define each Passport key URI exactly once."
  grep -Fqx "PASSPORT_PRIVATE_KEY=$passport_private_uri" "$app_dir/.env" \
    || fail "production Passport private-key URI is not pinned."
  grep -Fqx "PASSPORT_PUBLIC_KEY=$passport_public_uri" "$app_dir/.env" \
    || fail "production Passport public-key URI is not pinned."
}

assert_candidate_passport_runtime_configuration() {
  run_as_app env \
    EXPECTED_PASSPORT_PRIVATE_URI="$passport_private_uri" \
    EXPECTED_PASSPORT_PUBLIC_URI="$passport_public_uri" \
    php -r '
      require "vendor/autoload.php";
      $app = require "bootstrap/app.php";
      $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
      if (config("passport.private_key") !== getenv("EXPECTED_PASSPORT_PRIVATE_URI")
          || config("passport.public_key") !== getenv("EXPECTED_PASSPORT_PUBLIC_URI")) {
          throw new RuntimeException(
              "Production Passport key URIs are not pinned to /etc/lora-passport",
          );
      }
    '
}

assert_passport_fingerprint_matches_marker() {
  local fingerprint

  [ "$candidate_passport_required" = true ] || return 0
  assert_live_passport_environment
  fingerprint="$(assert_live_passport_key_pair)" \
    || fail "live Passport key pair could not be verified."
  [ "$fingerprint" = "$rehearsal_passport_public_fingerprint" ] \
    || fail "live Passport key pair differs from the release rehearsal marker."
}

assert_repository_security() {
  [ -d "$app_dir/.git" ] && [ ! -L "$app_dir/.git" ] \
    || fail "production .git must be a real directory."
  if find "$app_dir/.git" -xdev \
    \( ! -user root -o -perm /022 \) -print -quit | grep -q .; then
    fail "production Git metadata must be root-owned and non-writable by group/others."
  fi
  if find "$app_dir" -xdev \
    \( -path "$app_dir/.git" -o -path "$app_dir/vendor" \
       -o -path "$app_dir/node_modules" -o -path "$app_dir/storage" \
       -o -path "$app_dir/bootstrap/cache" -o -path "$app_dir/public/build" \
       -o -path "$app_dir/public/storage" \) -prune \
    -o \( -type l -o ! -user root -o -perm /022 \) -print -quit \
    | grep -q .; then
    fail "production source boundary is not root-owned and immutable."
  fi
  if [ -e "$app_dir/public/storage" ] || [ -L "$app_dir/public/storage" ]; then
    [ -L "$app_dir/public/storage" ] \
      && [ "$(readlink -f "$app_dir/public/storage")" = "$app_dir/storage/app/public" ] \
      || fail "public storage link does not target storage/app/public."
  fi
}

assert_root_release_artifact() {
  local path="$1"
  local label="$2"
  local mode

  [ -f "$path" ] && [ ! -L "$path" ] \
    || fail "$label must be a regular non-symlink file."
  [ "$(stat -c '%U:%G' "$path")" = root:root ] \
    || fail "$label must be owned by root:root."
  mode="$(stat -c %a "$path")"
  if (( (8#$mode & 8#022) != 0 )); then
    fail "$label must not be writable by group or others."
  fi
}

seal_tracked_source() {
  local path

  if git ls-files -s | grep -q '^120000 '; then
    fail "tracked symbolic links are prohibited in a production release."
  fi
  while IFS= read -r -d '' path; do
    [ -f "$path" ] && [ ! -L "$path" ] \
      || fail "tracked release path is not a regular file: $path"
    chown --no-dereference root:root -- "$path"
    chmod go-w -- "$path"
  done < <(git ls-files -z)
  find . -xdev \
    \( -path './storage' -o -path './bootstrap/cache' \
       -o -path './vendor' -o -path './public/build' \) -prune \
    -o -type d -exec chown root:root -- {} +
  find . -xdev \
    \( -path './storage' -o -path './bootstrap/cache' \
       -o -path './vendor' -o -path './public/build' \) -prune \
    -o -type d -exec chmod go-w -- {} +
}

assert_production_toolchain() {
  [ "$(run_as_app php -r 'echo PHP_VERSION;')" = '8.4.23' ] \
    || fail "production CLI PHP must be exactly 8.4.23."
}

validate_release_archive() {
  local archive="$1"
  local prefix="$2"
  local maximum_bytes="$3"
  local maximum_expanded_bytes="$4"
  local label="$5"
  local entry
  local normalized
  local size
  local expanded_size

  assert_root_0600 "$archive" "$label"
  size="$(stat -c %s "$archive")"
  [[ "$size" =~ ^[1-9][0-9]*$ ]] \
    || fail "$label is empty."
  [ "$size" -le "$maximum_bytes" ] \
    || fail "$label exceeds its size limit."
  expanded_size="$(LC_ALL=C tar -tvzf "$archive" | awk '
    $3 ~ /^[0-9]+$/ { total += $3 }
    END { printf "%.0f", total }
  ')"
  [[ "$expanded_size" =~ ^[1-9][0-9]*$ ]] \
    || fail "$label has no regular file payload."
  [ "$expanded_size" -le "$maximum_expanded_bytes" ] \
    || fail "$label exceeds its expanded size limit."
  while IFS= read -r entry; do
    [ -n "$entry" ] && [[ "$entry" != *$'\r'* ]] \
      || fail "$label contains an invalid path."
    normalized="${entry%/}"
    [[ "$normalized" = "$prefix" || "$normalized" = "$prefix/"* ]] \
      || fail "$label escapes $prefix."
    [[ "$normalized" != /* && ! "$normalized" =~ (^|/)[.][.](/|$) ]] \
      || fail "$label contains path traversal."
  done < <(LC_ALL=C tar -tzf "$archive")
  if LC_ALL=C tar -tvzf "$archive" \
    | awk 'substr($1, 1, 1) != "-" && substr($1, 1, 1) != "d" { found = 1; exit } END { exit found ? 0 : 1 }'; then
    fail "$label contains links or special files."
  fi
}

verify_vite_manifest() {
  local release_root="$1"
  local expected_build_id="$2"
  local manifest="$release_root/public/build/manifest.json"

  [ -f "$manifest" ] && [ ! -L "$manifest" ] \
    || fail "Vite manifest must be a regular non-symlink file."
  [ "$(stat -c %s "$manifest")" -le 10485760 ] \
    || fail "Vite manifest exceeds the 10 MiB verification limit."
  env -i \
    PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    EXPECTED_BUILD_ID="$expected_build_id" \
    RELEASE_ROOT="$release_root" \
    php -d memory_limit=64M -r '
    $buildId = (string) getenv("EXPECTED_BUILD_ID");
    $releaseRoot = (string) getenv("RELEASE_ROOT");
    if (preg_match("/^[0-9a-f]{12}$/D", $buildId) !== 1) {
        throw new RuntimeException("Invalid expected Vite build id.");
    }
    if ($releaseRoot === "" || ! is_dir($releaseRoot)) {
        throw new RuntimeException("Invalid isolated release root.");
    }
    $manifestPath = $releaseRoot."/public/build/manifest.json";
    if (! is_readable($manifestPath)) {
        throw new RuntimeException("Vite manifest is missing.");
    }
    $manifest = json_decode(
        (string) file_get_contents($manifestPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    foreach (["resources/css/app.css", "resources/js/app.js"] as $requiredEntry) {
        if (! isset($manifest[$requiredEntry]) || ! is_array($manifest[$requiredEntry])) {
            throw new RuntimeException("Vite manifest entry is missing: ".$requiredEntry);
        }
    }
    // Rolldown appends a numeric collision suffix when two chunks
    // otherwise resolve to the same output filename.
    $pattern = "/-".preg_quote($buildId, "/")."(?:[0-9]+)?\.[^\/]+$/D";
    $outputs = [];
    foreach ($manifest as $entry) {
        if (! is_array($entry)) {
            throw new RuntimeException("Vite manifest contains an invalid entry.");
        }
        if (isset($entry["file"])) {
            $outputs[] = $entry["file"];
        }
        foreach (["css", "assets"] as $listKey) {
            foreach (($entry[$listKey] ?? []) as $output) {
                $outputs[] = $output;
            }
        }
    }
    if ($outputs === []) {
        throw new RuntimeException("Vite manifest has no build outputs.");
    }
    foreach (array_unique($outputs) as $output) {
        if (! is_string($output)
            || str_starts_with($output, "/")
            || preg_match("#(?:^|/)\.\.(?:/|$)#", $output) === 1
            || preg_match($pattern, $output) !== 1
            || ! is_file($releaseRoot."/public/build/".$output)) {
            throw new RuntimeException("Unexpected Vite output: ".json_encode($output));
        }
    }
  '
}

pause_scheduler() {
  local destination

  hard_stop_cron || return 1
  if [ -e "$scheduler_file" ]; then
    destination="$scheduler_hold"
    if [ -e "$destination" ]; then
      destination="${scheduler_hold}.conflict.$(date --utc +%Y%m%dT%H%M%SZ)"
      echo "Scheduler hold already existed; pausing the active file at $destination." >&2
    fi
    mv -- "$scheduler_file" "$destination"
  fi
  (
    cd "$app_dir"
    run_as_app timeout 60s php artisan schedule:interrupt --quiet
  )
}

curl_https() {
  curl --fail --silent --show-error \
    --proto '=https' --proto-redir '=https' --tlsv1.2 \
    --connect-timeout 5 --max-time 30 "$@"
}

prearmed_fence_file_is_safe() {
  local path="$1"
  local expected

  expected="[Unit]
ConditionPathExists=$writer_fence_sentinel"
  [ -f "$path" ] && [ ! -L "$path" ] \
    && [ "$(stat -c '%U:%G:%a' "$path" 2>/dev/null || true)" = root:root:644 ] \
    && [ "$(<"$path")" = "$expected" ]
}

service_uses_dropin() {
  local service="$1"
  local dropin="$2"
  local paths

  paths="$(systemctl show --property=DropInPaths --value "$service" 2>/dev/null || true)"
  [[ " $paths " = *" $dropin "* ]]
}

handoff_sentinel_is_safe() {
  local path="$1"

  [ -f "$path" ] && [ ! -L "$path" ] \
    && [ "$(stat -c '%U:%G:%a' "$path" 2>/dev/null || true)" = root:root:600 ] \
    && [ "$(wc -l < "$path" | tr -d ' ')" = 1 ] \
    && [ "$(<"$path")" = "nonce=$handoff_nonce" ]
}

cleanup_pre_handoff() {
  local status=$?
  local fence_file
  local service
  local safe_to_remove_sentinel=true
  local backup_active_state
  local backup_main_pid
  local service_main_pid

  trap - EXIT HUP INT TERM
  set +e
  if [ -n "${handoff_request_tmp:-}" ]; then
    rm -f -- "$handoff_request_tmp" || status=1
  fi
  if [ -n "${handoff_sentinel_tmp:-}" ]; then
    rm -f -- "$handoff_sentinel_tmp" || status=1
  fi
  if [ "$handoff_cleanup_armed" = true ]; then
    if [ "$handoff_adopted" = true ] \
      || [ -e "$writer_fence_sentinel_hold" ] \
      || [ -L "$writer_fence_sentinel_hold" ] \
      || [ -e "$backup_handoff_ready" ] \
      || [ -L "$backup_handoff_ready" ]; then
      echo "Release handoff is active or ambiguous; production remains fail-closed." >&2
      status=1
    elif handoff_sentinel_is_safe "$writer_fence_sentinel"; then
      backup_active_state="$(systemctl show --property=ActiveState --value \
        lora-backup.service 2>/dev/null || true)"
      backup_main_pid="$(systemctl show --property=MainPID --value \
        lora-backup.service 2>/dev/null || true)"
      case "$backup_active_state:$backup_main_pid" in
        inactive:0|failed:0) ;;
        *)
        timeout --signal=TERM --kill-after=30s 16m \
          systemctl stop lora-backup.service || safe_to_remove_sentinel=false
          ;;
      esac
      backup_active_state="$(systemctl show --property=ActiveState --value \
        lora-backup.service 2>/dev/null || true)"
      backup_main_pid="$(systemctl show --property=MainPID --value \
        lora-backup.service 2>/dev/null || true)"
      case "$backup_active_state:$backup_main_pid" in
        inactive:0|failed:0) ;;
        *) safe_to_remove_sentinel=false ;;
      esac
      for service in "$php_fpm_service" "$queue_service" "$cron_service"; do
        service_main_pid="$(systemctl show --property=MainPID --value \
          "$service" 2>/dev/null || true)"
        systemctl is-active --quiet "$service" \
          && [[ "$service_main_pid" =~ ^[1-9][0-9]*$ ]] \
          || safe_to_remove_sentinel=false
      done
      [ -S "$php_fpm_socket" ] || safe_to_remove_sentinel=false
      [ -f "$scheduler_file" ] && [ ! -L "$scheduler_file" ] \
        || safe_to_remove_sentinel=false
      [ ! -e "$backup_scheduler_hold" ] \
        && [ ! -L "$backup_scheduler_hold" ] \
        || safe_to_remove_sentinel=false
      if [ "$safe_to_remove_sentinel" != true ]; then
        echo "Backup stop/resume was not proven; pre-armed release state remains." >&2
        exit 1
      fi
      for fence_file in \
        "$php_fpm_fence_file" "$queue_fence_file" "$cron_fence_file"; do
        if prearmed_fence_file_is_safe "$fence_file"; then
          rm -f -- "$fence_file" || status=1
          rmdir "${fence_file%/*}" >/dev/null 2>&1 || true
        else
          status=1
        fi
      done
      systemctl daemon-reload || status=1
      for service in "$php_fpm_service" "$queue_service" "$cron_service"; do
        case "$service" in
          "$php_fpm_service") fence_file="$php_fpm_fence_file" ;;
          "$queue_service") fence_file="$queue_fence_file" ;;
          *) fence_file="$cron_fence_file" ;;
        esac
        if [ -e "$fence_file" ] || [ -L "$fence_file" ] \
          || service_uses_dropin "$service" "$fence_file"; then
          safe_to_remove_sentinel=false
        fi
      done
      if [ "$safe_to_remove_sentinel" = true ]; then
        rm -f -- "$writer_fence_sentinel" \
          "$backup_handoff_request" || status=1
      else
        echo "Pre-armed drop-ins are still loaded; sentinel remains fail-open for old production." >&2
        status=1
      fi
    else
      echo "Release handoff pre-arm state is not exact; refusing unsafe cleanup." >&2
      status=1
    fi
  fi
  exit "$status"
}

install -d -o root -g root -m 0700 "$(dirname "$server_lock")"
exec 9>"$server_lock"
chmod 0600 "$server_lock"
flock -n 9 || fail "another server maintenance operation holds $server_lock."
command -v runuser >/dev/null 2>&1 \
  || fail "runuser is required to isolate application code from root."
command -v tar >/dev/null 2>&1 \
  || fail "tar is required to install verified release artifacts."
command -v composer >/dev/null 2>&1 \
  || fail "trusted Composer is required for candidate platform checks."
composer --version --no-ansi 2>/dev/null | grep -Eq '^Composer version 2[.]' \
  || fail "trusted Composer must be version 2.x."
command -v timeout >/dev/null 2>&1 \
  || fail "timeout is required for bounded production cleanup."
for required_command in df openssl pgrep ss sync; do
  command -v "$required_command" >/dev/null 2>&1 \
    || fail "required production safety command is missing: $required_command"
done
id "$app_user" >/dev/null 2>&1 \
  || fail "application user does not exist: $app_user"
install -d -o root -g "$app_user" -m 0750 "$release_dir"
if [ -e "$release_file" ] || [ -L "$release_file" ]; then
  [ -f "$release_file" ] && [ ! -L "$release_file" ] \
    && [ "$(stat -c '%U:%G:%a' "$release_file")" = "root:$app_user:640" ] \
    || fail "existing release identity file is not trusted."
fi

assert_repository_security
cd "$app_dir"

tested_sha="${1:-}"
validate_sha "$tested_sha" "Tested commit"
rehearsal_marker="$rehearsal_dir/$tested_sha.ok"

pre_deploy_commit="$(git rev-parse HEAD)"
validate_sha "$pre_deploy_commit" "Current production commit"
if [ -n "$(git status --porcelain --untracked-files=normal)" ]; then
  fail "production working tree is not clean."
fi

git fetch --no-tags origin \
  '+refs/heads/main:refs/remotes/origin/main' --quiet
main_sha="$(git rev-parse origin/main)"
validate_sha "$main_sha" "origin/main"
git cat-file -e "$tested_sha^{commit}"
[ "$tested_sha" = "$main_sha" ] \
  || fail "tested commit $tested_sha is not the current origin/main $main_sha."
git merge-base --is-ancestor "$pre_deploy_commit" "$tested_sha" \
  || fail "release is not a fast-forward from current production."

candidate_passport_required=false
initial_passport_public_fingerprint=not-required
if candidate_requires_passport "$tested_sha"; then
  candidate_passport_required=true
  assert_live_passport_environment
  initial_passport_public_fingerprint="$(assert_live_passport_key_pair)" \
    || fail "live Passport key pair could not be verified."
fi

[ -f "$backup_script" ] && [ ! -L "$backup_script" ] \
  && [ "$(stat -c '%U:%G:%a' "$backup_script")" = root:root:700 ] \
  || fail "installed backup script permissions are not trusted."
[ -f "$backup_service" ] && [ ! -L "$backup_service" ] \
  && [ "$(stat -c '%U:%G:%a' "$backup_service")" = root:root:644 ] \
  || fail "installed backup service permissions are not trusted."
printf '%s  %s\n%s  %s\n' \
  "$backup_script_sha" "$backup_script" \
  "$backup_service_sha" "$backup_service" \
  | sha256sum --check --strict
[ "$(systemctl show --property=FragmentPath --value lora-backup.service)" = "$backup_service" ] \
  || fail "lora-backup.service does not use the trusted unit file."
[ -z "$(systemctl show --property=DropInPaths --value lora-backup.service)" ] \
  || fail "lora-backup.service must not have systemd drop-ins."

assert_production_config
assert_app_filesystem_security
assert_production_toolchain
assert_no_pending_migrations
assert_production_database_privileges
current_mysql_fingerprint="$(production_database_fingerprint)"
current_mysql_fingerprint_sha256="$(printf '%s' "$current_mysql_fingerprint" | sha256sum | awk '{print $1}')"
[[ "$current_mysql_fingerprint_sha256" =~ ^[0-9a-f]{64}$ ]] \
  || fail "production MySQL fingerprint could not be hashed."
systemctl is-active --quiet "$queue_service" \
  || fail "queue service is not active before deploy."
if systemctl is-active --quiet cron.service; then
  cron_service=cron.service
elif systemctl is-active --quiet crond.service; then
  cron_service=crond.service
else
  fail "cron service is not active before deploy."
fi
mapfile -t php_fpm_services < <(
  systemctl list-units --type=service --state=running \
    'php*-fpm.service' --no-legend --plain | awk 'NF { print $1 }'
)
[ "${#php_fpm_services[@]}" -eq 1 ] \
  || fail "exactly one running PHP-FPM service is required."
php_fpm_service="${php_fpm_services[0]}"
php_fpm_version="${php_fpm_service#php}"
php_fpm_version="${php_fpm_version%-fpm.service}"
[[ "$php_fpm_version" =~ ^[0-9]+([.][0-9]+)?$ ]] \
  || fail "the active PHP-FPM service name is invalid."
php_fpm_socket="/run/php/php${php_fpm_version}-fpm.sock"
[[ "$php_fpm_socket" =~ ^/run/php/php[0-9]+([.][0-9]+)?-fpm[.]sock$ ]] \
  || fail "the active PHP-FPM socket path is invalid."
[ -S "$php_fpm_socket" ] || fail "the active PHP-FPM socket is unavailable."
php_fpm_fence_file="/etc/systemd/system/${php_fpm_service}.d/${writer_fence_name}"
queue_fence_file="/etc/systemd/system/${queue_service}.d/${writer_fence_name}"
cron_fence_file="/etc/systemd/system/${cron_service}.d/${writer_fence_name}"
[ -f "$scheduler_file" ] || fail "scheduler file is missing: $scheduler_file"
[ ! -e "$scheduler_hold" ] || fail "a previous scheduler hold still exists: $scheduler_hold"
[ ! -e "$backup_scheduler_hold" ] && [ ! -L "$backup_scheduler_hold" ] \
  || fail "a previous backup scheduler hold still exists: $backup_scheduler_hold"

assert_root_0600 "$rehearsal_marker" "Release rehearsal marker"
if [ "$(awk 'END { print NR }' "$rehearsal_marker")" -ne 13 ] \
  || ! grep -Eq '^candidate_sha=[0-9a-f]{40}$' "$rehearsal_marker" \
  || ! grep -Eq '^production_sha=[0-9a-f]{40}$' "$rehearsal_marker" \
  || ! grep -Eq '^snapshot_id=[0-9a-f]{64}$' "$rehearsal_marker" \
  || ! grep -Fqx "mysql_image=$rehearsal_mysql_image" "$rehearsal_marker" \
  || ! grep -Fqx "app_image=$rehearsal_app_image" "$rehearsal_marker" \
  || ! grep -Eq '^verified_tables=[1-9][0-9]*$' "$rehearsal_marker" \
  || ! grep -Eq '^schema_sha256=[0-9a-f]{64}$' "$rehearsal_marker" \
  || ! grep -Eq '^checksums_sha256=[0-9a-f]{64}$' "$rehearsal_marker" \
  || ! grep -Eq '^mysql_fingerprint_sha256=[0-9a-f]{64}$' "$rehearsal_marker" \
  || ! grep -Eq '^vendor_sha256=[0-9a-f]{64}$' "$rehearsal_marker" \
  || ! grep -Eq '^assets_sha256=[0-9a-f]{64}$' "$rehearsal_marker" \
  || ! grep -Eq '^passport_public_key_sha256=(not-required|[0-9a-f]{64})$' "$rehearsal_marker" \
  || ! grep -Eq '^completed_utc=[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$' "$rehearsal_marker"; then
  fail "release rehearsal marker has an invalid or non-exact format."
fi
rehearsal_candidate="$(sed -n 's/^candidate_sha=//p' "$rehearsal_marker")"
rehearsal_production="$(sed -n 's/^production_sha=//p' "$rehearsal_marker")"
rehearsal_completed="$(sed -n 's/^completed_utc=//p' "$rehearsal_marker")"
rehearsal_mysql_fingerprint_sha256="$(sed -n 's/^mysql_fingerprint_sha256=//p' "$rehearsal_marker")"
vendor_sha256="$(sed -n 's/^vendor_sha256=//p' "$rehearsal_marker")"
assets_sha256="$(sed -n 's/^assets_sha256=//p' "$rehearsal_marker")"
rehearsal_passport_public_fingerprint="$(sed -n 's/^passport_public_key_sha256=//p' "$rehearsal_marker")"
[ "$rehearsal_candidate" = "$tested_sha" ] \
  || fail "rehearsal candidate does not match the tested commit."
[ "$rehearsal_production" = "$pre_deploy_commit" ] \
  || fail "rehearsal production baseline does not match current production."
assert_fresh_timestamp "$rehearsal_completed" 86400 "Release rehearsal marker"
[ "$current_mysql_fingerprint_sha256" = "$rehearsal_mysql_fingerprint_sha256" ] \
  || fail "production MySQL settings differ from the rehearsed fingerprint."
if [ "$candidate_passport_required" = true ]; then
  [[ "$rehearsal_passport_public_fingerprint" =~ ^[0-9a-f]{64}$ ]] \
    && [ "$initial_passport_public_fingerprint" = "$rehearsal_passport_public_fingerprint" ] \
    || fail "production Passport key pair differs from the rehearsed fingerprint."
else
  [ "$rehearsal_passport_public_fingerprint" = not-required ] \
    || fail "release rehearsal unexpectedly requires a Passport key pair."
fi
release_artifact_dir="$release_artifact_root/$tested_sha"
vendor_archive="$release_artifact_dir/candidate-vendor.tar.gz"
assets_archive="$release_artifact_dir/candidate-assets.tar.gz"
[ -d "$release_artifact_root" ] && [ ! -L "$release_artifact_root" ] \
  && [ "$(stat -c '%U:%G:%a' "$release_artifact_root")" = root:root:700 ] \
  || fail "release artifact root is not trusted."
[ -d "$release_artifact_dir" ] && [ ! -L "$release_artifact_dir" ] \
  && [ "$(stat -c '%U:%G:%a' "$release_artifact_dir")" = root:root:700 ] \
  || fail "candidate release artifact directory is not trusted."
validate_release_archive "$vendor_archive" vendor 1073741824 3221225472 \
  "Candidate Composer artifact"
validate_release_archive "$assets_archive" public/build 536870912 1073741824 \
  "Candidate Vite artifact"
[ "$(sha256sum "$vendor_archive" | awk '{print $1}')" = "$vendor_sha256" ] \
  || fail "candidate Composer artifact hash does not match the rehearsal marker."
[ "$(sha256sum "$assets_archive" | awk '{print $1}')" = "$assets_sha256" ] \
  || fail "candidate Vite artifact hash does not match the rehearsal marker."
vendor_expanded_bytes="$(LC_ALL=C tar -tvzf "$vendor_archive" | awk '$3 ~ /^[0-9]+$/ { total += $3 } END { printf "%.0f", total }')"
assets_expanded_bytes="$(LC_ALL=C tar -tvzf "$assets_archive" | awk '$3 ~ /^[0-9]+$/ { total += $3 } END { printf "%.0f", total }')"
artifact_available_bytes="$(df --output=avail -B1 "$app_dir" | awk 'NR == 2 {print $1}')"
artifact_filesystem_bytes="$(df --output=size -B1 "$app_dir" | awk 'NR == 2 {print $1}')"
artifact_reserve_bytes=$((5 * 1024 * 1024 * 1024))
[[ "$artifact_available_bytes" =~ ^[0-9]+$ && "$artifact_filesystem_bytes" =~ ^[0-9]+$ ]] \
  || fail "release artifact disk capacity could not be determined."
if [ $((artifact_filesystem_bytes / 5)) -gt "$artifact_reserve_bytes" ]; then
  artifact_reserve_bytes=$((artifact_filesystem_bytes / 5))
fi
[ "$artifact_available_bytes" -gt $((vendor_expanded_bytes + assets_expanded_bytes + artifact_reserve_bytes)) ] \
  || fail "insufficient disk reserve to extract verified release artifacts."

systemctl is-active --quiet lora-backup.service \
  && fail "the production backup service is already active."
for stale_path in \
  "$backup_handoff_request" "$backup_handoff_ready" \
  "$writer_fence_sentinel" "$writer_fence_sentinel_hold" \
  "$php_fpm_fence_file" "$queue_fence_file" "$cron_fence_file"; do
  [ ! -e "$stale_path" ] && [ ! -L "$stale_path" ] \
    || fail "stale release handoff state requires operator recovery: $stale_path"
done

handoff_nonce="$(php -r 'echo bin2hex(random_bytes(32));')"
[[ "$handoff_nonce" =~ ^[0-9a-f]{64}$ ]] \
  || fail "release handoff nonce generation failed."
handoff_created_utc="$(date --utc +%Y-%m-%dT%H:%M:%SZ)"
handoff_request_tmp="${backup_handoff_request}.tmp.$$"
handoff_sentinel_tmp="${writer_fence_sentinel}.tmp.$$"
handoff_cleanup_armed=true
handoff_adopted=false
trap cleanup_pre_handoff EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

install -o root -g root -m 0600 /dev/null "$handoff_sentinel_tmp"
printf 'nonce=%s\n' "$handoff_nonce" > "$handoff_sentinel_tmp"
sync "$handoff_sentinel_tmp"
mv --no-target-directory "$handoff_sentinel_tmp" "$writer_fence_sentinel"
handoff_sentinel_tmp=
handoff_sentinel_is_safe "$writer_fence_sentinel" \
  || fail "release handoff sentinel is not exact."
for fence_file in \
  "$php_fpm_fence_file" "$queue_fence_file" "$cron_fence_file"; do
  install -d -o root -g root -m 0755 "${fence_file%/*}"
  install -o root -g root -m 0644 /dev/null "$fence_file"
  printf '[Unit]\nConditionPathExists=%s\n' \
    "$writer_fence_sentinel" > "$fence_file"
  prearmed_fence_file_is_safe "$fence_file" \
    || fail "release handoff fence is not exact: $fence_file"
done
systemctl daemon-reload
service_uses_dropin "$php_fpm_service" "$php_fpm_fence_file" \
  && service_uses_dropin "$queue_service" "$queue_fence_file" \
  && service_uses_dropin "$cron_service" "$cron_fence_file" \
  || fail "release handoff fences are not loaded."

install -o root -g root -m 0600 /dev/null "$handoff_request_tmp"
printf 'version=1\nnonce=%s\ncandidate_sha=%s\ncreated_utc=%s\n' \
  "$handoff_nonce" "$tested_sha" "$handoff_created_utc" \
  > "$handoff_request_tmp"
sync "$handoff_request_tmp"
mv --no-target-directory "$handoff_request_tmp" "$backup_handoff_request"
handoff_request_tmp=
assert_root_0600 "$backup_handoff_request" "Release handoff request"
[ "$(awk 'END { print NR }' "$backup_handoff_request")" -eq 4 ] \
  && grep -Fqx 'version=1' "$backup_handoff_request" \
  && grep -Fqx "nonce=$handoff_nonce" "$backup_handoff_request" \
  && grep -Fqx "candidate_sha=$tested_sha" "$backup_handoff_request" \
  && grep -Fqx "created_utc=$handoff_created_utc" "$backup_handoff_request" \
  || fail "release handoff request is not exact."
assert_passport_fingerprint_matches_marker

systemctl reset-failed lora-backup.service > /dev/null 2>&1 || true
backup_started_epoch="$(date --utc +%s)"
systemctl start --wait lora-backup.service
[ "$(systemctl show --property=Result --value lora-backup.service)" = "success" ] \
  || fail "fresh offsite backup did not complete successfully."
assert_root_0600 "$backup_success_file" "Backup success marker"
backup_completed="$(tr -d '\r\n' < "$backup_success_file")"
assert_fresh_timestamp "$backup_completed" 900 "Backup success marker"
backup_completed_epoch="$(date --date="$backup_completed" +%s)"
[ "$backup_completed_epoch" -ge "$backup_started_epoch" ] \
  || fail "backup success marker was not renewed by this backup run."
exec 7>/var/lib/lora-backup/backup.lock
flock -n 7 || fail "fresh backup lock was not released cleanly."
current_mysql_fingerprint="$(production_database_fingerprint)"
[ "$(printf '%s' "$current_mysql_fingerprint" | sha256sum | awk '{print $1}')" = "$rehearsal_mysql_fingerprint_sha256" ] \
  || fail "production MySQL settings changed after the release rehearsal."
assert_passport_fingerprint_matches_marker

maintenance_secret="$(php -r 'echo bin2hex(random_bytes(32));')"
smoke_cookie=
deploy_succeeded=false
deploy_guard_active=false
state_file=
integrity_file=
integrity_work_file=
handoff_dir=
release_tmp=
artifact_stage=

php_fpm_is_stopped() {
  local active_state
  local main_pid

  [ -n "$php_fpm_service" ] || return 1
  active_state="$(systemctl show --property=ActiveState --value "$php_fpm_service" 2>/dev/null || true)"
  main_pid="$(systemctl show --property=MainPID --value "$php_fpm_service" 2>/dev/null || true)"
  case "$active_state" in inactive|failed) ;; *) return 1 ;; esac
  [ "$main_pid" = 0 ]
}

hard_stop_php_fpm() {
  timeout 30s systemctl stop "$php_fpm_service" >/dev/null 2>&1 || true
  if ! php_fpm_is_stopped; then
    timeout 10s systemctl kill --kill-who=all --signal=KILL \
      "$php_fpm_service" >/dev/null 2>&1 || true
    timeout 15s systemctl stop "$php_fpm_service" >/dev/null 2>&1 || true
  fi
  php_fpm_is_stopped
}

queue_is_stopped() {
  local active_state
  local main_pid

  active_state="$(systemctl show --property=ActiveState --value "$queue_service" 2>/dev/null || true)"
  main_pid="$(systemctl show --property=MainPID --value "$queue_service" 2>/dev/null || true)"
  case "$active_state" in inactive|failed) ;; *) return 1 ;; esac
  [ "$main_pid" = 0 ]
}

cron_is_stopped() {
  local active_state
  local main_pid

  [ -n "$cron_service" ] || return 1
  active_state="$(systemctl show --property=ActiveState --value "$cron_service" 2>/dev/null || true)"
  main_pid="$(systemctl show --property=MainPID --value "$cron_service" 2>/dev/null || true)"
  case "$active_state" in inactive|failed) ;; *) return 1 ;; esac
  [ "$main_pid" = 0 ]
}

hard_stop_cron() {
  timeout 30s systemctl stop "$cron_service" >/dev/null 2>&1 || true
  if ! cron_is_stopped; then
    timeout 10s systemctl kill --kill-who=all --signal=KILL \
      "$cron_service" >/dev/null 2>&1 || true
    timeout 15s systemctl stop "$cron_service" >/dev/null 2>&1 || true
  fi
  cron_is_stopped
}

queue_process_is_absent() {
  local status

  if pgrep -f '[a]rtisan (queue:(work|listen)|horizon)([[:space:]]|$)' >/dev/null; then
    return 1
  else
    status=$?
    [ "$status" -eq 1 ]
  fi
}

scheduler_process_is_absent() {
  local status

  if pgrep -f '[a]rtisan schedule:(run|work|finish-command)([[:space:]]|$)' >/dev/null; then
    return 1
  else
    status=$?
    [ "$status" -eq 1 ]
  fi
}

php_fpm_process_is_absent() {
  local status

  if pgrep -f '(^|/)[p]hp-fpm([0-9.]*)?(:|[[:space:]]|$)' >/dev/null; then
    return 1
  else
    status=$?
    [ "$status" -eq 1 ]
  fi
}

php_fpm_listener_is_absent() {
  local listeners
  local status

  listeners="$(ss -H -xl)" || return 1
  if awk -v target="$php_fpm_socket" '
    { for (field = 1; field <= NF; field++) if ($field == target) found = 1 }
    END { exit found ? 0 : 1 }
  ' <<< "$listeners"; then
    return 1
  else
    status=$?
    [ "$status" -eq 1 ]
  fi
}

php_fpm_listener_is_present() {
  local listeners

  listeners="$(ss -H -xl)" || return 1
  awk -v target="$php_fpm_socket" '
    { for (field = 1; field <= NF; field++) if ($field == target) found = 1 }
    END { exit found ? 0 : 1 }
  ' <<< "$listeners"
}

runtime_fence_file_is_safe() {
  local path="$1"
  local expected

  expected="[Unit]
RefuseManualStart=yes
ConditionPathExists=$writer_fence_sentinel"
  [ -f "$path" ] && [ ! -L "$path" ] \
    && [ "$(stat -c '%U:%G:%a' "$path")" = root:root:644 ] \
    && [ "$(<"$path")" = "$expected" ]
}

service_uses_runtime_fence() {
  local service="$1"
  local fence_file="$2"
  local dropins

  dropins="$(systemctl show --property=DropInPaths --value "$service" 2>/dev/null || true)"
  [[ " $dropins " = *" $fence_file "* ]]
}

runtime_writer_fence_is_loaded() {
  [ ! -e "$writer_fence_sentinel" ] && [ ! -L "$writer_fence_sentinel" ] \
    && runtime_fence_file_is_safe "$php_fpm_fence_file" \
    && runtime_fence_file_is_safe "$queue_fence_file" \
    && runtime_fence_file_is_safe "$cron_fence_file" \
    && service_uses_runtime_fence "$php_fpm_service" "$php_fpm_fence_file" \
    && service_uses_runtime_fence "$queue_service" "$queue_fence_file" \
    && service_uses_runtime_fence "$cron_service" "$cron_fence_file"
}

ensure_runtime_writer_fence() {
  local fence_file

  [ ! -e "$writer_fence_sentinel" ] && [ ! -L "$writer_fence_sentinel" ] \
    || return 1
  for fence_file in \
    "$php_fpm_fence_file" "$queue_fence_file" "$cron_fence_file"; do
    if [ -e "$fence_file" ] || [ -L "$fence_file" ]; then
      runtime_fence_file_is_safe "$fence_file" || return 1
      continue
    fi
    install -d -o root -g root -m 0755 "${fence_file%/*}"
    [ -d "${fence_file%/*}" ] && [ ! -L "${fence_file%/*}" ] || return 1
    install -o root -g root -m 0644 /dev/null "$fence_file"
    printf '[Unit]\nRefuseManualStart=yes\nConditionPathExists=%s\n' \
      "$writer_fence_sentinel" > "$fence_file"
  done
  systemctl daemon-reload
  runtime_writer_fence_is_loaded
}

promote_prearmed_fence() {
  local fence_file="$1"
  local temporary

  prearmed_fence_file_is_safe "$fence_file" || return 1
  temporary="${fence_file}.promote.$$"
  [ ! -e "$temporary" ] && [ ! -L "$temporary" ] || return 1
  install -o root -g root -m 0644 /dev/null "$temporary"
  printf '[Unit]\nRefuseManualStart=yes\nConditionPathExists=%s\n' \
    "$writer_fence_sentinel" > "$temporary"
  mv --no-target-directory "$temporary" "$fence_file"
  runtime_fence_file_is_safe "$fence_file"
}

remove_runtime_fence() {
  local service="$1"
  local fence_file="$2"

  runtime_fence_file_is_safe "$fence_file" || return 1
  rm -f -- "$fence_file"
  rmdir "${fence_file%/*}" >/dev/null 2>&1 || true
  systemctl daemon-reload
  [ ! -e "$fence_file" ] && [ ! -L "$fence_file" ] \
    && ! service_uses_runtime_fence "$service" "$fence_file"
}

record_possible_exposure() {
  local exposed_at
  local quarantine
  local state_directory

  if [ -z "$state_file" ] || [ ! -f "$state_file" ] || [ -L "$state_file" ]; then
    return 1
  fi
  if grep -qx 'WRITERS_MAY_HAVE_RUN=1' "$state_file"; then
    sync "$state_file" && return 0
  fi
  exposed_at="$(date --utc +%Y-%m-%dT%H:%M:%SZ)"
  if printf 'WRITERS_MAY_HAVE_RUN=1\nWRITERS_ENABLED_AT_UTC=%s\n' \
      "$exposed_at" >> "$state_file" \
    && sync "$state_file" \
    && grep -qx 'WRITERS_MAY_HAVE_RUN=1' "$state_file" \
    && grep -qx "WRITERS_ENABLED_AT_UTC=$exposed_at" "$state_file"; then
    return 0
  fi
  state_directory="${state_file%/*}"
  quarantine="${state_file}.rollback-unsafe-${exposed_at//[:T-]/}-$$"
  chmod 000 -- "$state_file" >/dev/null 2>&1 || true
  if mv -- "$state_file" "$quarantine" >/dev/null 2>&1; then
    sync "$state_directory" >/dev/null 2>&1 || true
  fi
  echo "CRITICAL: exposure marker was not durable; release state was quarantined." >&2
  return 1
}

cleanup_deploy() {
  local status=$?
  local writers_fenced=true

  trap - EXIT HUP INT TERM
  if [ "$deploy_succeeded" != true ] && [ "$deploy_guard_active" = true ]; then
    echo "Deploy failed; hard-fencing HTTP and pausing every writer." >&2
    if [ -z "$php_fpm_service" ] || [ -z "$php_fpm_fence_file" ] \
      || ! ensure_runtime_writer_fence; then
      writers_fenced=false
      echo "CRITICAL: runtime writer start-fences could not be installed." >&2
    fi
    if [ -n "$php_fpm_service" ] && ! hard_stop_php_fpm; then
      writers_fenced=false
      echo "CRITICAL: $php_fpm_service could not be hard-fenced." >&2
    fi
    timeout 30s systemctl stop "$queue_service" >/dev/null 2>&1 || true
    if ! queue_is_stopped || ! queue_process_is_absent; then
      timeout 10s systemctl kill --kill-who=all --signal=KILL \
        "$queue_service" >/dev/null 2>&1 || true
      timeout 15s systemctl stop "$queue_service" >/dev/null 2>&1 || true
    fi
    queue_is_stopped && queue_process_is_absent || writers_fenced=false
    if ! hard_stop_cron; then
      writers_fenced=false
      echo "CRITICAL: $cron_service could not be hard-fenced." >&2
    fi
    if ! pause_scheduler; then
      writers_fenced=false
      echo "CRITICAL: scheduler interrupt/pause was incomplete." >&2
    fi
    for attempt in $(seq 1 30); do
      if scheduler_process_is_absent; then
        break
      fi
      [ "$attempt" -eq 30 ] || sleep 1
    done
    cd "$app_dir" || true
    if ! run_as_app timeout 2m php artisan down --retry=15 --secret="$maintenance_secret" --quiet; then
      echo "CRITICAL: could not re-enter Laravel maintenance mode." >&2
    fi
    [ ! -e "$scheduler_file" ] && [ ! -L "$scheduler_file" ] \
      && [ -f "$scheduler_hold" ] && [ ! -L "$scheduler_hold" ] \
      && [ "$(stat -c '%U:%G:%a' "$scheduler_hold" 2>/dev/null || true)" = root:root:644 ] \
      && scheduler_process_is_absent && cron_is_stopped || writers_fenced=false
    php_fpm_is_stopped && php_fpm_process_is_absent \
      && php_fpm_listener_is_absent || writers_fenced=false
    runtime_writer_fence_is_loaded || writers_fenced=false
    if [ "$writers_fenced" != true ]; then
      record_possible_exposure || true
    fi
  fi
  rm -f -- "$smoke_cookie"
  if [ -n "$handoff_dir" ]; then
    rm -rf -- "$handoff_dir"
  fi
  if [ -n "$release_tmp" ]; then
    rm -f -- "$release_tmp"
  fi
  if [ -n "$artifact_stage" ]; then
    rm -rf -- "$artifact_stage"
  fi
  exit "$status"
}

assert_root_0600 "$backup_handoff_ready" "Release handoff ready marker"
mapfile -t handoff_lines < "$backup_handoff_ready"
[ "$(wc -l < "$backup_handoff_ready" | tr -d ' ')" = 12 ] \
  && [ "${#handoff_lines[@]}" -eq 12 ] \
  && [ "${handoff_lines[0]}" = version=1 ] \
  && [ "${handoff_lines[1]}" = "nonce=$handoff_nonce" ] \
  && [ "${handoff_lines[2]}" = "candidate_sha=$tested_sha" ] \
  && [[ "${handoff_lines[3]}" =~ ^snapshot_created_utc=([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z)$ ]] \
  && [[ "${handoff_lines[4]}" =~ ^upload_completed_utc=([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z)$ ]] \
  && [[ "${handoff_lines[5]}" =~ ^snapshot_id=([0-9a-f]{64})$ ]] \
  && [ "${handoff_lines[6]}" = "php_fpm_service=$php_fpm_service" ] \
  && [ "${handoff_lines[7]}" = "php_fpm_socket=$php_fpm_socket" ] \
  && [ "${handoff_lines[8]}" = "queue_service=$queue_service" ] \
  && [ "${handoff_lines[9]}" = "cron_service=$cron_service" ] \
  && [ "${handoff_lines[10]}" = "scheduler_hold=$backup_scheduler_hold" ] \
  && [ "${handoff_lines[11]}" = "sentinel_hold=$writer_fence_sentinel_hold" ] \
  || fail "release handoff ready marker has an invalid or non-exact contract."
backup_snapshot_created="${handoff_lines[3]#snapshot_created_utc=}"
backup_completed="${handoff_lines[4]#upload_completed_utc=}"
backup_snapshot_id="${handoff_lines[5]#snapshot_id=}"
assert_fresh_timestamp "$backup_completed" 900 "Release handoff upload completion"
snapshot_created_epoch="$(date --date="$backup_snapshot_created" +%s)"
backup_completed_epoch="$(date --date="$backup_completed" +%s)"
[ "$snapshot_created_epoch" -ge "$backup_started_epoch" ] \
  && [ "$backup_completed_epoch" -ge "$snapshot_created_epoch" ] \
  || fail "release handoff snapshot/upload timestamps are not ordered within this backup run."
[ "$(tr -d '\r\n' < "$backup_success_file")" = "$backup_completed" ] \
  || fail "backup success marker does not match the handoff upload completion."
assert_root_0600 "$backup_handoff_request" "Release handoff request"
[ "$(<"$backup_handoff_request")" = "$(printf 'version=1\nnonce=%s\ncandidate_sha=%s\ncreated_utc=%s' \
  "$handoff_nonce" "$tested_sha" "$handoff_created_utc")" ] \
  || fail "release handoff request changed during backup."
[ ! -e "$writer_fence_sentinel" ] && [ ! -L "$writer_fence_sentinel" ] \
  || fail "release handoff did not activate the writer fences."
handoff_sentinel_is_safe "$writer_fence_sentinel_hold" \
  || fail "release handoff sentinel hold is not exact."
for fence_file in \
  "$php_fpm_fence_file" "$queue_fence_file" "$cron_fence_file"; do
  prearmed_fence_file_is_safe "$fence_file" \
    || fail "release handoff fence changed during backup: $fence_file"
done
service_uses_dropin "$php_fpm_service" "$php_fpm_fence_file" \
  && service_uses_dropin "$queue_service" "$queue_fence_file" \
  && service_uses_dropin "$cron_service" "$cron_fence_file" \
  || fail "release handoff fences are not loaded after backup."
php_fpm_is_stopped && php_fpm_process_is_absent \
  && php_fpm_listener_is_absent \
  && queue_is_stopped && queue_process_is_absent \
  && cron_is_stopped && scheduler_process_is_absent \
  || fail "release handoff did not leave every production writer stopped."
[ ! -e "$scheduler_file" ] && [ ! -L "$scheduler_file" ] \
  && [ -f "$backup_scheduler_hold" ] && [ ! -L "$backup_scheduler_hold" ] \
  && [ "$(stat -c '%U:%G:%a' "$backup_scheduler_hold")" = root:root:644 ] \
  && [ ! -e "$scheduler_hold" ] && [ ! -L "$scheduler_hold" ] \
  || fail "release handoff scheduler state is not exact."
mv --no-target-directory "$backup_scheduler_hold" "$scheduler_hold"
[ -f "$scheduler_hold" ] && [ ! -L "$scheduler_hold" ] \
  && [ "$(stat -c '%U:%G:%a' "$scheduler_hold")" = root:root:644 ] \
  || fail "release scheduler hold could not be adopted."
for fence_file in \
  "$php_fpm_fence_file" "$queue_fence_file" "$cron_fence_file"; do
  promote_prearmed_fence "$fence_file" \
    || fail "release handoff fence could not be hardened: $fence_file"
done
systemctl daemon-reload
runtime_writer_fence_is_loaded \
  || fail "hardened release writer fences are not loaded."
php_fpm_is_stopped && php_fpm_process_is_absent \
  && php_fpm_listener_is_absent \
  && queue_is_stopped && queue_process_is_absent \
  && cron_is_stopped && scheduler_process_is_absent \
  || fail "a writer changed state while the release handoff was adopted."

handoff_adopted=true
deploy_guard_active=true
trap cleanup_deploy EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
rm -f -- "$writer_fence_sentinel_hold" \
  "$backup_handoff_request" "$backup_handoff_ready"
[ ! -e "$writer_fence_sentinel_hold" ] \
  && [ ! -e "$backup_handoff_request" ] \
  && [ ! -e "$backup_handoff_ready" ] \
  || fail "release handoff control files were not removed after adoption."

artifact_available_bytes="$(df --output=avail -B1 "$app_dir" | awk 'NR == 2 {print $1}')"
[[ "$artifact_available_bytes" =~ ^[0-9]+$ ]] \
  && [ "$artifact_available_bytes" -gt $((vendor_expanded_bytes + assets_expanded_bytes + artifact_reserve_bytes)) ] \
  || fail "release artifact disk reserve changed before extraction."
artifact_stage="$app_dir/.candidate-${tested_sha}-$$"
[ ! -e "$artifact_stage" ] && [ ! -L "$artifact_stage" ] \
  || fail "candidate artifact staging path already exists."
install -d -o root -g root -m 0700 "$artifact_stage"
tar --extract --gzip --no-same-owner --no-same-permissions \
  --directory="$artifact_stage" --file="$vendor_archive"
tar --extract --gzip --no-same-owner --no-same-permissions \
  --directory="$artifact_stage" --file="$assets_archive"
[ -d "$artifact_stage/vendor" ] && [ ! -L "$artifact_stage/vendor" ] \
  || fail "candidate Composer artifact did not create vendor."
[ -d "$artifact_stage/public/build" ] && [ ! -L "$artifact_stage/public/build" ] \
  || fail "candidate Vite artifact did not create public/build."
[ "$(stat -c %d "$artifact_stage/vendor")" = "$(stat -c %d "$app_dir")" ] \
  || fail "candidate vendor staging is not on the application filesystem."
[ "$(stat -c %d "$artifact_stage/public/build")" = "$(stat -c %d "$app_dir/public")" ] \
  || fail "candidate asset staging is not on the public filesystem."
if find "$artifact_stage" -xdev ! -type d ! -type f -print -quit | grep -q .; then
  fail "candidate release artifacts contain links or special files."
fi
build_id="$(printf '%s' "$tested_sha" | cut -c1-12)"
verify_vite_manifest "$artifact_stage" "$build_id"
chown -R root:root "$artifact_stage"
chmod -R u=rwX,go=rX "$artifact_stage"
chmod 0700 "$artifact_stage"
if find "$artifact_stage" -xdev -perm /022 -print -quit | grep -q .; then
  fail "candidate release artifacts remain group/other writable."
fi
install -o root -g root -m 0644 /dev/null "$artifact_stage/composer.json"
install -o root -g root -m 0644 /dev/null "$artifact_stage/composer.lock"
git show "$tested_sha:composer.json" > "$artifact_stage/composer.json"
git show "$tested_sha:composer.lock" > "$artifact_stage/composer.lock"
[ -s "$artifact_stage/composer.json" ] && [ -s "$artifact_stage/composer.lock" ] \
  || fail "candidate Composer metadata is missing."
install -d -o "$app_user" -g "$app_user" -m 0700 \
  "$artifact_stage/.composer-home"
chmod 0755 "$artifact_stage"
(
  cd "$artifact_stage"
  run_as_app env \
    HOME="$artifact_stage/.composer-home" \
    COMPOSER_HOME="$artifact_stage/.composer-home" \
    COMPOSER_DISABLE_NETWORK=1 \
    COMPOSER_NO_INTERACTION=1 \
    composer check-platform-reqs \
      --no-dev --no-plugins --no-scripts --no-interaction --no-ansi
)
rm -rf -- "$artifact_stage/.composer-home"
rm -f -- "$artifact_stage/composer.json" "$artifact_stage/composer.lock"
chmod 0700 "$artifact_stage"

deploy_guard_active=true
ensure_runtime_writer_fence \
  || fail "runtime writer start-fences could not be installed."
run_as_app timeout 2m php artisan down --retry=15 --secret="$maintenance_secret" --quiet
smoke_cookie="$(mktemp /run/lora-deploy-smoke.XXXXXXXX)"
chmod 0600 "$smoke_cookie"

timeout 30s systemctl stop "$queue_service"
queue_is_stopped || fail "queue service did not stop cleanly."
queue_process_is_absent || fail "a rogue queue process is still active."
pause_scheduler
for attempt in $(seq 1 30); do
  if scheduler_process_is_absent; then
    break
  fi
  [ "$attempt" -ne 30 ] || fail "scheduler is still running after 30 seconds."
  sleep 1
done
[ ! -e "$scheduler_file" ] && [ ! -L "$scheduler_file" ] \
  || fail "scheduler entry is still active."
[ -f "$scheduler_hold" ] && [ ! -L "$scheduler_hold" ] \
  && [ "$(stat -c '%U:%G:%a' "$scheduler_hold")" = root:root:644 ] \
  || fail "scheduler hold is not a trusted root-owned file."
cron_is_stopped || fail "cron service is not fully stopped."
runtime_writer_fence_is_loaded \
  || fail "runtime writer start-fences are not loaded."

install -d -o root -g root -m 0700 "$state_dir"
release_timestamp="$(date --utc +%Y%m%dT%H%M%SZ)"
state_file="$state_dir/${release_timestamp}-${tested_sha}-deploy.env"
integrity_file="$state_dir/${release_timestamp}-${tested_sha}-integrity.json"
handoff_dir="$(mktemp -d /run/lora-deploy.XXXXXXXX)"
chmod 0711 "$handoff_dir"
integrity_work_file="$handoff_dir/integrity.json"
install -o root -g root -m 0600 /dev/null "$state_file"
install -o root -g root -m 0600 /dev/null "$integrity_file"
install -o "$app_user" -g "$app_user" -m 0600 /dev/null "$integrity_work_file"
previous_batch="$(run_as_app php artisan tinker --execute='echo DB::table("migrations")->max("batch") ?? 0;')"
case "$previous_batch" in
  ''|*[!0-9]*) fail "invalid pre-deploy migration batch: $previous_batch" ;;
esac
printf 'PRE_DEPLOY_COMMIT=%s\nCANDIDATE_COMMIT=%s\nPREVIOUS_BATCH=%s\nINTEGRITY_SNAPSHOT=%s\nREHEARSAL_COMPLETED_AT_UTC=%s\nBACKUP_SNAPSHOT_CREATED_AT_UTC=%s\nBACKUP_COMPLETED_AT_UTC=%s\nBACKUP_SNAPSHOT_ID=%s\nPHP_FPM_SERVICE=%s\nPHP_FPM_SOCKET=%s\n' \
  "$pre_deploy_commit" "$tested_sha" "$previous_batch" "$integrity_file" \
  "$rehearsal_completed" "$backup_snapshot_created" "$backup_completed" \
  "$backup_snapshot_id" "$php_fpm_service" "$php_fpm_socket" > "$state_file"

integrity_snapshot_options=(--snapshot="$integrity_work_file")
if run_as_app timeout 60s php artisan tenants:verify-integrity --help \
  | grep -q -- '--verify-storage'; then
  integrity_snapshot_options=(--verify-storage "${integrity_snapshot_options[@]}")
fi
run_as_app timeout 10m php artisan tenants:verify-integrity \
  "${integrity_snapshot_options[@]}"
install -o root -g root -m 0600 "$integrity_work_file" "$integrity_file"
install -o root -g "$app_user" -m 0640 "$integrity_file" "$integrity_work_file"

hard_stop_php_fpm \
  || fail "PHP-FPM did not stop before the candidate code swap."
php_fpm_process_is_absent \
  || fail "a rogue PHP-FPM process remains before the candidate code swap."
php_fpm_listener_is_absent \
  || fail "the PHP-FPM socket is still listening before the candidate code swap."
queue_is_stopped && queue_process_is_absent \
  || fail "queue writers are not fully fenced before the candidate code swap."
[ ! -e "$scheduler_file" ] && [ ! -L "$scheduler_file" ] \
  && scheduler_process_is_absent && cron_is_stopped \
  || fail "scheduler writers are not fully fenced before the candidate code swap."
runtime_writer_fence_is_loaded \
  || fail "runtime writer start-fences changed before the candidate code swap."

git reset --hard "$tested_sha"
[ "$(git rev-parse HEAD)" = "$tested_sha" ] \
  || fail "checked-out release does not match the tested commit."
seal_tracked_source
rm -rf -- vendor node_modules public/build
mv -- "$artifact_stage/vendor" vendor
install -d -o root -g root -m 0755 public
mv -- "$artifact_stage/public/build" public/build
rm -rf -- "$artifact_stage"
artifact_stage=
run_as_app php artisan package:discover --ansi

# Keep control-panel configuration inside the same lock and failure
# guard. Root-only operations are allowlisted by reviewed hashes;
# application PHP/Node code runs as the unprivileged app user.
assert_root_release_artifact \
  deploy/scripts/configure-control-panel-domain.sh \
  "control-panel configuration script"
assert_root_release_artifact \
  deploy/nginx/admin.lorapms.com.conf \
  "control-panel Nginx template"
assert_root_release_artifact \
  deploy/nginx/fastcgi-buffers.conf \
  "fastcgi buffer config"
printf '%s  %s\n%s  %s\n' \
  "$control_panel_script_sha" deploy/scripts/configure-control-panel-domain.sh \
  "$control_panel_template_sha" deploy/nginx/admin.lorapms.com.conf \
  | sha256sum --check --strict
printf '%s  %s\n' "$fastcgi_buffers_sha" deploy/nginx/fastcgi-buffers.conf \
  | sha256sum --check --strict
PHP_FPM_SOCKET="$php_fpm_socket" \
  bash deploy/scripts/configure-control-panel-domain.sh
assert_app_filesystem_security
assert_production_config
assert_production_database_privileges
current_mysql_fingerprint="$(production_database_fingerprint)"
[ "$(printf '%s' "$current_mysql_fingerprint" | sha256sum | awk '{print $1}')" = "$rehearsal_mysql_fingerprint_sha256" ] \
  || fail "production MySQL settings changed before migrations."

run_as_app php artisan migrate --force
assert_no_pending_migrations
release_batch="$(run_as_app php artisan tinker --execute='echo DB::table("migrations")->max("batch") ?? 0;')"
case "$release_batch" in
  ''|*[!0-9]*) fail "invalid release migration batch: $release_batch" ;;
esac
[ "$release_batch" -ge "$previous_batch" ] \
  || fail "release migration batch is older than the pre-deploy batch."
printf 'RELEASE_COMMIT=%s\nRELEASE_BATCH=%s\n' \
  "$tested_sha" "$release_batch" >> "$state_file"
run_as_app php artisan tenants:verify-integrity \
  --verify-storage \
  --compare="$integrity_work_file" \
  --allow-additive-schema \
  --allow-additive-settings
rm -f -- "$integrity_work_file"
integrity_work_file=
rm -rf -- "$handoff_dir"
handoff_dir=

run_as_app php artisan config:cache
run_as_app php artisan route:cache
run_as_app php artisan view:cache
assert_production_config
assert_no_pending_migrations
if [ "$candidate_passport_required" = true ]; then
  assert_candidate_passport_runtime_configuration
  assert_passport_fingerprint_matches_marker
fi
run_as_app php artisan queue:restart
chown -R "$app_user:$app_user" storage bootstrap/cache
release_tmp="$release_dir/.current-${tested_sha}-$$"
install -o root -g "$app_user" -m 0640 /dev/null "$release_tmp"
printf '%s\n' "$tested_sha" > "$release_tmp"
mv -- "$release_tmp" "$release_file"
release_tmp=
[ "$(stat -c '%U:%G:%a' "$release_file")" = "root:www-data:640" ] \
  || fail "release identity file permissions are invalid."
[ "$(tr -d '\r\n' < "$release_file")" = "$tested_sha" ] \
  || fail "release identity file does not contain the tested commit."

php_fpm_is_stopped && php_fpm_process_is_absent \
  && php_fpm_listener_is_absent \
  && queue_is_stopped && queue_process_is_absent \
  && [ ! -e "$scheduler_file" ] && [ ! -L "$scheduler_file" ] \
  && scheduler_process_is_absent && cron_is_stopped \
  && runtime_writer_fence_is_loaded \
  || fail "writer fence changed before candidate exposure."
if [ "$candidate_passport_required" = true ]; then
  assert_candidate_passport_runtime_configuration
  assert_passport_fingerprint_matches_marker
fi

# This is the first point where candidate PHP can receive HTTP.
# Mark before exposure so an unsafe DB rollback is always refused.
record_possible_exposure \
  || fail "candidate exposure marker could not be made durable."
remove_runtime_fence "$php_fpm_service" "$php_fpm_fence_file" \
  || fail "PHP-FPM start-fence could not be removed safely."
timeout 30s systemctl start "$php_fpm_service"
systemctl is-active --quiet "$php_fpm_service" \
  || fail "PHP-FPM did not start for the maintenance smoke test."
php_fpm_pid="$(systemctl show --property=MainPID --value "$php_fpm_service")"
[[ "$php_fpm_pid" =~ ^[1-9][0-9]*$ ]] \
  || fail "PHP-FPM did not expose a live MainPID."
[ -S "$php_fpm_socket" ] \
  || fail "the expected PHP-FPM socket was not recreated."
php_fpm_listener_is_present \
  || fail "the expected PHP-FPM socket has no listener."

install -m 0644 deploy/nginx/fastcgi-buffers.conf /etc/nginx/conf.d/villamucho-fastcgi-buffers.conf
nginx -t
systemctl reload nginx

app_url="$(run_as_app php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo rtrim((string) config("app.url"), "/");')"
case "$app_url" in
  https://*) ;;
  *) fail "APP_URL is not HTTPS." ;;
esac

curl_https --location --cookie-jar "$smoke_cookie" \
  "$app_url/$maintenance_secret" > /dev/null
curl_https --header 'Accept: application/json' --cookie "$smoke_cookie" \
  "$app_url/up" > /dev/null
deployed_release="$(curl_https --cookie "$smoke_cookie" "$app_url/up/release")"
[ "$deployed_release" = "$tested_sha" ] \
  || fail "maintenance smoke returned release $deployed_release instead of $tested_sha."

remove_runtime_fence "$queue_service" "$queue_fence_file" \
  || fail "queue start-fence could not be removed safely."
timeout 30s systemctl start "$queue_service"
systemctl is-active --quiet "$queue_service" \
  || fail "queue service did not start."
queue_pid="$(systemctl show --property=MainPID --value "$queue_service")"
[[ "$queue_pid" =~ ^[1-9][0-9]*$ ]] \
  || fail "queue service did not expose a live MainPID."
[ -f "$scheduler_hold" ] || fail "paused scheduler file is missing."
[ ! -e "$scheduler_file" ] || fail "active scheduler file unexpectedly exists."
mv -- "$scheduler_hold" "$scheduler_file"
[ -f "$scheduler_file" ] || fail "scheduler file was not restored."
remove_runtime_fence "$cron_service" "$cron_fence_file" \
  || fail "cron start-fence could not be removed safely."
timeout 30s systemctl start "$cron_service"
systemctl is-active --quiet "$cron_service" \
  || fail "cron service is not active after scheduler restore."
cron_pid="$(systemctl show --property=MainPID --value "$cron_service")"
[[ "$cron_pid" =~ ^[1-9][0-9]*$ ]] \
  || fail "cron service did not expose a live MainPID."

run_as_app php artisan up
traffic_opened_at="$(date --utc +%Y-%m-%dT%H:%M:%SZ)"
printf 'TRAFFIC_OPENED_AT_UTC=%s\n' "$traffic_opened_at" >> "$state_file"
sync "$state_file"

curl_https --header 'Accept: application/json' "$app_url/up" > /dev/null
public_release="$(curl_https "$app_url/up/release")"
[ "$public_release" = "$tested_sha" ] \
  || fail "public smoke returned release $public_release instead of $tested_sha."

printf 'DEPLOYED_AT_UTC=%s\n' "$(date --utc +%Y-%m-%dT%H:%M:%SZ)" >> "$state_file"
sync "$state_file"
[ ! -e "$php_fpm_fence_file" ] && [ ! -L "$php_fpm_fence_file" ] \
  && [ ! -e "$queue_fence_file" ] && [ ! -L "$queue_fence_file" ] \
  && [ ! -e "$cron_fence_file" ] && [ ! -L "$cron_fence_file" ] \
  && [ ! -e "$writer_fence_sentinel" ] && [ ! -L "$writer_fence_sentinel" ] \
  || fail "release writer fences were not removed after successful startup."
deploy_succeeded=true
trap - EXIT HUP INT TERM
rm -f -- "$smoke_cookie"
echo "Deployed tested commit $tested_sha; state: $state_file"
