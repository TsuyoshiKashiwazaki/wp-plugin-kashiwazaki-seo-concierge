=== Kashiwazaki SEO Concierge ===
Contributors: tsuyoshikashiwazaki
Tags: ai, chatbot, search, seo, openai
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

sitemap.xml と llms.txt を解析し、訪問者の質問に応じてサイト内の最適なコンテンツを AI が案内するフローティング型チャットボットを設置します。

== Description ==

Kashiwazaki SEO Concierge は、サイトの sitemap.xml と（存在すれば）llms.txt を解析してページを把握し、訪問者の自然言語の質問に対して、最も適切なサイト内コンテンツを OpenAI の埋め込み検索と Chat Completions で案内するフローティング型チャットボットです。

主な機能:

* sitemap.xml / llms.txt の解析とメタ情報のキャッシュ（カスタムテーブル + WP-Cron 差分更新）
* OpenAI text-embedding-3-small による埋め込み検索（コサイン類似度・正規化済みベクトルをパック保存）
* Chat Completions（gpt-4o-mini）＋ Structured Outputs による回答と候補ページの提示
* 回答の根拠（一致理由・関連度スコア・最終更新日）の表示
* ゼロ回答時のフォールバック導線、回答キャッシュ、コスト上限サーキットブレーカー
* プロンプトインジェクション/乱用ガード、レート制限、ブロックリスト、PII マスク
* WordPress プライバシー API 連携、ログ保持期間の自動削除
* 会話ログ・クエリ分析（コンテンツギャップ可視化・CSV エクスポート）、GA4/dataLayer 連携
* アクセシブルな（WCAG 配慮）軽量フロント UI（jQuery 非依存）
* 拡張フックと REST API、品質テスト用サンドボックス

== Installation ==

1. プラグインを `/wp-content/plugins/wp-plugin-kashiwazaki-seo-concierge` にアップロードします。
2. 管理画面の「プラグイン」メニューから有効化します。
3. 管理メニュー「Kashiwazaki SEO Concierge」から OpenAI API キーと sitemap.xml / llms.txt の URL を設定します。
4. 「インデックス」タブで「今すぐ再構築」を実行してインデックスを作成します。

API キーは wp-config.php に `define( 'KS_CONCIERGE_API_KEY', 'sk-...' );` を追加することで、データベースに保存せず注入できます（推奨）。

== Frequently Asked Questions ==

= 質問はどこへ送信されますか？ =

質問は回答生成のため OpenAI に送信されます。PII（メール・電話番号・郵便番号など）は送信前に best-effort でマスクされます。機微業種では「PII 検出時は外部送信せず定型応答」モードを推奨します。

== Changelog ==

= 1.0.0 =
* 初回リリース。
