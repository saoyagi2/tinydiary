<?php
require 'config.php';

{
  $app = new App($config);
  $app->run();
  exit();
}

class App {
  /** @var DB */
  private $db;

  /**
   * コンストラクタ
   *
   * @param array $config 設定情報
   */
  public function __construct(array $config)
  {
    $this->db = new DB($config['db_path']);
  }

  /**
   * 実行
   */
  public function run() : void
  {
    switch($_REQUEST['mode']) {
      case 'edit':
        $this->edit();
        break;
      case 'update':
        $this->update();
        break;
      case 'view':
      default:
        $this->view();
    }
  }

  /**
   * 日記表示画面
   */
  private function view() : void
  {
    $articles = $this->db->query("SELECT * FROM articles");
    View::display_view($articles);
  }

  /**
   * 日記編集画面
   */
  private function edit() : void
  {
  }

  /**
   * 日記更新
   */
  private function update() : void
  {
  }
}

class View {
  /**
   * 表示画面
   *
   * @param array $articles 日記データ
   */
  public static function display_view(array $articles) : void
  {
    $contents = "hogehoge";
    View::output(['contents' => $contents]);
  }

  /**
   * 編集画面
   *
   * @param array $article 日記データ
   */
  public static function display_edit(array $article) : void
  {
  }

  /**
   * 出力
   *
   * @param array $viewdata 表示データ
   */
  private static function output($viewdata) : void
  {
    print <<<HTML
      <!DOCTYPE html>
      <html lang="ja">
        <head>
          <link rel="stylesheet" href="style.css" type="text/css" title="base">
          <meta name="viewport" content="width=device-width, initial-scale=1">
        </head>
        <body>
          <div id="container">
            <header id="header">
            </header>
            <div id="contents">
              {$viewdata['contents']}
            </div>
            <footer id="footer">
            </footer>
          </div>
        </body>
      </html>
      HTML;
  }
}

class DB {
  /** @var PDO */
  private $db_conn = NULL;

  /**
   * コンストラクタ
   *
   * @param string $db_path DBファイルへのパス
   */
  public function __construct(string $db_path)
  {
    try {
      $this->connect_database($db_path);
      $this->create_schema();
    }
    catch(PDOException $e) {
      print $e->getMessage();
      exit();
    }
  }

  /**
   * DB接続
   *
   * @param string $db_path DBファイルへのパス
   */
  private function connect_database(string $db_path) : void
  {
    $this->db_conn = new PDO('sqlite:' . __DIR__ . '/' . $db_path, NULL, NULL, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
  }

  /**
   * スキーマ作成
   */
  private function create_schema() : void
  {
    $this->db_conn->exec("CREATE TABLE IF NOT EXISTS articles(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      date TEXT UNIQUE NOT NULL,
      message TEXT
    )");
    $this->db_conn->exec("CREATE INDEX IF NOT EXISTS article_idx ON articles (date)");
  }

  /**
   * クエリ実行
   *
   * @param string $sql クエリ
   * @param array $params パラメータ
   * @return array レコード配列
   */
  function query(string $sql, array $params = []) : array
  {
    $stmt = $this->db_conn->prepare($sql);
    $stmt->execute($params);
    return($stmt->fetchAll());
  }
}
