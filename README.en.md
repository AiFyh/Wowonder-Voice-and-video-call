# wowonder-p2p-call

Self-hosted **WebRTC voice/video calling** for [WowWonder](https://wowonder.com/) (PHP social
network script) with the **Sean** theme — no Agora, no Twilio, no public signaling cloud.
Peer-to-peer media first, self-hosted **coturn** TURN relay as fallback, call duration on screen,
and a "Call ended 01:21" entry written into the chat history.

```
Browser A ─┐                                   ┌─ GET /peerjs/id
           ├─ wss://<domain>/peerjs ─► Web srv ─► 127.0.0.1:9000  PeerJS signaling (SDP/ICE only)
Browser B ─┘                                   └─ connection log lines for easy debugging

media:   A ◄══ P2P direct (preferred) ══► B      or    A ◄══ TURN relay (3478 + 50000-50100/udp) ══► coturn
```

## Highlights

* **No third-party dependency**: self-hosted PeerJS signaling (Node) + self-hosted coturn (STUN/TURN). No API key, no paid account.
* **Direct connection when possible**, automatic TURN relay fallback behind symmetric NAT / corporate networks.
* **Origin IP not hard-coded**: ICE servers are served dynamically by `xhr/p2p_turn.php`
  (auto-detects the origin public IP) and TURN uses 10-minute ephemeral credentials (HMAC shared secret).
* **Call timer** that starts when media is actually connected.
* **Call log in chat** (`Call ended 01:21`), de-duplicated server-side by room id.
* **Reliable hang-up** via a WebRTC data-channel `bye` plus ICE state fallbacks.
* **No new language keys** — reuses existing ones from the script's language library.

## Layout

```
src/      3 brand-new files (copy in, nothing is overwritten)
patches/  10 unified-diff patches against existing script/theme files (with baseline md5s)
css/      call styles to append to the theme stylesheet
server/   signaling service (Node), systemd unit, Apache/Nginx reverse proxy, coturn example, installer
sql/      Wo_Config switches
tools/    apply-patches.sh (dry-run + baseline check)
docs/     install / architecture / configuration / troubleshooting (Chinese)
```

## Quick start

```bash
cp -a src/xhr/*.php <site>/xhr/
cp -a src/themes/Sean/layout/video/p2p.phtml <site>/themes/Sean/layout/video/
cat css/p2p-call.css >> <site>/themes/Sean/stylesheet/style.css
bash tools/apply-patches.sh <site>            # dry run
bash tools/apply-patches.sh <site> --apply    # apply

mysql -u<user> -p <db> < sql/wo_config.sql

sudo APP_DIR=/opt/peerjs RUN_USER=www NODE_BIN=/usr/bin/node bash server/install.sh
# then set up the reverse proxy (server/apache or server/nginx) and coturn (server/coturn)
```

Full instructions (Chinese): [`docs/INSTALL.md`](docs/INSTALL.md).

## Requirements

WowWonder + Sean theme (patch baselines in `patches/known-baselines.tsv`), Node.js ≥ 14
(use 16 on glibc < 2.28 systems such as CentOS 7), npm package `peer@^1.0.2`, coturn 4.x,
Apache with `mod_proxy_wstunnel` or Nginx.

Verified on: CentOS 7, Apache 2.4, PHP 7.4, MySQL 5.7, Node 16.20.2, coturn 4.6.2, behind Cloudflare.

## License

MIT for everything in this repository — see [`LICENSE`](LICENSE).
**This repository contains none of WowWonder's or the Sean theme's original files**; it only ships
patches (diffs) and original files. Obtain the appropriate license for those products before use —
see [`NOTICE.md`](NOTICE.md).
