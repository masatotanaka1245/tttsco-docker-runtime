# TROUBLESHOOTING.md

このファイルは、Codex / Continue Agent / Qwen などの作業中に発生したエラー、詰まり、誤判定、環境依存問題を記録し、再発防止に使う。

`TODO.md` は「次にやること」だけを管理する。
`TROUBLESHOOTING.md` は「過去に起きた問題・原因・対処・再発防止」を管理する。

## 記録ルール

エラーや詰まりが発生した場合、作業完了前に必要に応じてこのファイルへ短く記録する。

記録するのは以下のようなケース。

- 同じエラーが再発しそうなもの
- 環境依存で原因が分かりにくいもの
- Codex が誤解しやすい操作
- Docker / Git / DB / encoding / permission / network の問題
- 一度調べないと判断できなかったもの
- 次回の作業者が知っていると早く解決できるもの

記録しないもの。

- 一時的で再発性が低いもの
- すぐ直した単純な typo
- `TODO.md` に書くべき未作業タスク
- runtime log の丸写し
- 秘密情報、password、token、cookie、個人情報

## 記録フォーマット

```md
## YYYY-MM-DD: タイトル

### 症状
何が起きたか。

### 原因
分かった原因。未確定なら「推定」と書く。

### 対処
実際に行った対応。

### 再発防止
次回どう防ぐか。

### 関連ファイル
- `path/to/file`
```

## 2026-07-05: Git push が DNS失敗する

### 症状

`git push` が以下で失敗する。

`Could not resolve host: github.com`

### 原因

Codex 実行環境または一時的なネットワーク / DNS の問題。

### 対処

Codex 側で無理に再試行を続けず、ユーザーの手元ターミナルで push する。

```bash
git status -sb
git push
git status -sb
```

### 再発防止

push 失敗時は、DNS失敗 / 認証失敗 / 環境ポリシー拒否 / remote rejection を区別して報告する。

### 関連ファイル

- `.git/config`

## 2026-07-05: Docker socket 権限不足で docker compose ps が失敗する

### 症状

`docker compose ps` が Docker socket 権限不足で失敗する。

`permission denied while trying to connect to the docker API`

### 原因

Codex 実行環境から Docker socket にアクセスできない。

### 対処

Codex 側では Docker の起動状態確認を無理に続けない。
ユーザーの手元ターミナルで確認する。

```bash
docker compose ps
docker ps
```

### 再発防止

Codex 依頼文では、Docker 操作が必要な場合に「権限エラーなら停止して報告」と明記する。

### 関連ファイル

- `docker-compose.yml`
- `README_DOCKER.md`

## 2026-07-05: Codex実行環境から localhost に到達できない

### 症状

`curl -I http://localhost:8080` または `curl -I http://127.0.0.1:8080` が失敗する。

`curl: (7) Failed to connect`

### 原因

アプリ未起動、port 違い、または Codex 実行環境とユーザーのローカル環境が分離されている可能性。

### 対処

Codex 側では HTTP POST 確認を進めない。
ユーザーの手元ブラウザまたはターミナルで確認する。

### 再発防止

HTTP 確認タスクでは最初に base URL / port / Docker 状態を確認する。
到達できない場合は、認証・CSRF 確認へ進まない。

### 関連ファイル

- `docker-compose.yml`
- `README_DOCKER.md`
- `AI_System_Data/public/api/save_user_settings.php`

## 2026-07-05: DB backup dump が untracked になる

### 症状

migration 前 backup が `git status` に untracked として出る。

`?? AI_System_Data/backups/`

### 原因

DB dump を repository 内に作成したため。

### 対処

backup dump は repository 外へ移動する。

```bash
mkdir -p ~/tepsco_db_backups
mv AI_System_Data/backups/*.sql ~/tepsco_db_backups/
```

`.gitignore` に以下を追加する。

`/AI_System_Data/backups/`

### 再発防止

backup は commit しない。
DB dump にはユーザー情報や履歴が含まれる可能性があるため、repository 外で保管する。

### 関連ファイル

- `.gitignore`
- `AI_System_Data/backups/`

## 2026-08-04: chat_debug.log が0行のままになる

### 症状

`chat_debug.log` が0行のままとなり、アプリケーションログを追記できない。

### 原因

bind mount 上のログファイルが `root:root 644` になると、Apache/PHP 実行ユーザー `www-data` が追記できない。

### 対処

`stat` と `www-data` での `test -w` を確認し、app サービスだけを再作成する。

### 再発防止

Compose 起動時に `chat_debug.log` の所有者・権限を初期化する。

### 関連ファイル

- `docker-compose.yml`
- `AI_System_Data/src/AppLogger.php`

## 2026-08-04: entry質問分解stepが履歴に紐付かない

### 症状

entry質問分解stepの `chat_history_id` が `NULL` のままとなり、質問・案件・スレッド単位で追跡できない。

### 原因

user履歴IDをentry recorderへ渡せず、entry sessionと後段reasoning sessionの紐付け規則が分かれていた。

### 対処

新規entry stepは専用sessionで保存し、ルート完了後に対応user履歴へ `chat_history_id IS NULL` 条件でbindする。packetは `search_context` のJSONから確認する。

### 再発防止

既存の未紐付けstepを自動修復せず、新規stepだけを対象にする。確認時は `chat_reasoning_steps` から `chat_history` をJOINしてproject/threadを照合する。

### 関連ファイル

- `AI_System_Data/public/api/chat.php`
- `AI_System_Data/src/AdvancedReasoningStepRecorder.php`
- `AI_System_Data/src/AdvancedRouteFinalizer.php`
