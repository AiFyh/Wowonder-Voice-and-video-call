# Changelog

本项目遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/) 风格，版本号遵循语义化版本。

## [1.0.0] - 2026-09-25

首个发布版本，内容抽取自在生产站点（WowWonder + Sean 主题，CentOS 7 / Apache / PHP 7.4）
上完成并跑通的实现。

### Added

* **自建 PeerJS 信令服务**（`server/peerjs/`）：只监听 `127.0.0.1:9000`，
  `proxied` + `allow_discovery=false`，连接/断开都打日志；随附 systemd 单元、Apache 与 Nginx 反代配置、一键安装脚本。
* **自建 coturn STUN/TURN**（`server/coturn/turnserver.conf.example`）：一颗服务同时提供 STUN 与 TURN，
  中继端口 50000-50100，临时账号（共享密钥 HMAC）。
* **ICE 动态下发**（`src/xhr/p2p_turn.php`）：自动识别源站公网 IP（`/etc/turn-public-ip` 可选覆盖 + 6 小时缓存 + 失败退避），
  现场签发 10 分钟有效的 TURN 临时账号；不再使用大陆不可达的 Google STUN。
* **视频通话页**（`src/themes/Sean/layout/video/p2p.phtml`）：主叫占房 / 被叫拨入（`peer-unavailable` 自动重试 20 次）、
  数据通道 `bye` 通知挂断、ICE 状态兜底、码率上限与保帧率自适应。
* **语音通话弹窗**（`patches/0005`，`themes/Sean/layout/modals/talking.phtml`）：同一套 P2P 逻辑，
  处理了「挂断按钮被 disabled 后 click 不触发」的问题（用 `pointerdown/mousedown` 兜底）。
* **通话时长显示**：媒体真正接通起计时（`MM:SS`，满 1 小时 `HH:MM:SS`），视频页显示在画面左下角、语音弹窗内显示在头像下方。
* **通话记录写进聊天**（`src/xhr/call_log.php` + `patches/0006/0007`）：挂断后写一条 `type_two=call_log` 的消息，
  双方上报、服务端用房间号 + MySQL 命名锁去重；消息页与聊天小窗渲染为居中系统行。
* **后台开关**（`patches/0001`）：视频设置页增加「自建 P2P 通话」卡片，并与 Agora / Twilio 互斥。
* **文档**：安装（含手工合入锚点与回滚）、架构（时序与去重设计）、配置（开关/端口/密钥/改名）、排错（9 类实际问题）。

### Notes

* 不新增任何语言键：通话文案全部复用主程序语言库（`call_ended`、`calling_desc`、`media_access_failed` 等）。
* 补丁按基线 md5 校验（`patches/known-baselines.tsv`）：10 个补丁在基线上全部干净应用，
  应用结果与目标版本逐字节一致；其中 6 个与制作补丁时的生产文件 md5 相同。
* 与生产版本的两处有意差异（都是为了开源发布）：
  1. 兜底 STUN 由写死的源站公网 IP 改为配置项 `p2p_stun_fallback`（不公开源站 IP）；
  2. 后台「自建 P2P 通话」说明文案里的站点域名改成通用说法（`UDP 3478 / 自建 coturn`）。
* 代码里保留的站点专属标识（纯标识、无敏感信息）：房间号前缀 `seosq-`、TURN 用户名后缀 `:seosq`、
  缓存/UA/锁名前缀 —— 改名方法见 `docs/CONFIGURATION.md` 第 5 节。
