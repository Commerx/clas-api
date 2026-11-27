#!/usr/bin/env bash
#
# Anonymise a production copy of the CLAS database for use on the dev server.
#
#   ./scrub-dev-copy.sh clas
#
# The SQL is docs/sql/scrub-dev-copy.sql; this script is the part that plain
# SQL cannot do — refusing to run in the wrong place. MySQL has no way to
# abort a script file on a condition, so the guards live here.
#
# Three of them, in order of how much they matter:
#
#   1. The hostname must look like a dev box, or DEV_HOST_OK=1 must be set.
#      This is the one standing between a mistyped command and deleting
#      every customer's email address in production.
#   2. The database must not be reachable as the production host. Checked by
#      refusing when MYSQL_HOST points anywhere but localhost unless you say
#      otherwise.
#   3. You have to type the database name back. Not a y/n, because y/n is
#      what people press without reading.
#
# Nothing here is undoable. Take the dump first.

set -euo pipefail

DB="${1:-}"
MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_USER="${MYSQL_USER:-root}"

SQL="$(dirname "$0")/docs/sql/scrub-dev-copy.sql"

say()  { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
die()  { printf '\n\033[1;31mREFUSED: %s\033[0m\n\n' "$1" >&2; exit 1; }

[ -n "$DB" ]      || die "name the database: ./scrub-dev-copy.sh <database>"
[ -f "$SQL" ]     || die "cannot find $SQL"

# ---- guard 1: where are we -------------------------------------------------
HOST="$(hostname)"
if [ "${DEV_HOST_OK:-0}" != "1" ]; then
    case "$HOST" in
        *dev*|*staging*|*test*) ;;
        *) die "hostname '$HOST' does not look like a dev server.
         If it really is one, re-run with DEV_HOST_OK=1." ;;
    esac
fi

# ---- guard 2: which server -------------------------------------------------
case "$MYSQL_HOST" in
    127.0.0.1|localhost|::1) ;;
    *) [ "${REMOTE_DB_OK:-0}" = "1" ] \
        || die "MYSQL_HOST is '$MYSQL_HOST', which is not this machine.
         Scrubbing across the network is how the wrong database gets hit.
         If you are certain, re-run with REMOTE_DB_OK=1." ;;
esac

# ---- what is about to happen ----------------------------------------------
say "About to anonymise '$DB' on $MYSQL_HOST as $MYSQL_USER"

mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" -p "$DB" -N -e "
SELECT CONCAT('    accounts                 ', COUNT(*)) FROM user;
SELECT CONCAT('    of which @commerx.com    ', COUNT(*)) FROM user WHERE email LIKE '%@commerx.com';
SELECT CONCAT('    driver licence numbers   ', COUNT(*)) FROM profile WHERE driver_license_number IS NOT NULL AND driver_license_number <> '';
SELECT CONCAT('    phone numbers            ', COUNT(*)) FROM profile WHERE phone IS NOT NULL AND phone <> '';
SELECT CONCAT('    moneris vault tokens     ', COUNT(*)) FROM user_subscription WHERE data_key IS NOT NULL AND data_key <> '';
SELECT CONCAT('    device push tokens       ', COUNT(*)) FROM device_tokens;
" || die "could not read '$DB' — wrong name, or wrong credentials"

cat <<'WARNING'

    Every one of those, except the @commerx.com accounts, is about to be
    overwritten. There is no undo. If you have not taken a dump, stop now.

WARNING

printf "    Type the database name to continue: "
read -r typed
[ "$typed" = "$DB" ] || die "you typed '$typed', not '$DB'"

# ---- do it -----------------------------------------------------------------
say "Scrubbing"
mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" -p "$DB" < "$SQL"

say "Done"
cat <<'AFTER'
    Every count above should be zero except the Commerx one.

    Two things still to do before this server can be used:

      * MAIL_MAILER. Scrubbing means nothing can reach a customer, but
        keeping it on `log` for the first run is the belt to that braces.
      * Dry-run the scheduler before adding the cron entry:
            php artisan clas:send-renewal-reminders --dry-run
            php artisan clas:renew-subscriptions --dry-run

    The dev database is still confidential after this. Alert positions
    (profile.user_lat / user_lng) and listing addresses are deliberately
    kept, because the features under test need them.
AFTER
