#!/usr/bin/env bash
# デモの公開。https://proto.exbridge.jp/kcrmagent/
# DeepSeekキーとAPIトークンはこのスクリプトが注入する(リポジトリに置かない)。
set -euo pipefail
cd "$(dirname "$0")/.."
php scripts/check_kcrmagent.php >/dev/null || { echo "自己テスト失敗→デプロイ中止" >&2; exit 1; }
set -a; . /home/kojima/work/aixec/.env; set +a
KEY=$(grep -m1 '^KCBRAIN_DEEPSEEK_API_KEY=' /home/kojima/work/kcbrain/.env | cut -d= -f2)
[ -n "$KEY" ] || { echo "DeepSeekキーが見つからない" >&2; exit 1; }
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
