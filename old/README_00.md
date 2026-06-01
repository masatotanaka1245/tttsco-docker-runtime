# エンタープライズ AI システム

**統合設計ドキュメント (Native PHP & MySQL 構成)**  

> **PHP** 8.2.8 | **MySQL** 8.0.33 | **Apache** 2.4.57  

---  

## 1. 要件定義書  

### 1.1 システムの目的  
東電設計株式会社における建設コンサルタント業（全11部門）の共通業務を、**PHP 8.2.8** と **MySQL 8.0.33** を活用して効率化する。完全オンプレミス環境にて、LLM（大規模言語モデル）を用いたRAG検索、画像解析、ログ自動集計を実現し、**phpMyAdmin 5.2.1** を通じてセキュアかつ直感的なデータ管理を行う。  

### 1.2 機能要件  
- **対話型AI UI**: PHP で実装したチャットインターフェース（mysqli, mbstring 拡張を利用）。  
- **RAG検索**: 過去報告書等の意味検索（MySQL 8.0.33 上でベクトルデータを管理）。  
- **フォルダ監視自動化**: 共有フォルダ（public/）を監視し、ファイル投入時に自動で DB へインデックス化。  
- **DB管理**: phpMyAdmin によるデータ保守。  

---  

## 目次  

- [概要](#概要)  
- [アーキテクチャ](#アーキテクチャ)  
- [フォルダ構成](#フォルダ構成)  
- [セットアップ手順](#セットアップ手順)  
- [運用・監視](#運用・監視)  
- [開発・拡張](#開発・拡張)  
- [ライセンス](#ライセンス)  

---  

## 概要  

| 目的 | 機能 | 技術スタック |
|------|------|--------------|
| 共通業務の IT 化・自動化 | ・対話型 AI UI (PHP UI)<br>・RAG（検索拡張生成）<br>・ログ・データ自動集計<br>・Vision/OCR 解析<br>・議事録生成 | Windows Server + MySQL<br>PHP<br>Apache + Ollama (LLM, Embedding) |

---  

## アーキテクチャ  

```
┌───────────────────────┐
│  Windows Server (Head) │
│  ├─ PHP UI             │
│  ├─ watchdog           │
│  ├─ MySQL              │
│  └─ Apache             │
└───────┬────────────────┘
        │ HTTPS (443)
        ▼
┌───────────────────────┐
│  Ollama Server (GPU)  │
│  ├─ LLM 推論 (Ollama) │
│  ├─ Embedding API     │
│  ├─ Vision/OCR API    │
│  └─ Reranker API      │
└───────────────────────┘
```

---  

## フォルダ構成  

```
AI_System_Data/
├── public/
│   ├── 01_RAG_Documents/
│   │   ├── 00_全社共通/
│   │   ├── 01_河川_砂防_海岸_海洋/
│   │   ├── 02_土質_基礎/
│   │   ├── 03_港湾_空港/
│   │   ├── 04_鋼構造_コンクリート/
│   │   ├── 05_電力土木/
│   │   ├── 06_トンネル/
│   │   ├── 07_道路/
│   │   ├── 08_建設環境/
│   │   ├── 09_都市計画_地方計画/
│   │   ├── 10_電気電子/
│   │   └── 11_地質/
│   ├── 02_Logs_Data/
│   │   ├── Incoming/
│   │   └── Archive/
│   ├── 03_Images_OCR/
│   │   ├── Incoming/
│   │   └── Archive/
│   └── 04_Meeting_Notes/
│       ├── Incoming/
│       └── Archive/
├── config/
├── scripts/
├── logs/
├── data/
├── models/
├── docs/
├── tests/
├── certs/
└── tasks/
```

- `public/` : データ投入・取り込み対象フォルダ（監視対象）  
- `config/` : 環境変数・設定ファイル (`.env`, `database.yml`, `apache.conf`)  
- `scripts/` : 取り込み・バックアップ・監視スクリプト  
- `logs/` : 各サービスのログ  
- `models/` : OCR・リランキング・LLM モデル  
- `docs/` : アーキテクチャ図・API仕様・ユーザーマニュアル  
- `tests/` : 単体・統合テスト  
- `tasks/` : Windows タスクスケジューラ用 PowerShell スクリプト  

---  

## セットアップ手順  

1. **環境変数設定**  
   `config/.env` に DB 接続情報・データルートを記載。  
   ```env
   DB_HOST=localhost
   DB_NAME=tepscoapp
   DB_USER=newuser
   DB_PASS=password
   DATA_ROOT=\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public
   ```

2. **MySQL インストール**  
   ```bash
   sudo apt-get update
   sudo apt-get install -y mysql-server
   sudo mysql -u root -p -e "CREATE DATABASE tepscoapp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```

3. **Ollama インストール**  
   ```bash
   curl -fsSL https://ollama.ai/install.sh | sh
   ollama pull mxbai-embed-large
   ```

4. **Apache 設定**  
   `config/apache.conf` を `/etc/apache2/sites-available/tepscoapp.conf` にコピーし、サイトを有効化して Apache を再起動。  
   ```bash
   sudo cp config/apache.conf /etc/apache2/sites-available/tepscoapp.conf
   sudo a2ensite tepscoapp.conf
   sudo systemctl restart apache2
   ```

5. **監視スクリプト起動**  
   ```powershell
   python scripts/monitor/monitor_services.py
   ```

---  

## 運用・監視  

- `watchdog` で `public/` を監視し、ファイル投入時に自動で DB へ取り込み。  
- `phpMyAdmin` でデータベース管理。  
- `systemd` で Apache / Ollama / 監視スクリプトをサービス化し、再起動時に自動起動。  
- `cron` で定期バックアップ（`scripts/backup/backup_data.sh`）を実行。  

---  

## 開発・拡張  

- **API**: `scripts/monitor/monitor_services.py` をベースに FastAPI で拡張可能。  
- **テスト**: `tests/` に単体・統合テストを追加。  
- **CI/CD**: GitHub Actions でビルド・テスト・デプロイを自動化。  

---  

## ライセンス  

MIT ライセンスで配布しています。詳細は `LICENSE` をご確認ください。  

---  

> **備考**  
> - 本設計はオンプレミス環境を想定しており、外部クラウドへのデータ転送は行いません。  
> - PHP 8.2.8 と MySQL 8.0.33 の組み合わせで、`phpMyAdmin` からも管理できます。  
---  