# TEPSCO Docker Runtime

このZIPは、README_01.md のシステムを Docker で起動するための最小構成です。

## 前提

- Ollama は Windows ローカルにインストール済み
- Docker Desktop がインストール済み
- アプリ本体は `AI_System_Data` 配下に配置する

## Dockerで起動するもの

- PHP 8.2 + Apache
- MySQL 8.0
- phpMyAdmin
- watchdog 用 PHP CLI コンテナ
- PDF解析用の poppler-utils
- 報告書PDF生成用の Chromium headless

## Dockerに含めないもの

- Ollama
- LLMモデル
- Embeddingモデル

OllamaはWindows側の `http://localhost:11434` で起動し、Dockerコンテナ側からは `http://host.docker.internal:11434` でアクセスします。

## 使い方

### 1. ZIPを展開

例:

```powershell
C:\tepscoapp1
```

### 2. 既存アプリを配置

既存の `AI_System_Data` の中身を、このZIP内の `AI_System_Data` に上書きコピーしてください。

必要な主な配置:

```text
AI_System_Data/
├── public/
├── config/
├── src/
├── scripts/
├── logs/
├── composer.json  ※ある場合
└── .env
```

`AI_System_Data/.env` はこのZIPに含まれているDocker用の値を参考にしてください。

### 3. OllamaモデルをWindows側で準備

PowerShellで実行:

```powershell
ollama pull mxbai-embed-large
ollama pull llama3
```

70Bを使う場合:

```powershell
ollama pull llama3:70b
```

その場合は `AI_System_Data/.env` の `OLLAMA_CHAT_MODEL` を変更します。

```env
OLLAMA_CHAT_MODEL=llama3:70b
```

### 4. Docker起動

PowerShellで、このREADMEがあるフォルダに移動して実行:

```powershell
docker compose up -d --build
```

または:

```powershell
.\scripts\start.ps1
```

### 5. アクセス

```text
アプリ:      http://localhost:8080
phpMyAdmin:  http://localhost:8081
```

### 6. Composer install

アプリ本体に `composer.json` がある場合:

```powershell
docker compose exec app composer install
```

### 7. Ollama接続確認

Windows側から:

```powershell
curl http://localhost:11434/api/tags
```

Dockerコンテナ側から:

```powershell
docker compose exec app curl http://host.docker.internal:11434/api/tags
```

または:

```powershell
.\scripts\check-ollama-from-container.ps1
```

## .env の重要設定

```env
DB_HOST=db
DB_NAME=aisystem
DB_USER=newuser
DB_PASS=password
DATA_ROOT=/data/public
OLLAMA_HOST=http://host.docker.internal:11434
OLLAMA_EMBED_MODEL=mxbai-embed-large
OLLAMA_CHAT_MODEL=llama3
```

## よくあるエラー

### debug_tools.php で .exe が Exec format error になる

DockerコンテナはLinux環境のため、`AI_System_Data/tools/*.exe` のWindows用実行ファイルは実行できません。
Dockerでは `poppler-utils` に含まれるLinux版 `pdfinfo` / `pdftotext` / `pdftoppm` を使います。
Dockerfileを更新した場合は、次のように再ビルドしてください。

```powershell
docker compose up -d --build
```

報告書モードのPDF出力は、本番WindowsではComposerで導入した mPDF を優先します。Docker検証環境では、mPDFが未導入の場合でもChromium headlessへフォールバックできます。

### Docker内からOllamaに接続できない

Windows側で Ollama が起動しているか確認します。

```powershell
ollama list
curl http://localhost:11434/api/tags
```

Docker側から確認します。

```powershell
docker compose exec app curl http://host.docker.internal:11434/api/tags
```

### public/index.php だけが表示される

このZIPには起動確認用の仮 `index.php` しか入っていません。
実アプリの `AI_System_Data/public` を配置してください。

### MySQLのテーブルを作り直したい

初期化したい場合はボリュームごと削除します。

```powershell
docker compose down -v

docker compose up -d --build
```

注意: `down -v` は MySQL データを削除します。

## ファイル構成

```text
.
├── docker-compose.yml
├── Dockerfile
├── docker/
│   ├── apache/vhost.conf
│   ├── mysql/init.sql
│   └── php/*.ini
├── scripts/
│   ├── start.ps1
│   ├── stop.ps1
│   └── check-ollama-from-container.ps1
└── AI_System_Data/
    ├── .env
    ├── public/index.php
    ├── config/
    ├── src/
    ├── scripts/
    └── logs/
```
