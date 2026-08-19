# Kurage CRM Agent (kcrmagent)

「入力ゼロ」の1ファイルCRM。営業は日報を投げるだけ。AIが顧客・商談・活動に構造化して**下書きに起票**し、人は承認キューで1タップ確定する。DBサーバー不要(SQLite)・レンタルサーバーのPHPで動く。

- デモ: https://proto.exbridge.jp/kcrmagent/
- 対象: 「CRMを入れたが現場が入力しない」に悩む10〜50人規模の会社

## 設計の芯

1. **AIに自己採点させない** — AIの出力は必ず下書き(drafts)まで。台帳(companies/deals/activities)に書けるのは人の承認アクションだけ。AI出力の検証はAIではなく決定的なPHPコード(`kca_validate`)が行う(ステージ・日付・金額・既存照合をDBと突き合わせて矯正)。
2. **入口が違っても同じ関門を通る** — 人のWeb UI・外部API(`?api=report`)・AIの3者とも、書き込みは `kca_can(actor, action)` の宣言表で判定される。宣言に無い操作はどの入口からも実行できない(kdbagent / kvgwc と同じ思想)。
3. **監査ログ** — 誰が(ai/api/user)何をしたかを全記録。AIによる台帳書き込み記録は構造的に0件になる。
4. 検証エラーは4xxで返す(外形監視対策)。承認時は現在のDB状態で検証をやり直す。

## 構成

```
public/kcrmagent.php               本体(1ファイル)
public/kcrmagent_config.php.example  設定の雛形(コピーして編集)
scripts/check_kcrmagent.php        自己テスト44件(AI呼び出しなしで関門・検証・反映を機械検証)
scripts/deploy_demo.sh             デモ公開(自己テスト通過が前提条件)
demo/                              デモ環境の設定一式
```

## 導入(レンタルサーバー)

1. `public/` の中身をサーバーに置く
2. `kcrmagent_config.php.example` を `kcrmagent_config.php` にコピーし、パスワード・AIのAPIキー(既定はDeepSeek/OpenAI互換)を設定
3. ブラウザで開いてログイン。以上

要件: PHP 7.0+ / pdo_sqlite / curl。データは `kca_data/` に保存(このフォルダごとバックアップ)。

## 外部API(メール転送・チャット連携)

```
POST {url}?api=report
X-KCA-TOKEN: (設定したトークン)
{"report":"今日は○○商事を訪問。見積50万円を来週送る。"}
→ {"draft_id":1,"ops":3,"warns":[]}   ※台帳への反映は管理画面での承認が必要
GET {url}?api=health → {"ok":1}
```

## 開発

```
php scripts/check_kcrmagent.php    # 自己テスト(44件)
php -S 127.0.0.1:18390 -t public   # ローカル起動
bash scripts/deploy_demo.sh        # デモ公開(キーは kcbrain/.env から注入)
```

© EXBRIDGE, Inc. / MIT License
