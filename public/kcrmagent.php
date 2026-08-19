<?php
/**
 * Kurage CRM Agent — 「入力ゼロ」の1ファイルCRM。
 *
 * 営業は日報を投げるだけ。AIが顧客・商談・活動に構造化して「下書き起票」し、
 * 人は承認キューで1タップ確定する。DBサーバー不要(SQLite)・レンタルサーバーのPHPで動く。
 *
 * 【設計の芯】(kdbagent / kvgwc と同じ思想)
 *  1. AIに自己採点させない — AIの出力は必ず下書き(drafts)まで。台帳
 *     (companies/deals/activities)に書けるのは人の承認アクションだけ。
 *     AI出力の検証はAIではなく決定的なPHPコード(kca_validate)が行う。
 *  2. 入口が違っても同じ関門を通る — 人のWeb UI・外部API(?api=report)・AIの
 *     3者とも、書き込みは kca_can(actor, action) の宣言表で判定される。
 *     宣言に無い操作はどの入口からも実行できない。
 *  3. 検証エラーは4xxで返す(5xxで包むと外形監視が障害と誤判定する)。
 *  4. 承認時は現在のDB状態に対して検証をやり直す(下書き作成時と状態が変わっていても安全)。
 *
 * カスタマイズは kcrmagent_config.php を編集。PHP 7.0+ / pdo_sqlite。
 */

date_default_timezone_set('Asia/Tokyo');

$cfg = __DIR__ . '/kcrmagent_config.php';
if (!is_file($cfg)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'kcrmagent_config.php がありません。kcrmagent_config.php.example をコピーして作成してください。';
    exit;
}
require $cfg;

if (!defined('KCA_TITLE'))         { define('KCA_TITLE', 'Kurage CRM Agent'); }
if (!defined('KCA_BRAND_COLOR'))   { define('KCA_BRAND_COLOR', '#1b6d8c'); }
if (!defined('KCA_PASSWORD'))      { define('KCA_PASSWORD', ''); }
if (!defined('KCA_PASSWORD_HASH')) { define('KCA_PASSWORD_HASH', ''); }
if (!defined('KCA_API_TOKEN'))     { define('KCA_API_TOKEN', ''); }
if (!defined('KCA_API_BASE'))      { define('KCA_API_BASE', 'https://api.deepseek.com'); }
if (!defined('KCA_API_KEY'))       { define('KCA_API_KEY', ''); }
if (!defined('KCA_MODEL'))         { define('KCA_MODEL', 'deepseek-chat'); }
if (!defined('KCA_STAGES'))        { define('KCA_STAGES', '新規,提案中,受注,失注'); }
if (!defined('KCA_ACT_TYPES'))     { define('KCA_ACT_TYPES', '訪問,電話,メール,オンライン,メモ'); }
if (!defined('KCA_RATE_PER_HOUR')) { define('KCA_RATE_PER_HOUR', 20); }
if (!defined('KCA_INPUT_MAX'))     { define('KCA_INPUT_MAX', 2000); }
if (!defined('KCA_DEMO'))          { define('KCA_DEMO', false); }
if (!defined('KCA_DATA_DIR'))      { define('KCA_DATA_DIR', __DIR__ . '/kca_data'); }

/* ================= ユーティリティ ================= */

function kca_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function kca_json_out($code, $data) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function kca_stages() { return array_values(array_filter(array_map('trim', explode(',', KCA_STAGES)))); }
function kca_act_types() { return array_values(array_filter(array_map('trim', explode(',', KCA_ACT_TYPES)))); }

function kca_rate_max() { $n = (int)KCA_RATE_PER_HOUR; if (KCA_DEMO) { $n = min($n, 8); } return $n; }
function kca_input_max() { $n = (int)KCA_INPUT_MAX; if (KCA_DEMO) { $n = min($n, 1000); } return $n; }

/** 会社名の照合用正規化(全半角・空白・大小文字ゆれの吸収)。表示は原文のまま保つ。 */
function kca_norm($s) {
    $s = trim((string)$s);
    if (function_exists('mb_convert_kana')) { $s = mb_convert_kana($s, 'asKV', 'UTF-8'); }
    $s = preg_replace('/\s+/u', '', $s);
    if (function_exists('mb_strtolower')) { $s = mb_strtolower($s, 'UTF-8'); }
    return $s;
}

function kca_valid_date($s) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$s, $m)) { return false; }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/* ================= DB(SQLite) ================= */

function kca_pdo() {
    static $pdo = null;
    if ($pdo !== null) { return $pdo; }
    if (!is_dir(KCA_DATA_DIR)) { @mkdir(KCA_DATA_DIR, 0755, true); }
    $pdo = new PDO('sqlite:' . KCA_DATA_DIR . '/kcrmagent.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=8000');
    $pdo->exec('PRAGMA foreign_keys=ON');
    kca_schema($pdo);
    return $pdo;
}

