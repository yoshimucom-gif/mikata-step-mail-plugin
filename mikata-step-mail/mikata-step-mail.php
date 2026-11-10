<?php
/**
 * Plugin Name: 工務店のミカタステップメール
 * Description: 資料をダウンロードした人（フォームでお知らせメールの受け取りに同意した人）に、決めた順番・間隔でメールを自動で送ります。メールごとの開封・クリック・配信停止を記録し、管理画面で順番をドラッグで入れ替えられます。Contact Form 7 のフォームに同意の欄（acceptance msm-consent）を置いて使います。
 * Version: 1.0.1
 * Author: ミカタ株式会社
 * License: GPLv2 or later
 * Text Domain: mikata-step-mail
 *
 * ★設計上の決めごと
 *   - 送るのは同意の欄にチェックした人だけ（特定電子メール法）。全メールに送信者の表示と配信停止のリンクを自動で付ける。
 *   - 開封・クリック・配信停止の受け口は REST（/msm/v1/…）に置く。ステージングのように画面がログイン保護されていても届くため。
 *   - 順番は「前のメールから○日後」で持つ。並べ替えても間隔はそのまま、まだ届いていないメールにだけ効く。
 *   - 同じ人へは1日1通まで。送る時間帯（既定 9〜20時）の外では送らない。
 *   - 自動更新は最初の版から同梱（GitHub の raw に置いた update.json を見る）。
 */

if (!defined('ABSPATH')) exit;

define('MSM_VER', '1.0.1');
define('MSM_DB_VER', '1');
define('MSM_OPT', 'msm_options');
define('MSM_DIR', plugin_dir_path(__FILE__));
define('MSM_URL', plugin_dir_url(__FILE__));
define('MSM_UPDATE_URL', 'https://raw.githubusercontent.com/yoshimucom-gif/mikata-step-mail-plugin/main/update.json');

/* 更新チェッカー。is_admin() だけで囲わない（WP-Cron は管理画面の外で走る） */
if (is_admin() || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
    require_once __DIR__ . '/includes/plugin-updater.php';
    new MSM_Step_Mail_Updater(__FILE__, MSM_UPDATE_URL);
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/core.php';
if (is_admin()) {
    require_once __DIR__ . '/includes/admin.php';
}

register_activation_hook(__FILE__, 'msm_activate');
register_deactivation_hook(__FILE__, 'msm_deactivate');

function msm_activate() {
    msm_install();
    msm_schedule();
}

function msm_deactivate() {
    wp_clear_scheduled_hook('msm_tick');
}
