# 新プロジェクトの Markdown 運用方針

## 推奨リポジトリ構成（提案）

```text
new-openwebui-project/
├── README.md
├── AGENTS.md
├── TODO.md
├── TROUBLESHOOTING.md
├── docs/
│   ├── architecture.md
│   ├── feature-specs.md
│   ├── data-model.md
│   ├── tool-api.md
│   ├── security.md
│   └── migration-decisions.md
├── backend/                 # FastAPI / worker
├── portal/                  # 独自業務フロントエンド
├── infra/
└── openwebui/               # Functions・設定例・Tool登録手順のみ
```

## 各Markdownの責務

| ファイル | 記載すること | 記載しないこと |
| --- | --- | --- |
| `README.md` | 全体像、前提、最短起動、環境変数の参照先、検証入口 | 詳細な仕様、作業履歴、秘密情報 |
| `AGENTS.md` | Plan → Do → Check/Debug → Act、編集・検証・ログ・セキュリティの規則 | プロダクト固有の未決定仕様 |
| `TODO.md` | Now 1件、Next 最大3件、各項目の完了条件 | 完了済みの作業日誌、調査メモの全文 |
| `TROUBLESHOOTING.md` | 症状、原因、対処、再発防止、関連ファイル | パスワード、トークン、個人情報、DBダンプ |
| `docs/` | 判断済みの仕様、境界、API、データ、移行判断 | 一時的な作業ログ |

## TODO.md テンプレート

```md
# TODO

更新日: YYYY-MM-DD

## Now

- [ ] OpenAPI Tool の案件ACL契約を確定する
  - 完了条件: OpenAPIスキーマ、認可ルール、拒否時レスポンス、テスト観点を `docs/tool-api.md` に記載する。

## Next

- [ ] Open WebUI Knowledge の日本語PDF検索をPoC評価する
- [ ] CSV安全集計APIの最小実装を作る
- [ ] 非同期PDF生成ジョブの入出力を設計する

## Blocked

- なし
```

## 作業サイクル

1. **Plan**: 変更種別、対象、成功条件、影響する仕様書を決める。
2. **Do**: 小さく実装し、Open WebUI本体ではなく外部境界を優先する。
3. **Check / Debug**: API契約、認可、ジョブ失敗、RAG根拠、更新互換性を確認する。
4. **Act**: 決定事項を `docs/`、再発事項を `TROUBLESHOOTING.md`、次の未完了を `TODO.md` に反映する。

## 最初に作るべき資料

- `docs/architecture.md`: コンポーネント、データ境界、認証境界
- `docs/tool-api.md`: OpenAPI Toolの入出力、認可、非同期ジョブ
- `docs/data-model.md`: 案件、メンバー、成果物、ジョブ、データカタログ
- `docs/security.md`: Open WebUIと業務APIのID連携、権限、秘密情報、監査
- `docs/migration-decisions.md`: 移行する/しない機能と根拠