function kca_schema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS companies(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL, norm TEXT NOT NULL UNIQUE, note TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS deals(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company_id INTEGER NOT NULL REFERENCES companies(id),
        title TEXT NOT NULL, stage TEXT NOT NULL, amount INTEGER,
        next_action TEXT NOT NULL DEFAULT '', next_date TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS activities(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company_id INTEGER NOT NULL REFERENCES companies(id),
        deal_id INTEGER REFERENCES deals(id),
        type TEXT NOT NULL, content TEXT NOT NULL, at_date TEXT NOT NULL,
        created_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS inbox(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        raw TEXT NOT NULL, channel TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending',
        error TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS drafts(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        inbox_id INTEGER NOT NULL REFERENCES inbox(id),
        ops_json TEXT NOT NULL, warns_json TEXT NOT NULL DEFAULT '[]',
        status TEXT NOT NULL DEFAULT 'pending',
        decided_by TEXT NOT NULL DEFAULT '', decided_at TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts TEXT NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL, detail TEXT NOT NULL)");
}

function kca_now() { return date('Y-m-d H:i:s'); }

function kca_audit($actor, $action, $detail) {
    $st = kca_pdo()->prepare('INSERT INTO audit(ts,actor,action,detail) VALUES(?,?,?,?)');
    $st->execute(array(kca_now(), $actor, $action, mb_substr((string)$detail, 0, 500, 'UTF-8')));
}

/* ================= 関門(この宣言表がすべての書き込みを裁く) ================= */

class KcaDenied extends Exception {}

/**
 * 誰が何をできるかの宣言。ここに無い組み合わせは、どの入口からも実行できない。
 *  - ai   : AI。下書きの作成だけ。台帳には一切書けない
 *  - api  : 外部API(?api=report)。日報の投入だけ。台帳にも下書きの決裁にも触れない
 *  - user : ログイン済みの人。日報投入・下書きの承認/却下・台帳の手直し
 */
function kca_can($actor, $action) {
    $rules = array(
        'ai'   => array('draft.create'),
        'api'  => array('report.create'),
        'user' => array('report.create', 'draft.create', 'draft.approve', 'draft.reject',
                        'company.create', 'deal.set_stage'),
    );
    return isset($rules[$actor]) && in_array($action, $rules[$actor], true);
}

function kca_assert($actor, $action) {
    if (!kca_can($actor, $action)) {
        throw new KcaDenied($actor . ' は ' . $action . ' を許可されていません');
    }
}

/* ================= 台帳操作(必ず kca_assert を通ってから呼ぶ) ================= */

function kca_company_find($pdo, $name) {
    $st = $pdo->prepare('SELECT * FROM companies WHERE norm=?');
    $st->execute(array(kca_norm($name)));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function kca_company_create($actor, $name, $note = '') {
    kca_assert($actor, 'company.create');
    $pdo = kca_pdo();
    $ex = kca_company_find($pdo, $name);
    if ($ex) { return (int)$ex['id']; }
    $st = $pdo->prepare('INSERT INTO companies(name,norm,note,created_at) VALUES(?,?,?,?)');
    $st->execute(array(trim($name), kca_norm($name), (string)$note, kca_now()));
    $id = (int)$pdo->lastInsertId();
    kca_audit($actor, 'company.create', $name);
    return $id;
}

/* ================= 日報の投入 ================= */

function kca_report_create($actor, $raw, $channel) {
    kca_assert($actor, 'report.create');
    $pdo = kca_pdo();
    $st = $pdo->prepare('INSERT INTO inbox(raw,channel,created_at) VALUES(?,?,?)');
    $st->execute(array($raw, $channel, kca_now()));
    $id = (int)$pdo->lastInsertId();
    kca_audit($actor, 'report.create', 'inbox#' . $id . ' via ' . $channel);
    return $id;
}

/* ================= AI解析(構造化) ================= */

/** AIに渡す「現状」— 既存会社と進行中商談。照合はAI任せにせず、後段のkca_validateでも突き合わせる。 */
function kca_context($pdo) {
    $companies = $pdo->query('SELECT name FROM companies ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $stages = kca_stages();
    $closed = array();
    if (count($stages) >= 2) { $closed = array_slice($stages, -2); } // 慣例: 末尾2つ(受注/失注)を終了扱い
    $in = implode(',', array_fill(0, count($closed), '?'));
    $sql = 'SELECT d.id,c.name AS company,d.title,d.stage FROM deals d JOIN companies c ON c.id=d.company_id';
    if ($closed) { $sql .= " WHERE d.stage NOT IN ($in)"; }
    $sql .= ' ORDER BY d.id';
    $st = $pdo->prepare($sql);
    $st->execute($closed);
    return array('companies' => $companies, 'open_deals' => $st->fetchAll(PDO::FETCH_ASSOC));
}

function kca_ai_system_prompt($ctx) {
    $stages = implode(' / ', kca_stages());
    $types = implode(' / ', kca_act_types());
    return "あなたは営業事務のアシスタントです。営業担当者の日報テキストを読み、CRMに起票するためのJSONだけを出力します。説明文・前置き・コードフェンスは出力しません。\n\n"
        . "# 出力スキーマ(JSONオブジェクト1個)\n"
        . '{"items":[{"company":"会社名","activities":[{"type":"種別","content":"要約","date":"YYYY-MM-DD"}],"deal":{"existing_id":数値またはnull,"title":"商談名","stage":"ステージ","amount":数値またはnull,"next_action":"次のアクション","next_date":"YYYY-MM-DDまたは空"}またはnull}]}' . "\n\n"
        . "# ルール\n"
        . "- 会社ごとに1つのitemにまとめる。日報に出てくる会社だけを出す\n"
        . "- 既存会社一覧に同じ会社があれば、一覧の表記をそのまま使う\n"
        . "- 商談の話題があれば deal を出す。進行中商談一覧に該当があれば existing_id にそのidを入れ、無ければ existing_id は null(新規商談)で title を付ける\n"
        . "- stage は次から選ぶ: {$stages}。activityのtypeは次から選ぶ: {$types}\n"
        . "- 金額は円の数値(「50万」は500000)。不明なら null\n"
        . "- 日付は YYYY-MM-DD。「今日」は本日日付。不明なら本日\n"
        . "- 商談の動きがなく活動だけなら deal は null\n\n"
        . "# 本日日付\n" . date('Y-m-d') . "\n\n"
        . "# 既存会社一覧\n" . ($ctx['companies'] ? implode("\n", $ctx['companies']) : '(なし)') . "\n\n"
        . "# 進行中商談一覧(id: 会社 / 商談名 / ステージ)\n"
        . ($ctx['open_deals'] ? implode("\n", array_map(function ($d) {
            return $d['id'] . ': ' . $d['company'] . ' / ' . $d['title'] . ' / ' . $d['stage'];
        }, $ctx['open_deals'])) : '(なし)');
}

/** OpenAI互換APIで構造化。戻り値: array(パース済み) or array('error'=>...) */
function kca_ai_parse($raw, $ctx) {
    if (KCA_API_KEY === '' || KCA_API_KEY === 'sk-xxxx') {
        return array('error' => 'AIのAPIキーが未設定です(kcrmagent_config.php)');
    }
    $payload = json_encode(array(
        'model' => KCA_MODEL,
        'messages' => array(
            array('role' => 'system', 'content' => kca_ai_system_prompt($ctx)),
            array('role' => 'user', 'content' => $raw),
        ),
        'temperature' => 0.1,
        'max_tokens' => 2000,
        'response_format' => array('type' => 'json_object'),
    ), JSON_UNESCAPED_UNICODE);
    $ch = curl_init(rtrim(KCA_API_BASE, '/') . '/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . KCA_API_KEY),
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ));
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) { return array('error' => 'AIへの接続に失敗しました: ' . $err); }
    $j = json_decode($res, true);
    if ($code !== 200 || !isset($j['choices'][0]['message']['content'])) {
        $msg = isset($j['error']['message']) ? $j['error']['message'] : ('HTTP ' . $code);
        return array('error' => 'AIがエラーを返しました: ' . $msg);
    }
    $text = trim((string)$j['choices'][0]['message']['content']);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text); // 念のためフェンス除去
    $out = json_decode($text, true);
    if (!is_array($out)) { return array('error' => 'AIの出力をJSONとして解釈できませんでした'); }
    return array('data' => $out);
}

/* ================= 決定的検証(AIに自己採点させない本体) ================= */

/**
 * AI出力を現在のDB状態と突き合わせ、実行可能な操作列(ops)に正規化する。
 * AIの主張(既存/新規・id)は信用せず、ここで機械的に照合し直す。
 * 戻り値: array('ops'=>[], 'warns'=>[])
 */
function kca_validate($pdo, $data) {
    $ops = array(); $warns = array();
    $stages = kca_stages(); $types = kca_act_types();
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
    if (!$items) { $warns[] = '日報から起票できる内容が見つかりませんでした'; }
    $newCompanies = array(); // norm => 表示名(同一下書き内の重複作成防止)

    foreach ($items as $i => $it) {
        if (!is_array($it)) { continue; }
        $company = trim(isset($it['company']) ? (string)$it['company'] : '');
        if ($company === '' || mb_strlen($company, 'UTF-8') > 100) {
            $warns[] = '会社名が空/長すぎるitemを飛ばしました(#' . ($i + 1) . ')';
            continue;
        }
        $exist = kca_company_find($pdo, $company);
        $cname = $exist ? $exist['name'] : $company; // 既存表記を正とする
        if (!$exist && !isset($newCompanies[kca_norm($company)])) {
            $newCompanies[kca_norm($company)] = $company;
            $ops[] = array('op' => 'company.create', 'name' => $company);
        }

        // 商談
        $deal = isset($it['deal']) && is_array($it['deal']) ? $it['deal'] : null;
        if ($deal) {
            $title = trim(isset($deal['title']) ? (string)$deal['title'] : '');
            $stage = trim(isset($deal['stage']) ? (string)$deal['stage'] : '');
            if (!in_array($stage, $stages, true)) {
                if ($stage !== '') { $warns[] = '不明なステージ「' . $stage . '」→「' . $stages[0] . '」にしました'; }
                $stage = $stages[0];
            }
            $amount = null;
            if (isset($deal['amount']) && $deal['amount'] !== null && $deal['amount'] !== '') {
                if (is_numeric($deal['amount']) && $deal['amount'] >= 0) { $amount = (int)$deal['amount']; }
                else { $warns[] = '金額を数値として読めなかったため空にしました'; }
            }
            $naction = mb_substr(trim(isset($deal['next_action']) ? (string)$deal['next_action'] : ''), 0, 200, 'UTF-8');
            $ndate = trim(isset($deal['next_date']) ? (string)$deal['next_date'] : '');
            if ($ndate !== '' && !kca_valid_date($ndate)) { $warns[] = '次回日付が不正のため空にしました'; $ndate = ''; }

            $existingId = isset($deal['existing_id']) && is_numeric($deal['existing_id']) ? (int)$deal['existing_id'] : 0;
            $matched = null;
            if ($existingId > 0 && !$exist) {
                $warns[] = '商談id=' . $existingId . 'の指定がありましたが' . $cname . 'は新規会社のため新規商談として扱います';
            }
            if ($existingId > 0 && $exist) {
                // AIのidは検証してから使う: その会社の商談であることをDBで確認
                $st = $pdo->prepare('SELECT * FROM deals WHERE id=? AND company_id=?');
                $st->execute(array($existingId, (int)$exist['id']));
                $matched = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$matched) { $warns[] = '商談id=' . $existingId . 'は' . $cname . 'の商談ではないため新規として扱います'; }
            }
            if ($matched) {
                $set = array('stage' => $stage);
                if ($amount !== null) { $set['amount'] = $amount; }
                if ($naction !== '') { $set['next_action'] = $naction; }
                if ($ndate !== '') { $set['next_date'] = $ndate; }
                $ops[] = array('op' => 'deal.update', 'deal_id' => (int)$matched['id'],
                               'company' => $cname, 'title' => $matched['title'],
                               'before_stage' => $matched['stage'], 'set' => $set);
            } else {
                if ($title === '') { $title = $cname . 'の商談'; $warns[] = '商談名が無いため仮の名前を付けました'; }
                $ops[] = array('op' => 'deal.create', 'company' => $cname,
                               'title' => mb_substr($title, 0, 120, 'UTF-8'), 'stage' => $stage,
                               'amount' => $amount, 'next_action' => $naction, 'next_date' => $ndate);
            }
        }

        // 活動
        $acts = isset($it['activities']) && is_array($it['activities']) ? $it['activities'] : array();
        foreach ($acts as $a) {
            if (!is_array($a)) { continue; }
            $content = mb_substr(trim(isset($a['content']) ? (string)$a['content'] : ''), 0, 500, 'UTF-8');
            if ($content === '') { continue; }
            $type = trim(isset($a['type']) ? (string)$a['type'] : '');
            if (!in_array($type, $types, true)) { $type = 'メモ'; }
            $date = trim(isset($a['date']) ? (string)$a['date'] : '');
            if (!kca_valid_date($date)) { $date = date('Y-m-d'); }
            $ops[] = array('op' => 'activity.create', 'company' => $cname,
                           'type' => $type, 'content' => $content, 'date' => $date);
        }
    }
    return array('ops' => $ops, 'warns' => $warns);
}

/* ================= 下書きの作成と反映 ================= */

function kca_draft_create($actor, $inboxId, $ops, $warns) {
    kca_assert($actor, 'draft.create');
    $pdo = kca_pdo();
    $st = $pdo->prepare('INSERT INTO drafts(inbox_id,ops_json,warns_json,created_at) VALUES(?,?,?,?)');
    $st->execute(array($inboxId, json_encode($ops, JSON_UNESCAPED_UNICODE),
                       json_encode($warns, JSON_UNESCAPED_UNICODE), kca_now()));
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE inbox SET status='parsed' WHERE id=?")->execute(array($inboxId));
    kca_audit($actor, 'draft.create', 'draft#' . $id . ' ops=' . count($ops));
    return $id;
}

/** 日報→AI→検証→下書き の一連。戻り値: array('draft_id'=>..) or array('error'=>.., 'code'=>4xx) */
function kca_pipeline($actor, $raw, $channel) {
    $raw = trim((string)$raw);
    if ($raw === '') { return array('error' => '日報が空です', 'code' => 400); }
    if (mb_strlen($raw, 'UTF-8') > kca_input_max()) {
        return array('error' => '日報は' . kca_input_max() . '文字以内でお願いします', 'code' => 400);
    }
    $pdo = kca_pdo();
    $inboxId = kca_report_create($actor, $raw, $channel);
    $ai = kca_ai_parse($raw, kca_context($pdo));
    if (isset($ai['error'])) {
        $pdo->prepare("UPDATE inbox SET status='error', error=? WHERE id=?")->execute(array($ai['error'], $inboxId));
        return array('error' => $ai['error'], 'code' => 502);
    }
    $v = kca_validate($pdo, $ai['data']);
    if (!$v['ops']) {
        $msg = 'この日報からは起票項目を読み取れませんでした。会社名や商談の動きを含めて書いてください';
        $pdo->prepare("UPDATE inbox SET status='error', error=? WHERE id=?")->execute(array($msg, $inboxId));
        return array('error' => $msg, 'code' => 422, 'warns' => $v['warns']);
    }
    $draftId = kca_draft_create('ai', $inboxId, $v['ops'], $v['warns']);
    return array('draft_id' => $draftId, 'ops' => count($v['ops']), 'warns' => $v['warns']);
}

/** 承認: 現在のDB状態でopsを適用する(トランザクション)。 */
function kca_draft_approve($actor, $draftId, $decidedBy) {
    kca_assert($actor, 'draft.approve');
    $pdo = kca_pdo();
    $st = $pdo->prepare("SELECT * FROM drafts WHERE id=? AND status='pending'");
    $st->execute(array($draftId));
    $draft = $st->fetch(PDO::FETCH_ASSOC);
    if (!$draft) { return array('error' => 'この下書きは既に処理済みか、存在しません', 'code' => 409); }
    $ops = json_decode($draft['ops_json'], true);
    if (!is_array($ops)) { return array('error' => '下書きの中身が壊れています', 'code' => 422); }

    $pdo->beginTransaction();
    try {
        $applied = 0;
        foreach ($ops as $op) {
            $kind = isset($op['op']) ? $op['op'] : '';
            if ($kind === 'company.create') {
                kca_company_create($actor, $op['name']);
                $applied++;
            } elseif ($kind === 'deal.create') {
                $c = kca_company_find($pdo, $op['company']);
                if (!$c) { $cid = kca_company_create($actor, $op['company']); }
                else { $cid = (int)$c['id']; }
                $s = $pdo->prepare('INSERT INTO deals(company_id,title,stage,amount,next_action,next_date,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
                $s->execute(array($cid, $op['title'], $op['stage'],
                                  isset($op['amount']) ? $op['amount'] : null,
                                  isset($op['next_action']) ? $op['next_action'] : '',
                                  isset($op['next_date']) ? $op['next_date'] : '',
                                  kca_now(), kca_now()));
                $applied++;
            } elseif ($kind === 'deal.update') {
                $s = $pdo->prepare('SELECT * FROM deals WHERE id=?');
                $s->execute(array((int)$op['deal_id']));
                if (!$s->fetch(PDO::FETCH_ASSOC)) { continue; } // 消えていたら黙って飛ばさず記録
                $set = isset($op['set']) && is_array($op['set']) ? $op['set'] : array();
                $cols = array(); $vals = array();
                foreach (array('stage', 'amount', 'next_action', 'next_date') as $k) {
                    if (array_key_exists($k, $set)) { $cols[] = "$k=?"; $vals[] = $set[$k]; }
                }
                if (!$cols) { continue; }
                $cols[] = 'updated_at=?'; $vals[] = kca_now(); $vals[] = (int)$op['deal_id'];
                $pdo->prepare('UPDATE deals SET ' . implode(',', $cols) . ' WHERE id=?')->execute($vals);
                $applied++;
            } elseif ($kind === 'activity.create') {
                $c = kca_company_find($pdo, $op['company']);
                if (!$c) { $cid = kca_company_create($actor, $op['company']); }
                else { $cid = (int)$c['id']; }
                $s = $pdo->prepare('INSERT INTO activities(company_id,deal_id,type,content,at_date,created_at) VALUES(?,NULL,?,?,?,?)');
                $s->execute(array($cid, $op['type'], $op['content'], $op['date'], kca_now()));
                $applied++;
            }
        }
        $pdo->prepare("UPDATE drafts SET status='approved', decided_by=?, decided_at=? WHERE id=?")
            ->execute(array($decidedBy, kca_now(), $draftId));
        $pdo->prepare("UPDATE inbox SET status='applied' WHERE id=?")->execute(array((int)$draft['inbox_id']));
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return array('error' => '反映に失敗しました: ' . $e->getMessage(), 'code' => 500);
    }
    kca_audit($actor, 'draft.approve', 'draft#' . $draftId . ' applied=' . $applied . ' by=' . $decidedBy);
    return array('applied' => $applied);
}

function kca_draft_reject($actor, $draftId, $decidedBy) {
    kca_assert($actor, 'draft.reject');
    $pdo = kca_pdo();
    $st = $pdo->prepare("UPDATE drafts SET status='rejected', decided_by=?, decided_at=? WHERE id=? AND status='pending'");
    $st->execute(array($decidedBy, kca_now(), $draftId));
    if ($st->rowCount() < 1) { return array('error' => 'この下書きは既に処理済みです', 'code' => 409); }
    $d = kca_pdo()->prepare('SELECT inbox_id FROM drafts WHERE id=?');
    $d->execute(array($draftId));
    $inboxId = (int)$d->fetchColumn();
    kca_pdo()->prepare("UPDATE inbox SET status='rejected' WHERE id=?")->execute(array($inboxId));
    kca_audit($actor, 'draft.reject', 'draft#' . $draftId . ' by=' . $decidedBy);
    return array('ok' => 1);
}

/* ================= レート制限(AI呼び出しの防波堤・IP1時間) ================= */

function kca_rate_ok($ip) {
    $f = KCA_DATA_DIR . '/rate.json';
    if (!is_dir(KCA_DATA_DIR)) { @mkdir(KCA_DATA_DIR, 0755, true); }
    $key = substr(hash('sha256', $ip . '|kca'), 0, 16);
    $now = time();
    $fp = fopen($f, 'c+');
    if (!$fp) { return true; }
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $all = $raw ? json_decode($raw, true) : array();
    if (!is_array($all)) { $all = array(); }
    $hits = isset($all[$key]) ? $all[$key] : array();
    $hits = array_values(array_filter($hits, function ($t) use ($now) { return $t > $now - 3600; }));
    $ok = count($hits) < kca_rate_max();
    if ($ok) {
        $hits[] = $now; $all[$key] = $hits;
        foreach ($all as $k => $ts) {
            $ts = array_values(array_filter($ts, function ($t) use ($now) { return $t > $now - 3600; }));
            if ($ts) { $all[$k] = $ts; } else { unset($all[$k]); }
        }
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($all));
    }
    flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}

/* ================= 認証 ================= */

function kca_session_start() {
    if (session_status() === PHP_SESSION_NONE) { session_name('KCASESSID'); session_start(); }
}

function kca_logged_in() { kca_session_start(); return !empty($_SESSION['kca_ok']); }

function kca_try_login($pw) {
    if (KCA_PASSWORD_HASH !== '') { return password_verify($pw, KCA_PASSWORD_HASH); }
    if (KCA_PASSWORD !== '') { return hash_equals(KCA_PASSWORD, $pw); }
    return false;
}

function kca_csrf() {
    kca_session_start();
    if (empty($_SESSION['kca_csrf'])) { $_SESSION['kca_csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['kca_csrf'];
}

function kca_csrf_ok() {
    kca_session_start();
    $t = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    return !empty($_SESSION['kca_csrf']) && hash_equals($_SESSION['kca_csrf'], $t);
}

/* ================= 外部API ================= */

if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];
    if ($api === 'health') { kca_json_out(200, array('ok' => 1, 'app' => 'kcrmagent')); }
    if ($api === 'report') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { kca_json_out(405, array('error' => 'POSTで送信してください')); }
        $tok = isset($_SERVER['HTTP_X_KCA_TOKEN']) ? $_SERVER['HTTP_X_KCA_TOKEN'] : '';
        if (KCA_API_TOKEN === '' || !hash_equals(KCA_API_TOKEN, $tok)) {
            kca_json_out(401, array('error' => 'APIトークンが違います(X-KCA-TOKENヘッダ)'));
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        $raw = isset($body['report']) ? (string)$body['report'] : '';
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        if (!kca_rate_ok($ip)) { kca_json_out(429, array('error' => '利用が集中しています。1時間ほど空けてください')); }
        $r = kca_pipeline('api', $raw, 'api');
        if (isset($r['error'])) { kca_json_out($r['code'], array('error' => $r['error'])); }
        kca_json_out(200, array('draft_id' => $r['draft_id'], 'ops' => $r['ops'], 'warns' => $r['warns'],
                                'note' => '下書きを作成しました。反映には管理画面での承認が必要です'));
    }
    kca_json_out(404, array('error' => '不明なAPIです'));
}

/* ================= 画面(ログイン) ================= */

kca_session_start();
$login_error = '';
if (isset($_POST['kca_pw'])) {
    if (kca_try_login((string)$_POST['kca_pw'])) { $_SESSION['kca_ok'] = 1; header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }
    $login_error = 'パスワードが違います';
}
if (isset($_GET['logout'])) { unset($_SESSION['kca_ok']); header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }

$SELF = strtok($_SERVER['REQUEST_URI'], '?');

/* ---- 画面POST(要ログイン+CSRF) ---- */
$flash = ''; $flash_warns = array(); $flash_err = '';
if (kca_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    if (!kca_csrf_ok()) {
        $flash_err = 'ページの有効期限が切れました。再読み込みしてやり直してください';
    } else {
        $act = (string)$_POST['act'];
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        if ($act === 'report') {
            if (!kca_rate_ok($ip)) { $flash_err = 'AI解析の利用が集中しています。1時間ほど空けてください'; }
            else {
                $r = kca_pipeline('user', isset($_POST['report']) ? $_POST['report'] : '', 'web');
                if (isset($r['error'])) { $flash_err = $r['error']; if (isset($r['warns'])) { $flash_warns = $r['warns']; } }
                else { $flash = 'AIが下書き#' . $r['draft_id'] . 'を起票しました(' . $r['ops'] . '件)。内容を確認して承認してください'; $flash_warns = $r['warns']; }
            }
        } elseif ($act === 'approve') {
            $r = kca_draft_approve('user', (int)$_POST['draft_id'], 'admin');
            if (isset($r['error'])) { $flash_err = $r['error']; } else { $flash = '下書きを台帳に反映しました(' . $r['applied'] . '件)'; }
        } elseif ($act === 'reject') {
            $r = kca_draft_reject('user', (int)$_POST['draft_id'], 'admin');
            if (isset($r['error'])) { $flash_err = $r['error']; } else { $flash = '下書きを却下しました(台帳には反映されません)'; }
        } elseif ($act === 'company_add') {
            $nm = trim(isset($_POST['name']) ? (string)$_POST['name'] : '');
            if ($nm === '' || mb_strlen($nm, 'UTF-8') > 100) { $flash_err = '会社名を1〜100文字で入力してください'; }
            else { kca_company_create('user', $nm, trim(isset($_POST['note']) ? (string)$_POST['note'] : '')); $flash = '会社を追加しました'; }
        } elseif ($act === 'deal_stage') {
            $stage = (string)$_POST['stage'];
            if (!in_array($stage, kca_stages(), true)) { $flash_err = '不明なステージです'; }
            else {
                kca_assert('user', 'deal.set_stage');
                $st = kca_pdo()->prepare('UPDATE deals SET stage=?, updated_at=? WHERE id=?');
                $st->execute(array($stage, kca_now(), (int)$_POST['deal_id']));
                kca_audit('user', 'deal.set_stage', 'deal#' . (int)$_POST['deal_id'] . '→' . $stage);
                $flash = 'ステージを変更しました';
            }
        }
    }
}

$page = isset($_GET['p']) ? (string)$_GET['p'] : 'home';
$pdo = kca_logged_in() ? kca_pdo() : null;
?><!doctype html>
<html lang="ja"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo kca_h(KCA_TITLE); ?></title>
<meta name="robots" content="noindex">
<style>
:root { --brand:<?php echo kca_h(KCA_BRAND_COLOR); ?>; --ink:#22313a; --line:#d9e3e8; --paper:#f3f7f8; --ok:#2f7d4f; --warn:#a06a10; --err:#a33; }
* { box-sizing:border-box; }
body { margin:0; background:var(--paper); color:var(--ink); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif; font-size:14.5px; }
a { color:var(--brand); }
.wrap { max-width:980px; margin:0 auto; padding:0 14px 40px; }
header.top { background:#fff; border-bottom:2px solid var(--brand); margin:0 -14px 16px; padding:12px 16px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
header.top h1 { margin:0; font-size:17px; }
header.top h1 a { color:var(--brand); text-decoration:none; }
.demo-badge { background:#fff3d8; border:1px solid #e8c87a; color:#7a5b12; font-size:11px; font-weight:700; border-radius:6px; padding:2px 8px; }
nav.tabs { display:flex; gap:4px; margin-left:auto; flex-wrap:wrap; }
nav.tabs a { text-decoration:none; padding:6px 12px; border-radius:8px; font-size:13px; color:#3c525e; }
nav.tabs a.on { background:var(--brand); color:#fff; }
.card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px 18px; margin-bottom:14px; }
.card h2 { margin:0 0 10px; font-size:15.5px; color:var(--brand); }
textarea.rep { width:100%; min-height:110px; border:1.5px solid var(--line); border-radius:10px; padding:10px 12px; font-size:15px; font-family:inherit; resize:vertical; }
textarea.rep:focus { outline:none; border-color:var(--brand); }
.btn { background:var(--brand); color:#fff; border:none; border-radius:8px; padding:9px 18px; font-size:14px; font-weight:700; cursor:pointer; }
.btn.ghost { background:#fff; color:#3c525e; border:1px solid var(--line); }
.btn.ok { background:var(--ok); } .btn.ng { background:#8a8f94; }
.flash { border-radius:10px; padding:10px 14px; margin-bottom:14px; font-size:13.5px; }
.flash.ok { background:#e8f5ec; border:1px solid #b5d9c2; color:#215c3c; }
.flash.err { background:#fdeaea; border:1px solid #eab6b6; color:var(--err); }
.flash.warn { background:#fff7e5; border:1px solid #ecd9a0; color:var(--warn); }
.draft { border:1px solid #e8c87a; background:#fffdf5; }
.draft .raw { background:#f6f8f9; border:1px solid var(--line); border-radius:8px; padding:8px 12px; font-size:13px; color:#4a5b64; white-space:pre-wrap; margin:8px 0; }
ul.ops { margin:8px 0; padding-left:2px; list-style:none; }
ul.ops li { padding:7px 10px; border-left:3px solid var(--brand); background:#f2f8fa; border-radius:0 8px 8px 0; margin-bottom:6px; font-size:13.5px; }
ul.ops li .tag { display:inline-block; font-size:11px; font-weight:700; border-radius:5px; padding:1px 7px; margin-right:8px; color:#fff; background:var(--brand); }
ul.ops li .tag.new { background:var(--ok); }
ul.ops li .tag.upd { background:#9a6ab8; }
.warns { font-size:12.5px; color:var(--warn); margin:6px 0; }
table.list { width:100%; border-collapse:collapse; font-size:13.5px; }
table.list th, table.list td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--line); vertical-align:top; }
table.list th { color:#5a6c76; font-size:12px; }
.kanban { display:flex; gap:10px; overflow-x:auto; }
.kcol { flex:1; min-width:180px; background:#eef3f5; border-radius:10px; padding:10px; }
.kcol h3 { margin:0 0 8px; font-size:13px; color:#4a5b64; }
.kcard { background:#fff; border:1px solid var(--line); border-radius:8px; padding:8px 10px; margin-bottom:8px; font-size:13px; }
.kcard b { display:block; }
.kcard .co { color:#5a6c76; font-size:12px; }
.kcard .amt { color:var(--brand); font-weight:700; font-size:12.5px; }
.stat { display:flex; gap:12px; flex-wrap:wrap; }
.stat .s { background:#fff; border:1px solid var(--line); border-radius:10px; padding:12px 18px; min-width:130px; }
.stat .s b { display:block; font-size:22px; color:var(--brand); }
.stat .s span { font-size:12px; color:#5a6c76; }
.gate { max-width:380px; margin:80px auto; background:#fff; border:1px solid var(--line); border-radius:12px; padding:28px; text-align:center; }
.gate input { width:100%; padding:10px; font-size:15px; border:1.5px solid var(--line); border-radius:8px; margin:12px 0; }
.gate button { width:100%; background:var(--brand); color:#fff; border:none; border-radius:8px; padding:11px; font-size:15px; font-weight:700; cursor:pointer; }
.muted { color:#7a8a93; font-size:12.5px; }
.foot { text-align:center; font-size:11px; color:#8a99a1; padding:18px 0 6px; }
.pill { display:inline-block; font-size:11.5px; border-radius:999px; padding:2px 10px; background:#eef3f5; color:#4a5b64; }
form.inline { display:inline; }
select.stg { font-size:12.5px; padding:3px 6px; border:1px solid var(--line); border-radius:6px; }
</style></head>
<body>
<?php if (!kca_logged_in()): ?>
<div class="gate">
  <h1 style="font-size:18px;color:var(--brand);margin:0 0 4px"><?php echo kca_h(KCA_TITLE); ?></h1>
  <p class="muted">日報を投げるだけ。起票はAI、確定はあなた。</p>
  <?php if ($login_error): ?><p style="color:var(--err);font-size:13px"><?php echo kca_h($login_error); ?></p><?php endif; ?>
  <form method="post"><input type="password" name="kca_pw" placeholder="パスワード" autofocus><button>ログイン</button></form>
  <?php if (KCA_DEMO): ?><p class="muted">デモ環境です。データは定期的に初期化されます。</p><?php endif; ?>
</div>
<?php else: ?>
<div class="wrap">
<header class="top">
  <h1><a href="<?php echo kca_h($SELF); ?>"><?php echo kca_h(KCA_TITLE); ?></a></h1>
  <?php if (KCA_DEMO): ?><span class="demo-badge">デモ</span><?php endif; ?>
  <nav class="tabs">
    <?php
    $tabs = array('home' => '日報と承認', 'kanban' => 'かんばん', 'companies' => '会社台帳', 'history' => '履歴');
    foreach ($tabs as $k => $label) {
        $cls = $page === $k ? 'on' : '';
        echo '<a class="' . $cls . '" href="' . kca_h($SELF) . '?p=' . $k . '">' . kca_h($label) . '</a>';
    }
    ?>
    <a href="<?php echo kca_h($SELF); ?>?logout=1">ログアウト</a>
  </nav>
</header>

<?php if ($flash): ?><div class="flash ok"><?php echo kca_h($flash); ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="flash err"><?php echo kca_h($flash_err); ?></div><?php endif; ?>
<?php if ($flash_warns): ?><div class="flash warn">AIからの注記: <?php echo kca_h(implode(' / ', $flash_warns)); ?></div><?php endif; ?>

<?php if ($page === 'home'): ?>
<?php
$pending = $pdo->query("SELECT d.*, i.raw, i.channel FROM drafts d JOIN inbox i ON i.id=d.inbox_id WHERE d.status='pending' ORDER BY d.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$nCompanies = (int)$pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
$nDeals = (int)$pdo->query('SELECT COUNT(*) FROM deals')->fetchColumn();
$nActsMonth = (int)$pdo->query("SELECT COUNT(*) FROM activities WHERE at_date >= '" . date('Y-m-01') . "'")->fetchColumn();
?>
<div class="card">
  <h2>日報を投げる</h2>
  <p class="muted" style="margin:0 0 8px">会社名・やったこと・商談の動きを普通の文章で。AIが下書きに起票します(台帳には承認するまで反映されません)。</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo kca_h(kca_csrf()); ?>">
    <input type="hidden" name="act" value="report">
    <textarea class="rep" name="report" id="rep" maxlength="<?php echo kca_input_max(); ?>" placeholder="例: 今日は○○商事の田中さんを訪問。Web保守の見積を依頼された。50万くらい。来週火曜に見積を送る。あと△△工業から電話、社長不在で明日かけ直し。"></textarea>
    <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
      <button class="btn" type="submit">AIに起票させる</button>
      <button class="btn ghost" type="button" id="exbtn">例文を入れる</button>
      <span class="muted"><?php echo kca_input_max(); ?>文字まで / メール・チャット連携はAPI(?api=report)でも投入できます</span>
    </div>
  </form>
</div>

<div class="card">
  <h2>承認キュー<?php if ($pending): ?> <span class="pill"><?php echo count($pending); ?>件</span><?php endif; ?></h2>
  <?php if (!$pending): ?><p class="muted">承認待ちの下書きはありません。</p><?php endif; ?>
  <?php foreach ($pending as $d): $ops = json_decode($d['ops_json'], true) ?: array(); $warns = json_decode($d['warns_json'], true) ?: array(); ?>
  <div class="card draft">
    <div class="muted">下書き#<?php echo (int)$d['id']; ?> ・ <?php echo kca_h($d['created_at']); ?> ・ 入口: <?php echo kca_h($d['channel'] === 'api' ? 'API(メール/チャット連携)' : '画面'); ?></div>
    <div class="raw"><?php echo kca_h(mb_substr($d['raw'], 0, 400, 'UTF-8')); ?></div>
    <ul class="ops">
      <?php foreach ($ops as $op): ?>
      <li>
        <?php if ($op['op'] === 'company.create'): ?>
          <span class="tag new">新規会社</span><?php echo kca_h($op['name']); ?>
        <?php elseif ($op['op'] === 'deal.create'): ?>
          <span class="tag new">新規商談</span><?php echo kca_h($op['company']); ?> /「<?php echo kca_h($op['title']); ?>」 ステージ: <?php echo kca_h($op['stage']); ?>
          <?php if ($op['amount'] !== null && $op['amount'] !== ''): ?> / <?php echo number_format((int)$op['amount']); ?>円<?php endif; ?>
          <?php if (!empty($op['next_action'])): ?> / 次: <?php echo kca_h($op['next_action']); ?><?php echo !empty($op['next_date']) ? '(' . kca_h($op['next_date']) . ')' : ''; ?><?php endif; ?>
        <?php elseif ($op['op'] === 'deal.update'): ?>
          <span class="tag upd">商談更新</span><?php echo kca_h($op['company']); ?> /「<?php echo kca_h($op['title']); ?>」
          <?php if (isset($op['set']['stage'])): ?> <?php echo kca_h($op['before_stage']); ?> → <b><?php echo kca_h($op['set']['stage']); ?></b><?php endif; ?>
          <?php if (isset($op['set']['amount'])): ?> / <?php echo number_format((int)$op['set']['amount']); ?>円<?php endif; ?>
          <?php if (!empty($op['set']['next_action'])): ?> / 次: <?php echo kca_h($op['set']['next_action']); ?><?php echo !empty($op['set']['next_date']) ? '(' . kca_h($op['set']['next_date']) . ')' : ''; ?><?php endif; ?>
        <?php elseif ($op['op'] === 'activity.create'): ?>
          <span class="tag">活動</span><?php echo kca_h($op['company']); ?> / <?php echo kca_h($op['type']); ?>(<?php echo kca_h($op['date']); ?>): <?php echo kca_h($op['content']); ?>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($warns): ?><div class="warns">⚠ <?php echo kca_h(implode(' / ', $warns)); ?></div><?php endif; ?>
    <form class="inline" method="post">
      <input type="hidden" name="csrf" value="<?php echo kca_h(kca_csrf()); ?>">
      <input type="hidden" name="act" value="approve">
      <input type="hidden" name="draft_id" value="<?php echo (int)$d['id']; ?>">
      <button class="btn ok" type="submit">承認して台帳に反映</button>
    </form>
    <form class="inline" method="post" style="margin-left:6px">
      <input type="hidden" name="csrf" value="<?php echo kca_h(kca_csrf()); ?>">
      <input type="hidden" name="act" value="reject">
      <input type="hidden" name="draft_id" value="<?php echo (int)$d['id']; ?>">
      <button class="btn ng" type="submit">却下</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2>いまの台帳</h2>
  <div class="stat">
    <div class="s"><b><?php echo $nCompanies; ?></b><span>会社</span></div>
    <div class="s"><b><?php echo $nDeals; ?></b><span>商談</span></div>
    <div class="s"><b><?php echo $nActsMonth; ?></b><span>今月の活動</span></div>
    <?php
    $sums = $pdo->query("SELECT stage, SUM(COALESCE(amount,0)) s, COUNT(*) c FROM deals GROUP BY stage")->fetchAll(PDO::FETCH_ASSOC);
    $byStage = array(); foreach ($sums as $r) { $byStage[$r['stage']] = $r; }
    foreach (kca_stages() as $sname): if (!isset($byStage[$sname])) { continue; } ?>
    <div class="s"><b><?php echo number_format((int)$byStage[$sname]['s'] / 10000); ?>万円</b><span><?php echo kca_h($sname); ?> (<?php echo (int)$byStage[$sname]['c']; ?>件)</span></div>
    <?php endforeach; ?>
  </div>
</div>

<?php elseif ($page === 'kanban'): ?>
<div class="card">
  <h2>商談かんばん</h2>
  <div class="kanban">
    <?php foreach (kca_stages() as $sname): ?>
    <div class="kcol">
      <h3><?php echo kca_h($sname); ?></h3>
      <?php
      $st = $pdo->prepare('SELECT d.*, c.name co FROM deals d JOIN companies c ON c.id=d.company_id WHERE d.stage=? ORDER BY d.updated_at DESC LIMIT 50');
      $st->execute(array($sname));
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $dl): ?>
      <div class="kcard">
        <b><?php echo kca_h($dl['title']); ?></b>
        <span class="co"><?php echo kca_h($dl['co']); ?></span>
        <?php if ($dl['amount'] !== null && $dl['amount'] !== ''): ?><span class="amt"><?php echo number_format((int)$dl['amount']); ?>円</span><?php endif; ?>
        <?php if ($dl['next_action'] !== ''): ?><div class="co">次: <?php echo kca_h($dl['next_action']); ?><?php echo $dl['next_date'] !== '' ? '(' . kca_h($dl['next_date']) . ')' : ''; ?></div><?php endif; ?>
        <form class="inline" method="post">
          <input type="hidden" name="csrf" value="<?php echo kca_h(kca_csrf()); ?>">
          <input type="hidden" name="act" value="deal_stage">
          <input type="hidden" name="deal_id" value="<?php echo (int)$dl['id']; ?>">
          <select class="stg" name="stage" onchange="this.form.submit()">
            <?php foreach (kca_stages() as $s2): ?>
            <option value="<?php echo kca_h($s2); ?>"<?php echo $s2 === $dl['stage'] ? ' selected' : ''; ?>><?php echo kca_h($s2); ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php elseif ($page === 'companies'): ?>
<?php $cid = isset($_GET['c']) ? (int)$_GET['c'] : 0; ?>
<?php if ($cid):
    $st = $pdo->prepare('SELECT * FROM companies WHERE id=?'); $st->execute(array($cid));
    $co = $st->fetch(PDO::FETCH_ASSOC);
    if ($co): ?>
<div class="card">
  <h2><?php echo kca_h($co['name']); ?></h2>
  <?php if ($co['note'] !== ''): ?><p class="muted"><?php echo kca_h($co['note']); ?></p><?php endif; ?>
  <h3 style="font-size:13.5px;margin:12px 0 6px">商談</h3>
  <table class="list"><tr><th>商談名</th><th>ステージ</th><th>金額</th><th>次のアクション</th><th>更新</th></tr>
  <?php $s2 = $pdo->prepare('SELECT * FROM deals WHERE company_id=? ORDER BY updated_at DESC'); $s2->execute(array($cid));
  foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $dl): ?>
    <tr><td><?php echo kca_h($dl['title']); ?></td><td><?php echo kca_h($dl['stage']); ?></td>
        <td><?php echo $dl['amount'] !== null && $dl['amount'] !== '' ? number_format((int)$dl['amount']) . '円' : '—'; ?></td>
        <td><?php echo kca_h($dl['next_action']); ?><?php echo $dl['next_date'] !== '' ? '(' . kca_h($dl['next_date']) . ')' : ''; ?></td>
        <td class="muted"><?php echo kca_h(substr($dl['updated_at'], 0, 10)); ?></td></tr>
  <?php endforeach; ?></table>
  <h3 style="font-size:13.5px;margin:14px 0 6px">活動タイムライン</h3>
  <table class="list"><tr><th>日付</th><th>種別</th><th>内容</th></tr>
  <?php $s3 = $pdo->prepare('SELECT * FROM activities WHERE company_id=? ORDER BY at_date DESC, id DESC LIMIT 100'); $s3->execute(array($cid));
  foreach ($s3->fetchAll(PDO::FETCH_ASSOC) as $a): ?>
    <tr><td><?php echo kca_h($a['at_date']); ?></td><td><?php echo kca_h($a['type']); ?></td><td><?php echo kca_h($a['content']); ?></td></tr>
  <?php endforeach; ?></table>
  <p><a href="<?php echo kca_h($SELF); ?>?p=companies">← 会社一覧へ</a></p>
</div>
    <?php endif; ?>
<?php else: ?>
<div class="card">
  <h2>会社台帳</h2>
  <table class="list"><tr><th>会社名</th><th>商談</th><th>直近の活動</th><th>登録</th></tr>
  <?php
  $rows = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM deals WHERE company_id=c.id) nd,
      (SELECT MAX(at_date) FROM activities WHERE company_id=c.id) la
      FROM companies c ORDER BY c.id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r): ?>
  <tr><td><a href="<?php echo kca_h($SELF); ?>?p=companies&c=<?php echo (int)$r['id']; ?>"><?php echo kca_h($r['name']); ?></a></td>
      <td><?php echo (int)$r['nd']; ?>件</td><td><?php echo kca_h($r['la'] ?: '—'); ?></td>
      <td class="muted"><?php echo kca_h(substr($r['created_at'], 0, 10)); ?></td></tr>
  <?php endforeach; ?></table>
</div>
<div class="card">
  <h2>会社を手動で追加</h2>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="csrf" value="<?php echo kca_h(kca_csrf()); ?>">
    <input type="hidden" name="act" value="company_add">
    <input name="name" placeholder="会社名" style="flex:1;min-width:180px;padding:9px;border:1.5px solid var(--line);border-radius:8px">
    <input name="note" placeholder="メモ(任意)" style="flex:2;min-width:200px;padding:9px;border:1.5px solid var(--line);border-radius:8px">
    <button class="btn" type="submit">追加</button>
  </form>
</div>
<?php endif; ?>

<?php elseif ($page === 'history'): ?>
<div class="card">
  <h2>日報と下書きの履歴</h2>
  <table class="list"><tr><th>#</th><th>日時</th><th>入口</th><th>日報(冒頭)</th><th>状態</th></tr>
  <?php
  $rows = $pdo->query("SELECT i.*, (SELECT status FROM drafts WHERE inbox_id=i.id ORDER BY id DESC LIMIT 1) ds
      FROM inbox i ORDER BY i.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
  $labels = array('pending' => '解析中', 'parsed' => '承認待ち', 'applied' => '反映済み', 'rejected' => '却下', 'error' => 'エラー');
  foreach ($rows as $r): ?>
  <tr><td><?php echo (int)$r['id']; ?></td><td class="muted"><?php echo kca_h($r['created_at']); ?></td>
      <td><?php echo kca_h($r['channel']); ?></td>
      <td><?php echo kca_h(mb_substr($r['raw'], 0, 60, 'UTF-8')); ?></td>
      <td><span class="pill"><?php echo kca_h(isset($labels[$r['status']]) ? $labels[$r['status']] : $r['status']); ?></span>
          <?php if ($r['error'] !== ''): ?><div class="warns"><?php echo kca_h($r['error']); ?></div><?php endif; ?></td></tr>
  <?php endforeach; ?></table>
</div>
<div class="card">
  <h2>監査ログ(直近50件)</h2>
  <p class="muted" style="margin:0 0 8px">AIは下書きの作成しかできません。台帳への反映は、すべて人の承認として記録されます。</p>
  <table class="list"><tr><th>日時</th><th>誰が</th><th>操作</th><th>詳細</th></tr>
  <?php foreach ($pdo->query('SELECT * FROM audit ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC) as $a): ?>
  <tr><td class="muted"><?php echo kca_h($a['ts']); ?></td><td><?php echo kca_h($a['actor']); ?></td>
      <td><?php echo kca_h($a['action']); ?></td><td class="muted"><?php echo kca_h($a['detail']); ?></td></tr>
  <?php endforeach; ?></table>
</div>
<?php endif; ?>

<div class="foot">Kurage CRM Agent — 起票はAI、確定はあなた。<?php if (KCA_DEMO): ?>(デモ環境・AI解析は1時間<?php echo kca_rate_max(); ?>回まで)<?php endif; ?></div>
</div>
<script>
(function(){
  var b = document.getElementById('exbtn'), t = document.getElementById('rep');
  if (b && t) b.addEventListener('click', function(){
    t.value = '今日は○○商事の田中部長を訪問。基幹システム入れ替えの相談で、まずWebサイト保守から始めたいとのこと。月5万円くらいの規模。来週火曜までに見積を送る約束。\nその後△△工業へ電話。先月提案した業務アプリの件、社内で予算が通ったので受注！金額は60万円で確定。\n夕方、新規の株式会社サンプル製作所から問い合わせメール。ホームページ制作の相談。明日オンラインで打ち合わせ予定。';
    t.focus();
  });
})();
</script>
<?php endif; ?>
</body></html>
