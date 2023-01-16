<?php
require "config.php";

{
  $app = new App($config);
  $app->run();
  exit();
}

class App {
  const SEARCH_LIMIT = 20;

  /** @var config */
  private $config;
  /** @var DB */
  private $db;
  private $view;

  /**
   * コンストラクタ
   *
   * @param array $config 設定情報
   */
  public function __construct(array $config)
  {
    $this->config = $config;
    $this->db = new DB($this->config["db_path"]);
    $this->view = new View();
  }

  /**
   * 実行
   */
  public function run() : void
  {
    $mode = $_REQUEST["mode"] ?? "";
    switch($mode) {
      case "edit":
        $this->edit();
        break;
      case "update":
        $this->update();
        break;
      case "search":
        $this->search();
        break;
      case "show":
      default:
        $this->show();
    }
  }

  /**
   * 日記表示画面
   */
  private function show() : void
  {
    $year = (int)($_GET["year"] ?? date("Y"));
    $month = (int)($_GET["month"] ?? date("m"));

    $params = [
      ":year" => $year,
      ":month" => $month,
    ];
    $sql = "SELECT * FROM articles WHERE year = :year AND month = :month";

    $articles = $this->db->query($sql, $params);
    if($year < (int)date("Y") || $year == (int)date("Y") && $month <= (int)date("m")) {
      if($year == (int)date("Y") && $month == (int)date("m")) {
        $lastday = (int)date("d");
      }
      else {
        $lastday = (int)date("t", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1)));
      }
      for($day = 1; $day <= $lastday; $day++) {
        if(count(array_filter($articles, function($article) use($year, $month, $day) {
          return((int)$article["year"] === $year && (int)$article["month"] === $month && (int)$article["day"] === $day);
        })) == 0) {
          $articles[] = ["year" => $year, "month" => $month, "day" => $day, "message" => ""];
        }
      }
      usort($articles, function($a, $b) {
        return($a["day"] <=> $b["day"]);
      });
    }

    $this->view->display_show(["title" => $this->config["title"], "articles" => $articles, "year" => $year, "month" => $month]);
  }

  /**
   * 日記検索結果画面
   */
  private function search() : void
  {
    $keyword = $_REQUEST["keyword"] ?? "";

    $wheres = [];
    $params = [];
    foreach(explode(" ", $keyword) as $_keyword) {
      $wheres[] = "message LIKE ?";
      $params[] = "%{$_keyword}%";
    }
    $sql = "SELECT * FROM articles WHERE " . implode(" AND ", $wheres) . " ORDER BY year DESC, month DESC, day DESC LIMIT 21";

    $articles = $this->db->query($sql, $params);
    $limited = count($articles) > App::SEARCH_LIMIT;
    $articles = array_slice($articles, 0, App::SEARCH_LIMIT);

    $this->view->display_show(["title" => $this->config["title"], "articles" => $articles, "keyword" => $keyword, "limited" => $limited]);
  }

  /**
   * 日記編集画面
   */
  private function edit() : void
  {
    $year = (int)($_REQUEST["year"] ?? date("Y"));
    $month = (int)($_REQUEST["month"] ?? date("m"));
    $day = (int)($_REQUEST["day"] ?? date("d"));
    $article = $this->db->query(
      "SELECT * FROM articles WHERE year = :year AND month = :month AND day = :day",
      [
        ":year" => $year,
        ":month" => $month,
        ":day" => $day
      ])[0] ??
      [
        "year" => $year,
        "month" => $month,
        "day" => $day,
        "message" => ""
      ];
    $this->view->display_edit(["title" => $this->config["title"], "article" => $article]);
  }

  /**
   * 日記更新
   */
  private function update() : void
  {
    $year = (int)($_REQUEST["year"]);
    $month = (int)($_REQUEST["month"]);
    $day = (int)($_REQUEST["day"]);
    $message = $_REQUEST["message"];
    $this->db->query(
      "REPLACE INTO articles (year, month, day, message) VALUES(:year, :month, :day, :message)",
      [
        ":year" => $year,
        ":month" => $month,
        ":day" => $day,
        ":message" => $message
      ]);
    header("Location: index.php?year={$year}&month={$month}");
  }
}

