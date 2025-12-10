# debtapp
お金の貸し借り管理Webアプリケーション

### 重要: コード変更の反映について
このGitHubリポジトリを編集すると、自動的に再ビルドされサーバに反映されます
反映までの所要時間: 約5分
Cloud Buildが自動でビルド → Cloud Runにデプロイ

### 本番環境: https://debtapp-565547399529.asia-northeast1.run.app
⚠️ 注意: 現在デプロイ中です（ミネヤ/2025_12_09内のsqlファイルとアプリ(.zip)をデプロイ中）

### ローカルからの変更点
- ファイル指定URI
✖　http://localhost/debtapp/...
〇　https://debtapp-565547399529.asia-northeast1.run.app/...

#### 機能
- ユーザー登録・ログイン（Google OAuth対応）
- 債務の登録・編集・削除
- メールによる返済期限リマインダー
- 延滞通知の自動送信

#### 技術スタック
- バックエンド: PHP 8.2
- データベース: MySQL (Cloud SQL)
- メール送信: PHPMailer (Gmail SMTP)
- 認証: Google OAuth 2.0
- インフラ: Google Cloud Run
- 自動化: Cloud Scheduler (cron), Cloud Build

#### インフラ構成
- Cloud Run: PHPアプリケーションのホスティング
- Cloud SQL: MySQLデータベース
- Cloud Scheduler: 定期実行タスク（リマインダー、延滞通知）
- Secret Manager: 機密情報の管理
- Cloud Build: 自動ビルド&デプロイ
