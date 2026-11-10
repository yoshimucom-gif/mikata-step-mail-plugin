#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""ミカタ ステップメール プラグインのビルド。

使い方:
    py build.py 1.0.1 "・○○を修正"

  1. mikata-step-mail/mikata-step-mail.php の Version ヘッダーと MSM_VER を書き換える
  2. update.json の version と changelog を書き換える
  3. mikata-step-mail.zip を作り直す（/区切り＝WPで正しく展開できる）

このあと `git add -A && git commit -m "v1.0.1" && git push` で、入れてある全サイトに更新が出る。
"""
import json
import re
import sys
import zipfile
from pathlib import Path

BASE = Path(__file__).parent
SLUG = "mikata-step-mail"
PLUGIN_DIR = BASE / SLUG
MAIN_PHP = PLUGIN_DIR / f"{SLUG}.php"
UPDATE_JSON = BASE / "update.json"
ZIP_PATH = BASE / f"{SLUG}.zip"

VERSION_RE = re.compile(r"^(\s*\*\s*Version:\s*)(\S+)", re.MULTILINE)
CONST_RE = re.compile(r"(define\('MSM_VER',\s*')([^']+)('\))")


def bump(version, changelog):
    php = MAIN_PHP.read_text(encoding="utf-8")
    if not VERSION_RE.search(php) or not CONST_RE.search(php):
        sys.exit("エラー: Version ヘッダーか MSM_VER 定数が見つかりません")
    php = VERSION_RE.sub(rf"\g<1>{version}", php, count=1)
    php = CONST_RE.sub(rf"\g<1>{version}\g<3>", php, count=1)
    MAIN_PHP.write_text(php, encoding="utf-8")
    meta = json.loads(UPDATE_JSON.read_text(encoding="utf-8"))
    meta["version"] = version
    if changelog:
        meta.setdefault("sections", {})["changelog"] = f"{version}\n{changelog}"
    UPDATE_JSON.write_text(json.dumps(meta, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"[version] {version}")


def build_zip():
    if ZIP_PATH.exists():
        ZIP_PATH.unlink()
    with zipfile.ZipFile(ZIP_PATH, "w", zipfile.ZIP_DEFLATED) as z:
        for f in sorted(p for p in PLUGIN_DIR.rglob("*") if p.is_file()):
            z.write(f, f"{SLUG}/" + f.relative_to(PLUGIN_DIR).as_posix())
    names = zipfile.ZipFile(ZIP_PATH).namelist()
    bad = [n for n in names if "\\" in n]
    if bad:
        sys.exit(f"エラー: zipに \\ 区切りが混ざっている {bad}")
    print(f"[zip] {ZIP_PATH.name} {ZIP_PATH.stat().st_size} bytes")
    for n in names:
        print("   ", n)


def main():
    if len(sys.argv) < 2 or not re.match(r"^\d+\.\d+\.\d+$", sys.argv[1]):
        sys.exit('使い方: py build.py <x.y.z> ["変更内容"]')
    bump(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else "")
    build_zip()
    print(f'\n次: git add -A && git commit -m "v{sys.argv[1]}" && git push')


if __name__ == "__main__":
    main()
