# 架构说明

```
浏览器 A ─┐                                   ┌─ GET /peerjs/id        取随机 peer id（HTTP）
          ├─ wss://<域名>/peerjs ─► Web服务器 ─► 127.0.0.1:9000  PeerJS 信令服务（本仓库 server/peerjs）
浏览器 B ─┘        （只走 SDP/ICE 信令）        └─ 连接/断开都打日志，便于定位"到底连没连上"

媒体：
   浏览器 A ◄══ P2P 直连（UDP，优先）══════════► 浏览器 B
       或   ◄══ TURN 中继（3478 + 50000-50100/udp）═► coturn（本仓库 server/coturn）
```

## 角色分工

| 组件 | 职责 | 说明 |
|---|---|---|
| `themes/Sean/javascript/peerjs.min.js` | 浏览器端 WebRTC 封装 | 随主题提供；`new Peer(id, {host: location.hostname, path: '/', key: 'peerjs'})` |
| 信令服务（Node） | 转发 SDP/ICE、分配 peer id | 只监听 127.0.0.1:9000，`allow_discovery=false`；媒体不经过它 |
| coturn | STUN + TURN | STUN 取公网映射，尽量直连；直连失败时中继媒体 |
| `xhr/p2p_turn.php` | 下发 ICE 列表 | 动态识别源站公网 IP + 现场签发 10 分钟 TURN 临时账号（HMAC-SHA1） |
| `xhr/create_new_*_call.php` | 建立"房间" | 生成 `seosq-`+10 位随机串作为 peer id，写 `Wo_VideoCalles` / `Wo_AudioCalles` |
| `themes/Sean/layout/video/p2p.phtml` | 视频通话页 | 主叫占房（`own()`）、被叫拨入（`join()`，失败重试 20 次） |
| `themes/Sean/layout/modals/talking.phtml` | 语音通话弹窗 | 同一套 P2P 逻辑 |
| `xhr/call_log.php` | 通话记录 | 挂断后由双方上报，按房间号去重写一条 `type_two=call_log` 消息 |

## 为什么不用公共云信令

公共云信令 `0.peerjs.com` 只做信令、不中转媒体；在中国大陆网络下它的握手经常要数秒甚至连不上，
表现就是"通话一直停在请稍候"。自建后信令握手在同一台机器上（毫秒级），且不受第三方可用性影响。
同理，`stun:stun.l.google.com` 在大陆不可达，ICE 里不要再写它 —— 本项目用自建 coturn 同时提供 STUN 与 TURN。

## 通话建立时序（视频）

1. 主叫点击通话按钮 → `xhr/create_new_video_call.php` 生成房间号并写 `Wo_VideoCalles` → 跳 `/video-call/<id>`；
   被叫侧通过后台轮询（`xhr/update_data.php`）收到来电弹窗，接听 → `xhr/answer_call.php` → 也进 `/video-call/<id>`；
2. 两端页面加载 `video/p2p.phtml`：先请求 `xhr/p2p_turn.php` 拿到 ICE 列表，再 `getUserMedia`；
3. 主叫用房间号占住 peer id（`own()`），被叫用 `peer.call(ROOM, stream)` 拨入（`join()`，`peer-unavailable` 时每 2 秒重试）；
4. 媒体通道建立（`c.on('stream')`）→ 开始显示通话时长（计时起点 = 真正接通，不是打开页面的时刻）；
5. 挂断：本端发数据通道 `bye` + 调 `xhr/cancel_call.php` 清房间；对端收到 `bye`（或媒体连接 `disconnected/failed`）后同样收尾；
   两端各自上报时长到 `xhr/call_log.php`，服务端按房间号去重。

## 通话记录（`type_two = call_log`）

* 只在**真正接通**（已开始计时）后上报，拒接/无人接听不写；
* 双方都会上报 → 服务端用 `notification_id = 'calllog_<房间号>'` + MySQL 命名锁
  （`GET_LOCK('seosq_calllog_'+md5(mark))`）串行化「查重 → 插入」，保证一通电话只落一条；
* 消息内容是人可读文本（语言键 `call_ended` + 服务端格式化的时长，`MM:SS`，满 1 小时 `HH:MM:SS`），
  所以任何渲染路径（消息页、聊天小窗、会话列表预览、App 接口）都能正常显示；
  消息页/小窗额外识别 `type_two` 把它渲染成居中的系统行；
* 渲染时**保留 `messages-wrapper` + `data-message-id`**：聊天页用
  `$('.messages-container').find('.messages-wrapper:last').attr('data-message-id')` 追踪「最后一条消息 id」，
  缺了它轮询会每 5 秒把这条记录重复追加一次（这是踩过的坑）。

## 安全设计

* 信令服务只监听 127.0.0.1，公网不可达；`allow_discovery=false` 禁止列举在线 id；
* TURN 用「共享密钥 + 时间戳用户名」的临时账号（10 分钟有效），配置里不存长期用户密码；
* 所有 AJAX 走主程序的 `requests.php`，受 `Wo_CheckMainSession($hash_id)` 会话校验；
* `xhr/call_log.php` 校验登录态、`to_id` 合法性、时长范围（1 秒 ~ 24 小时），并使用预处理式的转义；
* 房间号是随机串，且被叫必须已经与主叫建立一次通话记录才能进入 `/video-call/<id>`（`sources/video.php` 会校验 call_id 归属）。
