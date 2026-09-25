# wowonder-p2p-call

给 **WowWonder**（PHP 社区站程序）+ **Sean 主题** 增加「自建信令 + 自建中继」的语音/视频通话：
不依赖 Agora / Twilio / 公共云信令，浏览器之间 **P2P 直连**，打不通才走**自建 coturn 中继**；
通话真正接通后显示时长，挂断后把「通话已结束 01:21」写进聊天记录。

```
浏览器 A ─┐                                  ┌─ GET /peerjs/id
          ├─ wss://<域名>/peerjs ─► Web服务器 ─► 127.0.0.1:9000  PeerJS 信令（本项目自带，只转发 SDP/ICE）
浏览器 B ─┘                                   └─ 连接/断开都有日志

媒体：A ◄══ P2P 直连（优先）══► B      或      A ◄══ TURN 中继（3478 + 50000-50100/udp）══► coturn
```

## 特性

* **不依赖第三方**：信令服务自建（Node + PeerJS Server），STUN/TURN 自建（coturn），无需付费账号、无需 API Key；
* **能穿就直连**：STUN 拿公网映射，P2P 直连优先；对称 NAT/企业网自动回落 TURN 中继；
* **不暴露源站 IP**：ICE 列表由服务端动态下发（`xhr/p2p_turn.php` 自动识别源站公网 IP），
  TURN 用 10 分钟有效的临时账号（共享密钥 HMAC），配置里不存长期密码；
* **通话时长**：媒体真正接通才开始计时（`MM:SS`，满 1 小时 `HH:MM:SS`）；
* **通话记录**：挂断后聊天里出现居中的「通话已结束 01:21」，双方上报、服务端按房间号去重，一通电话只落一条；
* **挂断可靠**：额外用 WebRTC 数据通道发 `bye`，对端立即知道；媒体连接异常（`disconnected/failed`）也有兜底；
* **不新增语言键**：文案复用主程序语言库（`call_ended` 等）。

## 目录结构

```
src/            三个全新文件（直接拷进站点即可，不覆盖任何东西）
  xhr/p2p_turn.php                            ICE( STUN/TURN ) 下发 + TURN 临时账号签发
  xhr/call_log.php                            通话记录写入（按房间号去重）
  themes/Sean/layout/video/p2p.phtml          视频通话页（PeerJS 实现）
patches/        对主程序/主题既有文件的 10 处增量补丁（unified diff，含基线 md5）
css/            追加到主题样式表的通话样式
server/         服务端：信令服务（Node）、systemd 单元、Apache/Nginx 反代、coturn 配置示例、安装脚本
sql/            Wo_Config 开关
tools/          apply-patches.sh 一键打补丁（带基线校验与干跑）
docs/           安装、架构、配置、排错
```

## 快速开始

```bash
# 0) 备份站点（务必）
# 1) 站点侧：拷 3 个新文件 + 追加样式 + 打补丁
cp -a src/xhr/*.php                <站点根>/xhr/
cp -a src/themes/Sean/layout/video/p2p.phtml <站点根>/themes/Sean/layout/video/
cat css/p2p-call.css >>            <站点根>/themes/Sean/stylesheet/style.css
bash tools/apply-patches.sh <站点根>            # 先干跑
bash tools/apply-patches.sh <站点根> --apply    # 再应用

# 2) 数据库开关
mysql -u<用户> -p <库> < sql/wo_config.sql

# 3) 信令服务（默认装到 /opt/peerjs）
sudo APP_DIR=/opt/peerjs RUN_USER=www NODE_BIN=/usr/bin/node bash server/install.sh

# 4) 反代：Apache 见 server/apache/、Nginx 见 server/nginx/，然后重载
# 5) coturn：见 server/coturn/turnserver.conf.example（注意 TURN 密钥要三处一致）
```

完整步骤、验收清单与手工合入锚点：[`docs/INSTALL.md`](docs/INSTALL.md)

## 依赖

| 组件 | 版本 | 说明 |
|---|---|---|
| WowWonder 主程序 | 与本补丁基线一致（见 `patches/known-baselines.tsv`） | 补丁按基线 md5 校验，不匹配请手工合入 |
| 主题 | Sean（姊妹主题 Seanq 的同名模板结构一致） | 其它主题需自行照搬模板改法 |
| Node.js | ≥ 14（推荐 16/18/20 LTS） | 信令服务；glibc < 2.28 的机器（如 CentOS 7）只能用 Node 16 |
| npm 包 `peer` | `^1.0.2` | 信令服务端 |
| coturn | 4.x | STUN + TURN 中继（CentOS 7 需 EPEL） |
| Web 服务器 | Apache（`mod_proxy` + `mod_proxy_wstunnel`）/ Nginx | WebSocket 反代 |

实测环境：CentOS 7 + Apache 2.4 + PHP 7.4 + MySQL 5.7 + Node 16.20.2 + coturn 4.6.2，
站点在 Cloudflare 之后（信令走 CDN，TURN 走源站 IP）。

## 验证状态（诚实说明）

* 补丁：10 个补丁在基线文件上**全部干净应用**，应用结果与项目目标版本逐字节一致；
  其中 7 个与**生产站点**文件 md5 完全相同，`talking.phtml` 的唯一差异是把源站 IP 改成配置项，
  `chat-tab.phtml` / `script.js` 的差异只有主题里与本功能无关的其它改动。
* 功能：已在生产站点完成**双端真实通话验证**（声音/画面正常）、通话时长与通话记录验证。
* 未做：与其它主题、其它主程序版本的兼容性验证；自动化测试。

## 许可与重要提示

* 本项目（`src/`、`patches/`、`css/`、`server/`、`sql/`、`tools/`、`docs/` 中的原创内容）以 **MIT** 许可发布，见 [`LICENSE`](LICENSE)。
* **本仓库不包含 WowWonder 主程序与 Sean 主题的任何原始文件**，只提供补丁（增量差异）与原创文件。
  使用前请自行取得这些第三方软件的合法授权；补丁的分发与使用方式请遵守其许可条款。详见 [`NOTICE.md`](NOTICE.md)。
* 仓库内**没有任何密钥**：coturn 的 `static-auth-secret`、TURN 密钥文件均为占位符（安装时自行生成）。

## 文档

* [`docs/INSTALL.md`](docs/INSTALL.md) —— 安装、验收、回滚、手工合入
* [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) —— 通话时序、去重设计、安全设计
* [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md) —— 开关、端口、密钥、改名
* [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) —— 9 类常见问题（都是实际踩过的坑）