class View {
  /**
   * 表示画面
   *
   * @param array $viewdata 表示データ
   */
  public function display_show(array $viewdata) : void
  {
    $year = $viewdata["year"] ?? "";
    $month = $viewdata["month"] ?? "";
    $keyword = $viewdata["keyword"] ?? "";
    $limited = $viewdata["limited"] ?? false;

    $contents = "";

    $contents .= <<<HTML
      <form action="index.php?mode=view" method="GET">
        <label>検索:
          <input type="hidden" name="mode" value="search">
          <input type="type" name="keyword" value="{$keyword}">
        </label>
        <input type="submit" value="検索">
      </form>
      HTML;
    if(!empty($year) && !empty($month)) { // 年月表示モード
      $contents .= "<div class=\"navi\"><ul>";
      $prev_year = (int)(date("Y", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "-1 month")));
      $prev_month = (int)(date("n", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "-1 month")));
      if($prev_year < (int)(date("Y")) || ($prev_year == (int)(date("Y")) && $prev_month <= (int)(date("m")))) {
        $contents .= "<li><a href=\"index.php?year={$prev_year}&amp;month={$prev_month}\">前月</a></li>";
      }
      $next_year = (int)(date("Y", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "+1 month")));
      $next_month = (int)(date("n", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "+1 month")));
      if($next_year < (int)(date("Y")) || ($next_year == (int)(date("Y")) && $next_month <= (int)(date("m")))) {
        $contents .= "<li><a href=\"index.php?year={$next_year}&amp;month={$next_month}\">翌月</a></li>";
      }
      $contents .= "</ul></div>";
    }
    foreach($viewdata["articles"] as $article) {
      $contents .= $this->_view_daily($article);
    }
    if($limited) {
      $contents .= "<p>制限以上ヒットしたため省略しました</p>";
    }

    $this->output(["title" => $viewdata["title"], "contents" => $contents]);
  }

  /**
   * 編集画面
   *
   * @param array $viewdata 表示データ
   */
  public function display_edit(array $viewdata) : void
  {
    $year = (int)$viewdata["article"]["year"];
    $month = (int)$viewdata["article"]["month"];
    $day = (int)$viewdata["article"]["day"];
    $weekday = ["日", "月", "火", "水", "木", "金", "土"][(int)date("w", strtotime(sprintf("%04d-%02d-%02d", $year, $month, $day)))];
    $message = $this->h($viewdata["article"]["message"]);

    $contents = <<<HTML
      <div class="date">{$year}年{$month}月{$day}日({$weekday})</div>
      <div class="message">
      <form action="index.php" method="POST">
        <input type="hidden" name="mode" value="update">
        <input type="hidden" name="year" value="${year}">
        <input type="hidden" name="month" value="${month}">
        <input type="hidden" name="day" value="${day}">
        <textarea name="message">{$message}</textarea>
        <input type="submit" value="更新">
      </form>
      HTML;
    $this->output(["title" => $viewdata["title"], "contents" => $contents]);
  }

  /**
   * 出力
   *
   * @param array $viewdata 表示データ
   */
  private function output($viewdata) : void
  {
    $title = $this->h($viewdata["title"]);

    print <<<HTML
      <!DOCTYPE html>
      <html lang="ja">
        <head>
          <title>{$title}</title>
          <link rel="stylesheet" href="style.css" type="text/css" title="base">
          <meta name="viewport" content="width=device-width, initial-scale=1">
        </head>
        <body>
          <div id="container">
            <header id="header">
              <h1><a href="index.php">{$title}</a></h1>
            </header>
            <div id="contents">
              {$viewdata["contents"]}
            </div>
            <footer id="footer">
            </footer>
          </div>
        </body>
      </html>
      HTML;
  }

  /**
   * 日記1日分表示
   *
   * @param array $article 1日分日記データ
   */
  private function _view_daily(array $article) : string
  {
    $contents = "";

    $year = (int)$article["year"];
    $month = (int)$article["month"];
    $day = (int)$article["day"];

    $weekday = ["日", "月", "火", "水", "木", "金", "土"][(int)date("w", strtotime(sprintf("%04d-%02d-%02d", $year, $month, $day)))];

    $message = "";
    if(!empty($article)) {
      foreach(explode("\n", str_replace(array("\r\n", "\r", "\n"), "\n", $article["message"])) as $_message) {
        $message .= "<p>" . $this->h($_message) . "</p>";
      }
    }

    $date = sprintf("%04d%02d%02d", $year, $month, $day);
    $contents .= <<<HTML
      <div class="article" id="d{$date}">
        <div class="date">{$year}年{$month}月{$day}日({$weekday})</div>
        <div class="links"><a href="index.php?mode=edit&amp;year={$year}&amp;month={$month}&amp;day={$day}">編集</a></div>
        <div class="message">{$message}</div>
      </div>
      HTML;

    return($contents);
  }

  /**
  * HTML エスケープ
  *
  * @param string $str エスケープする文字列
  * @return string エスケープ済文字列
  */
  private function h(string $str) : string
  {
    return(htmlspecialchars($str, ENT_QUOTES, "UTF-8"));
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
    $this->db_conn = new PDO("sqlite:" . __DIR__ . "/" . $db_path, NULL, NULL, [
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
      year INTGER NOT NULL,
      month INTGER NOT NULL,
      day INTGER NOT NULL,
      message TEXT
    )");
    $this->db_conn->exec("CREATE UNIQUE INDEX IF NOT EXISTS article_ymd_idx ON articles (year, month, day)");
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
