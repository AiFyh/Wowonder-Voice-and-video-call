# 安装指南

前置：已安装 WowWonder 主程序（含 Sean 主题），并有服务器 root/sudo 权限。
本补丁**不改动** `requests.php`（它按 `xhr/<f>.php` 自动加载）、不改动数据库表结构。

---

## 0. 需要准备的依赖

| 组件 | 版本要求 | 说明 |
|---|---|---|
| Node.js | ≥ 14（推荐 16 / 18 / 20 LTS） | 只用来跑信令服务。⚠ CentOS 7 / glibc 2.17 装不了 Node ≥ 18 |
| npm 包 `peer` | `^1.0.2` | 信令服务端，`npm install` 自动装 |
| coturn | 4.x（CentOS 7 需 EPEL） | STUN + TURN 中继 |
| Web 服务器模块 | Apache: `mod_proxy` + **`mod_proxy_wstunnel`**；Nginx: 内置 | WebSocket 反代，缺了会 400/502 |
| PHP | 主程序要求即可（本站 7.4 实测） | 用到 `hash_hmac` / `base64_encode` / `stream_socket_client`(UDP)，`disable_functions` 不能禁这些 |
| 数据库 | MySQL 5.7 / MariaDB 10.x | 只写 `Wo_Config` 开关，不改表结构 |

**端口**

| 端口 | 协议 | 用途 | 是否可走 CDN/反代 |
|---|---|---|---|
| 443 | TCP | 站点 + WSS 信令 | 可以（Cloudflare 等 CDN 代理没问题） |
| 3478 | UDP | STUN / TURN | **不行，必须直连源站 IP** |
| 50000-50100 | UDP | TURN 中继端口 | 不行，同上 |
| 9000 | TCP | 信令服务 | **只绑 127.0.0.1，不要对公网开放** |

---

## 1. 站点侧

### 1.1 拷贝原创文件（3 个，全新文件，不会覆盖任何东西）

```
src/xhr/p2p_turn.php                              → <站点根>/xhr/p2p_turn.php
src/xhr/call_log.php                              → <站点根>/xhr/call_log.php
src/themes/Sean/layout/video/p2p.phtml            → <站点根>/themes/Sean/layout/video/p2p.phtml
```

### 1.2 追加样式

把 `css/p2p-call.css` 的内容追加到主题样式表末尾：

```bash
cat css/p2p-call.css >> <站点根>/themes/Sean/stylesheet/style.css
```

> 主题样式表带 `?v=filemtime` 版本号，改完即生效，不需要手动清 CDN 缓存。

### 1.3 打补丁（10 处对既有文件的增量修改）

```bash
bash tools/apply-patches.sh /path/to/site          # 干跑
bash tools/apply-patches.sh /path/to/site --apply  # 应用
```

补丁清单、基线 md5 与手工合入锚点见 `patches/README.md`。

### 也可手工合入（补丁打不上时）

| 文件 | 怎么改 |
|---|---|
| `xhr/create_new_video_call.php` | 在 `if ($wo['config']['agora_chat_video'] == 1) {` **前面**插入补丁里 `+` 开头的那段 `if ($wo['config']['p2p_chat_video'] == 1) { … } elseif` |
| `xhr/create_new_audio_call.php` | 同上（语音版） |
| `themes/Sean/layout/video/content.phtml` | ① 把 `if ($wo['config']['agora_chat_video'] == 1) {` 改成 `if ($wo['config']['p2p_chat_video'] == 1) { …p2p 容器… } elseif ($wo['config']['agora_chat_video'] == 1) {`；② 在 `</div>`+`<button class="btn btn-danger btn-mat end_vdo_call hidden">` 之前加 `<div id="p2p-call-duration" class="p2p_call_duration hidden">00:00</div>`；③ 底部 `Wo_LoadPage('video/p2p')` 分支同理 |
| `themes/Sean/layout/modals/talking.phtml` | 把 `<?php if ($wo['config']['agora_chat_video'] == 1) { ?>` 改为 `<?php if ($wo['config']['p2p_chat_video'] == 1) { ?>` 并插入补丁中的 P2P 脚本块（`elseif` 保留原 Agora 分支） |
| `chat-tab.phtml` / `messages/content.phtml` | 把语音/视频按钮的条件加上 `|| $wo['config']['p2p_chat_video'] == 1` |
| `messages-text-list.phtml` / `chat-list.phtml` | 在文件**开头**加补丁里那段 `type_two == 'call_log'` 渲染分支（后接 `return;`） |
| `script.js` | 把发起通话的两处 AJAX 加上 `hash_id: $('.main_session').val()` |
| `admin-panel/.../video-settings/content.phtml` | 加「自建 P2P 通话」卡片 + 互斥 JS（见补丁） |

