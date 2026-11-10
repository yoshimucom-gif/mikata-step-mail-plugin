<?php
/**
 * 登録（Contact Form 7 の送信）→ 配信（WP-Cron）→ 記録（REST の受け口）
 */
if (!defined('ABSPATH')) exit;

/* ------------------------------------------------------------------ 配信の時計 */
add_filter('cron_schedules', function ($s) {
    $s['msm_15min'] = array('interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'ステップメール（15分ごと）');
    return $s;
});

function msm_schedule() {
    if (!wp_next_scheduled('msm_tick')) {
        wp_schedule_event(time() + 60, 'msm_15min', 'msm_tick');
    }
}
add_action('init', 'msm_schedule');
add_action('msm_tick', 'msm_run_queue');

/* ------------------------------------------------------------------ 登録 */
add_action('wpcf7_mail_sent', 'msm_on_cf7_sent');

function msm_on_cf7_sent($contact_form) {
    if (!msm_opt('enabled') || !class_exists('WPCF7_Submission')) return;
    $sub = WPCF7_Submission::get_instance();
    if (!$sub) return;
    $d = (array) $sub->get_posted_data();
    $consent = $d[msm_opt('consent_field')] ?? '';
    if (is_array($consent)) $consent = implode('', $consent);
    if ((string) $consent === '' || (string) $consent === '0') return;   // 同意していない人は登録しない

    $post_id = (int) $sub->get_meta('container_post_id');
    msm_subscribe(array(
        'email'   => $d[msm_opt('field_email')] ?? '',
        'name'    => $d[msm_opt('field_name')] ?? '',
        'company' => $d[msm_opt('field_company')] ?? '',
        'tel'     => $d[msm_opt('field_tel')] ?? '',
        'form_id' => method_exists($contact_form, 'id') ? (int) $contact_form->id() : 0,
        'post_id' => $post_id,
        'title'   => $post_id ? get_the_title($post_id) : '',
    ));
}

/**
 * 読者を登録し、ダウンロードの記録と配信の対象（全員＋記事のカテゴリー）を付ける。
 * 配信停止した人がもう一度同意してダウンロードした場合は、配信を再開する（本人が改めて同意したため）。
 * 返り値: 読者ID（メールアドレスが不正なら 0）
 */
function msm_subscribe($in) {
    global $wpdb;
    $email = strtolower(trim(sanitize_email(is_array($in['email']) ? implode('', $in['email']) : (string) $in['email'])));
    if (!$email || !is_email($email)) return 0;
    $clean = function ($v, $len) {
        $v = sanitize_text_field(is_array($v) ? implode(' ', $v) : (string) $v);
        return function_exists('mb_substr') ? mb_substr($v, 0, $len) : substr($v, 0, $len);
    };
    $now = msm_now();
    $t = msm_table('subscribers');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE email = %s", $email));
    $data = array('updated_at' => $now);
    foreach (array('name' => 100, 'company' => 150, 'tel' => 40) as $k => $len) {
        $v = $clean($in[$k] ?? '', $len);
        if ($v !== '') $data[$k] = $v;
    }
    if ($row) {
        $id = (int) $row->id;
        if ($row->status !== 'active') {
            $data['status'] = 'active';
            $data['unsubscribed_at'] = null;
            $data['unsub_step_id'] = 0;
        }
        $wpdb->update($t, $data, array('id' => $id));
    } else {
        $data += array('email' => $email, 'status' => 'active', 'token' => msm_token(), 'created_at' => $now,
                       'name' => '', 'company' => '', 'tel' => '', 'unsub_step_id' => 0);
        if (!$wpdb->insert($t, $data)) return 0;
        $id = (int) $wpdb->insert_id;
    }
    $wpdb->insert(msm_table('downloads'), array(
        'subscriber_id' => $id, 'form_id' => (int) ($in['form_id'] ?? 0), 'post_id' => (int) ($in['post_id'] ?? 0),
        'title' => $clean($in['title'] ?? '', 255), 'created_at' => $now,
    ));
    $scenarios = array('all');
    $pid = (int) ($in['post_id'] ?? 0);
    if ($pid) {
        foreach ((array) wp_get_post_categories($pid) as $cid) $scenarios[] = 'cat:' . (int) $cid;
    }
    $e = msm_table('enrollments');
    foreach ($scenarios as $sc) {
        $has = $wpdb->get_var($wpdb->prepare("SELECT id FROM $e WHERE subscriber_id = %d AND scenario = %s", $id, $sc));
        if (!$has) $wpdb->insert($e, array('subscriber_id' => $id, 'scenario' => $sc, 'started_at' => $now));
    }
    // 「当日すぐ」のメールを待たせないよう、1分後に配信を1回走らせる
    if (!wp_next_scheduled('msm_tick_once')) wp_schedule_single_event(time() + 60, 'msm_tick_once');
    return $id;
}
add_action('msm_tick_once', 'msm_run_queue');

function msm_token() {
    return md5(wp_generate_password(32, false, false) . microtime(true) . mt_rand());
}

function msm_sig($data) {
    return substr(hash_hmac('sha256', $data, (string) get_option('msm_secret')), 0, 20);
}

/* ------------------------------------------------------------------ 配信 */

/* サイトの時刻で、送ってよい時間帯か */
function msm_in_window($ts) {
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Asia/Tokyo');
    $h = (int) (new DateTime('@' . $ts))->setTimezone($tz)->format('G');
    $s = (int) msm_opt('send_start');
    $e = (int) msm_opt('send_end');
    return $s <= $e ? ($h >= $s && $h < $e) : ($h >= $s || $h < $e);
}

/* 配信順に並んだ「送る状態」のメール（シナリオごと） */
function msm_active_steps($scenario) {
    global $wpdb;
    $t = msm_table('steps');
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $t WHERE scenario = %s AND status = 'active' ORDER BY position ASC, id ASC", $scenario));
}

/**
 * 期日の来たメールを送る。返り値は array('sent'=>n, 'failed'=>n, 'skipped'=>理由)
 * $force=true は時間帯と停止スイッチを無視する（テスト用）
 */
function msm_run_queue($force = false) {
    global $wpdb;
    $force = ($force === true);
    $stat = array('sent' => 0, 'failed' => 0, 'skipped' => '');
    if (!$force && !msm_opt('enabled')) { $stat['skipped'] = 'disabled'; return $stat; }
    $now_ts = msm_time();
    if (!$force && !msm_in_window($now_ts)) { $stat['skipped'] = 'window'; return $stat; }
    if (get_transient('msm_lock')) { $stat['skipped'] = 'locked'; return $stat; }
    set_transient('msm_lock', 1, 5 * MINUTE_IN_SECONDS);

    $limit = max(1, (int) msm_opt('per_run'));
    $E = msm_table('enrollments');
    $S = msm_table('subscribers');
    $L = msm_table('sends');
    $rows = $wpdb->get_results(
        "SELECT e.id AS eid, e.scenario, e.started_at, e.last_sent_at, s.* FROM $E e
         INNER JOIN $S s ON s.id = e.subscriber_id WHERE s.status = 'active' ORDER BY e.id ASC");
    $cache = array();
    $sent_today = array();
    $day_ago = gmdate('Y-m-d H:i:s', $now_ts - 20 * HOUR_IN_SECONDS);
    foreach ($rows as $r) {
        if ($stat['sent'] + $stat['failed'] >= $limit) break;
        $sid = (int) $r->id;
        if (!isset($cache[$r->scenario])) $cache[$r->scenario] = msm_active_steps($r->scenario);
        $steps = $cache[$r->scenario];
        if (!$steps) continue;
        // 同じ人へは1日1通まで（シナリオをまたいでも）
        if (!isset($sent_today[$sid])) {
            $sent_today[$sid] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $L WHERE subscriber_id = %d AND sent_at > %s", $sid, $day_ago));
        }
        if ($sent_today[$sid] > 0) continue;
        // 送れたもの・3回失敗したものは済み。失敗が3回未満なら次の回にもう一度送る
        $done = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT step_id FROM $L WHERE subscriber_id = %d AND (result = 'sent' OR attempts >= 3)", $sid)));
        $next = null;
        foreach ($steps as $st) {
            if (!in_array((int) $st->id, $done, true)) { $next = $st; break; }
        }
        if (!$next) continue;
        $base = $r->last_sent_at ? $r->last_sent_at : $r->started_at;
        $due = strtotime($base . ' UTC') + (int) $next->delay_days * DAY_IN_SECONDS;
        if ($due > $now_ts) continue;
        $ok = msm_send_step($next, $r);
        if ($ok === null) continue;   // 二重送信の防止に当たった
        $sent_today[$sid] = 1;
        if (!$ok) { $stat['failed']++; continue; }   // 失敗は間隔の起点にしない（次の回に送り直す）
        $stat['sent']++;
        $wpdb->update($E, array('last_sent_at' => msm_now()), array('id' => (int) $r->eid));
    }
    delete_transient('msm_lock');
    return $stat;
}

/**
 * 1通送って記録する。true=送信できた / false=送信に失敗 / null=すでに送った（記録の重複で止めた）
 */
function msm_send_step($step, $sub) {
    global $wpdb;
    $L = msm_table('sends');
    $prev = $wpdb->get_row($wpdb->prepare("SELECT * FROM $L WHERE subscriber_id = %d AND step_id = %d", (int) $sub->id, (int) $step->id));
    if ($prev) {
        if ($prev->result !== 'failed' || (int) $prev->attempts >= 3) return null;
        $token = $prev->token;
        $send_id = (int) $prev->id;
        $wpdb->update($L, array('attempts' => (int) $prev->attempts + 1, 'sent_at' => msm_now(), 'result' => 'sending'), array('id' => $send_id));
    } else {
        $token = msm_token();
        $ins = $wpdb->insert($L, array('subscriber_id' => (int) $sub->id, 'step_id' => (int) $step->id, 'token' => $token,
                                       'sent_at' => msm_now(), 'result' => 'sending', 'attempts' => 1, 'open_count' => 0, 'click_count' => 0));
        if (!$ins) return null;   // 同じ人に同じメールの記録がすでにある（同時に走った別の配信が先に入れた）
        $send_id = (int) $wpdb->insert_id;
    }
    $ok = msm_mail($step, $sub, $token);
    $wpdb->update($L, array('result' => $ok ? 'sent' : 'failed'), array('id' => $send_id));
    return $ok;
}

/* 差し込み: {会社名} {お名前} {DLした資料} */
function msm_fill($text, $sub) {
    global $wpdb;
    $title = '';
    if (!empty($sub->id)) {
        $title = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT title FROM " . msm_table('downloads') . " WHERE subscriber_id = %d ORDER BY id DESC LIMIT 1", (int) $sub->id));
    }
    $name = trim((string) ($sub->name ?? ''));
    return strtr((string) $text, array(
        '{会社名}'     => trim((string) ($sub->company ?? '')),
        '{お名前}'     => $name !== '' ? $name : 'ご担当者',
        '{DLした資料}' => $title,
    ));
}

/**
 * 本文（テキスト）→ HTML。段落・改行・URLのリンク化。$token が null のときはクリックを記録しない（テスト送信）
 */
function msm_text_to_html($text, $token) {
    $html = esc_html($text);
    $html = preg_replace_callback('~https?://[^\s<>"\'）」』】]+~u', function ($m) use ($token) {
        $url = html_entity_decode($m[0], ENT_QUOTES, 'UTF-8');
        $href = $token ? msm_click_url($token, $url) : $url;
        return '<a href="' . esc_url($href) . '" style="color:#4A7440">' . $m[0] . '</a>';
    }, $html);
    $paras = preg_split("/\R{2,}/u", trim($html));
    $out = '';
    foreach ($paras as $p) $out .= '<p style="margin:0 0 1.2em">' . preg_replace("/\R/u", '<br>', $p) . "</p>\n";
    return $out;
}

function msm_click_url($token, $url) {
    return add_query_arg(array('t' => $token, 'u' => rtrim(strtr(base64_encode($url), '+/', '-_'), '='),
                               's' => msm_sig($token . '|' . $url)), rest_url('msm/v1/c'));
}

function msm_open_url($token) {
    return rest_url('msm/v1/o/' . $token);
}

function msm_unsub_url($sub_token, $send_token = '') {
    $args = array('t' => $sub_token, 's' => msm_sig('u|' . $sub_token));
    if ($send_token) $args['m'] = $send_token;
    return add_query_arg($args, rest_url('msm/v1/u'));
}

/* メールを組み立てて送る（$token=null はテスト送信） */
function msm_mail($step, $sub, $token, $to = null) {
    $subject = msm_fill($step->subject, $sub);
    $body = msm_fill($step->body, $sub);
    $unsub = msm_unsub_url((string) ($sub->token ?? 'test'), (string) $token);
    $footer = trim((string) msm_opt('footer'));
    $html = '<div style="font-family:\'Hiragino Sans\',\'Yu Gothic\',Meiryo,sans-serif;font-size:15px;line-height:1.8;color:#333833;max-width:640px">'
        . msm_text_to_html($body, $token)
        . '<div style="margin-top:2em;padding-top:1em;border-top:1px solid #dfe2dc;font-size:12px;color:#686C68">'
        . msm_text_to_html($footer, null)
        . '<p style="margin:0">配信の停止：<a href="' . esc_url($unsub) . '" style="color:#686C68">お知らせメールを停止する</a></p>'
        . '</div>'
        . ($token ? '<img src="' . esc_url(msm_open_url($token)) . '" width="1" height="1" alt="" style="display:block;border:0">' : '')
        . '</div>';
    $alt = $body . "\n\n" . $footer . "\n\n配信の停止：" . $unsub;
    $headers = array('Content-Type: text/html; charset=UTF-8',
                     'List-Unsubscribe: <' . $unsub . '>',
                     'List-Unsubscribe-Post: List-Unsubscribe=One-Click');
    $from = trim((string) msm_opt('from_email'));
    if ($from && is_email($from)) {
        $name = trim((string) msm_opt('from_name'));
        $headers[] = 'From: ' . ($name !== '' ? '=?UTF-8?B?' . base64_encode($name) . '?= ' : '') . '<' . $from . '>';
    }
    $set_alt = function ($phpmailer) use ($alt) { $phpmailer->AltBody = $alt; };
    add_action('phpmailer_init', $set_alt);
    $ok = (bool) wp_mail($to ? $to : $sub->email, $subject, $html, $headers);
    remove_action('phpmailer_init', $set_alt);
    return $ok;
}

/* ------------------------------------------------------------------ 記録（REST） */
add_action('rest_api_init', function () {
    register_rest_route('msm/v1', '/o/(?P<t>[a-f0-9]{32})', array(
        'methods' => 'GET', 'callback' => 'msm_rest_open', 'permission_callback' => '__return_true'));
    register_rest_route('msm/v1', '/c', array(
        'methods' => 'GET', 'callback' => 'msm_rest_click', 'permission_callback' => '__return_true'));
    register_rest_route('msm/v1', '/u', array(
        'methods' => array('GET', 'POST'), 'callback' => 'msm_rest_unsub', 'permission_callback' => '__return_true'));
});

function msm_find_send($token) {
    global $wpdb;
    if (!preg_match('/^[a-f0-9]{32}$/', (string) $token)) return null;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . msm_table('sends') . " WHERE token = %s", $token));
}

