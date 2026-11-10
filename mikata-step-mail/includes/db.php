<?php
/**
 * テーブルと設定。日時はすべて UTC（GMT）の 'Y-m-d H:i:s' で持ち、表示のときにサイトの時刻へ直す。
 */
if (!defined('ABSPATH')) exit;

function msm_table($name) {
    global $wpdb;
    return $wpdb->prefix . 'msm_' . $name;
}

function msm_now() {
    return gmdate('Y-m-d H:i:s', msm_time());
}

/* テストで時刻を進められるように、現在時刻は1か所から取る */
function msm_time() {
    return isset($GLOBALS['MSM_FAKE_NOW']) ? (int) $GLOBALS['MSM_FAKE_NOW'] : time();
}

function msm_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    $sql = array();
    $sql[] = "CREATE TABLE " . msm_table('subscribers') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(190) NOT NULL,
  name varchar(100) NOT NULL DEFAULT '',
  company varchar(150) NOT NULL DEFAULT '',
  tel varchar(40) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'active',
  token varchar(40) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  unsubscribed_at datetime NULL,
  unsub_step_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  KEY token (token)
) $c;";
    $sql[] = "CREATE TABLE " . msm_table('downloads') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  subscriber_id bigint(20) unsigned NOT NULL,
  form_id bigint(20) unsigned NOT NULL DEFAULT 0,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY subscriber_id (subscriber_id)
) $c;";
    $sql[] = "CREATE TABLE " . msm_table('enrollments') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  subscriber_id bigint(20) unsigned NOT NULL,
  scenario varchar(50) NOT NULL DEFAULT 'all',
  started_at datetime NOT NULL,
  last_sent_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY sub_scenario (subscriber_id,scenario)
) $c;";
    $sql[] = "CREATE TABLE " . msm_table('steps') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scenario varchar(50) NOT NULL DEFAULT 'all',
  position int(11) NOT NULL DEFAULT 0,
  delay_days int(11) NOT NULL DEFAULT 0,
  subject varchar(255) NOT NULL DEFAULT '',
  body longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'paused',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY scenario (scenario)
) $c;";
    $sql[] = "CREATE TABLE " . msm_table('sends') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  subscriber_id bigint(20) unsigned NOT NULL,
  step_id bigint(20) unsigned NOT NULL,
  token varchar(40) NOT NULL DEFAULT '',
  sent_at datetime NOT NULL,
  result varchar(20) NOT NULL DEFAULT 'sending',
  attempts int(11) NOT NULL DEFAULT 1,
  opened_at datetime NULL,
  open_count int(11) NOT NULL DEFAULT 0,
  clicked_at datetime NULL,
  click_count int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY sub_step (subscriber_id,step_id),
  KEY token (token),
  KEY step_id (step_id)
) $c;";
    $sql[] = "CREATE TABLE " . msm_table('clicks') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  send_id bigint(20) unsigned NOT NULL,
  url text NOT NULL,
  clicked_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY send_id (send_id)
) $c;";
    foreach ($sql as $q) dbDelta($q);
    if (!get_option('msm_secret')) {
        update_option('msm_secret', wp_generate_password(48, false, false), false);
    }
    update_option('msm_db_ver', MSM_DB_VER, false);
}

add_action('plugins_loaded', function () {
    if (get_option('msm_db_ver') !== MSM_DB_VER) msm_install();
});

function msm_defaults() {
    return array(
        'mode'          => 'test',                 // test=読者には送らない（入れた直後の既定）／live=本番
        'test_emails'   => 'info@f-mikata.co.jp',  // テストモードで実際に送る宛先
        'enabled'       => 1,
        'from_name'     => '',
        'from_email'    => '',
        'send_start'    => 9,
        'send_end'      => 20,
        'per_run'       => 30,
        'consent_field' => 'msm-consent',
        'field_email'   => 'your-email',
        'field_name'    => 'your-name',
        'field_company' => 'company',
        'field_tel'     => 'tel',
        'footer'        => "――――――――――――――――――――\n工務店のミカタ（運営：ミカタ株式会社）\n〒101-0047 東京都千代田区内神田2-15-2 内神田DNKビル5F\nmail：info@f-mikata.co.jp\n\nこのメールは、工務店のミカタで資料をダウンロードした際に、お知らせメールの受け取りに同意いただいた方にお送りしています。",
    );
}

function msm_opt($key = null) {
    $o = isset($GLOBALS['MSM_OPT_OVERRIDE']) ? $GLOBALS['MSM_OPT_OVERRIDE'] : wp_parse_args((array) get_option(MSM_OPT, array()), msm_defaults());
    return $key === null ? $o : ($o[$key] ?? null);
}
