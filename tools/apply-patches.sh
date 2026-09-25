#!/bin/bash
# ============================================================================
# 一键打补丁：把 patches/*.patch 应用到站点根目录
#
#   bash tools/apply-patches.sh /path/to/site          # 先干跑（默认，不改文件）
#   bash tools/apply-patches.sh /path/to/site --apply  # 真正应用
#
# 行为：
#   · 先用 patches/known-baselines.tsv 里的 md5 校验目标文件是不是我们制作补丁时的基线版本；
#     md5 不符会跳过该文件并提示「手工合入」（避免把补丁打到不兼容的版本上）。
#   · 每个补丁应用后都会报告结果；失败的文件会留下 .rej，按 docs/INSTALL.md 手工合入。
# ============================================================================
set -uo pipefail

SITE="${1:-}"
MODE="${2:-dryrun}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
TSV="$HERE/patches/known-baselines.tsv"

if [ -z "$SITE" ] || [ ! -d "$SITE" ]; then
    echo "用法: bash tools/apply-patches.sh <站点根目录> [--apply]"; exit 1
fi
if [ "$MODE" != "--apply" ]; then
    echo "== 干跑模式（不改动任何文件；加 --apply 真正应用）=="
fi

ok=0; skip=0; fail=0
while IFS=$'\t' read -r rel base_md5 patchfile; do
    [ -z "$rel" ] && continue
    target="$SITE/$rel"
    if [ ! -f "$target" ]; then
        echo "跳过   $rel  ← 文件不存在"; skip=$((skip+1)); continue
    fi
    now_md5=$(md5sum "$target" | cut -d' ' -f1)
    if [ "$now_md5" != "$base_md5" ]; then
        echo "跳过   $rel  ← 版本不匹配"
        echo "        期望基线 md5: $base_md5"
        echo "        当前文件 md5: $now_md5"
        echo "        请按 docs/INSTALL.md 的「手工合入」一节处理"
        skip=$((skip+1)); continue
    fi
    if [ "$MODE" = "--apply" ]; then
        if ( cd "$SITE" && patch -p1 --batch --forward < "$HERE/patches/$patchfile" ) >/dev/null 2>&1; then
            echo "已应用 $rel"; ok=$((ok+1))
        else
            echo "失败   $rel  ← 见 $rel.rej，按 docs/INSTALL.md 手工合入"; fail=$((fail+1))
        fi
    else
        if ( cd "$SITE" && patch -p1 --dry-run --batch --forward < "$HERE/patches/$patchfile" ) >/dev/null 2>&1; then
            echo "可应用 $rel"; ok=$((ok+1))
        else
            echo "会失败 $rel"; fail=$((fail+1))
        fi
    fi
done < "$TSV"

echo
echo "结果：可应用/已应用 $ok 个，跳过 $skip 个，失败 $fail 个"
echo "补丁只覆盖上面这些文件；src/ 里的三个原创文件与 css/p2p-call.css 需要手工拷贝/追加（见 docs/INSTALL.md）"