---

## 2. 数据库开关

```bash
mysql -u<用户> -p <库名> < sql/wo_config.sql
```

跑完用 `SELECT name,value FROM Wo_Config WHERE name LIKE 'p2p%';` 确认 `p2p_chat_video=1`。
若站点启用 Memcached 配置缓存，最多 5 分钟生效（或直接去后台「视频设置」点一下开关，即时生效）。

---

## 3. 服务端

### 3.1 信令服务

```bash
sudo APP_DIR=/opt/peerjs RUN_USER=www NODE_BIN=/usr/bin/node bash server/install.sh
journalctl -u peerjs -f        # 每次浏览器连上/断开都会打一行（带 peer id）
```

### 3.2 反向代理

* **Apache**：把 `server/apache/peerjs-ws.conf.in` 放进该站点的 vhost 配置（宝塔面板的扩展挂载点：
  `/www/server/panel/vhost/apache/extension/<域名>/`），然后 `apachectl -k graceful`。
* **Nginx**：把 `server/nginx/peerjs-ws.conf.in` 里的两段放进站点 `server { }`，然后 `nginx -t && nginx -s reload`。

验证：

```bash
curl -s http://127.0.0.1:9000/peerjs/id      # 直连后端：{"id":"..."}
curl -s https://<你的域名>/peerjs/id          # 经反代：{"id":"..."}
```

### 3.3 coturn（STUN/TURN）

```bash
yum install -y epel-release && yum install -y coturn      # CentOS/RHEL
apt-get install -y coturn                                 # Debian/Ubuntu

SECRET=$(openssl rand -hex 32)
cp server/coturn/turnserver.conf.example /etc/coturn/turnserver.conf
# 按注释改：listening-ip / relay-ip（内网 IP）、external-ip（公网IP/内网IP）、realm、static-auth-secret
sed -i "s|<用 openssl rand -hex 32 生成>|$SECRET|" /etc/coturn/turnserver.conf

# 站点侧读取的密钥文件（必须与 static-auth-secret 完全一致）
printf '%s' "$SECRET" > /etc/turn-rest-secret
chown root:www /etc/turn-rest-secret && chmod 640 /etc/turn-rest-secret
su -s /bin/sh www -c 'test -r /etc/turn-rest-secret && echo OK'   # 必须输出 OK

systemctl enable --now coturn
```

站点侧读取顺序（`xhr/p2p_turn.php` 的 `wo_turn_secret()`）：
`/etc/turn-rest-secret` → `/etc/coturn/turn-rest-secret` → coturn 配置里的 `static-auth-secret`。
**站点读到的那个必须与 coturn 实际使用的一致**，否则临时账号会被拒绝（症状：直连能通，弱网/对称 NAT 打不通）。

---

## 4. 验收

1. `systemctl is-active peerjs coturn` → 都是 `active`；
2. `curl -s https://<域名>/peerjs/id` 返回 `{"id":"..."}`；
3. 两个账号互相呼叫：被叫弹出「视频来电」→ 接听后双方有画面/声音；接通后页面上出现通话时长（从 `00:00` 起）；
4. 挂断后到消息页（或等 5 秒轮询），会话里出现居中的「通话已结束 01:21」记录行；
5. 通话中在服务器上看中继是否有流量：`ss -unap | grep -cE ':(500[0-9][0-9]|50100)'`（直连成功时可能为 0，属正常）。

---

## 5. 卸载 / 回滚

| 范围 | 做法 |
|---|---|
| 站点侧 | 用补丁反向恢复 `patch -R`，或从备份还原；删除 `xhr/p2p_turn.php`、`xhr/call_log.php`、`themes/Sean/layout/video/p2p.phtml` |
| 开关 | `Wo_Config.p2p_chat_video` 改回 `0`，站点回到 Agora/Twilio 分支 |
| 信令 | `systemctl disable --now peerjs`，删单元文件与安装目录 |
| 反代 | 删对应 conf 段后重载 Web 服务器 |
| coturn | `systemctl disable --now coturn`（保留也不影响，只是不再被使用） |

> 已写入聊天记录的通话记录消息仍在 `Wo_Messages` 里，可按 `type_two='call_log'` 清理或保留（不影响功能）。
