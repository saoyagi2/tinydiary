# tinydiary

軽量で最低限の機能のみを実装したウェブ日記。

## 動作環境

* PHP 7.0以上
    - PDO(SQLite)
* SQlite

## インストール方法

1. index.php をウェブからアクセス可能なディレクトリに置きます。
2. config-original.php を config.php にリネームし、index.php と同じディレクトリに置きます。
3. config.php を自分の環境に合わせて編集します。
    - `db_path` はデータベースファイルのパスです。ウェブに公開されない場所にします。
    - `title` は日記のタイトルです。ヘッダ等に表示されます。
    - `password` は編集モードのパスワードです。他人に知られないようにしましょう。
    - `css` はカスタム CSS ファイルのパスです。未指定の場合 `default.css` が適用されます。
    - `favicon` は favicon へのパスです。
4. index.php にウェブブラウザからアクセスします。初回アクセス時に DB ファイルが自動的に作成されます。

## データ構造

SQLite に格納されるテーブルは以下の通りです。

### articles テーブル

| カラム | 型 | 内容 | 制約、その他 |
|---|---|---|---|
| id | INTEGER | プライマリキー | AUTOINCREMENT |
| year | INTEGER | 年 | NOT NULL, year/month/dayで複合unique index |
| month | INTEGER | 月 | NOT NULL |
| day | INTEGER | 日 | NOT NULL |
| message | TEXT | 日記本文 | |
