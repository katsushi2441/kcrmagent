<?php
/** デモ環境の設定(https://proto.exbridge.jp/kcrmagent/)。APIキーはデプロイ時に注入される。 */
define('KCA_TITLE',       'Kurage CRM Agent デモ');
define('KCA_BRAND_COLOR', '#1b6d8c');
define('KCA_PASSWORD',    'demo2026');
define('KCA_PASSWORD_HASH', '');
define('KCA_API_TOKEN',   '__KCA_API_TOKEN__');
// デモの AI は gemma4（当社サーバーの共通の中継 :18343 経由）。**デモで DeepSeek を使わない**（有料サービス専用）。
define('KCA_API_BASE',    'http://exbridge.ddns.net:18343/v1');
define('KCA_API_KEY',     '__KCA_API_KEY__');
define('KCA_MODEL',       'gemma4:12b-it-qat');
define('KCA_STAGES',      '新規,提案中,受注,失注');
define('KCA_ACT_TYPES',   '訪問,電話,メール,オンライン,メモ');
define('KCA_RATE_PER_HOUR', 8);
define('KCA_INPUT_MAX',   1000);
define('KCA_DEMO',        true);
