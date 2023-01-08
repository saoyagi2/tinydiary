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
    $date = $_REQUEST['date'] ?? date('Ymd');
    $article = $this->db->query("SELECT * FROM articles WHERE date = :date", [':date' => $date])[0] ?? ['date' => $date, 'message' => ""];
    View::display_edit($article);
  }

  /**
   * 日記更新
   */
  private function update() : void
  {
    $date = sprintf("%04d%02d%02d", $_REQUEST['year'], $_REQUEST['month'], $_REQUEST['day']);
    $message = $_REQUEST['message'];
    $this->db->query("REPLACE INTO articles (date, message) VALUES(:date, :message)", [':date' => $date, ':message' => $message]);
    header("Location: index.php?date={$date}");
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
    $contents = "";
    foreach($articles as $article) {
      $year = (int)substr($article['date'], 0, 4);
      $month = (int)substr($article['date'], 4, 2);
      $day = (int)substr($article['date'], 6, 2);
      if(!checkdate($month, $day, $year)) {
        continue;
      }
      $weekday = ["日", "月", "火", "水", "木", "金", "土"][(int)date("w", strtotime(sprintf("%04d-%02d-%02d", $year, $month, $day)))];

      $message = "";
      foreach(explode("\n", str_replace(array("\r\n", "\r", "\n"), "\n", $article['message'])) as $_message) {
        $message .= "<p>" . View::h($_message) . "</p>";
      }

      $contents .= <<<HTML
        <div class="article">
          <div class="date">{$year}年{$month}月{$day}日({$weekday})</div>
          <div class="message">{$message}</div>
        </div>
        HTML;
    }
    View::output(['contents' => $contents]);
  }

  /**
   * 編集画面
   *
   * @param array $article 日記データ
   */
  public static function display_edit(array $article) : void
  {
    $year = (int)substr($article['date'], 0, 4);
    $month = (int)substr($article['date'], 4, 2);
    $day = (int)substr($article['date'], 6, 2);
    $message = View::h($article['message']);

    $contents = <<<HTML
      <form action="index.php" method="POST">
        <input type="hidden" name="mode" value="update">年
        <input type="text" name="year" value="${year}">年
        <input type="text" name="month" value="${month}">月
        <input type="text" name="day" value="${day}">日
        <textarea name="message">{$message}</textarea>
        <input type="submit" value="更新">
      </form>
      HTML;
    View::output(['contents' => $contents]);
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

  /**
  * HTML エスケープ
  *
  * @param string $str エスケープする文字列
  * @return string エスケープ済文字列
  */
  private static function h(string $str) : string
  {
    return(htmlspecialchars($str, ENT_QUOTES, 'UTF-8'));
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
