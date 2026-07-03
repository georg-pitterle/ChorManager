#!/bin/sh
set -eu

# Startup helper for the custom ChorManager SnappyMail image. Runs in the
# background next to the image's real entrypoint (see the Dockerfile CMD) - it
# must not block or replace it.
#
# Responsibilities, in order:
#   1. Pass SNAPPYMAIL_SSO_SECRET through to php-fpm workers.
#   2. Sync the baked plugin into the data volume (so image updates apply even
#      when the named volume already exists).
#   3. Enable the plugin in application.ini.

SRC_PLUGIN="/opt/chormanager-sso/chormanager-sso"
PLUGINS_DIR="/var/lib/snappymail/_data_/_default_/plugins"
DEST_PLUGIN="${PLUGINS_DIR}/chormanager-sso"
CONFIG_FILE="/var/lib/snappymail/_data_/_default_/configs/application.ini"
PLUGIN_NAME="chormanager-sso"
TIMEOUT_SECONDS=30
WAITED=0

# --- 1. php-fpm env passthrough -------------------------------------------
#
# php-fpm clears the worker process environment by default (no "clear_env = no"
# and no "env[...]" directive in the image's active pool config), so
# getenv('SNAPPYMAIL_SSO_SECRET') inside a plugin request would return "".
# Mirror the image's own pattern by appending an explicit env[] passthrough.
#
# entrypoint.sh sed-edits this same file's <UPLOAD_MAX_SIZE>/<MEMORY_LIMIT>
# placeholders within its first few lines. Appending before those substitutions
# complete loses a race (the concurrent sed -i rewrites the file from a version
# read before our append). So wait until both placeholders are gone first.
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

# Idempotent. The value MUST be double-quoted: base64 secrets routinely contain
# "+", "/" and trailing "=" padding, and an unquoted "=" inside a php-fpm env[]
# value breaks its ini-style parser.
if [ -f "${FPM_POOL_CONFIG}" ] && ! grep -q '^env\[SNAPPYMAIL_SSO_SECRET\]' "${FPM_POOL_CONFIG}"; then
    echo "env[SNAPPYMAIL_SSO_SECRET] = \"${SNAPPYMAIL_SSO_SECRET:-}\"" >> "${FPM_POOL_CONFIG}"
    echo "[chormanager-enable-plugin] Added SNAPPYMAIL_SSO_SECRET passthrough to ${FPM_POOL_CONFIG}."
fi

# --- 2. wait for the data volume, then sync the baked plugin ---------------
echo "[chormanager-enable-plugin] Waiting for ${CONFIG_FILE} to appear..."
while [ ! -f "${CONFIG_FILE}" ]; do
    if [ "${WAITED}" -ge "${TIMEOUT_SECONDS}" ]; then
        echo "[chormanager-enable-plugin] ERROR: ${CONFIG_FILE} did not appear within ${TIMEOUT_SECONDS}s" >&2
        exit 1
    fi
    sleep 1
    WAITED=$((WAITED + 1))
done

# Refresh the plugin code from the baked copy on every boot. Only the code dir
# is replaced; runtime state (replay markers, domain configs) lives elsewhere
# under _data_/_default_/ and is untouched.
mkdir -p "${PLUGINS_DIR}"
rm -rf "${DEST_PLUGIN}"
cp -a "${SRC_PLUGIN}" "${DEST_PLUGIN}"
chown -R www-data:www-data "${DEST_PLUGIN}"
echo "[chormanager-enable-plugin] Synced baked plugin into ${DEST_PLUGIN}."

# --- 3. enable the plugin in application.ini -------------------------------
echo "[chormanager-enable-plugin] Ensuring plugin is enabled..."

# Idempotently switch "enable = Off" to "enable = On" within the [plugins]
# section and add the plugin to enabled_list. Restrict the substitution to the
# [plugins] section so we never touch an unrelated "enable = Off/On" line.
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
