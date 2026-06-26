# 本番 missing AI報告書 再生成前チェック runbook

最終更新: 2026-06-26

## 目的

本番環境で missing AI報告書を再生成する前に、以下を確認する。

- 本番コードが最新か
- `documents` と実ファイルの欠落状態を確認できるか
- 再生成候補が `confidence=high` / `mode=near_rebuild` か
- PHP から report 保存先へ書き込みできるか
- PDF生成コマンドが本番で動作するか
- 再生成APIを実行してよい状態か

## 0. 前提

再生成APIは以下の方針で使う。

- 既存 missing `documents` は更新しない
- 既存 missing `documents` は削除しない
- 既存 `doc_chunks` も削除しない
- 成功時は新規 `documents` と新規 `doc_chunks` を作る
- `exact replay` ではなく `near-rebuild` として扱う
- 本番ではまず 1 件だけ実行する

## 1. 本番で git 状態確認

```bash
git status -sb
```

期待:

```text
## main...origin/main
```

未反映がある場合は pull する。

```bash
git pull
git status -sb
```

## 2. 主要ファイルの存在確認

以下が本番に存在することを確認する。

```bash
ls -l AI_System_Data/public/api/document_integrity_check.php
ls -l AI_System_Data/public/api/report_regeneration_candidates.php
ls -l AI_System_Data/public/api/report_regenerate_missing.php
ls -l AI_System_Data/src/DocumentFileIntegrityChecker.php
ls -l AI_System_Data/src/ReportRegenerationCandidateFinder.php
```

## 3. PHP構文確認

本番で可能なら実行する。

```bash
php -l AI_System_Data/public/api/document_integrity_check.php
php -l AI_System_Data/public/api/report_regeneration_candidates.php
php -l AI_System_Data/public/api/report_regenerate_missing.php
php -l AI_System_Data/src/DocumentFileIntegrityChecker.php
php -l AI_System_Data/src/ReportRegenerationCandidateFinder.php
php -l AI_System_Data/src/ReportGenerator.php
```

すべて `No syntax errors detected` が期待。

## 4. 保存先ディレクトリの書き込み確認

対象 `project_id=4` の場合:

```bash
ls -ld AI_System_Data/public/01_RAG_Documents/4
```

Webサーバ実行ユーザーから書き込みできる必要がある。

確認が難しい場合は、権限・所有者・Webサーバユーザーを確認する。

```bash
ls -ld AI_System_Data/public/01_RAG_Documents
ls -ld AI_System_Data/public/01_RAG_Documents/4
```

補足:

- `report_*.html` / `report_*.pdf` は runtime 成果物であり、Git pull では配布されない
- 本番で再生成APIを実行したときに、本番サーバ上で新規生成される
- `.gitignore` は開発・本番どちらでも runtime 成果物を Git 管理しないためのもの

## 5. PDF生成コマンド確認

本番で `ReportGenerator` が使う PDF生成方式を確認する。

確認対象:

- `wkhtmltopdf`
- headless Chrome / Chromium
- PHP内PDF生成
- 実行パス
- 権限
- 一時ディレクトリ

コマンド例:

```bash
which wkhtmltopdf
wkhtmltopdf --version
```

Chrome系を使う場合:

```bash
which google-chrome
which chromium
google-chrome --version
chromium --version
```

存在しない場合は、再生成API実行前に止める。

## 6. integrity check 確認

管理者ログイン後、本番画面で `support.php?project_id=4&tab=pdf` を開き、`PDFファイル整合性チェック` を実行する。

期待:

- `id=6` など既存PDFは `OK`
- `id=23/24/25` は `MISSING`
- API `document_integrity_check.php` が `200`
- Console error なし

CLIが使える場合:

```bash
php AI_System_Data/scripts/check_document_files.php
```

期待例:

```text
[OK] document_id=6 ...
[MISSING] document_id=23 ...
[MISSING] document_id=24 ...
[MISSING] document_id=25 ...
Summary: ...
```

## 7. regeneration candidate 確認

対象 document について、support の missing AI報告書カードから `再生成候補` を確認する。

期待:

