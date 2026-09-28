#!/usr/bin/env bash
# デモの公開。https://proto.exbridge.jp/kcrmagent/
# デモの AI は gemma4。共通の gemma4 中継(:18343)の合言葉と APIトークンをこのスクリプトが注入する(リポジトリに置かない)。
# **デモで DeepSeek を使わない**(有料サービス専用。2026-09-29 まで DeepSeek のキーを入れていた)。
set -euo pipefail
cd "$(dirname "$0")/.."
php scripts/check_kcrmagent.php >/dev/null || { echo "自己テスト失敗→デプロイ中止" >&2; exit 1; }
set -a; . /home/kojima/work/aixec/.env; set +a
KEY=$(grep -m1 '^RELAY_CLIENT_KCRMAGENT=' /home/kojima/work/kaima/.env | cut -d= -f2)
[ -n "$KEY" ] || { echo "中継の合言葉が見つからない(kaima/.env の RELAY_CLIENT_KCRMAGENT)" >&2; exit 1; }
grep -q "api.deepseek.com" demo/kcrmagent_config.php && { echo "デモの設定が DeepSeek を向いている。止める" >&2; exit 1; }
curl -s -m 10 http://127.0.0.1:18343/healthz | grep -q kcrmagent || { echo "共通の gemma4 中継(18343)に kcrmagent が登録されていない" >&2; exit 1; }
APITOKEN=$(grep -m1 '^KCA_DEMO_API_TOKEN=' .env | cut -d= -f2)
[ -n "$APITOKEN" ] || { echo "KCA_DEMO_API_TOKEN が .env にない" >&2; exit 1; }
remote="/web/proto_exbridge_jp/kcrmagent"
up() { curl --fail --silent --show-error --ftp-create-dirs -T "$1" \
  "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${2}"; echo "up: $2"; }
tmp=$(mktemp)
sed -e "s/__KCA_API_KEY__/${KEY}/" -e "s/__KCA_API_TOKEN__/${APITOKEN}/" demo/kcrmagent_config.php > "$tmp"
up public/kcrmagent.php kcrmagent.php
up "$tmp" kcrmagent_config.php
rm -f "$tmp"
up demo/index.php index.php
up demo/.htaccess .htaccess
up public/kca_data/.htaccess kca_data/.htaccess
echo "published: https://proto.exbridge.jp/kcrmagent/"
