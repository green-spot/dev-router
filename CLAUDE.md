<!-- Pave MCP -->
このプロジェクトでは Pave MCP を使用してコンテンツ品質を管理しています。
コンフィグは MCPツール（`save_config`）・Hub 管理画面のどちらからでも変更できます。
各ルールのコンテンツは `paved/{ルール名}/` 配下に配置してください。
以下のルールに従ってください。

### ドキュメント管理（docルール）
- ドキュメントの作成・編集時は、必ず最初に `guide_write(rule="doc")` を呼び出し、返却されたガイドに従うこと
- ドキュメントに関するコンフィグの作成・編集時は `guide_config(rule="doc")` を呼び出すこと
- ドキュメント完成後は `validate_content(rule="doc")` で検証すること

### ノート管理（noteルール）
- ノートの作成・編集時は、必ず最初に `guide_write(rule="note")` を呼び出し、返却されたガイドに従うこと
- ノートに関するコンフィグの作成・編集時は `guide_config(rule="note")` を呼び出すこと
- ノート完成後は `validate_content(rule="note")` で検証すること

### タスク管理（taskルール）
- タスクの作成・編集時は、必ず最初に `guide_write(rule="task")` を呼び出し、返却されたガイドに従うこと
- タスクに関するコンフィグの作成・編集時は `guide_config(rule="task")` を呼び出すこと
- タスク完成後は `validate_content(rule="task")` で検証すること

### Pave MCP設定の更新時
- Hub管理画面でルールの追加・変更を行った場合は `init` を再実行し、このセクションを更新すること
<!-- /Pave MCP -->
