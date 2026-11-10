<?php
/**
 * 管理画面: メール（順番の入れ替え・成果）／メールの編集／読者／設定
 */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page('ステップメール', 'ステップメール', 'manage_options', 'msm', 'msm_page_steps', 'dashicons-email-alt', 58);
    add_submenu_page('msm', 'メール', 'メール', 'manage_options', 'msm', 'msm_page_steps');
    add_submenu_page('msm', '配信予定（ドライラン）', '配信予定（ドライラン）', 'manage_options', 'msm-plan', 'msm_page_plan');
    add_submenu_page('msm', '読者', '読者', 'manage_options', 'msm-readers', 'msm_page_readers');
    add_submenu_page('msm', '設定', '設定', 'manage_options', 'msm-settings', 'msm_page_settings');
    add_submenu_page('', 'メールの編集', 'メールの編集', 'manage_options', 'msm-edit', 'msm_page_edit');
    add_submenu_page('', '読者の詳細', '読者の詳細', 'manage_options', 'msm-reader', 'msm_page_reader');
});

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos((string) $hook, 'msm') === false) return;
    wp_enqueue_script('msm-admin', MSM_URL . 'assets/admin.js', array('jquery', 'jquery-ui-sortable'), MSM_VER, true);
    wp_localize_script('msm-admin', 'MSM', array('ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('msm_reorder')));
    wp_enqueue_style('msm-admin', MSM_URL . 'assets/admin.css', array(), MSM_VER);
});

function msm_cap() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
}

function msm_scenarios() {
    $out = array('all' => 'DLした全員');
    foreach (get_categories(array('hide_empty' => true)) as $c) {
        $out['cat:' . $c->term_id] = '「' . $c->name . '」の資料をDLした人';
    }
    return $out;
}

function msm_local($gmt, $fmt = 'Y/m/d H:i') {
    return $gmt ? get_date_from_gmt($gmt, $fmt) : '―';
}

function msm_pct($a, $b) {
    return $b > 0 ? number_format(100 * $a / $b, 1) . '%' : '―';
}

