# Custom Tachyon image for ChorManager.
#
# Tachyon (https://github.com/kimusan/Tachyon) is the maintained fork of the
# discontinued SnappyMail; data directory and config layout are unchanged.
#
# Bakes the chormanager-sso SSO plugin into the image so that NO host-side
# bind-mounts are required - this is what makes the stack usable from the
# Portainer web editor (where you cannot place files next to the compose
# file).
#
# Pin the base tag deliberately; bump it consciously instead of tracking a
# floating tag.
FROM ghcr.io/kimusan/tachyon:v3.2.2

# The plugin source is baked OUTSIDE the /var/lib/tachyon volume. The
# startup script copies it into the volume on every boot, so pulling a new
# image actually updates the plugin even when the named volume already exists.
COPY chormanager-sso /opt/chormanager-sso/chormanager-sso
COPY enable-plugin.sh /chormanager-enable-plugin.sh

# The base image declares `ENTRYPOINT []` and `CMD ["/entrypoint.sh"]`, so
# overriding CMD fully replaces startup. We launch our idempotent enabler in
# the background and then exec the image's real entrypoint unchanged.
CMD ["sh", "-c", "sh /chormanager-enable-plugin.sh & exec /entrypoint.sh"]
