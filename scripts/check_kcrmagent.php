<?php
/**
 * kcrmagent の自己テスト。デプロイ前に実行する:  php scripts/check_kcrmagent.php
 * AIは呼ばない(AI出力はフィクスチャで与え、関門・検証・反映のロジックを機械検証する)。
 */
error_reporting(E_ALL);
$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok($name, $cond, $note = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $name\n"; }
    else { $fail++; echo "  NG  $name" . ($note ? " ($note)" : '') . "\n"; }
}

echo "== kcrmagent check ==\n";

ok('本体 kcrmagent.php が存在', is_file($root . '/public/kcrmagent.php'));
$lint = (string)shell_exec('php -l ' . escapeshellarg($root . '/public/kcrmagent.php') . ' 2>&1');
ok('本体のPHP構文', strpos($lint, 'No syntax errors') !== false, trim($lint));
ok('設定example が存在', is_file($root . '/public/kcrmagent_config.php.example'));
ok('デモ設定が存在', is_file($root . '/demo/kcrmagent_config.php'));
ok('kca_data保護(.htaccess deny)', trim((string)file_get_contents($root . '/public/kca_data/.htaccess')) === 'Require all denied');

// ---- テスト用設定(一時データディレクトリ) ----
$tmp = sys_get_temp_dir() . '/kca_test_' . getmypid();
@mkdir($tmp, 0755, true);
define('KCA_TITLE', 'テスト');
define('KCA_PASSWORD', 'testpw');
define('KCA_API_TOKEN', 'testtoken');
define('KCA_API_KEY', 'sk-not-used-in-tests');
define('KCA_STAGES', '新規,提案中,受注,失注');
define('KCA_ACT_TYPES', '訪問,電話,メール,オンライン,メモ');
define('KCA_RATE_PER_HOUR', 3);
define('KCA_DEMO', false);
define('KCA_DATA_DIR', $tmp);

// 本体の関数部だけ読み込む(外部API・画面は実行しない)
$src = file_get_contents($root . '/public/kcrmagent.php');
$cut = strpos($src, '/* ================= 外部API');
ok('関数部の切り出しマーカー', $cut !== false);
$funcs = substr($src, 0, $cut);
$funcs = preg_replace('/\$cfg = __DIR__.*?require \$cfg;/s', '', str_replace('<?php', '', $funcs), 1);
eval($funcs);

// ---- 関門(宣言表) ----
ok('関門: aiは下書き作成のみ可', kca_can('ai', 'draft.create') === true);
ok('関門: aiは台帳に書けない', !kca_can('ai', 'company.create') && !kca_can('ai', 'deal.set_stage'));
ok('関門: aiは承認できない', !kca_can('ai', 'draft.approve'));
ok('関門: apiは日報投入のみ可', kca_can('api', 'report.create') && !kca_can('api', 'draft.approve') && !kca_can('api', 'company.create'));
ok('関門: userは承認できる', kca_can('user', 'draft.approve') && kca_can('user', 'draft.reject'));
ok('関門: 未知のactorは全拒否', !kca_can('root', 'draft.approve'));
$denied = false;
try { kca_company_create('ai', 'AI直書きテスト'); } catch (KcaDenied $e) { $denied = true; }
ok('関門: aiの台帳直書きは例外で止まる', $denied);

// ---- 正規化・日付 ----
ok('会社名正規化: 全半角・空白ゆれ吸収', kca_norm('株式会社ＡＢＣ商事 ') === kca_norm('株式会社abc商事'));
ok('日付検証: 正常', kca_valid_date('2026-08-19') === true);
ok('日付検証: 不正日付を弾く', kca_valid_date('2026-02-30') === false && kca_valid_date('来週') === false);

