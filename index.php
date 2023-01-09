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
    $year = $_REQUEST['year'] ?? '';
    $month = $_REQUEST['month'] ?? '';
    $day = $_REQUEST['day'] ?? '';

    $wheres = [];
    $params = [];
    if($year === '' && $month === '' && $day === '') {
      $wheres[] = 'year = :year';
      $params[':year'] = date('Y');
      $wheres[] = 'month = :month';
      $params[':month'] = date('m');
      $wheres[] = 'day = :day';
      $params[':day'] = date('d');
    }
    else {
      if($year !== '') {
        $wheres[] = 'year = :year';
        $params[':year'] = (int)$year;
      }
      if($month !== '') {
        $wheres[] = 'month = :month';
        $params[':month'] = (int)$month;
      }
      if($day !== '') {
        $wheres[] = 'day = :day';
        $params[':day'] = (int)$day;
      }
    }
    $sql = 'SELECT * FROM articles';
    if(!empty($wheres)) {
      $sql .= ' WHERE ' . implode(' AND ', $wheres);
    }
    $sql .= " ORDER BY year ASC, month ASC, day ASC";

    $articles = $this->db->query($sql, $params);
    View::display_view($year, $month, $day, $articles);
  }

  /**
   * 日記編集画面
   */
  private function edit() : void
  {
    $year = $_REQUEST['year'] ?? date('Y');
    $month = $_REQUEST['month'] ?? date('m');
    $day = $_REQUEST['day'] ?? date('d');
    $article = $this->db->query("SELECT * FROM articles WHERE year = :year AND month = :month AND day = :day", [':year' => $year, ':month' => $month, ':day' => $day])[0] ?? ['year' => $year, 'month' => $month, 'day' => $day, 'message' => ""];
    View::display_edit($article);
  }

  /**
   * 日記更新
   */
  private function update() : void
  {
    $year = $_REQUEST['year'];
    $month = $_REQUEST['month'];
    $day = $_REQUEST['day'];
    $message = $_REQUEST['message'];
    $this->db->query("REPLACE INTO articles (year, month, day, message) VALUES(:year, :month, :day, :message)", [':year' => $year, ':month' => $month, ':day' => $day, ':message' => $message]);
    header("Location: index.php?year={$year}&month={$month}&day={$day}");
  }
}

