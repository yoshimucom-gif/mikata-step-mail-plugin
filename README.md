# 工務店のミカタステップメール（WordPressプラグイン）

資料をダウンロードした人（Contact Form 7 のフォームでお知らせメールの受け取りに同意した人）に、決めた順番・間隔でメールを自動で送る。

- 同意の欄：`[acceptance msm-consent optional] お知らせメールを受け取る [/acceptance]` にチェックした人だけを登録
- 送る相手：「DLした全員」と、DLした記事のカテゴリーごとに分けられる
- 順番：管理画面でドラッグ。間隔は「前のメールから○日後」
- 記録：メールごとの送信・開封・クリック・配信停止。読者ごとの履歴とCSV
- 受け口は REST（/msm/v1/o・c・u）。画面がログイン保護されたサイトでも届く
- 同じ人へは1日1通まで。送る時間帯（既定9〜20時）の外では送らない。送信に失敗したら翌日以降に3回まで送り直す

## 更新の配り方

```
py build.py 1.0.1 "・変更点"
git add -A && git commit -m "v1.0.1" && git push
```

## テスト

`../wptest-stepmail/unit.php`（`php -d extension=pdo_sqlite unit.php`）
