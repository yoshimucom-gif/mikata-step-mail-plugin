/* ステップメール 管理画面: 行のドラッグで順番を入れ替えて保存する */
jQuery(function ($) {
  var $body = $('#msm-sortable');
  if ($body.length) {
    $body.sortable({
      handle: '.msm-h',
      axis: 'y',
      helper: function (e, tr) {
        var $orig = tr.children(), $help = tr.clone();
        $help.children().each(function (i) { $(this).width($orig.eq(i).width()); });
        return $help;
      },
      update: function () {
        var ids = $body.children('tr').map(function () { return $(this).data('id'); }).get();
        // 番号と「送るタイミング」の書き方（1通目だけ「DLから」）をその場で直す
        $body.children('tr').each(function (i) {
          var $tr = $(this), d = parseInt($tr.find('.msm-when').data('days'), 10) || 0;
          $tr.find('.msm-no').text(i + 1);
          $tr.find('.msm-when').text(i === 0 ? (d === 0 ? 'DLした当日' : 'DLから' + d + '日後') : '前のメールから' + d + '日後');
        });
        $.post(MSM.ajax, { action: 'msm_reorder', nonce: MSM.nonce, sc: $body.data('sc'), ids: ids }, function (r) {
          $('.msm-saved').prop('hidden', !(r && r.ok)).text(r && r.ok ? '順番を保存しました。' : '保存できませんでした。画面を読み込み直してください。');
        });
      }
    });
  }
  // 本番への切り替えは確認してから
  var wasLive = $('#msm-live').is(':checked');
  $('#msm-live').closest('form').on('submit', function () {
    if (!wasLive && $('#msm-live').is(':checked')) {
      return window.confirm('本番に切り替えると、同意した読者にステップメールが届き始めます。配信予定（ドライラン）で確かめましたか？');
    }
    return true;
  });
  $('#msm-sc-jump').on('change', function () {
    location.href = 'admin.php?page=msm&sc=' + encodeURIComponent(this.value);
  });
});
