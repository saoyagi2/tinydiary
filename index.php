<?php
require "config.php";

use App;
use View;
use Database;
use PDO;

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
  /** @var Database $db データベースオブジェクト */
  private $database;
  /** @var View $view 表示オブジェクト */
  private $view;
  /** @var View $logined ログイン状態フラグ */
  private $logined;

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
    $this->database = new Database($this->config["db_path"]);
    $this->view = new View();

    session_start();
    $this->logined = $_SESSION['logined'] ?? FALSE;
  }

  /**
   * 実行
   */
  public function run() : void
  {
    $getMode = $this->getParam("mode", "GET");
    $postMode = $this->getParam("mode", "POST");
    if($postMode === "login") {
      $this->login();
    }
    if($getMode === "logout") {
      $this->logout();
    }
    if($postMode === "update") {
      $this->update();
    }
    elseif($getMode === "edit") {
      $this->edit();
    }
    elseif($getMode === "search") {
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
    $year = (int)($this->getParam("year", "GET") ?? date("Y"));
    $month = (int)($this->getParam("month", "GET") ?? date("m"));

    if(checkdate($month, 1, $year)) {
      $params = [
        ":year" => $year,
        ":month" => $month,
      ];
      $sql = "SELECT * FROM articles WHERE year = :year AND month = :month";
      $articles = $this->database->query($sql, $params);
      $articles = $this->interpolateArticles($articles, $year, $month);
    }
    else {
      $articles = [];
    }

    $this->view->displayShow([
      "title" => $this->config["title"],
      "articles" => $articles,
      "year" => $year,
      "month" => $month,
      "logined" => $this->logined,
      'csrf_token' => $this->getCsrfToken(),
      "notice" => $this->get_notice(),
    ]);
  }

  /**
   * 日記検索結果画面
   */
  private function search() : void
  {
    $keyword = $this->getParam("keyword", "GET") ?? "";

    $wheres = [];
    $params = [];
    foreach(explode(" ", $keyword) as $fragment) {
      if(empty($fragment)) {
        continue;
      }
      $wheres[] = "message LIKE ?";
      $params[] = "%" . preg_replace('/(?=[!_%])/', '!', $fragment) . "%";
    }
    if(!empty($wheres)) {
      $sql = "SELECT * FROM articles WHERE " . implode(" AND ", $wheres) . " ESCAPE '!' ORDER BY year DESC, month DESC, day DESC LIMIT 21";
      $articles = $this->database->query($sql, $params);
      $searchLimited = count($articles) > App::SEARCH_LIMIT;
      $articles = array_slice($articles, 0, App::SEARCH_LIMIT);
    }
    else {
      $articles = [];
      $searchLimited = false;
    }

    $this->view->displayShow([
      "title" => $this->config["title"],
      "articles" => $articles,
      "keyword" => $keyword,
      "searchLimited" => $searchLimited,
      "logined" => $this->logined,
      'csrf_token' => $this->getCsrfToken(),
      "notice" => $this->get_notice(),
    ]);
  }

  /**
   * 日記編集画面
   */
  private function edit() : void
  {
    if(!$this->logined) {
      header("Location: index.php");
      return;
    }

    $year = (int)($this->getParam("year", "GET") ?? date("Y"));
    $month = (int)($this->getParam("month", "GET") ?? date("m"));
    $day = (int)($this->getParam("day", "GET") ?? date("d"));
    $article = $this->database->query(
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

    $this->view->displayEdit([
      "title" => $this->config["title"],
      "article" => $article,
      'csrf_token' => $this->getCsrfToken(),
      "notice" => $this->get_notice(),
    ]);
  }

  /**
   * 日記更新
   */
  private function update() : void
  {
    $form_token = $this->getParam("csrf_token", "POST");
    if(!$this->logined || !$this->checkCsrfToken($form_token)) {
      header("Location: index.php");
      return;
    }

    $year = (int)($this->getParam("year", "POST") ?? 0);
    $month = (int)($this->getParam("month", "POST") ?? 0);
    $day = (int)($this->getParam("day", "POST") ?? 0);
    $message = $this->getParam("message", "POST") ?? "";
    if(!checkdate($month, $day, $year)) {
      header("Location: index.php");
      return;
    }

    $this->database->query(
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
   * ログイン
   */
  private function login() : void
  {
    $form_token = $this->getParam("csrf_token", "POST");
    if($this->checkCsrfToken($form_token) && hash_equals($this->getParam("password", "POST"), $this->config['password'])) {
      $_SESSION['logined'] = TRUE;
    }
    header("Location: index.php");
  }

  /**
   * ログアウト
   */
  private function logout() : void
  {
    $_SESSION['logined'] = FALSE;
    header("Location: index.php");
  }

  /**
   * 日記データ補完
   *
   * @param array $articles 欠落込み日記データ
   * @param int $year 年
   * @param int $month 月
   * @return arrray $articles 補完済日記データ
   */
  private function interpolateArticles($articles, $year, $month) : array
  {
    $thisYear = (int)date("Y");
    $thisMonth = (int)date("m");

    // 来月以降の日記は表示しない
    if($year > $thisYear || ($year == $thisYear && $month > $thisMonth)) {
      return([]);
    }

    if($year == $thisYear && $month == $thisMonth) {
      $lastDay = (int)date("d");
    }
    else {
      $lastDay = (int)date("t", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1)));
    }
    for($day = 1; $day <= $lastDay; $day++) {
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

  /**
   * パラメータ取得
   *
   * @param string $key パラメータ名
   * @param string $method メソッド(GET, POST)
   * @return ?string パラメータ値
   */
  private function getParam(string $key, string $method) : ?string
  {
    switch($method) {
      case 'GET':
        $param = $_GET[$key] ?? NULL;
        break;
      case 'POST':
        $param = $_POST[$key] ?? NULL;
        break;
      default:
        $param = NULL;
    }
    return($param);
  }

  /**
   * お知らせ取得
   *
   * @return ?string お知らせ
   */
  private function get_notice() : ?string
  {
    $message = $_SESSION["notice"];
    unset($_SESSION["notice"]);
    return($message);
  }

  /**
   * お知らせセット
   *
   * @param string message お知らせ
   */
  private function set_notice(string $message) : void
  {
    $_SESSION["notice"] = $message;
  }

  /**
   * CSRF対策トークン生成
   *
   * @return $string CSRF対策トークン
   */
  private function getCsrfToken() : string
  {
    $token = bin2hex(random_bytes(32));
    $_SESSION["csrf_token"] = $token;
    return($token);
  }

  /**
   * CSRF対策トークン照合
   *
   * @param string form_token FORMから渡されたCSRF対策トークン
   * @retrun bool 照合結果(trueなら正常、falseなら不正)
   */
  private function checkCsrfToken(string $form_token) : bool
  {
    $csrf_token = $_SESSION["csrf_token"];
    unset($_SESSION["csrf_token"]);
    $result = hash_equals($csrf_token, $form_token);
    return(hash_equals($csrf_token, $form_token));
  }
}

/**
 * 表示クラス
 */
class View {
  /**
   * 表示画面
   *
   * @param array $viewData 表示データ
   */
  public function displayShow(array $viewData) : void
  {
    $year = (int)($viewData["year"] ?? 0);
    $month = (int)($viewData["month"] ?? 0);
    $keyword = $viewData["keyword"] ?? "";
    $searchLimited = $viewData["searchLimited"] ?? false;
    $logined = $viewData["logined"] ?? false;

    $contents = "";

    $contents .= <<<HTML
      <form action="index.php?mode=view" method="GET">
        <input type="hidden" name="mode" value="search">
        <label>検索:
          <input type="text" name="keyword" value="{$keyword}">
        </label>
        <input type="submit" value="検索">
      </form>
      HTML;

    if(checkdate($month, 1, $year)) { // 年月表示モードなら前月・翌月ナビ表示
      $thisYear = (int)date("Y");
      $thisMonth = (int)date("m");

      $contents .= <<<HTML
        <div class="navi">
          <ul>
        HTML;
      $prevYear = (int)(date("Y", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "-1 month")));
      $prevMonth = (int)(date("n", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "-1 month")));
      if($prevYear < $thisYear || ($prevYear == $thisYear && $prevMonth <= $thisMonth)) {
        $contents .= <<<HTML
          <li>
            <a href="index.php?year={$prevYear}&amp;month={$prevMonth}">前月</a>
          </li>
          HTML;
      }
      $nextYear = (int)(date("Y", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "+1 month")));
      $nextMonth = (int)(date("n", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "+1 month")));
      if($nextYear < $thisYear || ($nextYear == $thisYear && $nextMonth <= $thisMonth)) {
        $contents .= <<<HTML
          <li>
            <a href="index.php?year={$nextYear}&amp;month={$nextMonth}">翌月</a>
          </li>
          HTML;
      }
      $contents .= <<<HTML
          </ul>
        </div>
        HTML;
    }

    $displayYear = 0;
    $displayMonth = 0;
    foreach($viewData["articles"] as $article) {
      $year = (int)$article["year"];
      $month = (int)$article["month"];
      $day = (int)$article["day"];
      $weekday = $this->weekday($year, $month, $day);

      if($displayYear !== $year || $displayMonth !== $month) {
        $contents .= <<<HTML
          <div class="yearmonth">
            <h2><a href="index.php?year={$year}&amp;month={$month}">{$year}年{$month}月</a></h2>
          </div>
          HTML;
        $displayYear = $year;
        $displayMonth = $month;
      }

      $date = sprintf("%04d%02d%02d", $year, $month, $day);
      $contents .= <<<HTML
        <div class="article" id="d{$date}">
          <div class="date"><h3>{$year}年{$month}月{$day}日({$weekday})</h3></div>
        HTML;
        if($logined) {
          $contents .= <<<HTML
            <div class="links"><a href="index.php?mode=edit&amp;year={$year}&amp;month={$month}&amp;day={$day}">編集</a></div>
            HTML;
        }
        $contents .= <<<HTML
          <div class="message">
        HTML;
      foreach(preg_split("/\R/", $article["message"]) as $fragment) {
        $contents .= "<p>" . $this->h($fragment) . "</p>";
      }
      $contents .= <<<HTML
          </div>
        </div>
        HTML;
    }
    if($searchLimited) {
      $contents .= "<p>制限以上ヒットしたため省略しました</p>";
    }

    if($logined) {
      $contents .= <<<HTML
        <a href="index.php?mode=logout">ログアウト</a>
        HTML;
    }
    else {
      $csrf_token = $this->h($viewData["csrf_token"]);
      $contents .= <<<HTML
        <form action="index.php" method="POST">
          <input type="hidden" name="mode" value="login">
          <input type="hidden" name="csrf_token" value="{$csrf_token}">
          <label>パスワード:
            <input type="password" name="password">
          </label>
          <input type="submit" value="ログイン">
        </form>
        HTML;
    }

    $this->output([
      "title" => $viewData["title"],
      "notice" => $this->h($viewData["notice"] ?? ""),
      "contents" => $contents
    ]);
  }

  /**
   * 編集画面
   *
   * @param array $viewData 表示データ
   */
  public function displayEdit(array $viewData) : void
  {
    $year = (int)$viewData["article"]["year"];
    $month = (int)$viewData["article"]["month"];
    $day = (int)$viewData["article"]["day"];
    $weekday = $this->weekday($year, $month, $day);
    $message = $this->h($viewData["article"]["message"]);
    $csrf_token = $this->h($viewData["csrf_token"]);

    $contents = <<<HTML
      <div class="date">{$year}年{$month}月{$day}日({$weekday})</div>
      <div class="message">
      <form action="index.php" method="POST">
        <input type="hidden" name="mode" value="update">
        <input type="hidden" name="csrf_token" value="{$csrf_token}">
        <input type="hidden" name="year" value="${year}">
        <input type="hidden" name="month" value="${month}">
        <input type="hidden" name="day" value="${day}">
        <textarea name="message">{$message}</textarea>
        <input type="submit" value="更新">
      </form>
      HTML;
    $this->output([
      "title" => $viewData["title"],
      "notice" => $this->h($viewData["notice"] ?? ""),
      "contents" => $contents
    ]);
  }

  /**
   * 出力
   *
   * @param array $viewData 表示データ
   */
  private function output($viewData) : void
  {
    $title = $this->h($viewData["title"]);

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
            <div id="notice">
              {$viewData["notice"]}
            </div>
            <div id="contents">
              {$viewData["contents"]}
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
class Database {
  /** @var PDO */
  private $conn = NULL;

  /**
   * コンストラクタ
   *
   * @param string $dbPath データベースファイルへのパス
   */
  public function __construct(string $dbPath)
  {
    try {
      $this->connectDatabase($dbPath);
      $this->createSchema();
    }
    catch(PDOException $e) {
      print $e->getMessage();
      exit();
    }
  }

  /**
   * DB接続
   *
   * @param string $dbPath データベースファイルへのパス
   */
  private function connectDatabase(string $dbPath) : void
  {
    $this->conn = new PDO("sqlite:" . __DIR__ . "/" . $dbPath, NULL, NULL, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
  }

  /**
   * スキーマ作成
   */
  private function createSchema() : void
  {
    $this->conn->exec("CREATE TABLE IF NOT EXISTS articles(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      year INTGER NOT NULL,
      month INTGER NOT NULL,
      day INTGER NOT NULL,
      message TEXT
    )");
    $this->conn->exec("CREATE UNIQUE INDEX IF NOT EXISTS article_ymd_idx ON articles (year, month, day)");
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
    $stmt = $this->conn->prepare($sql);
    $stmt->execute($params);
    return($stmt->fetchAll());
  }
}
