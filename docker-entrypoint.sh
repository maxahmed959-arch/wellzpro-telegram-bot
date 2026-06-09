#!/bin/sh
set -e
mkdir -p data/sessions data/orders data/admins data/notify_map data/apk_queue data/apk_locks

# Render يضبط PORT دائماً — لا نعتمد فقط على RENDER_EXTERNAL_URL (قد يتأخر)
use_web=0
if [ -n "${PORT}" ] || [ -n "${RENDER_EXTERNAL_URL}" ] || [ -n "${BOT_PUBLIC_URL}" ] || [ -n "${RENDER_SERVICE_ID}" ]; then
  use_web=1
fi

if [ "$use_web" = "1" ]; then
  listen_port="${PORT:-8080}"
  echo "[entrypoint] Cloud/web mode — http://0.0.0.0:${listen_port}/"
  # الويب هوك في الخلفية حتى يمر health check فوراً (لا يعلق الإقلاع)
  (
    sleep 1
    php scripts/set-webhook.php 2>&1 || echo "[entrypoint] webhook setup failed — راجع TELEGRAM_BOT_TOKEN"
  ) &
  exec php -S "0.0.0.0:${listen_port}" -t public
fi

echo "[entrypoint] Worker mode — long polling (محلي فقط)"
exec php bot.php
