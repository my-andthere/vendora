#!/usr/bin/env bash
# Menjalankan aplikasi secara online:
#   1. Container MariaDB + Apache/PHP (stack lokal, port 8080)
#   2. Cloudflare Tunnel (URL publik https://xxx.trycloudflare.com)
#
# Pakai:  ./start.sh          -> start semua + cetak URL publik
#         ./start.sh stop     -> stop tunnel + container
#         ./start.sh logs     -> log container web
set -euo pipefail
cd "$(dirname "$0")"

case "${1:-start}" in
  stop)
    docker rm -f vendora-tunnel >/dev/null 2>&1 || true
    docker compose down
    echo "Stopped."
    exit 0
    ;;
  logs)
    docker compose logs -f web
    exit 0
    ;;
esac

echo "==> Menjalankan container (MariaDB + Apache/PHP)"
docker compose up -d --build

echo "==> Menjalankan Cloudflare Tunnel"
docker rm -f vendora-tunnel >/dev/null 2>&1 || true
docker run -d --name vendora-tunnel --network host \
  cloudflare/cloudflared:latest tunnel --no-autoupdate --url http://127.0.0.1:"${WEB_PORT:-8080}" >/dev/null

echo -n "==> Menunggu URL publik"
URL=""
for i in $(seq 1 30); do
  sleep 2
  URL=$(docker logs vendora-tunnel 2>&1 | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | head -1)
  if [ -n "$URL" ]; then break; fi
  echo -n "."
done
echo
if [ -z "$URL" ]; then
  echo "Gagal mendapatkan URL tunnel. Cek: docker logs vendora-tunnel"
  exit 1
fi

echo
echo "=============================================="
echo "  APLIKASI ONLINE: $URL"
echo "  Login: admin / admin123   (ganti password setelah masuk)"
echo "  API NOC: $URL/api/tickets.php  (header X-API-KEY)"
echo "=============================================="
