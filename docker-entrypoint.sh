#!/bin/sh
set -e
mkdir -p data/sessions data/orders data/admins data/notify_map

if [ -n "$RENDER_EXTERNAL_URL" ] || [ -n "$BOT_PUBLIC_URL" ]; then
  echo "[entrypoint] Cloud mode — registering Telegram webhook..."
  php scripts/set-webhook.php || echo "[entrypoint] webhook setup failed (retry from Render shell)"
  exec php -S "0.0.0.0:${PORT:-8080}" -t public
fi

echo "[entrypoint] Worker mode — long polling (delete webhook)..."
exec php bot.php
