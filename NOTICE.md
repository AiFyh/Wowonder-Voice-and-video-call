# NOTICE —— 第三方组件与许可边界

## 1. 本仓库包含什么、不包含什么

| 内容 | 是否包含 | 许可 |
|---|---|---|
| 本项目原创代码：`src/xhr/p2p_turn.php`、`src/xhr/call_log.php`、`src/themes/Sean/layout/video/p2p.phtml`、`css/`、`server/`（信令服务、systemd 单元、反代配置、coturn 示例、安装脚本）、`sql/`、`tools/`、`docs/`、`README*` | ✅ 包含 | MIT |
| 对既有文件的增量修改 | ✅ 以 **unified diff 补丁**形式提供（`patches/`），只含改动与必要的上下文行 | MIT（就本项目所写的改动部分） |
| WowWonder 主程序（PHP 源码、模板、资源文件） | ❌ **不包含** | 属第三方商业软件，遵循其自身许可（一般不允许再分发源码） |
| Sean / Seanq 主题（模板、样式、脚本） | ❌ **不包含**（`css/p2p-call.css` 只含本项目新增的样式块） | 同上 |
| 数据库导出、`config.php`、任何密钥/密码/证书 | ❌ 不包含 | —— |

**使用前请先取得上述第三方产品的合法授权。** 补丁的形式（差异 + 少量上下文）用于在**已授权安装**
的实例上做增量修改；请勿把它当作第三方源码的分发渠道。

## 2. 运行时依赖（由使用者自行安装，本仓库仅引用）

| 组件 | 许可 | 用途 |
|---|---|---|
| [PeerJS Server](https://github.com/peers/peerjs-server)（npm 包 `peer`，`^1.0.2`） | MIT | 信令服务端 |
| [PeerJS](https://github.com/peers/peerjs) 客户端（`peerjs.min.js`，随主题资源提供） | MIT | 浏览器端 WebRTC 封装 |
| [coturn](https://github.com/coturn/coturn)（4.x） | BSD-3-Clause | STUN / TURN 中继 |
| [Node.js](https://nodejs.org/) | MIT（含部分其它许可） | 运行信令服务 |

## 3. 密钥与隐私

* 仓库内**没有任何密钥明文**：coturn 的 `static-auth-secret` 与 `/etc/turn-rest-secret` 均为占位符
  （`<用 openssl rand -hex 32 生成>` / `<PLEASE-GENERATE-YOUR-OWN-SECRET>`）。
* 反代配置、coturn 示例里的 IP / 域名 / realm 都是占位符（`<内网IP>`、`<公网IP>`、`<你的域名>`），
  其中 `external-ip` 的正确写法是 `公网IP/内网IP`（云主机走 NAT 时必须写，否则中继候选地址不可达）。
* 生产部署时请勿把真实密钥、真实源站 IP 提交进公开仓库；
  若站点在 CDN（如 Cloudflare）之后，公开源站 IP 会削弱 CDN 的防护效果。
