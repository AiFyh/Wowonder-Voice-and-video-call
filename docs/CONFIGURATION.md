# 配置项

## 1. 数据库开关（`Wo_Config`）

| 键 | 值 | 作用 |
|---|---|---|
| `p2p_chat_video` | `1` | 启用自建 P2P 通道 |
| `agora_chat_video` | `0` | 关闭 Agora（必须关，否则 `sources/video.php` 优先走 Agora 分支） |
| `twilio_video_chat` | `0` | 关闭 Twilio |
| `video_chat` | `1` | 允许视频通话 |
| `audio_chat` | `1` | 允许语音通话 |
| `p2p_stun_fallback` | 可选，如 `stun:turn.example.com:3478` | JS 里 ICE 的兜底 STUN；留空则完全依赖 `xhr/p2p_turn.php` 下发的动态列表 |

后台入口：**管理后台 → 视频设置 → 自建 P2P 通话**（补丁 0001 增加的开关卡片，启用时会自动关掉 Agora/Twilio）。

> 站点若启用 Memcached 配置缓存（`Wo_GetConfig` 缓存 TTL 300 秒），直接改库最多 5 分钟生效；
> 走后台开关或清缓存则即时生效。

## 2. TURN 密钥与地址

`xhr/p2p_turn.php` 的两个函数：

* `wo_turn_secret()` —— 按顺序读：`/etc/turn-rest-secret` → `/etc/coturn/turn-rest-secret` → `/etc/coturn/turnserver.conf` 的 `static-auth-secret`；
  都读不到就返回空串，此时前端退化为**只用 STUN 直连**（对称 NAT 下会连不上）。
* `wo_turn_public_ip()` —— 按顺序取：`/etc/turn-public-ip`（可选，多公网 IP 时显式指定）→ `/tmp/seosq-turn-public-ip` 缓存（6 小时）→ 自动探测
  （先用 STUN Binding 取出口 IP，失败再用 HTTP 回显服务），探测失败 10 分钟内不再重试，避免拖慢通话建立。

**密钥必须三处一致**：站点读到的那个值 = coturn 的 `static-auth-secret`。
coturn 配置文件通常是 `640 root:coturn`，Web 用户读不到，所以**推荐单独放 `/etc/turn-rest-secret`（`640 root:<web用户>`）**。

## 3. 网络与端口

| 端口 | 协议 | 用途 | 要点 |
|---|---|---|---|
| 3478 | UDP | STUN + TURN | 云安全组 + 系统防火墙都要放行；**UDP 不能走 CDN 代理** |
| 50000-50100 | UDP | TURN 中继 | 与 coturn 的 `min-port` / `max-port` 一致 |
| 443 | TCP | 站点 + WSS 信令 | 可以走 Cloudflare 等 CDN |
| 9000 | TCP | 信令服务 | 只绑 127.0.0.1 |

> CDN 场景（如 Cloudflare 橙云）：信令 WSS 走 CDN 没问题；但 TURN 必须用**源站真实公网 IP**，
> 这也是 `wo_turn_public_ip()` 自动探测 IP、而不是直接用站点域名的原因。

## 4. 信令服务参数

`server/peerjs/server.js` 支持环境变量（都有默认值）：

| 变量 | 默认 | 说明 |
|---|---|---|
| `PEERJS_PORT` | `9000` | 监听端口 |
| `PEERJS_HOST` | `127.0.0.1` | **不要改成 0.0.0.0**，否则信令直接暴露公网 |
| `PEERJS_PATH` | `/` | 要与前端 `path` 一致；客户端实际请求 `/peerjs` |
| `PEERJS_KEY` | `peerjs` | 与前端 `key` 一致 |

前端写死的 `path: '/'`、`key: 'peerjs'` 在 `themes/Sean/layout/video/p2p.phtml` 与 `modals/talking.phtml` 的 `makePeer()` 里。

## 5. 可自定义的标识（改名/换品牌）

| 位置 | 现值 | 说明 |
|---|---|---|
| `xhr/create_new_video_call.php` / `create_new_audio_call.php` | 房间号前缀 `seosq-` | 纯标识，可换成任意前缀（长度注意别超过 `Wo_VideoCalles.room_name` 字段长度） |
| `xhr/p2p_turn.php` | TURN 用户名后缀 `:seosq`、缓存文件 `/tmp/seosq-turn-public-ip`、User-Agent `seosq-turn-check` | 纯标识 |
| `xhr/call_log.php` | 锁名前缀 `seosq_calllog_`、消息标记 `calllog_` | 纯标识（`calllog_` 会写进 `Wo_Messages.notification_id`，改前缀只影响去重键） |
| 页面上的通话文案 | 语言键 `call_ended`、`calling_desc`、`media_access_failed`、`call_already_active`、`call_component_failed` 等 | 都在主程序语言库里，按需翻译即可；本项目**没有新增语言键** |

一键改名（在站点根目录执行，先备份）：

```bash
sed -i "s/'seosq-'/'myapp-'/" xhr/create_new_video_call.php xhr/create_new_audio_call.php
sed -i "s/':seosq'/':myapp'/; s|seosq-turn-public-ip|myapp-turn-public-ip|; s|seosq-turn-check|myapp-turn-check|" xhr/p2p_turn.php
sed -i "s/seosq_calllog_/myapp_calllog_/" xhr/call_log.php
```
