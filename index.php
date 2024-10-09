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
    $this->logined = $_SESSION["logined"] ?? false;
  }

  /**
   * 実行
   */
  public function run() : void
  {
    $getAction = $this->getParam("action", "GET");
    $postAction = $this->getParam("action", "POST");
    if($postAction === "login") {
      $this->login();
    }
    elseif($getAction === "logout") {
      $this->logout();
    }
    elseif($postAction === "update") {
      $this->update();
    }
    elseif($getAction === "edit") {
      $this->edit();
    }
    elseif($getAction === "search") {
      $this->search();
    }
    elseif($getAction === "view" || $getAction === null) {
      $this->show();
    }
    else {
      $this->setNotice("Actionが不正です");
      header("Location: " . $this->getFullUrl());
    }
  }

  /**
   * 日記表示画面
   */
  private function show() : void
  {
    $year = $this->getParam("year", "GET", "int");
    $month = $this->getParam("month", "GET", "int");
    $day = $this->getParam("day", "GET", "int");
    $viewMode = $this->getViewMode($year, $month, $day);
    if($viewMode === "default") {
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
    if($year !== null) {
      $wheres[] = "year = :year";
      $params["year"] = $year;
    }
    if($month !== null) {
      $wheres[] = "month = :month";
      $params["month"] = $month;
    }
    if($day !== null) {
      $wheres[] = "day = :day";
      $params["day"] = $day;
    }
    if(!empty($wheres)) {
      $sql = "SELECT * FROM articles WHERE " . implode(" AND ", $wheres) . " ORDER BY year, month, day " . $sort;
      $articles = $this->database->query($sql, $params);
      if($this->logined) {
        $articles = $this->interpolateArticles($articles, $year, $month, $day, $sort);
      }
    }

    $this->view->displayShow([
      "title" => $this->config["title"],
      "description" => $this->config["description"],
      "articles" => $articles,
      "year" => $year,
      "month" => $month,
      "day" => $day,
      "viewMode" => $viewMode,
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
      $sql = "SELECT * FROM articles WHERE " . implode(" AND ", $wheres) . " ESCAPE '!' ORDER BY year DESC, month DESC, day DESC LIMIT " . App::SEARCH_LIMIT + 1;
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
      "description" => $this->config["description"],
      "articles" => $articles,
      "keyword" => $keyword,
      "viewMode" => "search",
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

    $year = $this->getParam("year", "GET", "int");
    $month = $this->getParam("month", "GET", "int");
    $day = $this->getParam("day", "GET", "int");
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
      "description" => $this->config["description"],
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
    if(!$this->logined) {
      $this->setNotice("ログインしていません");
      header("Location: " . $this->getFullUrl());
      return;
    }
    $formToken = $this->getParam("csrf_token", "POST");
    if(!$this->checkCsrfToken($formToken)) {
      $this->setNotice("不正な操作です");
      header("Location: " . $this->getFullUrl());
      return;
    }

    $year = $this->getParam("year", "POST", "int");
    $month = $this->getParam("month", "POST", "int");
    $day = $this->getParam("day", "POST", "int");
    $message = $this->getParam("message", "POST");
    if(!checkdate($month, $day, $year)) {
      $this->setNotice("日付が異常です");
      header("Location: " . $this->getFullUrl());
      return;
    }

    if(!empty($message)) {
      $this->database->query(
        "REPLACE INTO articles (year, month, day, message) VALUES(:year, :month, :day, :message)",
        [
          ":year" => $year,
          ":month" => $month,
          ":day" => $day,
          ":message" => $message
        ]);
    }
    else {
      $this->database->query(
        "DELETE FROM articles WHERE year=:year AND month=:month AND day=:day",
        [
          ":year" => $year,
          ":month" => $month,
          ":day" => $day,
        ]);
    }

    header("Location: " . $this->getFullUrl(["year" => $year, "month" => $month, "day" => $day]));
  }

  /**
   * ログイン
   */
  private function login() : void
  {
    $formToken = $this->getParam("csrf_token", "POST");
    if($this->checkCsrfToken($formToken) && hash_equals($this->getParam("password", "POST"), $this->config["password"])) {
      $_SESSION["logined"] = true;
      $this->getCsrfToken(true);
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
    $_SESSION["logined"] = false;
    $this->setNotice("ログアウトしました");
    header("Location: " . $this->getFullUrl());
  }

  /**
   * 表示モード
   *
   * @param ?int $year 年
   * @param ?int $month 月
   * @param ?int $day 日
   * @return ?string 表示モード(default/year/month/day/yearmonth/yearday/yearmonthday/monthday)
   */
  private function getViewMode(?int $year, ?int $month, ?int $day) : ?string
  {
    if($year === null && $month === null && $day === null) {
      return("default");
    }
    if($year !== null && $month === null && $day === null) {
      return("year");
    }
    if($year === null && $month !== null && $day === null) {
      return("month");
    }
    if($year === null && $month === null && $day !== null) {
      return("day");
    }
    if($year !== null && $month !== null && $day === null) {
      return("yearmonth");
    }
    if($year !== null && $month === null && $day !== null) {
      return("yearday");
    }
    if($year !== null && $month !== null && $day !== null) {
      return("yearmonthday");
    }
    if($year === null && $month !== null && $day !== null) {
      return("monthday");
    }
    return(null);
  }

  /**
   * 日記データ補完
   *
   * @param array $articles 欠落込み日記データ
   * @param ?int $year 年
   * @param ?int $month 月
   * @param ?int $day 日
   * @param string $sort ソート順。"ASC"なら昇順、"DESC"なら降順
   * @return arrray $articles 補完済日記データ
   */
  private function interpolateArticles(array $articles, ?int $year, ?int $month, ?int $day, string $sort) : array
  {
    // 年/年月/年月日以外なら補完なし
    $viewMode = $this->getViewMode($year, $month, $day);
    if(!in_array($viewMode, ["year", "yearmonth", "yearmonthday"], true)) {
      return($articles);
    }

    $thisYear = (int)date("Y");
    $thisMonth = (int)date("m");
    $thisDay = (int)date("j");

    // 未来の日記は表示しない
    if(($year > $thisYear) ||
      (in_array($viewMode, ["yearmonth", "yearmonthday"], true) && $year === $thisYear && $month > $thisMonth) ||
      ($viewMode === "yearmonthday" && $year === $thisYear && $month === $thisMonth && $day > $thisDay)) {
      return([]);
    }

    $article_dates = array_map(function($article) {
      return(sprintf("%04d%02d%02d", $article["year"], $article["month"], $article["day"]));
    }, $articles);

    if($viewMode === "year") {
      if($year === $thisYear) {
        $lastMonth = (int)date("m");
      }
      else {
        $lastMonth = 12;
      }
      for($_month = 1; $_month <= $lastMonth; $_month++) {
        if($year === $thisYear && $_month === $thisMonth) {
          $lastDay = (int)date("d");
        }
        else {
          $lastDay = (int)date("t", strtotime(sprintf("%04d-%02d-%02d", $year, $_month, 1)));
        }
        for($_day = 1; $_day <= $lastDay; $_day++) {
          if(!in_array(sprintf("%04d%02d%02d", $year, $_month, $_day), $article_dates, true)) {
            $articles[] = ["year" => $year, "month" => $_month, "day" => $_day, "message" => ""];
          }
        }
      }
    }
    else if($viewMode === "yearmonth") {
      if($year === $thisYear && $month === $thisMonth) {
        $lastDay = (int)date("d");
      }
      else {
        $lastDay = (int)date("t", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1)));
      }
      for($_day = 1; $_day <= $lastDay; $_day++) {
        if(!in_array(sprintf("%04d%02d%02d", $year, $month, $_day), $article_dates, true)) {
          $articles[] = ["year" => $year, "month" => $month, "day" => $_day, "message" => ""];
        }
      }
    }
    else if($viewMode === "yearmonthday") {
      if(!in_array(sprintf("%04d%02d%02d", $year, $month, $day), $article_dates, true)) {
        $articles[] = ["year" => $year, "month" => $month, "day" => $day, "message" => ""];
      }
    }

    usort($articles, function($a, $b) use($sort) {
      if($sort === "ASC") {
        return($a["month"] <=> $b["month"] ?: $a["day"] <=> $b["day"]);
      }
      else {
        return($b["month"] <=> $a["month"] ?: $b["day"] <=> $a["day"]);
      }
    });

    return($articles);
  }

  /**
   * パラメータ取得
   *
   * @param string $key パラメータ名
   * @param string $method メソッド(GET, POST)
   * @param ?string $type 型
   * @return ?string パラメータ値
   */
  private function getParam(string $key, string $method, ?string $type = null) : mixed
  {
    switch($method) {
      case "GET":
        $param = $_GET[$key] ?? null;
        break;
      case "POST":
        $param = $_POST[$key] ?? null;
        break;
      default:
        $param = null;
    }
    if($param !== null && $type !== null) {
      switch($type) {
        case "int":
          $param = (int)$param;
          break;
        case "bool":
          $param = (bool)$param;
          break;
      }
    }
    return($param);
  }

  /**
   * フルURL生成
   *
   * @param ?array $queries クエリ
   * @return string フルURL
   */
  private function getFullUrl(?array $queries = null) : string
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
    $notice = $_SESSION["notice"] ?? null;
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
   * @param bool $reset trueならトークン生成、falseなら再利用
   * @return string CSRF対策トークン
   */
  private function getCsrfToken(bool $reset = false) : string
  {
    if(!isset($_SESSION["csrf_token"]) || $reset) {
      $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return($_SESSION["csrf_token"]);
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
    $year = $viewData["year"] ?? null;
    $month = $viewData["month"] ?? null;
    $day = $viewData["day"] ?? null;
    $viewMode = $viewData["viewMode"];
    $keyword = $viewData["keyword"] ?? "";
    $logined = $viewData["logined"] ?? false;

    $contents = "";

    $contents .= <<<HTML
      <div class="form">
        <form action="index.php" method="GET">
          <input type="hidden" name="action" value="search">
          <label>検索:
            <input type="text" id="keyword" name="keyword" value="{$keyword}">
          </label>
          <button type="submit">検索</button>
        </form>
      </div>
      HTML;

    $links = [];
    $thisYear = (int)date("Y");
    $thisMonth = (int)date("n");
    $thisDay = (int)date("j");
    if($viewMode === "yearmonthday") {
      $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
      $prevYear = (int)(date("Y", strtotime("{$date} -1 day")));
      $prevMonth = (int)(date("n", strtotime("{$date} -1 day")));
      $prevDay = (int)(date("j", strtotime("{$date} -1 day")));
      if($prevYear < $thisYear || ($prevYear === $thisYear && $prevMonth < $thisMonth) || ($prevYear === $thisYear && $prevMonth === $thisMonth && $prevDay <= $thisDay)) {
        $links[] = "<a href=\"index.php?action=view&amp;year={$prevYear}&amp;month={$prevMonth}&amp;day={$prevDay}\">前日</a>";
      }
      if($year <= $thisYear) {
        $links[] = "<a href=\"index.php?action=view&amp;year={$year}&amp;month={$month}\">当月</a>";
      }
      $nextYear = (int)(date("Y", strtotime("{$date} +1 day")));
      $nextMonth = (int)(date("n", strtotime("{$date} +1 day")));
      $nextDay = (int)(date("j", strtotime("{$date} +1 day")));
      if($nextYear < $thisYear || ($nextYear === $thisYear && $nextMonth < $thisMonth) || ($nextYear === $thisYear && $nextMonth === $thisMonth && $nextDay <= $thisDay)) {
        $links[] = "<a href=\"index.php?action=view&amp;year={$nextYear}&amp;month={$nextMonth}&amp;day={$nextDay}\">翌日</a>";
      }
    }
    if($viewMode === "default" || $viewMode === "yearmonth") {
      $date = sprintf("%04d-%02d-%02d", $year, $month, 1);
      $prevYear = (int)(date("Y", strtotime("{$date} -1 month")));
      $prevMonth = (int)(date("n", strtotime("{$date} -1 month")));
      if($prevYear < $thisYear || ($prevYear === $thisYear && $prevMonth <= $thisMonth)) {
        $links[] = "<a href=\"index.php?action=view&amp;year={$prevYear}&amp;month={$prevMonth}\">前月</a>";
      }
      if($year <= $thisYear) {
        $links[] = "<a href=\"index.php?action=view&amp;year={$year}\">当年</a>";
      }
      $nextYear = (int)(date("Y", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "+1 month")));
      $nextMonth = (int)(date("n", strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "+1 month")));
      if($nextYear < $thisYear || ($nextYear === $thisYear && $nextMonth <= $thisMonth)) {
        $links[] = "<a href=\"index.php?action=view&amp;year={$nextYear}&amp;month={$nextMonth}\">翌月</a>";
      }
    }
    if($viewMode === "year") {
      $date = sprintf("%04d-%02d-%02d", $year, 1, 1);
      $prevYear = (int)(date("Y", strtotime("{$date} -1 year")));
      if($prevYear <= $thisYear) {
        $links[] = "<a href=\"index.php?action=view&amp;year={$prevYear}\">前年</a>";
      }
      $nextYear = (int)(date("Y", strtotime("{$date} +1 year")));
      if($nextYear <= $thisYear) {
        $links[] = "<a href=\"index.php?action=view&amp;year={$nextYear}\">翌年</a>";
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

    if($viewMode === "yearmonth") {
      $contents .= <<<HTML
        <div class="yearmonth">
          <h2>{$year}年{$month}月</h2>
        </div>
        HTML;
    }
    if($viewMode === "year") {
      $contents .= <<<HTML
        <div class="yearmonth">
          <h2>{$year}年</h2>
        </div>
        HTML;
    }

    $keywords = array_filter(preg_split("/[　\s]/u", $keyword), function($fragment) {
      return !empty($fragment);
    });
    foreach($viewData["articles"] as $article) {
      $_year = $article["year"];
      $_month = $article["month"];
      $_day = $article["day"];
      $_weekday = $this->weekday($_year, $_month, $_day);

      $_date = sprintf("%04d%02d%02d", $_year, $_month, $_day);
      $contents .= <<<HTML
        <div class="article" id="d{$_date}">
          <div class="date">
            <h3>
              <a href="index.php?action=view&amp;year={$_year}&amp;month={$_month}&amp;day={$_day}">{$_year}年{$_month}月{$_day}日({$_weekday})</a>
            </h3>
          </div>
          <div class="links">
            <ul>
              <li><a href="index.php?action=view&amp;month={$_month}&amp;day={$_day}">長年日記</a></li>
        HTML;
      if($logined) {
        $contents .= <<<HTML
          <li><a href="index.php?action=edit&amp;year={$_year}&amp;month={$_month}&amp;day={$_day}">編集</a></li>
        HTML;
      }
      $contents .= <<<HTML
          </ul>
        </div>
        <div class="message">
      HTML;
      foreach(preg_split("/\R/u", $article["message"]) as $fragment) {
        $_contents = $this->h($fragment);
        foreach($keywords as $keyword) {
          $_contents = preg_replace("/($keyword)/i", "<em>$1</em>", $_contents);
        }
        $contents .= "<p>$_contents</p>";
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
            <li><a href="index.php?action=logout">ログアウト</a></li>
          </ul>
        </div>
        HTML;
    }
    else {
      $csrfToken = $this->h($viewData["csrf_token"]);
      $contents .= <<<HTML
        <div class="form">
          <form action="index.php" method="POST">
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="csrf_token" value="{$csrfToken}">
            <label>パスワード:
              <input type="password" name="password">
            </label>
            <button type="submit">ログイン</button>
          </form>
        </div>
        HTML;
    }

    $contents .= <<<HTML
      <script>
      window.addEventListener('load', () => {
        document.getElementById("keyword").focus();
      });
      </script>
    HTML;

    $this->output([
      "title" => $viewData["title"],
      "description" => $viewData["description"],
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
    $year = $viewData["article"]["year"];
    $month = $viewData["article"]["month"];
    $day = $viewData["article"]["day"];
    $weekday = $this->weekday($year, $month, $day);
    $message = $this->h($viewData["article"]["message"]);
    $csrfToken = $this->h($viewData["csrf_token"]);

    $contents = <<<HTML
      <div class="date"><h3>{$year}年{$month}月{$day}日({$weekday})</h3></div>
      <div class="form">
        <form action="index.php" method="POST">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="csrf_token" value="{$csrfToken}">
          <input type="hidden" name="year" value="${year}">
          <input type="hidden" name="month" value="${month}">
          <input type="hidden" name="day" value="${day}">
          <textarea id="message" name="message">{$message}</textarea>
          <button type="submit">更新</button>
        </form>
      </div>
      <script>
      window.addEventListener('load', () => {
        document.getElementById("message").focus();
      });
      </script>
      HTML;
    $this->output([
      "title" => $viewData["title"],
      "description" => $viewData["description"],
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
    if(!empty($outputData["description"])) {
      $description = $this->h($outputData["description"]);
      $descriptionMetaHtml = <<<HTML
        <meta name="description" content="{$description}">
        HTML;
      $descriptionBodyHtml = <<<HTML
        <div id="description">{$description}</div>
        HTML;
    }
    else {
      $descriptionMetaHtml = "";
      $descriptionBodyHtml = "";
    }
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
          {$descriptionMetaHtml}
          <link rel="stylesheet" href="{$css}" type="text/css" title="base">
          {$faviconHtml}
          <meta name="viewport" content="width=device-width, initial-scale=1">
        </head>
        <body>
          <div id="container">
            <header id="header">
              <h1><a href="index.php">{$title}</a></h1>
              {$descriptionBodyHtml}
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
  private $conn = null;

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
    $this->conn = new PDO("sqlite:" . __DIR__ . "/" . $dbPath, null, null, [
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