/* メールごとの成果（送信・開封・クリック・停止） */
function msm_step_stats() {
    global $wpdb;
    $L = msm_table('sends');
    $S = msm_table('subscribers');
    $out = array();
    foreach ((array) $wpdb->get_results("SELECT step_id, SUM(CASE WHEN result = 'sent' THEN 1 ELSE 0 END) AS sent,
            SUM(CASE WHEN result = 'failed' THEN 1 ELSE 0 END) AS failed,
            SUM(CASE WHEN result = 'sent' AND opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
            SUM(CASE WHEN result = 'sent' AND clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked
            FROM $L GROUP BY step_id") as $r) {
        $out[(int) $r->step_id] = array('sent' => (int) $r->sent, 'failed' => (int) $r->failed, 'opened' => (int) $r->opened,
                                        'clicked' => (int) $r->clicked, 'unsub' => 0);
    }
    foreach ((array) $wpdb->get_results("SELECT unsub_step_id AS sid, COUNT(*) AS n FROM $S WHERE status = 'unsubscribed' AND unsub_step_id > 0 GROUP BY unsub_step_id") as $r) {
        if (!isset($out[(int) $r->sid])) $out[(int) $r->sid] = array('sent' => 0, 'failed' => 0, 'opened' => 0, 'clicked' => 0, 'unsub' => 0);
        $out[(int) $r->sid]['unsub'] = (int) $r->n;
    }
    return $out;
}

function msm_notice() {
    if (msm_is_test_mode()) {
        $list = msm_test_list();
        echo '<div class="notice notice-warning msm-testmode"><p><strong>テストモードです。読者には送っていません。</strong> '
            . ($list ? 'テスト用の宛先（' . esc_html(implode('、', $list)) . '）にだけ、件名に［テスト］を付けて実際に送ります。' : 'テスト用の宛先が空なので、誰にも送りません。')
            . ' <a href="' . esc_url(admin_url('admin.php?page=msm-settings')) . '">設定で本番に切り替える</a></p></div>';
    }
    $m = isset($_GET['msm_msg']) ? sanitize_text_field(wp_unslash($_GET['msm_msg'])) : '';
    if ($m !== '') echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($m) . '</p></div>';
    $e = isset($_GET['msm_err']) ? sanitize_text_field(wp_unslash($_GET['msm_err'])) : '';
    if ($e !== '') echo '<div class="notice notice-error"><p>' . esc_html($e) . '</p></div>';
}

/* ------------------------------------------------------------------ メール一覧 */
function msm_page_steps() {
    msm_cap();
    global $wpdb;
    $scs = msm_scenarios();
    $cur = isset($_GET['sc']) && isset($scs[$_GET['sc']]) ? sanitize_text_field(wp_unslash($_GET['sc'])) : 'all';
    $T = msm_table('steps');
    $steps = $wpdb->get_results($wpdb->prepare("SELECT * FROM $T WHERE scenario = %s ORDER BY position ASC, id ASC", $cur));
    $stats = msm_step_stats();
    $S = msm_table('subscribers');
    $L = msm_table('sends');
    $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM $S WHERE status = 'active'");
    $stopped = (int) $wpdb->get_var("SELECT COUNT(*) FROM $S WHERE status = 'unsubscribed'");
    $tot = $wpdb->get_row("SELECT SUM(CASE WHEN result = 'sent' THEN 1 ELSE 0 END) AS sent,
        SUM(CASE WHEN result = 'sent' AND opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
        SUM(CASE WHEN result = 'sent' AND clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked FROM $L");
    $next = wp_next_scheduled('msm_tick');
    echo '<div class="wrap msm"><h1 class="wp-heading-inline">ステップメール</h1>';
    echo '<a class="page-title-action" href="' . esc_url(admin_url('admin.php?page=msm-edit&sc=' . rawurlencode($cur))) . '">メールを追加</a>';
    msm_notice();
    if (!msm_opt('enabled')) echo '<div class="notice notice-warning"><p>配信は止まっています（設定で再開できます）。</p></div>';
    echo '<div class="msm-cards">';
    foreach (array(array('配信中の読者', number_format($active) . '人'), array('配信停止', number_format($stopped) . '人'),
                   array('送ったメール', number_format((int) $tot->sent) . '通'), array('開封率', msm_pct((int) $tot->opened, (int) $tot->sent)),
                   array('クリック率', msm_pct((int) $tot->clicked, (int) $tot->sent)),
                   array('次の配信の確認', $next ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'm/d H:i') : '未設定')) as $c) {
        echo '<div class="msm-card"><span>' . esc_html($c[0]) . '</span><strong>' . esc_html($c[1]) . '</strong></div>';
    }
    echo '</div>';
    echo '<h2 class="nav-tab-wrapper">';
    foreach ($scs as $k => $label) {
        $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $T WHERE scenario = %s", $k));
        if ($k !== 'all' && $n === 0 && $k !== $cur) continue;
        echo '<a class="nav-tab' . ($k === $cur ? ' nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=msm&sc=' . rawurlencode($k))) . '">'
            . esc_html($label) . ($n ? '（' . $n . '）' : '') . '</a>';
    }
    echo '</h2>';
    echo '<p class="description">行をドラッグすると送る順番が変わります。間隔（前のメールから○日後）はそのまま、まだ届いていないメールにだけ効きます。'
        . '別のカテゴリーで分けて送るときは <select id="msm-sc-jump">';
    foreach ($scs as $k => $label) echo '<option value="' . esc_attr($k) . '"' . selected($k, $cur, false) . '>' . esc_html($label) . '</option>';
    echo '</select> を選んでメールを追加します。</p>';
    if (!$steps) {
        echo '<p>まだメールがありません。「メールを追加」から作ってください。</p></div>';
        return;
    }
    echo '<table class="widefat striped msm-steps"><thead><tr><th class="msm-h"></th><th>順番</th><th>件名</th><th>送るタイミング</th><th>状態</th>'
        . '<th class="num">送信</th><th class="num">開封率</th><th class="num">クリック率</th><th class="num">配信停止</th><th></th></tr></thead><tbody id="msm-sortable" data-sc="' . esc_attr($cur) . '">';
    $i = 0;
    foreach ($steps as $st) {
        $i++;
        $s = $stats[(int) $st->id] ?? array('sent' => 0, 'failed' => 0, 'opened' => 0, 'clicked' => 0, 'unsub' => 0);
        $when = $i === 1 ? ((int) $st->delay_days === 0 ? 'DLした当日' : 'DLから' . (int) $st->delay_days . '日後')
                         : '前のメールから' . (int) $st->delay_days . '日後';
        $edit = admin_url('admin.php?page=msm-edit&id=' . (int) $st->id);
        echo '<tr data-id="' . (int) $st->id . '"><td class="msm-h" title="ドラッグで並べ替え">≡</td><td class="msm-no">' . $i . '</td>'
            . '<td><a href="' . esc_url($edit) . '"><strong>' . esc_html($st->subject) . '</strong></a></td>'
            . '<td class="msm-when" data-days="' . (int) $st->delay_days . '">' . esc_html($when) . '</td>'
            . '<td>' . ($st->status === 'active' ? '<span class="msm-on">送る</span>' : '<span class="msm-off">止めている</span>') . '</td>'
            . '<td class="num">' . number_format($s['sent']) . ($s['failed'] ? '<br><small class="msm-err">失敗 ' . $s['failed'] . '</small>' : '') . '</td>'
            . '<td class="num">' . msm_pct($s['opened'], $s['sent']) . '</td><td class="num">' . msm_pct($s['clicked'], $s['sent']) . '</td>'
            . '<td class="num">' . number_format($s['unsub']) . '</td><td><a href="' . esc_url($edit) . '">編集</a></td></tr>';
    }
    echo '</tbody></table><p class="msm-saved" hidden>順番を保存しました。</p>';
    echo '<p class="description">開封率は、メールの画像が読み込まれた割合です。iPhoneのメールアプリは受信しただけで開封になることがあるため、高めに出ます。成果はクリック率で見てください。</p></div>';
}

add_action('wp_ajax_msm_reorder', function () {
    check_ajax_referer('msm_reorder', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json(array('ok' => false));
    global $wpdb;
    $ids = array_map('intval', (array) ($_POST['ids'] ?? array()));
    $sc = sanitize_text_field(wp_unslash($_POST['sc'] ?? 'all'));
    $T = msm_table('steps');
    foreach ($ids as $pos => $id) {
        $wpdb->update($T, array('position' => $pos + 1, 'updated_at' => msm_now()), array('id' => $id, 'scenario' => $sc));
    }
    wp_send_json(array('ok' => true));
});

/* ------------------------------------------------------------------ メールの編集 */
function msm_page_edit() {
    msm_cap();
    global $wpdb;
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $st = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM " . msm_table('steps') . " WHERE id = %d", $id)) : null;
    $scs = msm_scenarios();
    $sc = $st ? $st->scenario : (isset($_GET['sc']) && isset($scs[$_GET['sc']]) ? sanitize_text_field(wp_unslash($_GET['sc'])) : 'all');
    $back = admin_url('admin.php?page=msm&sc=' . rawurlencode($sc));
    echo '<div class="wrap msm"><h1>' . ($st ? 'メールの編集' : 'メールを追加') . '</h1><p><a href="' . esc_url($back) . '">← メールの一覧へ</a></p>';
    msm_notice();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('msm_save_step');
    echo '<input type="hidden" name="action" value="msm_save_step"><input type="hidden" name="id" value="' . (int) $id . '">';
    echo '<table class="form-table"><tr><th>送る相手</th><td><select name="scenario">';
    foreach ($scs as $k => $label) echo '<option value="' . esc_attr($k) . '"' . selected($k, $sc, false) . '>' . esc_html($label) . '</option>';
    echo '</select></td></tr>';
    echo '<tr><th>送るタイミング</th><td>前のメールから <input type="number" name="delay_days" min="0" max="365" value="' . (int) ($st->delay_days ?? 0) . '" class="small-text"> 日後'
        . '<p class="description">1通目は「DLした日から○日後」になります。0なら当日（送る時間帯の中ですぐ）送ります。</p></td></tr>';
    echo '<tr><th>状態</th><td><label><input type="radio" name="status" value="active"' . checked(($st->status ?? 'paused'), 'active', false) . '> 送る</label>　'
        . '<label><input type="radio" name="status" value="paused"' . checked(($st->status ?? 'paused'), 'paused', false) . '> 止めておく（下書き）</label></td></tr>';
    echo '<tr><th>件名</th><td><input type="text" name="subject" class="large-text" required value="' . esc_attr($st->subject ?? '') . '"></td></tr>';
    echo '<tr><th>本文</th><td><textarea name="body" rows="18" class="large-text code">' . esc_textarea($st->body ?? "{会社名}\n{お名前} 様\n\n") . '</textarea>'
        . '<p class="description">差し込み：{会社名} {お名前}（空なら「ご担当者」） {DLした資料}（最後にDLした資料名）。URLはそのまま書けばリンクになり、クリックが記録されます。'
        . '空行で段落が分かれます。末尾に送信者の表示と配信停止のリンクが自動で付きます。</p></td></tr></table>';
    submit_button($st ? '保存する' : '追加する');
    echo '</form>';
    if ($st) {
        echo '<hr><h2>テスト送信</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('msm_test_step');
        echo '<input type="hidden" name="action" value="msm_test_step"><input type="hidden" name="id" value="' . (int) $id . '">'
            . '<input type="email" name="to" required class="regular-text" placeholder="送り先のメールアドレス" value="' . esc_attr(wp_get_current_user()->user_email) . '"> ';
        submit_button('この内容でテスト送信', 'secondary', 'submit', false);
        echo '<p class="description">テスト送信は記録に残りません。差し込みは「株式会社テスト／テスト 太郎」で入ります。保存してから送ってください。</p></form>';
        echo '<hr><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'このメールを削除します。送った記録も消えます。よろしいですか？\')">';
        wp_nonce_field('msm_delete_step');
        echo '<input type="hidden" name="action" value="msm_delete_step"><input type="hidden" name="id" value="' . (int) $id . '">';
        submit_button('このメールを削除', 'delete', 'submit', false);
        echo '</form>';
    }
    echo '</div>';
}

add_action('admin_post_msm_save_step', function () {
    msm_cap();
    check_admin_referer('msm_save_step');
    global $wpdb;
    $T = msm_table('steps');
    $id = (int) ($_POST['id'] ?? 0);
    $scs = msm_scenarios();
    $sc = sanitize_text_field(wp_unslash($_POST['scenario'] ?? 'all'));
    if (!isset($scs[$sc])) $sc = 'all';
    $data = array(
        'scenario'   => $sc,
        'delay_days' => max(0, min(365, (int) ($_POST['delay_days'] ?? 0))),
        'status'     => (($_POST['status'] ?? '') === 'active') ? 'active' : 'paused',
        'subject'    => sanitize_text_field(wp_unslash($_POST['subject'] ?? '')),
        'body'       => sanitize_textarea_field(wp_unslash($_POST['body'] ?? '')),
        'updated_at' => msm_now(),
    );
    if ($id) {
        $old = $wpdb->get_row($wpdb->prepare("SELECT scenario FROM $T WHERE id = %d", $id));
        if ($old && $old->scenario !== $sc) {
            $data['position'] = (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(position) FROM $T WHERE scenario = %s", $sc)) + 1;
        }
        $wpdb->update($T, $data, array('id' => $id));
    } else {
        $data['position'] = (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(position) FROM $T WHERE scenario = %s", $sc)) + 1;
        $data['created_at'] = msm_now();
        $wpdb->insert($T, $data);
        $id = (int) $wpdb->insert_id;
    }
    wp_safe_redirect(admin_url('admin.php?page=msm-edit&id=' . $id . '&msm_msg=' . rawurlencode('保存しました。')));
    exit;
});

add_action('admin_post_msm_test_step', function () {
    msm_cap();
    check_admin_referer('msm_test_step');
    global $wpdb;
    $id = (int) ($_POST['id'] ?? 0);
    $to = sanitize_email(wp_unslash($_POST['to'] ?? ''));
    $st = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . msm_table('steps') . " WHERE id = %d", $id));
    $dummy = (object) array('id' => 0, 'email' => $to, 'name' => 'テスト 太郎', 'company' => '株式会社テスト', 'token' => '');
    $ok = $st && $to && msm_mail($st, $dummy, null, $to);
    $q = $ok ? 'msm_msg=' . rawurlencode($to . ' にテスト送信しました。') : 'msm_err=' . rawurlencode('テスト送信できませんでした。送り先とメールの設定を確かめてください。');
    wp_safe_redirect(admin_url('admin.php?page=msm-edit&id=' . $id . '&' . $q));
    exit;
});

add_action('admin_post_msm_delete_step', function () {
    msm_cap();
    check_admin_referer('msm_delete_step');
    global $wpdb;
    $id = (int) ($_POST['id'] ?? 0);
    $st = $wpdb->get_row($wpdb->prepare("SELECT scenario FROM " . msm_table('steps') . " WHERE id = %d", $id));
    $sends = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . msm_table('sends') . " WHERE step_id = %d", $id));
    foreach ($sends as $sid) $wpdb->delete(msm_table('clicks'), array('send_id' => (int) $sid));
    $wpdb->delete(msm_table('sends'), array('step_id' => $id));
    $wpdb->delete(msm_table('steps'), array('id' => $id));
    wp_safe_redirect(admin_url('admin.php?page=msm&sc=' . rawurlencode($st ? $st->scenario : 'all') . '&msm_msg=' . rawurlencode('削除しました。')));
    exit;
});

/* ------------------------------------------------------------------ 読者 */
function msm_readers_query($status) {
    global $wpdb;
    $S = msm_table('subscribers');
    $D = msm_table('downloads');
    $L = msm_table('sends');
    $where = in_array($status, array('active', 'unsubscribed'), true) ? $wpdb->prepare("WHERE s.status = %s", $status) : '';
    return $wpdb->get_results("SELECT s.*,
        (SELECT COUNT(*) FROM $D d WHERE d.subscriber_id = s.id) AS dl_count,
        (SELECT d.title FROM $D d WHERE d.subscriber_id = s.id ORDER BY d.id DESC LIMIT 1) AS last_title,
        (SELECT COUNT(*) FROM $L l WHERE l.subscriber_id = s.id AND l.result = 'sent') AS sent,
        (SELECT COUNT(*) FROM $L l WHERE l.subscriber_id = s.id AND l.result = 'sent' AND l.opened_at IS NOT NULL) AS opened,
        (SELECT COUNT(*) FROM $L l WHERE l.subscriber_id = s.id AND l.result = 'sent' AND l.clicked_at IS NOT NULL) AS clicked
        FROM $S s $where ORDER BY s.id DESC");
}

function msm_page_readers() {
    msm_cap();
    $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
    $rows = msm_readers_query($status);
    echo '<div class="wrap msm"><h1 class="wp-heading-inline">読者</h1>';
    echo '<a class="page-title-action" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=msm_export&status=' . rawurlencode($status)), 'msm_export')) . '">CSVで書き出す</a>';
    msm_notice();
    echo '<ul class="subsubsub">';
    foreach (array('' => 'すべて', 'active' => '配信中', 'unsubscribed' => '配信停止') as $k => $label) {
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=msm-readers&status=' . $k)) . '"' . ($k === $status ? ' class="current"' : '') . '>' . esc_html($label) . '</a> | </li>';
    }
    echo '</ul><table class="widefat striped"><thead><tr><th>登録日</th><th>会社名</th><th>お名前</th><th>メールアドレス</th><th>状態</th>'
        . '<th>DLした資料</th><th class="num">受信</th><th class="num">開封</th><th class="num">クリック</th></tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="9">まだ読者がいません。DLフォームでお知らせメールに同意した人がここに入ります。</td></tr>';
    foreach ($rows as $r) {
        $link = admin_url('admin.php?page=msm-reader&id=' . (int) $r->id);
        echo '<tr><td>' . esc_html(msm_local($r->created_at, 'Y/m/d')) . '</td><td>' . esc_html($r->company) . '</td><td>' . esc_html($r->name) . '</td>'
            . '<td><a href="' . esc_url($link) . '">' . esc_html($r->email) . '</a></td>'
            . '<td>' . ($r->status === 'active' ? '<span class="msm-on">配信中</span>' : '<span class="msm-off">停止</span>') . '</td>'
            . '<td>' . esc_html($r->last_title) . ((int) $r->dl_count > 1 ? '<br><small>ほか' . ((int) $r->dl_count - 1) . '件</small>' : '') . '</td>'
            . '<td class="num">' . (int) $r->sent . '</td><td class="num">' . (int) $r->opened . '</td><td class="num">' . (int) $r->clicked . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function msm_page_reader() {
    msm_cap();
    global $wpdb;
    $id = (int) ($_GET['id'] ?? 0);
    $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . msm_table('subscribers') . " WHERE id = %d", $id));
    echo '<div class="wrap msm"><h1>読者の詳細</h1><p><a href="' . esc_url(admin_url('admin.php?page=msm-readers')) . '">← 読者の一覧へ</a></p>';
    msm_notice();
    if (!$r) { echo '<p>見つかりません。</p></div>'; return; }
    echo '<table class="form-table"><tr><th>会社名</th><td>' . esc_html($r->company) . '</td></tr><tr><th>お名前</th><td>' . esc_html($r->name) . '</td></tr>'
        . '<tr><th>メールアドレス</th><td>' . esc_html($r->email) . '</td></tr><tr><th>電話番号</th><td>' . esc_html($r->tel) . '</td></tr>'
        . '<tr><th>状態</th><td>' . ($r->status === 'active' ? '配信中' : '配信停止（' . esc_html(msm_local($r->unsubscribed_at)) . '）') . '</td></tr></table>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('msm_reader_status');
    echo '<input type="hidden" name="action" value="msm_reader_status"><input type="hidden" name="id" value="' . (int) $id . '">';
    submit_button($r->status === 'active' ? 'この人への配信を止める' : 'この人への配信を再開する', 'secondary', 'submit', false);
    echo '</form>';
    echo '<h2>DLした資料</h2><table class="widefat striped"><thead><tr><th>日時</th><th>資料</th></tr></thead><tbody>';
    foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM " . msm_table('downloads') . " WHERE subscriber_id = %d ORDER BY id DESC", $id)) as $d) {
        $t = $d->post_id ? '<a href="' . esc_url(get_permalink($d->post_id)) . '" target="_blank">' . esc_html($d->title) . '</a>' : esc_html($d->title);
        echo '<tr><td>' . esc_html(msm_local($d->created_at)) . '</td><td>' . $t . '</td></tr>';
    }
    echo '</tbody></table><h2>届いたメール</h2><table class="widefat striped"><thead><tr><th>送信日時</th><th>件名</th><th>結果</th><th>開封</th><th>クリック</th></tr></thead><tbody>';
    $L = msm_table('sends');
    $T = msm_table('steps');
    $sends = $wpdb->get_results($wpdb->prepare("SELECT l.*, t.subject FROM $L l LEFT JOIN $T t ON t.id = l.step_id WHERE l.subscriber_id = %d ORDER BY l.id DESC", $id));
    if (!$sends) echo '<tr><td colspan="5">まだ届いていません。</td></tr>';
    foreach ($sends as $s) {
        $urls = $wpdb->get_col($wpdb->prepare("SELECT url FROM " . msm_table('clicks') . " WHERE send_id = %d ORDER BY id ASC", (int) $s->id));
        echo '<tr><td>' . esc_html(msm_local($s->sent_at)) . '</td><td>' . esc_html($s->subject ?: '（削除したメール）') . '</td>'
            . '<td>' . ($s->result === 'sent' ? '送信済み' : ($s->result === 'failed' ? '失敗（' . (int) $s->attempts . '回）' : '送信中')) . '</td>'
            . '<td>' . ($s->opened_at ? esc_html(msm_local($s->opened_at)) . '（' . (int) $s->open_count . '回）' : '―') . '</td>'
            . '<td>' . ($s->clicked_at ? esc_html(msm_local($s->clicked_at)) . '<br><small>' . esc_html(implode(' / ', array_unique($urls))) . '</small>' : '―') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

add_action('admin_post_msm_reader_status', function () {
    msm_cap();
    check_admin_referer('msm_reader_status');
    global $wpdb;
    $id = (int) ($_POST['id'] ?? 0);
    $S = msm_table('subscribers');
    $r = $wpdb->get_row($wpdb->prepare("SELECT status FROM $S WHERE id = %d", $id));
    if ($r) {
        $stop = $r->status === 'active';
        $wpdb->update($S, array('status' => $stop ? 'unsubscribed' : 'active', 'unsubscribed_at' => $stop ? msm_now() : null,
                                'unsub_step_id' => 0, 'updated_at' => msm_now()), array('id' => $id));
    }
    wp_safe_redirect(admin_url('admin.php?page=msm-reader&id=' . $id . '&msm_msg=' . rawurlencode('変更しました。')));
    exit;
});

add_action('admin_post_msm_export', function () {
    msm_cap();
    check_admin_referer('msm_export');
    $status = sanitize_text_field(wp_unslash($_GET['status'] ?? ''));
    $rows = msm_readers_query($status);
    if (!headers_sent()) {
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="step-mail-readers-' . gmdate('Ymd') . '.csv"');
    }
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array('登録日', '会社名', 'お名前', 'メールアドレス', '電話番号', '状態', '最後にDLした資料', 'DL数', '受信', '開封', 'クリック'));
    foreach ($rows as $r) {
        fputcsv($out, array(msm_local($r->created_at, 'Y-m-d H:i'), $r->company, $r->name, $r->email, $r->tel,
                            $r->status === 'active' ? '配信中' : '配信停止', $r->last_title, $r->dl_count, $r->sent, $r->opened, $r->clicked));
    }
    fclose($out);
    if (defined('MSM_TESTING')) return;
    exit;
});

/* ------------------------------------------------------------------ 配信予定（ドライラン） */
function msm_page_plan() {
    msm_cap();
    $days = isset($_GET['days']) ? max(1, min(180, (int) $_GET['days'])) : 30;
    $as_live = !isset($_GET['view']) || $_GET['view'] !== 'now';
    $log = msm_simulate($days, $as_live);
    $scs = msm_scenarios();
    $base = admin_url('admin.php?page=msm-plan');
    echo '<div class="wrap msm"><h1>配信予定（ドライラン）</h1>';
    msm_notice();
    echo '<p>メールは送らずに、今の読者・メール・設定のまま進めた場合に、誰にいつどのメールが届くかを計算します。'
        . '送る時間帯・1日1通・メールの順番と間隔・止めているメールの扱いは、実際の配信と同じ判断を使っています。</p>';
    echo '<p class="msm-plan-switch">';
    foreach (array(7, 30, 90) as $d) {
        echo '<a class="button' . ($d === $days ? ' button-primary' : '') . '" href="' . esc_url(add_query_arg(array('days' => $d, 'view' => $as_live ? 'live' : 'now'), $base)) . '">' . $d . '日先まで</a> ';
    }
    echo '　<a class="button' . ($as_live ? ' button-primary' : '') . '" href="' . esc_url(add_query_arg(array('days' => $days, 'view' => 'live'), $base)) . '">本番にした場合（全読者）</a> ';
    echo '<a class="button' . (!$as_live ? ' button-primary' : '') . '" href="' . esc_url(add_query_arg(array('days' => $days, 'view' => 'now'), $base)) . '">今の設定のまま</a></p>';
    if (!$log) {
        echo '<p>この期間に届く予定のメールはありません。メールが「送る」になっているか、読者がいるかを確かめてください。</p></div>';
        return;
    }
    $by_step = array();
    foreach ($log as $x) {
        $k = (int) $x['step']->id;
        if (!isset($by_step[$k])) $by_step[$k] = array('subject' => $x['step']->subject, 'n' => 0);
        $by_step[$k]['n']++;
    }
    echo '<h2>メールごとの通数</h2><table class="widefat striped" style="max-width:720px"><thead><tr><th>件名</th><th class="num">通数</th></tr></thead><tbody>';
    foreach ($by_step as $s) echo '<tr><td>' . esc_html($s['subject']) . '</td><td class="num">' . number_format($s['n']) . '</td></tr>';
    echo '<tr><th>合計</th><th class="num">' . number_format(count($log)) . '</th></tr></tbody></table>';
    echo '<h2>届く予定（時刻順・最大1,000件）</h2><table class="widefat striped"><thead><tr><th>日時（目安）</th><th>読者</th><th>送る相手</th><th>件名</th></tr></thead><tbody>';
    foreach (array_slice($log, 0, 1000) as $x) {
        $when = get_date_from_gmt(gmdate('Y-m-d H:i:s', $x['ts']), 'Y/m/d H') . '時台';
        $who = trim($x['sub']->company . ' ' . $x['sub']->name);
        echo '<tr><td>' . esc_html($when) . '</td><td>' . esc_html($who !== '' ? $who : '（名前なし）') . '<br><small>' . esc_html($x['sub']->email) . '</small></td>'
            . '<td>' . esc_html($scs[$x['step']->scenario] ?? $x['step']->scenario) . '</td><td>' . esc_html(msm_fill($x['step']->subject, $x['sub'])) . '</td></tr>';
    }
    echo '</tbody></table><p class="description">日時は1時間単位の目安です（実際の配信は15分ごと）。この画面ではメールは送られず、記録も変わりません。</p></div>';
}

/* ------------------------------------------------------------------ 設定 */
function msm_page_settings() {
    msm_cap();
    $o = msm_opt();
    echo '<div class="wrap msm"><h1>ステップメールの設定</h1>';
    msm_notice();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('msm_save_settings');
    echo '<input type="hidden" name="action" value="msm_save_settings"><table class="form-table">';
    echo '<tr><th>動作モード</th><td><label><input type="radio" name="mode" value="test"' . checked($o['mode'] !== 'live', true, false) . '> テスト（読者には送らない）</label><br>'
        . '<label><input type="radio" name="mode" value="live" id="msm-live"' . checked($o['mode'], 'live', false) . '> 本番（読者に送る）</label>'
        . '<p class="description">テストでは、下の「テスト用の宛先」の読者にだけ、本番と同じ流れで実際に送ります（件名に［テスト］が付き、開封・クリックも記録）。'
        . 'ほかの読者には何も送らず、配信の順番も進めません。本番に切り替える前に「配信予定（ドライラン）」で、誰にいつ何が届くかを確かめてください。</p></td></tr>';
    echo '<tr><th>テスト用の宛先</th><td><textarea name="test_emails" rows="3" class="large-text">' . esc_textarea($o['test_emails']) . '</textarea>'
        . '<p class="description">1行に1件。このアドレスでDLフォームから同意してDLすると、テストの読者として登録され、ステップメールが順に届きます。</p></td></tr>';
    echo '<tr><th>配信</th><td><label><input type="checkbox" name="enabled" value="1"' . checked($o['enabled'], 1, false) . '> 配信する</label>'
        . '<p class="description">外すと、すべてのメールの配信を止めます（登録は続きます）。</p></td></tr>';
    echo '<tr><th>送る時間帯</th><td><input type="number" name="send_start" min="0" max="23" class="small-text" value="' . (int) $o['send_start'] . '"> 時 〜 '
        . '<input type="number" name="send_end" min="1" max="24" class="small-text" value="' . (int) $o['send_end'] . '"> 時（サイトの時刻）</td></tr>';
    echo '<tr><th>送信者</th><td><input type="text" name="from_name" class="regular-text" placeholder="例：工務店のミカタ" value="' . esc_attr($o['from_name']) . '"> '
        . '<input type="email" name="from_email" class="regular-text" placeholder="空欄ならサイトの既定" value="' . esc_attr($o['from_email']) . '">'
        . '<p class="description">メール送信のプラグイン（WP Mail SMTP など）が送信元を固定している場合は、そちらが優先されます。</p></td></tr>';
    echo '<tr><th>メールの末尾</th><td><textarea name="footer" rows="7" class="large-text">' . esc_textarea($o['footer']) . '</textarea>'
        . '<p class="description">送信者の名前・住所・連絡先を必ず入れてください（特定電子メール法）。配信停止のリンクはこの下に自動で付きます。</p></td></tr>';
    echo '<tr><th>1回に送る上限</th><td><input type="number" name="per_run" min="1" max="200" class="small-text" value="' . (int) $o['per_run'] . '"> 通（15分ごと）</td></tr>';
    echo '<tr><th>フォームの項目名</th><td>同意 <input type="text" name="consent_field" value="' . esc_attr($o['consent_field']) . '"> '
        . 'メール <input type="text" name="field_email" value="' . esc_attr($o['field_email']) . '"> お名前 <input type="text" name="field_name" value="' . esc_attr($o['field_name']) . '"> '
        . '会社名 <input type="text" name="field_company" value="' . esc_attr($o['field_company']) . '"> 電話 <input type="text" name="field_tel" value="' . esc_attr($o['field_tel']) . '">'
        . '<p class="description">Contact Form 7 の項目名です。同意の欄（例：<code>[acceptance msm-consent optional] お知らせメールを受け取る [/acceptance]</code>）にチェックした人だけを登録します。</p></td></tr>';
    echo '</table>';
    submit_button();
    echo '</form></div>';
}

add_action('admin_post_msm_save_settings', function () {
    msm_cap();
    check_admin_referer('msm_save_settings');
    $p = wp_unslash($_POST);
    $o = array(
        'mode'          => (($p['mode'] ?? '') === 'live') ? 'live' : 'test',
        'test_emails'   => sanitize_textarea_field($p['test_emails'] ?? ''),
        'enabled'       => empty($p['enabled']) ? 0 : 1,
        'send_start'    => max(0, min(23, (int) ($p['send_start'] ?? 9))),
        'send_end'      => max(1, min(24, (int) ($p['send_end'] ?? 20))),
        'from_name'     => sanitize_text_field($p['from_name'] ?? ''),
        'from_email'    => sanitize_email($p['from_email'] ?? ''),
        'footer'        => sanitize_textarea_field($p['footer'] ?? ''),
        'per_run'       => max(1, min(200, (int) ($p['per_run'] ?? 30))),
    );
    foreach (array('consent_field', 'field_email', 'field_name', 'field_company', 'field_tel') as $k) {
        $v = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($p[$k] ?? ''));
        $o[$k] = $v !== '' ? $v : msm_defaults()[$k];
    }
    update_option(MSM_OPT, $o, false);
    wp_safe_redirect(admin_url('admin.php?page=msm-settings&msm_msg=' . rawurlencode('保存しました。')));
    exit;
});
