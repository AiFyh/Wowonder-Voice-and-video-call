/**
 * 自建 PeerJS 信令服务（WebRTC 音视频通话的信令通道）
 *
 * 作用：只转发 SDP / ICE 信令，不中转媒体。媒体走浏览器之间的 P2P 直连，
 *       直连打不通时回落自建 coturn(TURN) 中继。
 *
 * 为什么自建：公共云信令 0.peerjs.com 在中国大陆网络下经常很慢甚至连不上，
 *       表现就是通话一直停在「请稍候」。自建后信令在同一台机器上，握手延迟从秒级降到毫秒级。
 *
 * 部署形态（推荐）：
 *   - 本服务只监听 127.0.0.1:9000，不对公网开放，也不需要单独申请证书；
 *   - 由站点的 Web 服务器反代：wss://<站点域名>/peerjs → ws://127.0.0.1:9000/peerjs
 *     （Apache 见 server/apache/peerjs-ws.conf.in，Nginx 见 server/nginx/peerjs-ws.conf.in）
 *   - 前端 new Peer(...) 用 host = location.hostname、path = '/'、key = 'peerjs'
 *
 * 环境变量（都有默认值，一般不用设）：
 *   PEERJS_PORT  默认 9000
 *   PEERJS_HOST  默认 127.0.0.1（不要改成 0.0.0.0，除非你确实要直接暴露）
 *   PEERJS_PATH  默认 '/'（与前端 path 保持一致；客户端实际请求 /peerjs）
 *   PEERJS_KEY   默认 'peerjs'
 *
 * 日志：客户端每次连上/断开都会打印一行（带 peer id），排查「浏览器到底有没有连到信令」时看这个。
 */
const { PeerServer } = require('peer');

const PORT = parseInt(process.env.PEERJS_PORT || '9000', 10);
const HOST = process.env.PEERJS_HOST || '127.0.0.1';
const PATH = process.env.PEERJS_PATH || '/';
const KEY = process.env.PEERJS_KEY || 'peerjs';

function ts() {
  return new Date().toISOString().replace('T', ' ').slice(0, 19);
}

const peerServer = PeerServer({
  port: PORT,
  host: HOST,
  path: PATH,
  key: KEY,
  proxied: true,           // 位于反向代理之后，信任 X-Forwarded-For
  allow_discovery: false,  // 禁止列举在线 id，避免被扫描/滥用
  alive_timeout: 60000,    // 客户端心跳超时(ms)
});

peerServer.on('connection', function (client) {
  console.log('[' + ts() + '] connected    id=' + client.getId());
});
peerServer.on('disconnect', function (client) {
  console.log('[' + ts() + '] disconnected id=' + client.getId());
});
peerServer.on('error', function (err) {
  console.log('[' + ts() + '] error: ' + (err && err.message ? err.message : err));
});

console.log('[' + ts() + '] peerjs signaling listening on ' + HOST + ':' + PORT + ' path=' + PATH + ' key=' + KEY);