function msm_mark_open($send) {
    global $wpdb;
    $L = msm_table('sends');
    $wpdb->query($wpdb->prepare("UPDATE $L SET open_count = open_count + 1 WHERE id = %d", (int) $send->id));
    if (!$send->opened_at) $wpdb->update($L, array('opened_at' => msm_now()), array('id' => (int) $send->id));
}

function msm_rest_open($req) {
    $send = msm_find_send($req['t']);
    if ($send) msm_mark_open($send);
    if (defined('MSM_TESTING')) return array('ok' => (bool) $send);
    nocache_headers();
    header('Content-Type: image/gif');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

function msm_rest_click($req) {
    global $wpdb;
    $token = (string) $req['t'];
    $u = (string) $req['u'];
    $url = base64_decode(strtr($u, '-_', '+/') . str_repeat('=', (4 - strlen($u) % 4) % 4), true);
    $valid = $url && preg_match('~^https?://~i', $url) && hash_equals(msm_sig($token . '|' . $url), (string) $req['s']);
    if ($valid) {
        $send = msm_find_send($token);
        if ($send) {
            $L = msm_table('sends');
            $wpdb->query($wpdb->prepare("UPDATE $L SET click_count = click_count + 1 WHERE id = %d", (int) $send->id));
            if (!$send->clicked_at) $wpdb->update($L, array('clicked_at' => msm_now()), array('id' => (int) $send->id));
            if (!$send->opened_at) msm_mark_open($send);   // 画像を読まない設定でも、クリックしたなら開封している
            $wpdb->insert(msm_table('clicks'), array('send_id' => (int) $send->id, 'url' => esc_url_raw($url), 'clicked_at' => msm_now()));
        }
    }
    $dest = $valid ? $url : home_url('/');
    if (defined('MSM_TESTING')) return array('ok' => $valid, 'location' => $dest);
    wp_redirect($dest, 302);
    exit;
}

/**
 * 配信停止。GET は確認の画面、POST で止める。
 * メールソフトの「配信停止」ボタン（List-Unsubscribe-Post: One-Click）は POST で来るので、そのまま止める。
 */
function msm_rest_unsub($req) {
    global $wpdb;
    $t = (string) $req['t'];
    $valid = preg_match('/^[a-f0-9]{32}$/', $t) && hash_equals(msm_sig('u|' . $t), (string) $req['s']);
    $S = msm_table('subscribers');
    $sub = $valid ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $S WHERE token = %s", $t)) : null;
    $msg = '';
    if (!$sub) {
        $msg = 'このリンクは無効です。お手数ですが、届いたメールのリンクからもう一度お試しください。';
    } elseif ($req->get_method() === 'POST') {
        if ($sub->status !== 'unsubscribed') {
            $step_id = 0;
            $send = msm_find_send($req['m']);
            if ($send && (int) $send->subscriber_id === (int) $sub->id) $step_id = (int) $send->step_id;
            $wpdb->update($S, array('status' => 'unsubscribed', 'unsubscribed_at' => msm_now(), 'updated_at' => msm_now(),
                                    'unsub_step_id' => $step_id), array('id' => (int) $sub->id));
        }
        $msg = '配信を停止しました。これまでお読みいただき、ありがとうございました。';
    }
    if (defined('MSM_TESTING')) return array('valid' => (bool) $sub, 'message' => $msg);
    nocache_headers();
    header('Content-Type: text/html; charset=UTF-8');
    $site = esc_html(get_bloginfo('name'));
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>配信の停止｜' . $site . '</title>'
        . '<style>body{font-family:sans-serif;background:#F6F7F4;color:#333833;margin:0;padding:48px 16px}'
        . '.b{max-width:520px;margin:0 auto;background:#fff;border:1px solid #dfe2dc;border-radius:8px;padding:32px;text-align:center}'
        . 'button{background:#5E8A4E;color:#fff;border:0;border-radius:6px;padding:14px 32px;font-size:16px;cursor:pointer}</style></head><body><div class="b">'
        . '<h1 style="font-size:20px">' . $site . ' のお知らせメール</h1>';
    if ($msg) {
        echo '<p>' . esc_html($msg) . '</p>';
    } else {
        echo '<p>' . esc_html($sub->email) . ' へのお知らせメールを停止します。</p><form method="post">'
            . '<input type="hidden" name="t" value="' . esc_attr($t) . '"><input type="hidden" name="s" value="' . esc_attr($req['s']) . '">'
            . '<input type="hidden" name="m" value="' . esc_attr((string) $req['m']) . '">'
            . '<button type="submit">配信を停止する</button></form>';
    }
    echo '<p style="margin-top:24px"><a href="' . esc_url(home_url('/')) . '">' . $site . ' へ</a></p></div></body></html>';
    exit;
}
