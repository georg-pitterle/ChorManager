#!/bin/sh
set -eu

# Idempotently enables the chormanager-sso plugin in SnappyMail's
# application.ini once the image's entrypoint has generated it on first
# boot. Runs in the background alongside the image's real entrypoint (see
# .ddev/docker-compose.snappymail.yaml command: override) - it must not
# block or replace it.

CONFIG_FILE="/var/lib/snappymail/_data_/_default_/configs/application.ini"
PLUGIN_NAME="chormanager-sso"
TIMEOUT_SECONDS=30
WAITED=0

# php-fpm clears the worker process environment by default (no "clear_env = no"
# and no "env[...]" directive in the image's active pool config, confirmed by
# reading /usr/local/etc/php-fpm.d/php-fpm.conf) - so getenv('SNAPPYMAIL_SSO_SECRET')
# inside a plugin request always returned "" even though the shell/container
# environment had it set correctly. Mirror the image's own established pattern
# (entrypoint.sh sed-edits this same file's <UPLOAD_MAX_SIZE>/<MEMORY_LIMIT>
# placeholders) by appending an explicit env[] passthrough line here, before
# php-fpm starts via the later "exec supervisord" in entrypoint.sh.
#
# This script and entrypoint.sh both start concurrently (see command: override
# in docker-compose.snappymail.yaml) and entrypoint.sh edits this SAME file
# with "sed -i" within its first few lines. Appending here without
# synchronizing against that lost the race in practice (confirmed empirically:
# the appended line was present right after our own `echo >>`, but had
# disappeared again a few seconds later once the container was fully up -
# entrypoint.sh's sed -i had run concurrently and rewritten the file from a
# version it read before our append landed). So: wait until entrypoint.sh's
# <UPLOAD_MAX_SIZE>/<MEMORY_LIMIT> placeholders are gone (proving both of its
# sed -i calls against this file have already completed) before appending -
# this guarantees no further writes to this file are still in flight from
# entrypoint.sh ahead of php-fpm actually starting.
FPM_POOL_CONFIG="/usr/local/etc/php-fpm.d/php-fpm.conf"
FPM_WAIT_SECONDS=0
FPM_WAIT_TIMEOUT_SECONDS=30
while grep -q '<UPLOAD_MAX_SIZE>\|<MEMORY_LIMIT>' "${FPM_POOL_CONFIG}" 2>/dev/null; do
    if [ "${FPM_WAIT_SECONDS}" -ge "${FPM_WAIT_TIMEOUT_SECONDS}" ]; then
        echo "[chormanager-enable-plugin] ERROR: ${FPM_POOL_CONFIG} placeholders never got substituted within ${FPM_WAIT_TIMEOUT_SECONDS}s" >&2
        exit 1
    fi
    sleep 1
    FPM_WAIT_SECONDS=$((FPM_WAIT_SECONDS + 1))
done

# Idempotent: skip if the line is already present (e.g. on a second run of
# this script). The value MUST be double-quoted: base64 secrets routinely
# contain "+", "/" and trailing "=" padding, and an unquoted "=" inside a
# php-fpm env[] value breaks its ini-style parser (confirmed empirically: an
# unquoted value caused "PHP: syntax error, unexpected '=' in Unknown on line
# 1" and php-fpm refused to start at all).
if [ -f "${FPM_POOL_CONFIG}" ] && ! grep -q '^env\[SNAPPYMAIL_SSO_SECRET\]' "${FPM_POOL_CONFIG}"; then
    echo "env[SNAPPYMAIL_SSO_SECRET] = \"${SNAPPYMAIL_SSO_SECRET:-}\"" >> "${FPM_POOL_CONFIG}"
    echo "[chormanager-enable-plugin] Added SNAPPYMAIL_SSO_SECRET passthrough to ${FPM_POOL_CONFIG}."
fi

echo "[chormanager-enable-plugin] Waiting for ${CONFIG_FILE} to appear..."
while [ ! -f "${CONFIG_FILE}" ]; do
    if [ "${WAITED}" -ge "${TIMEOUT_SECONDS}" ]; then
        echo "[chormanager-enable-plugin] ERROR: ${CONFIG_FILE} did not appear within ${TIMEOUT_SECONDS}s" >&2
        exit 1
    fi
    sleep 1
    WAITED=$((WAITED + 1))
done

echo "[chormanager-enable-plugin] Found ${CONFIG_FILE}, ensuring plugin is enabled..."

# Idempotently switch "enable = Off" to "enable = On" within the [plugins]
# section. SnappyMail's generated file uses one "[section]" block per
# section with "key = value" lines; sections are separated by blank lines.
# Restrict the substitution to the [plugins] section so we never touch an
# unrelated "enable = Off/On" line in another section.
awk -v plugin="${PLUGIN_NAME}" '
    BEGIN { in_plugins = 0 }
    /^\[/ {
        in_plugins = ($0 == "[plugins]")
        print
        next
    }
    in_plugins && /^enable[[:space:]]*=/ {
        print "enable = On"
        next
    }
    in_plugins && /^enabled_list[[:space:]]*=/ {
        line = $0
        sub(/^enabled_list[[:space:]]*=[[:space:]]*/, "", line)
        gsub(/^"|"$/, "", line)
        gsub(/^[[:space:]]+|[[:space:]]+$/, "", line)
        if (line == "") {
            list = plugin
        } else {
            found = 0
            n = split(line, parts, ",")
            for (i = 1; i <= n; i++) {
                if (parts[i] == plugin) {
                    found = 1
                }
            }
            if (found) {
                list = line
            } else {
                list = line "," plugin
            }
        }
        print "enabled_list = \"" list "\""
        next
    }
    { print }
' "${CONFIG_FILE}" > "${CONFIG_FILE}.chormanager-tmp"

mv "${CONFIG_FILE}.chormanager-tmp" "${CONFIG_FILE}"

echo "[chormanager-enable-plugin] Plugin '${PLUGIN_NAME}' enabled in ${CONFIG_FILE}."
