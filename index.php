<?php
require "config.php";

{
  $app = new App($config);
  $app->run();
  exit();
}

/**
 * アプリケーションクラス
 */
class App {
  /** @var array config 設定情報 */
  private $config;
  /** @var DB $db データベースオブジェクト */
  private $db;
  /** @var View $view 表示オブジェクト */
  private $view;

  /** @var int 検索件数上限 */ 
  const SEARCH_LIMIT = 20;

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
    $mode = $_POST["mode"] ?? $_GET["mode"] ?? "";
    $method = $_SERVER["REQUEST_METHOD"];
    if($method === "POST" && $mode === "update") {
      $this->update();
    }
    elseif($method === "GET" && $mode === "edit") {
      $this->edit();
    }
    elseif($method === "GET" && $mode === "search") {
      $this->search();
    }
    else {
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

    if(checkdate($month, 1, $year)) {
      $params = [
        ":year" => $year,
        ":month" => $month,
      ];
      $sql = "SELECT * FROM articles WHERE year = :year AND month = :month";
      $articles = $this->db->query($sql, $params);
      $articles = $this->interpolate_articles($articles, $year, $month);
    }
    else {
      $articles = [];
    }

    $this->view->display_show(["title" => $this->config["title"], "articles" => $articles, "year" => $year, "month" => $month]);
  }

  /**
   * 日記検索結果画面
   */
  private function search() : void
  {
    $keyword = $_GET["keyword"] ?? "";

    $wheres = [];
    $params = [];
    foreach(explode(" ", $keyword) as $_keyword) {
      if(empty($_keyword)) {
        continue;
      }
      $wheres[] = "message LIKE ?";
      $params[] = "%" . preg_replace('/(?=[!_%])/', '!', $_keyword) . "%";
    }
    if(!empty($wheres)) {
      $sql = "SELECT * FROM articles WHERE " . implode(" AND ", $wheres) . " ESCAPE '!' ORDER BY year DESC, month DESC, day DESC LIMIT 21";
      $articles = $this->db->query($sql, $params);
      $search_limited = count($articles) > App::SEARCH_LIMIT;
      $articles = array_slice($articles, 0, App::SEARCH_LIMIT);
    }
    else {
      $articles = [];
      $search_limited = false;
    }

    $this->view->display_show(["title" => $this->config["title"], "articles" => $articles, "keyword" => $keyword, "search_limited" => $search_limited]);
  }

  /**
   * 日記編集画面
   */
  private function edit() : void
  {
    $year = (int)($_GET["year"] ?? date("Y"));
    $month = (int)($_GET["month"] ?? date("m"));
    $day = (int)($_GET["day"] ?? date("d"));
    $article = $this->db->query(
      "SELECT * FROM articles WHERE year = :year AND month = :month AND day = :day",
      [
        ":year" => $year,
        ":month" => $month,
        ":day" => $day
      ])[0];
    if(empty($article)) {
      $article = [
        "year" => $year,
        "month" => $month,
        "day" => $day,
        "message" => ""
      ];
    }

    $this->view->display_edit(["title" => $this->config["title"], "article" => $article]);
  }

  /**
   * 日記更新
   */
  private function update() : void
  {
    $year = (int)($_POST["year"] ?? 0);
    $month = (int)($_POST["month"] ?? 0);
    $day = (int)($_POST["day"] ?? 0);
    $message = $_POST["message"] ?? "";
    if(!checkdate($month, $day, $year)) {
      header("Location: index.php");
      return;
    }

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

  /**
   * 日記データ補完
   *
   * @param array $articles 欠落込み日記データ
   * @param int $year 年
   * @param int $month 月
   * @return arrray $articles 補完済日記データ
   */
  private function interpolate_articles($articles, $year, $month) : array
  {
    $thisyear = (int)date("Y");
    $thismonth = (int)date("m");

    // 来月以降の日記は表示しない
    if($year > $thisyear || ($year == $thisyear && $month > $thismonth)) {
      return([]);
    }

    if($year == $thisyear && $month == $thismonth) {
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

    return($articles);
  }
}

/**
 * 表示クラス
 */
class View {
  /**
   * 表示画面
   *
   * @param array $viewdata 表示データ
   */
  public function display_show(array $viewdata) : void
  {
    $year = (int)($viewdata["year"] ?? 0);
    $month = (int)($viewdata["month"] ?? 0);
    $keyword = $viewdata["keyword"] ?? "";
    $search_limited = $viewdata["search_limited"] ?? false;

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

    if(checkdate($month, 1, $year)) { // 年月表示モードなら前月・翌月ナビ表示
      $thisyear = (int)date("Y");
      $thismonth = (int)date("m");

      $contents .= "<div class=\"navi\"><ul>";
      $prev_year = (int)(date("Y", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "-1 month")));
      $prev_month = (int)(date("n", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "-1 month")));
      if($prev_year < $thisyear || ($prev_year == $thisyear && $prev_month <= $thismonth)) {
        $contents .= "<li><a href=\"index.php?year={$prev_year}&amp;month={$prev_month}\">前月</a></li>";
      }
      $next_year = (int)(date("Y", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "+1 month")));
      $next_month = (int)(date("n", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "+1 month")));
      if($next_year < $thisyear || ($next_year == $thisyear && $next_month <= $thismonth)) {
        $contents .= "<li><a href=\"index.php?year={$next_year}&amp;month={$next_month}\">翌月</a></li>";
      }
      $contents .= "</ul></div>";
    }

    $displayyear = 0;
    $displaymonth = 0;
    foreach($viewdata["articles"] as $article) {
      $year = (int)$article["year"];
      $month = (int)$article["month"];
      $day = (int)$article["day"];
      $weekday = $this->weekday($year, $month, $day);

      if($displayyear !== $year || $displaymonth !== $month) {
        $contents .= "<div class=\"yearmonth\"><h2><a href=\"index.php?year={$year}&amp;month={$month}\">{$year}年{$month}月</a></h2></div>";
        $displayyear = $year;
        $displaymonth = $month;
      }

      $message = "";
      foreach(preg_split("/\R/", $article["message"]) as $_message) {
        $message .= "<p>" . $this->h($_message) . "</p>";
      }

      $date = sprintf("%04d%02d%02d", $year, $month, $day);
      $contents .= <<<HTML
        <div class="article" id="d{$date}">
          <div class="date"><h3>{$year}年{$month}月{$day}日({$weekday})</h3></div>
          <div class="links"><a href="index.php?mode=edit&amp;year={$year}&amp;month={$month}&amp;day={$day}">編集</a></div>
          <div class="message">{$message}</div>
        </div>
        HTML;
    }
    if($search_limited) {
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
    $weekday = $this->weekday($year, $month, $day);
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
  * HTML エスケープ
  *
  * @param string $str エスケープ対象文字列
  * @return string エスケープ済文字列
  */
  private function h(string $str) : string
  {
    return(htmlspecialchars($str, ENT_QUOTES, "UTF-8"));
  }

  /**
   * 曜日文字
   *
   * @param int $year 年
   * @param int $month 月
   * @param int $day 日
   * @return string 曜日文字
   */
   private function weekday(int $year, int $month, int $day) : string
   {
    return(["日", "月", "火", "水", "木", "金", "土"][(int)date("w", strtotime(sprintf("%04d-%02d-%02d", $year, $month, $day)))]);
  }

}

/**
 * データベース操作クラス
 */
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