- `confidence=high`
- `mode=near_rebuild`
- `exact_replay=false`
- user `chat_history` id が表示される
- assistant `chat_history` id が表示される
- reasoning session id が表示される
- reasoning steps count が表示される

APIで確認する場合:

```bash
curl -s "https://<本番ホスト>/api/report_regeneration_candidates.php?document_id=25"
```

期待:

```json
{
  "ok": true,
  "candidate": {
    "confidence": "high",
    "mode": "near_rebuild",
    "exact_replay": false
  }
}
```

`confidence=high` でない場合は再生成しない。

## 8. 再生成APIのGET拒否確認

再生成APIが `GET` で実行されないことを確認する。

```bash
curl -i "https://<本番ホスト>/api/report_regenerate_missing.php?document_id=25"
```

期待:

- `405`
- または `ok=false`
- `post_required`

GETで再生成される場合は本番実行禁止。

## 9. 本番で再生成する前の確認

実行前に以下を確認する。

- 対象 document は missing AI報告書である
- `confidence=high`
- `mode=near_rebuild`
- `exact_replay=false`
- 旧 document を更新しない設計である
- 旧 `doc_chunks` を削除しない設計である
- PHPから保存先へ書き込み可能
- PDF生成コマンドが動く
- 管理者として明示実行する
- まず 1 件だけ実行する

## 10. 再生成API実行

CSRF token の取り方は既存画面・既存API方式に合わせる。

例:

```bash
curl -X POST "https://<本番ホスト>/api/report_regenerate_missing.php" \
  -H "X-CSRF-Token: <CSRF_TOKEN>" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data "document_id=25"
```

期待:

```json
{
  "ok": true,
  "old_document_id": 25,
  "new_document_id": 29,
  "mode": "near_rebuild",
  "exact_replay": false
}
```

本番では `new_document_id` は環境により異なる。

## 11. 再生成後確認

### DB確認

```sql
SELECT id, project_id, title, file_path, created_at
FROM documents
WHERE id IN (25, <new_document_id>)
ORDER BY id;
```

期待:

- old `25` は残っている
- new `<new_document_id>` が増えている

```sql
SELECT document_id, COUNT(*) AS chunks
FROM doc_chunks
WHERE document_id IN (25, <new_document_id>)
GROUP BY document_id;
```

期待:

- old `25` の chunks は残っている
- new `<new_document_id>` の chunks が作成されている

### 実ファイル確認

```bash
ls -l AI_System_Data/public/01_RAG_Documents/4/
```

新しい `report_*.pdf` と `report_*.html` が生成されていること。

### integrity 再確認

```bash
php AI_System_Data/scripts/check_document_files.php
```

期待:

- old missing は引き続き `MISSING`
- new document は `OK`

## 12. support.php 上の最終確認

`support.php?project_id=4&tab=pdf` で確認する。

- 旧 missing カードは残っている
- 新しい report PDF カードが追加されている
- 新カードは preview できる
- `PDFファイル整合性チェック` の summary が更新されている

## 13. 実行停止条件

以下のいずれかに当てはまる場合は、本番再生成を止める。

- `git status -sb` が clean でない
- 主要 API / service ファイルが無い
- `php -l` が通らない
- 保存先ディレクトリへ PHP から書き込みできない
- PDF生成コマンドが見つからない / 実行できない
- integrity check が失敗する
- candidate が `confidence=high` でない
- regeneration API が `GET` で実行できてしまう

## 14. トラブルシュート観点

- `document_integrity_check` と `report_regeneration_candidates` を先に確認してから再生成する
- 本番の `01_RAG_Documents` 配下に PHP から書き込み権限があるか確認する
- 本番で `wkhtmltopdf` / PDF生成コマンド / パス設定が開発環境と同様に動くか確認する
- 生成後は old/new の `documents` と `doc_chunks` が共存する前提で確認する

## 15. 残課題

- 本番での再生成実行結果を踏まえて、運用手順へより具体的な失敗例を追記する
- `old_document_id` と `new_document_id` の厳密リンクは schema なしでは弱いため、将来的な `documents.status` / `documents.source_document_id` の要否を見直す
- runtime report artifacts の durable 保存運用を継続整理する