// ---- スキーマ・日報投入 ----
$pdo = kca_pdo();
ok('SQLiteスキーマ初期化', (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn() >= 6);
$inboxId = kca_report_create('user', '今日は○○商事を訪問。', 'web');
ok('日報投入(人)', $inboxId > 0);
$inboxId2 = kca_report_create('api', 'API経由の日報。', 'api');
ok('日報投入(API)', $inboxId2 > 0);

// ---- 決定的検証(AIフィクスチャ) ----
kca_company_create('user', '○○商事');
$fixture = array('items' => array(
    array('company' => '○○商事',   // 既存(表記一致)
          'activities' => array(array('type' => '訪問', 'content' => '田中部長と面談', 'date' => '2026-08-19')),
          'deal' => array('existing_id' => null, 'title' => 'Web保守契約', 'stage' => '提案中',
                          'amount' => 500000, 'next_action' => '見積送付', 'next_date' => '2026-08-26')),
    array('company' => '△△工業',   // 新規会社
          'activities' => array(array('type' => '謎の種別', 'content' => '電話した', 'date' => '来週')),
          'deal' => array('existing_id' => 999, 'title' => '新案件', 'stage' => '存在しないステージ',
                          'amount' => 'たくさん', 'next_action' => '', 'next_date' => '')),
));
$v = kca_validate($pdo, $fixture);
$kinds = array();
foreach ($v['ops'] as $op) { $kinds[] = $op['op']; }
ok('検証: 既存会社は再作成しない', !in_array('company.create', array_slice($kinds, 0, 2)) || $v['ops'][0]['op'] !== 'company.create' || $v['ops'][0]['name'] !== '○○商事');
ok('検証: 新規会社が起票される', in_array('company.create', $kinds));
ok('検証: 商談・活動が起票される', in_array('deal.create', $kinds) && in_array('activity.create', $kinds));
$badDeal = null; $badAct = null;
foreach ($v['ops'] as $op) {
    if ($op['op'] === 'deal.create' && $op['company'] === '△△工業') { $badDeal = $op; }
    if ($op['op'] === 'activity.create' && $op['company'] === '△△工業') { $badAct = $op; }
}
ok('検証: 不正ステージは既定値に矯正', $badDeal && $badDeal['stage'] === '新規');
ok('検証: 不正金額はnullに', $badDeal && $badDeal['amount'] === null);
ok('検証: 実在しない商談idは新規扱い(idを信用しない)', $badDeal !== null);
ok('検証: 不正な活動種別はメモに矯正', $badAct && $badAct['type'] === 'メモ');
ok('検証: 不正日付は本日に矯正', $badAct && $badAct['date'] === date('Y-m-d'));
ok('検証: 警告が記録される', count($v['warns']) >= 3);
$v0 = kca_validate($pdo, array('items' => array()));
ok('検証: 空出力はops=0+警告', count($v0['ops']) === 0 && count($v0['warns']) === 1);

// ---- 下書き→承認→台帳反映 ----
$draftId = kca_draft_create('ai', $inboxId, $v['ops'], $v['warns']);
ok('下書き作成(actor=ai)', $draftId > 0);
$before = (int)$pdo->query('SELECT COUNT(*) FROM deals')->fetchColumn();
$r = kca_draft_approve('user', $draftId, 'tester');
ok('承認で台帳に反映', isset($r['applied']) && $r['applied'] >= 4);
ok('承認後: 商談が増えている', (int)$pdo->query('SELECT COUNT(*) FROM deals')->fetchColumn() > $before);
ok('承認後: 新規会社が台帳にある', kca_company_find($pdo, '△△工業') !== null);
$st = $pdo->prepare('SELECT status FROM drafts WHERE id=?'); $st->execute(array($draftId));
ok('承認後: 下書きstatus=approved', $st->fetchColumn() === 'approved');
$r2 = kca_draft_approve('user', $draftId, 'tester');
ok('二重承認は409で拒否', isset($r2['error']) && $r2['code'] === 409);

// ---- 却下 ----
$v2 = kca_validate($pdo, $fixture);
$draft2 = kca_draft_create('ai', $inboxId2, $v2['ops'], $v2['warns']);
$rr = kca_draft_reject('user', $draft2, 'tester');
ok('却下できる', isset($rr['ok']));
$rr2 = kca_draft_reject('user', $draft2, 'tester');
ok('二重却下は拒否', isset($rr2['error']));

// ---- 監査ログ ----
$nAudit = (int)$pdo->query('SELECT COUNT(*) FROM audit')->fetchColumn();
ok('監査ログが残る', $nAudit >= 5);
$aiWrites = (int)$pdo->query("SELECT COUNT(*) FROM audit WHERE actor='ai' AND action LIKE 'company.%'")->fetchColumn();
ok('監査: aiによる台帳書き込み記録が0件', $aiWrites === 0);

// ---- レート制限(上限3に設定済み) ----
$okCount = 0;
for ($i = 0; $i < 5; $i++) { if (kca_rate_ok('203.0.113.9')) { $okCount++; } }
ok('レート制限: 上限で止まる', $okCount === 3, "通過={$okCount}");

// ---- AIプロンプト(構造のみ・API呼び出しなし) ----
$ctx = kca_context($pdo);
$sp = kca_ai_system_prompt($ctx);
ok('プロンプト: 既存会社一覧を含む', strpos($sp, '○○商事') !== false);
ok('プロンプト: 本日日付を含む', strpos($sp, date('Y-m-d')) !== false);
ok('プロンプト: ステージ列挙を含む', strpos($sp, '提案中') !== false);
ok('コンテキスト: 終了ステージ(受注/失注)の商談を除外', !array_filter($ctx['open_deals'], function ($d) { return $d['stage'] === '受注' || $d['stage'] === '失注'; }));

// 後片付け
foreach (glob($tmp . '/*') as $f) { @unlink($f); }
@rmdir($tmp);

echo "\n結果: pass={$pass} fail={$fail}\n";
exit($fail ? 1 : 0);