class View {
  /**
   * 表示画面
   *
   * @param string $year 年
   * @param string $month 月
   * @param string $day 日
   * @param array $articles 日記データ
   */
  public static function display_view(string $year, string $month, string $day, array $articles) : void
  {
    $contents = "";

    if($year === '' && $month === '' && $day === '') { // 年月日指定なし=今日のみ
      $_year = (int)date('Y');
      $_month = (int)date('m');
      $_day = (int)date('d');

      $contents .= <<<HTML
        <div class="navi">
          <ul>
            <li><a href="index.php?year={$_year}&amp;month={$_month}&amp;day={$day}">前日</a></li>
            <li><a href="index.php?year={$_year}&amp;month={$_month}&amp;day={$day}">翌日</a></li>
            <li><a href="index.php?year={$_year}&amp;month={$_month}">今月</a></li>
          </ul>
        </div>
        HTML;

      $article = array_values(array_filter($articles, function($article) use($_year, $_month, $_day) {
        return($article['year'] == $_year && $article['month'] == $_month && $article['day'] == $_day);
      }))[0];

      $contents .= View::_view_daily($_year, $_month, $_day, $article);
    }
    else if($year !== '' && $month !== '' && $day !== '') { // 年月日指定全て指定あり
      $prev_year = date('Y', strtotime(sprintf("%04d-%02d-%02d", $year, $month, $day) . "-1 day"));
      $prev_month = date('n', strtotime(sprintf("%04d-%02d-%02d", $year, $month, $day) . "-1 day"));
      $prev_day = date('j', strtotime(sprintf("%04d-%02d-%02d", $year, $month, $day) . "-1 day"));
      $next_year = date('Y', strtotime(sprintf("%04d-%02d-%02d", $year, $month, $day) . "+1 day"));
      $next_month = date('n', strtotime(sprintf("%04d-%02d-%02d", $year, $month, $day) . "+1 day"));
      $next_day = date('j', strtotime(sprintf("%04d-%02d-%02d", $year, $month, $day) . "+1 day"));
      $contents .= <<<HTML
        <div class="navi">
          <ul>
            <li><a href="index.php?year={$prev_year}&amp;month={$prev_month}&amp;day={$prev_day}">前日</a></li>
            <li><a href="index.php?year={$next_year}&amp;month={$next_month}&amp;day={$next_day}">翌日</a></li>
            <li><a href="index.php?year={$year}&amp;month={$month}">今月</a></li>
          </ul>
        </div>
        HTML;

      $article = array_values(array_filter($articles, function($article) use($year, $month, $day) {
        return($article['year'] == $year && $article['month'] == $month && $article['day'] == $day);
      }))[0];

      $contents .= View::_view_daily($year, $month, $day, $article);
    }
    else if($year !== '' && $month === '' && $day === '') { // 年のみ指定あり
      $prev_year = $year - 1;
      $next_year = $year + 1;
      $contents .= <<<HTML
        <div class="navi">
          <ul>
            <li><a href="index.php?year={$prev_year}">前年</a></li>
            <li><a href="index.php?year={$next_year}">翌年</a></li>
          </ul>
        </div>
        HTML;

      for($_month = 1; $_month <= 12; $_month++) {
        for($_day = 1; $_day <= (int)date('t', strtotime(sprintf("%04d-%02d-01", $year, $_month))); $_day++) {
          $article = array_values(array_filter($articles, function($article) use($year, $_month, $_day) {
            return($article['year'] == $year && $article['month'] == $_month && $article['day'] == $_day);
          }))[0];

          $contents .= View::_view_daily($year, $_month, $_day, $article);
        }
      }
    }
    else if($year !== '' && $month !== '' && $day === '') { // 年月のみ指定あり
      $prev_year = date('Y', strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "-1 month"));
      $prev_month = date('n', strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "-1 month"));
      $next_year = date('Y', strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "+1 month"));
      $next_month = date('n', strtotime(sprintf("%04d-%02d-%02d", $year, $month, 1) . "+1 month"));
      $contents .= <<<HTML
        <div class="navi">
          <ul>
            <li><a href="index.php?year={$prev_year}&amp;month={$prev_month}">前月</a></li>
            <li><a href="index.php?year={$next_year}&amp;month={$next_month}">翌月</a></li>
            <li><a href="index.php?year={$year}">今年</a></li>
          </ul>
        </div>
        HTML;

      for($_day = 1; $_day <= (int)date('t', strtotime(sprintf("%04d-%02d-01", $year, $month))); $_day++) {
        $article = array_values(array_filter($articles, function($article) use($year, $month, $_day) {
          return($article['year'] == $year && $article['month'] == $month && $article['day'] == $_day);
        }))[0];

        $contents .= View::_view_daily($year, $month, $_day, $article);
      }
    }

    View::output(['contents' => $contents]);
  }

  private static function _view_daily(int $year, int $month, int $day, ?array $article) : string
  {
    $contents = "";

    $weekday = ["日", "月", "火", "水", "木", "金", "土"][(int)date("w", strtotime(sprintf("%04d-%02d-%02d", $year, $month, $day)))];

    $message = "";
    if(!empty($article)) {
      foreach(explode("\n", str_replace(array("\r\n", "\r", "\n"), "\n", $article['message'])) as $_message) {
        $message .= "<p>" . View::h($_message) . "</p>";
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
   * 編集画面
   *
   * @param array $article 日記データ
   */
  public static function display_edit(array $article) : void
  {
    $year = (int)$article['year'];
    $month = (int)$article['month'];
    $day = (int)$article['day'];
    $weekday = ["日", "月", "火", "水", "木", "金", "土"][(int)date("w", strtotime(sprintf("%04d-%02d-%02d", $year, $month, $day)))];
    $message = View::h($article['message']);

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
