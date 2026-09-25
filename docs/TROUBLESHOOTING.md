# 排错手册

下面每一条都是部署本项目时实际踩过的坑，按「症状 → 判断方法 → 处理」写。

---

## 1. 通话一直停在「请稍候」/「Call connection failed」

**先看信令有没有连上**：

```bash
journalctl -u peerjs -f        # 通话时应该看到 connected id=...
curl -s https://<域名>/peerjs/id   # 必须返回 {"id":"..."}
```

* 没有 `connected` 日志 → 浏览器根本没连到信令。检查反代（Apache 需要 `mod_proxy_wstunnel`；Nginx 需要
  `Upgrade`/`Connection` 两个头）、`/peerjs` 是否被其它规则吃掉、CDN 是否把 WebSocket 关了（Cloudflare 默认支持）。
* 有 `connected` 但还是连不上 → 多半是 **ICE 没拿到**：打开浏览器控制台看 `[P2P]` 日志有没有 `TURN 已就绪`；
  再检查 `xhr/p2p_turn.php` 是否 500（TURN 密钥文件权限、`www` 是否可读）。

## 2. 弱网/部分用户连不上，直连有时又能通

对称 NAT / 企业网 / 部分移动网络必须走 TURN 中继。按顺序查：

1. `systemctl is-active coturn`；
2. 云安全组与系统防火墙是否放行 **UDP 3478** 与 **UDP 50000-50100**；
3. **TURN 密钥是否一致**（最常见的坑）：

   ```bash
   su -s /bin/sh www -c 'test -r /etc/turn-rest-secret && echo OK'   # 必须 OK
   ```

   站点读到的值必须与 coturn 的 `static-auth-secret` 完全相同，否则 coturn 拒绝临时账号。
   注意 `turnserver.conf` 通常是 `640 root:coturn`，Web 用户读不到，所以要单独放 `/etc/turn-rest-secret`。
4. 看中继端口有没有流量：`ss -unap | grep -cE ':(500[0-9][0-9]|50100)'`（直连成功时为 0 是正常的）。

## 3. `0.peerjs.com` 与 `stun.l.google.com`

公共云信令与 Google STUN 在中国大陆网络下经常不可达/极慢，**不要用**：
信令要用自建服务（本项目的做法），STUN/TURN 用自建 coturn（一颗 coturn 同时干这两件事）。

## 4. 页面上没有通话按钮

`themes/Sean/layout/chat/chat-tab.phtml` 与 `messages/content.phtml` 的按钮条件是
`audio_chat/video_chat == 1` **且** 三种通道至少开一个。打完补丁后条件里要包含 `p2p_chat_video`；
开关没生效时先确认 `Wo_Config.p2p_chat_video = 1`（Memcached 缓存最长 5 分钟）。

## 5. 通话记录行每 5 秒重复一条

原因是系统行的外层缺少 `messages-wrapper` + `data-message-id`：聊天页用
`$('.messages-container').find('.messages-wrapper:last').attr('data-message-id')` 追踪最后一条消息 id，
拿不到就认为「没有新消息被消费」，于是每次轮询都重新追加。补丁里的渲染分支已包含这两个属性，手工合入时别漏。

## 6. 通话记录没写进聊天

* **未接通不写**（设计如此）：计时未开始 → 不上报；拒接、无人接听、连接失败都不会有记录。
* 上报用 `$.get` 且**先上报再跳转**（最多等 1.5 秒）。`requests.php` 强制要求
  `X-Requested-With: XMLHttpRequest`，所以不能用 `navigator.sendBeacon`；如果用户直接关掉标签页，
  可能来不及上报，但**对端页面也会上报同一次通话**（服务端按房间号去重），记录不会丢。
* 检查 `xhr/call_log.php` 是否存在、`xhr/` 目录是否可读。

## 7. Node 版本问题（CentOS 7 等老系统）

`glibc < 2.28` 的机器装不了 Node ≥ 18（官方二进制需要新 glibc）。
CentOS 7 请用 Node 16；若报 `GLIBC_2.28 not found`，就是版本选错了。

## 8. Apache 反代写对了却 503

`retry=0` 让后端重启时立刻恢复；如果持续 503，看 `error_log` 里是否有
`proxy: error reading status line from remote server` / `AH01114`（后端没起）或
`AH01144`（协议不对：`/peerjs/id` 必须用 `http://` 转发，WebSocket 用 `ws://`）。

## 9. 面板/宝塔环境注意

* 反代配置要放在面板**不会被重写覆盖**的位置：Apache 是
  `/www/server/panel/vhost/apache/extension/<域名>/`；Nginx 用面板的「配置文件」编辑器写入站点 `server { }`。
* 重载 Apache 用 `/www/server/apache/bin/apachectl -k graceful`（部分面板环境下 `systemctl reload httpd` 不适用）。
* 面板「Node 版本管理器」装 Node 时，注意信令服务的 `ExecStart` 要指向实际二进制路径。
