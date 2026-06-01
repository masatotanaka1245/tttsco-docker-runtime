# 2026-06-01 DBスキーマ確認・補正手順

対象環境:

- PHP 8.2.8
- MySQL 8.0.33
- Apache 2.4.57
- DB名: `tepscoapp`

## 1. 事前バックアップ

phpMyAdmin または mysqldump で、必ず `tepscoapp` のバックアップを取得してください。

推奨ファイル名:

```text
tepscoapp_backup_before_20260601_schema_align.sql
```

## 2. 差分チェック

phpMyAdminで `tepscoapp` を選択し、次のファイルのSQLを実行します。

```text
AI_System_Data/config/schema_check.sql
```

確認する結果:

- `MISSING_COLUMN`: 本番DBに不足しているカラム
- `EXTRA_COLUMN`: 本番DBにだけ存在するカラム
- `TYPE_MISMATCH`: 型またはNULL許容が期待値と違うカラム
- `SUMMARY`: 期待カラム数と実カラム数

期待値:

```text
SUMMARY  expected_columns=106  actual_columns_in_expected_tables=106
```

差分行が表示されず、`SUMMARY` が `106 / 106` なら補正SQLは不要です。

## 3. 差分補正

差分チェックで以下が出た場合は、補正SQLを実行します。

- `logs.ip_address` が `EXTRA_COLUMN`
- `chat_reasoning_steps.original_question` が `text`
- `chat_reasoning_steps.search_context` が `text`
- `chat_reasoning_steps.sub_answer` が `text`
- `users.department` が `varchar(128)`

phpMyAdminで次のファイルのSQLを実行します。

```text
AI_System_Data/config/migrate_20260601_align_schema.sql
```

このSQLは `INFORMATION_SCHEMA` を確認してから実行するため、対象カラムが存在しない場合はスキップします。

## 4. 再チェック

補正後に、もう一度以下を実行します。

```text
AI_System_Data/config/schema_check.sql
```

期待値:

```text
SUMMARY  expected_columns=106  actual_columns_in_expected_tables=106
```

`MISSING_COLUMN` / `EXTRA_COLUMN` / `TYPE_MISMATCH` の結果が表示されなければ完了です。

## 5. 補正内容

`migrate_20260601_align_schema.sql` が行う変更:

- `users.department` を `VARCHAR(100) NULL` に変更
- `chat_reasoning_steps.original_question` を `LONGTEXT NOT NULL` に変更
- `chat_reasoning_steps.search_context` を `LONGTEXT NULL` に変更
- `chat_reasoning_steps.sub_answer` を `LONGTEXT NULL` に変更
- `logs.ip_address` を削除

## 6. 注意

`logs.ip_address` を残したい場合は、`migrate_20260601_align_schema.sql` の最後の `ALTER TABLE logs DROP COLUMN ip_address` ブロックを実行しないでください。

ただし、現在のアプリコードと `README_01.md` の本番DB定義では `logs.ip_address` は使用していません。
