#!/bin/sh
# Checks a running stack against its hardening requirements. Start the stack first
# (docker compose up -d). Set DOCKER="sudo docker" if your user can't reach the daemon.
# Exits non-zero if any check fails.
set -u
cd "$(dirname "$0")"

DOCKER=${DOCKER:-docker}
COMPOSE="$DOCKER compose"
failures=0

pass() { echo "ok    $1"; }
fail() { echo "FAIL  $1"; failures=$((failures + 1)); }
check() { # check "description" command...
  desc=$1; shift
  if "$@" >/dev/null 2>&1; then pass "$desc"; else fail "$desc"; fi
}

check "compose file is valid" $COMPOSE config --quiet

services="panel database cache"

for svc in $services; do
  id=$($COMPOSE ps -q "$svc")
  if [ -z "$id" ]; then
    fail "$svc is running"
    continue
  fi

  health=$($DOCKER inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$id")
  [ "$health" = healthy ] && pass "$svc is healthy" || fail "$svc is healthy (status: ${health:-none})"

  inspect() { $DOCKER inspect -f "$1" "$id"; }
  [ "$(inspect '{{.HostConfig.ReadonlyRootfs}}')" = true ] && pass "$svc has a read-only root filesystem" || fail "$svc has a read-only root filesystem"
  inspect '{{json .HostConfig.SecurityOpt}}' | grep -q 'no-new-privileges' && pass "$svc sets no-new-privileges" || fail "$svc sets no-new-privileges"
  [ "$(inspect '{{json .HostConfig.CapDrop}}')" = '["ALL"]' ] && pass "$svc drops all capabilities" || fail "$svc drops all capabilities"
  case "$(inspect '{{json .HostConfig.CapAdd}}')" in null|'[]') pass "$svc adds no capabilities" ;; *) fail "$svc adds no capabilities" ;; esac
  user=$(inspect '{{.Config.User}}')
  case "$user" in ''|root|0|0:*) fail "$svc runs as a non-root user (user: '${user}')" ;; *) pass "$svc runs as a non-root user ($user)" ;; esac
  [ "$(inspect '{{.HostConfig.Memory}}')" -gt 0 ] && pass "$svc has a memory limit" || fail "$svc has a memory limit"
  [ "$(inspect '{{if .HostConfig.PidsLimit}}{{.HostConfig.PidsLimit}}{{else}}0{{end}}')" -gt 0 ] && pass "$svc has a PID limit" || fail "$svc has a PID limit"
done

# Only the panel publishes a port.
[ -z "$($COMPOSE port database 3306 2>/dev/null)" ] && pass "database publishes no port" || fail "database publishes no port"
[ -z "$($COMPOSE port cache 6379 2>/dev/null)" ] && pass "cache publishes no port" || fail "cache publishes no port"
bound=$($COMPOSE port panel 8080 2>/dev/null)
case "$bound" in
  127.0.0.1:*) pass "panel is published on loopback only ($bound)" ;;
  '') fail "panel is published" ;;
  *) echo "note  panel is published on $bound (PANEL_BIND is set)" ;;
esac

# Network reachability.
check "panel reaches the database" $COMPOSE exec -T panel nc -z -w 3 database 3306
check "panel reaches the cache" $COMPOSE exec -T panel nc -z -w 3 cache 6379
check "panel reaches the internet" $COMPOSE exec -T panel wget -q -T 10 -O /dev/null https://cdn.pterodactyl.io/releases/latest.json
if $COMPOSE exec -T database timeout 5 bash -c 'exec 3<>/dev/tcp/1.1.1.1/443' >/dev/null 2>&1; then
  fail "database cannot reach the internet"
else
  pass "database cannot reach the internet"
fi

# Redis ACL: blocked commands are refused, and the panel's own use of Redis still works.
$COMPOSE exec -T cache redis-cli flushall 2>&1 | grep -q NOPERM && pass "cache refuses FLUSHALL" || fail "cache refuses FLUSHALL"
$COMPOSE exec -T cache redis-cli config get maxmemory 2>&1 | grep -q NOPERM && pass "cache refuses CONFIG" || fail "cache refuses CONFIG"
check "php artisan cache:clear works" $COMPOSE exec -T panel php artisan cache:clear
# Queue a real job (artisan cache:forget) for a key we set, and wait for the worker to run it.
tinker() { $COMPOSE exec -T -e HOME=/tmp panel php artisan tinker --execute "$1" 2>/dev/null | tr -d '\r\n'; }
tinker "cache()->put('verify-queue', 'pending', 300); Artisan::queue('cache:forget', ['key' => 'verify-queue']);" >/dev/null
queued=pending
for _ in 1 2 3 4 5 6 7 8 9 10; do
  queued=$(tinker "echo cache()->get('verify-queue', 'gone');")
  [ "$queued" = gone ] && break
  sleep 3
done
[ "$queued" = gone ] && pass "queue worker processes jobs" || fail "queue worker processes jobs"

# Secrets stay out of git.
check ".env is ignored by git" git check-ignore -q .env
check "panel.env is ignored by git" git check-ignore -q panel.env

if [ "$failures" -gt 0 ]; then
  echo "$failures check(s) failed."
  exit 1
fi
echo "All checks passed."
