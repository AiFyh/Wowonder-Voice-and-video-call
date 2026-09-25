#!/bin/bash
# ============================================================================
# 自建 PeerJS 信令服务 —— 安装脚本
#
# 做的事：落文件 → 装依赖 → 写 systemd 单元 → 起服务 → 自检
# 不做的事：不动 Web 服务器配置、不动 coturn、不动站点代码（那些见 docs/INSTALL.md）
#
# 用法：
#   sudo bash server/install.sh                                  # 全默认
#   sudo APP_DIR=/opt/peerjs RUN_USER=www NODE_BIN=/usr/bin/node bash server/install.sh
#
# 参数（都可用环境变量覆盖）：
#   APP_DIR   默认 /opt/peerjs        信令服务安装目录
#   RUN_USER  默认 www                运行用户（Debian/Ubuntu 常为 www-data）
#   NODE_BIN  默认 node               Node 可执行文件路径
#   PORT      默认 9000               监听端口（只监听 127.0.0.1）
# ============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/peerjs}"
RUN_USER="${RUN_USER:-www}"
NODE_BIN="${NODE_BIN:-node}"
PORT="${PORT:-9000}"
HERE="$(cd "$(dirname "$0")" && pwd)"
UNIT=/etc/systemd/system/peerjs.service

echo "== [1/5] 检查环境 =="
if ! command -v "$NODE_BIN" >/dev/null 2>&1 && [ ! -x "$NODE_BIN" ]; then
    echo "✗ 找不到 Node：$NODE_BIN"; echo "  先安装 Node（建议 16/18/20 LTS），或用 NODE_BIN=/path/to/node 指定"; exit 1
fi
echo "  Node: $("$NODE_BIN" -v  2>/dev/null || echo '?')   版本低于 14 请先升级"
id "$RUN_USER" >/dev/null 2>&1 || { echo "✗ 用户 $RUN_USER 不存在"; exit 1; }

echo "== [2/5] 落地文件到 $APP_DIR =="
mkdir -p "$APP_DIR"
cp -f "$HERE/peerjs/server.js" "$HERE/peerjs/package.json" "$APP_DIR/"
if [ -f "$HERE/peerjs/package-lock.json" ]; then cp -f "$HERE/peerjs/package-lock.json" "$APP_DIR/"; fi
if command -v npm >/dev/null 2>&1; then
    ( cd "$APP_DIR" && npm install --omit=dev --no-audit --no-fund >/dev/null 2>&1 ) && echo "  依赖已安装（peer）" || echo "  ⚠ npm 安装失败，请手动在 $APP_DIR 执行 npm install"
else
    echo "  ⚠ 未找到 npm，请手动在 $APP_DIR 执行 npm install（依赖 peer@^1.0.2）"
fi

echo "== [3/5] 生成 systemd 单元 $UNIT =="
if [ -f "$UNIT" ]; then
    cp -a "$UNIT" "${UNIT}.bak_$(date +%Y%m%d_%H%M%S)"
    echo "  已备份原单元"
fi
sed -e "s|@APP_DIR@|$APP_DIR|g" \
    -e "s|@RUN_USER@|$RUN_USER|g" \
    -e "s|@NODE_BIN@|$NODE_BIN|g" \
    "$HERE/systemd/peerjs.service.in" > "$UNIT"
chmod 644 "$UNIT"

echo "== [4/5] 启动 =="
systemctl daemon-reload
systemctl enable peerjs >/dev/null
systemctl restart peerjs
sleep 2
systemctl is-active peerjs

echo "== [5/5] 自检 =="
if curl -s --max-time 5 "http://127.0.0.1:${PORT}/peerjs/id" | grep -q '"id"'; then
    echo "  ✔ 信令服务应答正常"
else
    echo "  ✗ 无应答，看日志：journalctl -u peerjs -n 50 --no-pager"; exit 1
fi

cat <<'NEXT'

------------------------------------------------------------
接下来（详细步骤见 docs/INSTALL.md）：
  1) 反向代理：Apache → server/apache/peerjs-ws.conf.in，Nginx → server/nginx/peerjs-ws.conf.in
     验证：curl -s https://<你的域名>/peerjs/id  应返回 {"id":"..."}
  2) 防火墙/安全组放行：TCP 443（站点）、UDP 3478、UDP 50000-50100（中继）
  3) coturn：见 server/coturn/turnserver.conf.example
  4) 站点侧：拷 src/ 里的文件、打 patches/ 里的补丁、跑 sql/wo_config.sql
------------------------------------------------------------
NEXT
