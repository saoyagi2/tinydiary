<?php
require "config.php";

{
  try {
    $app = new App($config);
    $app->run();
  }
  catch(Exception $e) {
    print $e->getMessage();
  }
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

    if(!empty($config["timezone"])) {
      date_default_timezone_set($config["timezone"]);
    }

    session_start();
    $this->logined = $_SESSION["logined"] ?? FALSE;
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
    elseif($getMode === "logout") {
      $this->logout();
    }
    elseif($postMode === "update") {
      $this->update();
    }
    elseif($getMode === "edit") {
      $this->edit();
    }
    elseif($getMode === "search") {
      $this->search();
    }
    elseif(is_null($getMode)) {
      $this->show();
    }
    else {
      $this->setNotice("modeが不正です");
      header("Location: " . $this->getFullUrl());
    }
  }

  /**
   * 日記表示画面
   */
  private function show() : void
  {
    $year = $this->getParam("year", "GET");
    if(!is_null($year)) {
      $year = (int)$year;
    }
    $month = $this->getParam("month", "GET");
    if(!is_null($month)) {
      $month = (int)$month;
    }
    $day = $this->getParam("day", "GET");
    if(!is_null($day)) {
      $day = (int)$day;
    }
    if(is_null($year) && is_null($month) && is_null($day)) {
      $year = (int)date("Y");
      $month = (int)date("m");
      $sort = "DESC";
    }
    else {
      $sort = "ASC";
    }

    if(!checkdate($month ?? 1, $day ?? 1, $year ?? 2000)) { // $month=2, $day=29 を OK とするため$year無視定時は 2000 とする
      $this->setNotice("日付が異常です");
      header("Location: " . $this->getFullUrl());
      return;
    }
    $wheres = [];
    $params = [];
    if(!empty($year)) {
      $wheres[] = "year = :year";
      $params["year"] = $year;
    }
    if(!empty($month)) {
      $wheres[] = "month = :month";
      $params["month"] = $month;
    }
    if(!empty($day)) {
      $wheres[] = "day = :day";
      $params["day"] = $day;
    }
    if(!empty($wheres)) {
      $sql = "SELECT * FROM articles WHERE " . implode(" AND ", $wheres) . " ORDER BY year, month, day " . $sort;
      $articles = $this->database->query($sql, $params);
      if($this->logined && !is_null($year) && !is_null($month) && is_null($day)) {
        $articles = $this->interpolateArticles($articles, $year, $month, $sort);
      }
    }

    $this->view->displayShow([
      "title" => $this->config["title"],
      "articles" => $articles,
      "year" => $year,
      "month" => $month,
      "day" => $day,
      "logined" => $this->logined,
      "csrf_token" => $this->getCsrfToken(),
      "notice" => $this->getNotice(),
      "css" => $this->config["css"],
      "favicon" => $this->config["favicon"],
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
    foreach(preg_split("/[　\s]/u", $keyword) as $fragment) {
      if(empty($fragment)) {
        continue;
      }
      $wheres[] = "message LIKE ?";
      $params[] = "%" . preg_replace("/(?=[!_%])/", "!", $fragment) . "%";
    }
    if(!empty($wheres)) {
      $sql = "SELECT * FROM articles WHERE " . implode(" AND ", $wheres) . " ESCAPE '!' ORDER BY year DESC, month DESC, day DESC LIMIT 21";
      $articles = $this->database->query($sql, $params);
      if(count($articles) > App::SEARCH_LIMIT) {
        $this->setNotice("制限以上ヒットしたため検索結果を一部省略しました");
        $articles = array_slice($articles, 0, App::SEARCH_LIMIT);
      }
      else {
        $this->setNotice(sprintf("%d件ヒットしました", count($articles)));
      }
    }
    else {
      $this->setNotice("検索語がありません");
      $articles = [];
    }

    $this->view->displayShow([
      "title" => $this->config["title"],
      "articles" => $articles,
      "keyword" => $keyword,
      "logined" => $this->logined,
      "csrf_token" => $this->getCsrfToken(),
      "notice" => $this->getNotice(),
      "css" => $this->config["css"],
      "favicon" => $this->config["favicon"],
    ]);
  }

  /**
   * 日記編集画面
   */
  private function edit() : void
  {
    if(!$this->logined) {
      $this->setNotice("ログインしていません");
      header("Location: " . $this->getFullUrl());
      return;
    }

    $year = (int)($this->getParam("year", "GET") ?? date("Y"));
    $month = (int)($this->getParam("month", "GET") ?? date("m"));
    $day = (int)($this->getParam("day", "GET") ?? date("d"));
    if(!checkdate($month, $day, $year)) {
      $this->setNotice("日付が異常です");
      header("Location: " . $this->getFullUrl());
      return;
    }

    $articles = $this->database->query(
      "SELECT * FROM articles WHERE year = :year AND month = :month AND day = :day",
      [
        ":year" => $year,
        ":month" => $month,
        ":day" => $day
      ]);
    if(!empty($articles)) {
      $article = $articles[0];
    }
    else {
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
      "csrf_token" => $this->getCsrfToken(),
      "notice" => $this->getNotice(),
      "css" => $this->config["css"],
      "favicon" => $this->config["favicon"],
    ]);
  }

  /**
   * 日記更新
   */
  private function update() : void
  {
    $formToken = $this->getParam("csrf_token", "POST");
    if(!$this->logined) {
      $this->setNotice("ログインしていません");
      header("Location: " . $this->getFullUrl());
      return;
    }
    if(!$this->checkCsrfToken($formToken)) {
      $this->setNotice("不正な操作です");
      header("Location: " . $this->getFullUrl());
      return;
    }

    $year = (int)($this->getParam("year", "POST") ?? 0);
    $month = (int)($this->getParam("month", "POST") ?? 0);
    $day = (int)($this->getParam("day", "POST") ?? 0);
    $message = $this->getParam("message", "POST") ?? "";
    if(!checkdate($month, $day, $year)) {
      $this->setNotice("日付が異常です");
      header("Location: " . $this->getFullUrl());
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

    header("Location: " . $this->getFullUrl(["year" => $year, "month" => $month, "day" => $day]));
  }

  /**
   * ログイン
   */
  private function login() : void
  {
    $formToken = $this->getParam("csrf_token", "POST");
    if($this->checkCsrfToken($formToken) && hash_equals($this->getParam("password", "POST"), $this->config["password"])) {
      $_SESSION["logined"] = TRUE;
      $this->setNotice("ログインしました");
    }
    else {
      $this->setNotice("ログインに失敗しました");
    }
    header("Location: " . $this->getFullUrl());
  }

  /**
   * ログアウト
   */
  private function logout() : void
  {
    $_SESSION["logined"] = FALSE;
    $this->setNotice("ログアウトしました");
    header("Location: " . $this->getFullUrl());
  }

  /**
   * 日記データ補完
   *
   * @param array $articles 欠落込み日記データ
   * @param int $year 年
   * @param int $month 月
   * @param string $sort ソート順。"ASC"なら昇順、"DESC"なら降順
   * @return arrray $articles 補完済日記データ
   */
  private function interpolateArticles(array $articles, int $year, int $month, string $sort) : array
  {
    $thisYear = (int)date("Y");
    $thisMonth = (int)date("m");

    // 来月以降の日記は表示しない
    if($year > $thisYear || ($year === $thisYear && $month > $thisMonth)) {
      return([]);
    }

    if($year === $thisYear && $month === $thisMonth) {
      $lastDay = (int)date("d");
    }
    else {
      $lastDay = (int)date("t", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1)));
    }
    $dates = array_map(function($article) {
      return(sprintf("%04d%02d%02d", (int)$article["year"], (int)$article["month"], (int)$article["day"]));
    }, $articles);
    for($day = 1; $day <= $lastDay; $day++) {
      if(!in_array(sprintf("%04d%02d%02d", $year, $month, $day), $dates, true)) {
        $articles[] = ["year" => $year, "month" => $month, "day" => $day, "message" => ""];
      }
    }
    usort($articles, function($a, $b) use($sort) {
      if($sort === "ASC") {
        return($a["day"] <=> $b["day"]);
      }
      else {
        return($b["day"] <=> $a["day"]);
      }
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
      case "GET":
        $param = $_GET[$key] ?? NULL;
        break;
      case "POST":
        $param = $_POST[$key] ?? NULL;
        break;
      default:
        $param = NULL;
    }
    return($param);
  }

  /**
   * フルURL生成
   *
   * @param ?array $queries クエリ
   * @return string フルURL
   */
  private function getFullUrl(?array $queries = NULL) : string
  {
    $fullUrl = ((empty($_SERVER["HTTPS"]) || $_SERVER["HTTPS"] === "off") ? "http://" : "https://") . $_SERVER["HTTP_HOST"] . $_SERVER["SCRIPT_NAME"];
    if(!empty($queries)) {
      $fullUrl .= "?" . implode("&", array_map(function($key, $value) {
        return(urlencode($key) . "=" . urlencode($value));
      }, array_keys($queries), array_values($queries)));
    }

    return($fullUrl);
  }

  /**
   * お知らせ取得
   *
   * @return ?string お知らせ
   */
  private function getNotice() : ?string
  {
    $notice = $_SESSION["notice"] ?? "";
    unset($_SESSION["notice"]);
    return($notice);
  }

  /**
   * お知らせセット
   *
   * @param string notice お知らせ
   */
  private function setNotice(string $notice) : void
  {
    $_SESSION["notice"] = $notice;
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
   * @param string formToken FORMから渡されたCSRF対策トークン
   * @retrun bool 照合結果(trueなら正常、falseなら不正)
   */
  private function checkCsrfToken(string $formToken) : bool
  {
    $csrfToken = $_SESSION["csrf_token"];
    unset($_SESSION["csrf_token"]);
    return(hash_equals($csrfToken, $formToken));
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
    $day = (int)($viewData["day"] ?? 0);
    $keyword = $viewData["keyword"] ?? "";
    $logined = $viewData["logined"] ?? false;

    $contents = "";

    $contents .= <<<HTML
      <div class="form">
        <form action="index.php?mode=view" method="GET">
          <input type="hidden" name="mode" value="search">
          <label>検索:
            <input type="text" name="keyword" value="{$keyword}">
          </label>
          <input type="submit" value="検索">
        </form>
      </div>
      HTML;

    $links = [];
    $thisYear = (int)date("Y");
    $thisMonth = (int)date("n");
    $thisDay = (int)date("j");
    if($year !== 0 && $month !== 0 && $day !== 0) { // 年月日表示モードなら前日・今月・翌日ナビ表示
      $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
      $prevYear = (int)(date("Y", strtotime("{$date} -1 day")));
      $prevMonth = (int)(date("n", strtotime("{$date} -1 day")));
      $prevDay = (int)(date("j", strtotime("{$date} -1 day")));
      if($prevYear < $thisYear || ($prevYear === $thisYear && $prevMonth < $thisMonth) || ($prevYear === $thisYear && $prevMonth === $thisMonth && $prevDay <= $thisDay)) {
        $links[] = "<a href=\"index.php?year={$prevYear}&amp;month={$prevMonth}&amp;day={$prevDay}\">前日</a>";
      }
      if($year <= $thisYear) {
        $links[] = "<a href=\"index.php?year={$year}&amp;month={$month}\">今月</a>";
      }
      $nextYear = (int)(date("Y", strtotime("{$date} +1 day")));
      $nextMonth = (int)(date("n", strtotime("{$date} +1 day")));
      $nextDay = (int)(date("j", strtotime("{$date} +1 day")));
      if($nextYear < $thisYear || ($nextYear === $thisYear && $nextMonth < $thisMonth) || ($nextYear === $thisYear && $nextMonth === $thisMonth && $nextDay <= $thisDay)) {
        $links[] = "<a href=\"index.php?year={$nextYear}&amp;month={$nextMonth}&amp;day={$nextDay}\">翌日</a>";
      }
    }
    if($year !== 0 && $month !== 0 && $day === 0) { // 年月表示モードなら前月・今年・翌月ナビ表示
      $date = sprintf("%04d-%02d-%02d", $year, $month, 1);
      $prevYear = (int)(date("Y", strtotime("{$date} -1 month")));
      $prevMonth = (int)(date("n", strtotime("{$date} -1 month")));
      if($prevYear < $thisYear || ($prevYear === $thisYear && $prevMonth <= $thisMonth)) {
        $links[] = "<a href=\"index.php?year={$prevYear}&amp;month={$prevMonth}\">前月</a>";
      }
      if($year <= $thisYear) {
        $links[] = "<a href=\"index.php?year={$year}\">今年</a>";
      }
      $nextYear = (int)(date("Y", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "+1 month")));
      $nextMonth = (int)(date("n", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "+1 month")));
      if($nextYear < $thisYear || ($nextYear === $thisYear && $nextMonth <= $thisMonth)) {
        $links[] = "<a href=\"index.php?year={$nextYear}&amp;month={$nextMonth}\">翌月</a>";
      }
    }
    if($year !== 0 && $month === 0 && $day === 0) { // 年表示モードなら前年・翌年ナビ表示
      $date = sprintf("%04d-%02d-%02d", $year, 1, 1);
      $prevYear = (int)(date("Y", strtotime("{$date} -1 year")));
      if($prevYear <= $thisYear) {
        $links[] = "<a href=\"index.php?year={$prevYear}\">前年</a>";
      }
      $nextYear = (int)(date("Y", strtotime("{$date} +1 year")));
      if($nextYear <= $thisYear) {
        $links[] = "<a href=\"index.php?year={$nextYear}\">翌年</a>";
      }
    }
    if(!empty($links)) {
      $contents .= <<<HTML
        <div class="links">
          <ul>
        HTML;
      foreach($links as $link) {
        $contents .= "<li>${link}</li>";
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
          <div class="date">
            <h3>
              <a href="index.php?year={$year}&amp;month={$month}&amp;day={$day}">{$year}年{$month}月{$day}日({$weekday})</a>
            </h3>
          </div>
        HTML;
      if($logined) {
        $contents .= <<<HTML
          <div class="links">
            <ul>
              <li><a href="index.php?mode=edit&amp;year={$year}&amp;month={$month}&amp;day={$day}">編集</a></li>
            </ul>
          </div>
          HTML;
      }
      $contents .= <<<HTML
        <div class="message">
      HTML;

      foreach(preg_split("/\R/u", $article["message"]) as $fragment) {
        $contents .= "<p>" . $this->h($fragment) . "</p>";
      }
      $contents .= <<<HTML
          </div>
        </div>
        HTML;
    }

    if($logined) {
      $contents .= <<<HTML
        <div class="links">
          <ul>
            <li><a href="index.php?mode=logout">ログアウト</a></li>
          </ul>
        </div>
        HTML;
    }
    else {
      $csrfToken = $this->h($viewData["csrf_token"]);
      $contents .= <<<HTML
        <div class="form">
          <form action="index.php" method="POST">
            <input type="hidden" name="mode" value="login">
            <input type="hidden" name="csrf_token" value="{$csrfToken}">
            <label>パスワード:
              <input type="password" name="password">
            </label>
            <input type="submit" value="ログイン">
          </form>
        </div>
        HTML;
    }

    $this->output([
      "title" => $viewData["title"],
      "notice" => $this->h($viewData["notice"] ?? ""),
      "contents" => $contents,
      "css" => $viewData["css"],
      "favicon" => $viewData["favicon"],
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
    $csrfToken = $this->h($viewData["csrf_token"]);

    $contents = <<<HTML
      <div class="date"><h3>{$year}年{$month}月{$day}日({$weekday})</h3></div>
      <div class="form">
        <form action="index.php" method="POST">
          <input type="hidden" name="mode" value="update">
          <input type="hidden" name="csrf_token" value="{$csrfToken}">
          <input type="hidden" name="year" value="${year}">
          <input type="hidden" name="month" value="${month}">
          <input type="hidden" name="day" value="${day}">
          <textarea name="message">{$message}</textarea>
          <input type="submit" value="更新">
        </form>
      </div>
      HTML;
    $this->output([
      "title" => $viewData["title"],
      "notice" => $this->h($viewData["notice"] ?? ""),
      "contents" => $contents,
      "css" => $viewData["css"],
      "favicon" => $viewData["favicon"],
    ]);
  }

  /**
   * 出力
   *
   * @param array $outputData 出力データ
   */
  private function output(array $outputData) : void
  {
    $title = $this->h($outputData["title"]);
    $css = urlencode(!empty($outputData["css"]) ? $outputData["css"] : "default.css");
    if(!empty($outputData["favicon"])) {
      $favicon = urlencode($outputData["favicon"]);
      $faviconHtml = <<<HTML
        <link rel="icon" href="{$favicon}" sizes="any">
        HTML;
    }
    else {
      $faviconHtml = "";
    }
    if(!empty($outputData["notice"])) {
      $notice = $outputData["notice"];
      $noticeHtml = <<<HTML
        <div id="notice">
          {$notice}
        </div>
        HTML;
    }
    else {
      $noticeHtml = "";
    }

    print <<<HTML
      <!DOCTYPE html>
      <html lang="ja">
        <head>
          <meta charset="utf-8">
          <title>{$title}</title>
          <link rel="stylesheet" href="{$css}" type="text/css" title="base">
          {$faviconHtml}
          <meta name="viewport" content="width=device-width, initial-scale=1">
        </head>
        <body>
          <div id="container">
            <header id="header">
              <h1><a href="index.php">{$title}</a></h1>
            </header>
            {$noticeHtml}
            <div id="contents">
              {$outputData["contents"]}
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
   * @throws PDOException DB初期化失敗
   */
  public function __construct(string $dbPath)
  {
    $this->connectDatabase($dbPath);
    $this->createSchema();
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
