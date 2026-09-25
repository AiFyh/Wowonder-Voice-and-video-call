<?php 
if ($f == 'p2p_turn') {
    $data = array('status' => 200, 'servers' => array());
    if ($wo['config']['p2p_chat_video'] == 1) {
        // 源站公网 IP：不再写死，换服务器后自动重新识别
        $ip = wo_turn_public_ip();
        // STUN 与 TURN 同一个自建 coturn（3478/udp 同时应答 STUN Binding，取映射地址不需要账号密码）。
        // STUN 放在列表最前面：浏览器优先用它拿到自己的公网映射地址做 NAT 穿透，
        // 尽量走 P2P 直连、少占服务器中继带宽；原来那颗 stun:stun.l.google.com 在中国大陆不可达，
        // 等于完全没有 STUN，只剩下中继一条路。
        if ($ip !== '') {
            $data['servers'][] = array(
                'urls' => 'stun:' . $ip . ':3478'
            );
        }
        $secret = wo_turn_secret();
        if ($secret !== '') {
            $user = (time() + 600) . ':seosq';
            $pass = base64_encode(hash_hmac('sha1', $user, $secret, true));
            if ($ip !== '') {
                $data['servers'][] = array(
                    'urls' => 'turn:' . $ip . ':3478?transport=udp',
                    'username' => $user,
                    'credential' => $pass
                );
            }
            // 域名兜底：站点走 Cloudflare 代理时 UDP 3478 到不了源站，
            // 只有把该域名改成 DNS 直连源站时才有效，所以排在后面。
            $host = parse_url($wo['config']['site_url'], PHP_URL_HOST);
            if (!empty($host) && $host !== $ip) {
                $data['servers'][] = array(
                    'urls' => 'turn:' . $host . ':3478?transport=udp',
                    'username' => $user,
                    'credential' => $pass
                );
            }
        }
    }
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}

/**
 * TURN 密钥来源（按顺序取第一个可用的，任何情况下都不输出、不写日志）：
 *   1. /etc/turn-rest-secret              换机时按部署脚本放好即可（640 root:www）
 *   2. /etc/coturn/turn-rest-secret       备选路径
 *   3. coturn 自己的 static-auth-secret   只需 www 能读 turnserver.conf 就自动生效
 * 都不行时返回空串，前端会退化为纯 STUN(直连) 通话。
 */
function wo_turn_secret() {
    foreach (array('/etc/turn-rest-secret', '/etc/coturn/turn-rest-secret') as $file) {
        if (is_readable($file)) {
            $s = trim((string) @file_get_contents($file));
            if ($s !== '') {
                return $s;
            }
        }
    }
    $conf = '/etc/coturn/turnserver.conf';
    if (is_readable($conf)) {
        $txt = (string) @file_get_contents($conf);
        if (preg_match('/^[ \t]*static-auth-secret[ \t]*=[ \t]*([^\s#]+)/mi', $txt, $m)) {
            return trim($m[1], " \t\"'");
        }
    }
    return '';
}

/**
 * TURN 对外公网 IP（自动识别，换服务器无需改代码）：
 *   1. /etc/turn-public-ip   可选：多公网 IP / 特殊网络时显式指定，优先使用
 *   2. 缓存                   /tmp 下缓存 6 小时，避免每次请求都探测
 *   3. 自动探测               a) 向公共 STUN 取本机出口 IP（UDP，最贴近 TURN 的真实出口）
 *                            b) 失败则用 IP 回显服务
 * 探测失败返回空串，此时只下发域名那条，通话仍可尝试直连。
 */
function wo_turn_public_ip() {
    $file = '/etc/turn-public-ip';
    if (is_readable($file)) {
        $ip = trim((string) @file_get_contents($file));
        if (wo_turn_ip_usable($ip)) {
            return $ip;
        }
    }
    $cache = rtrim(sys_get_temp_dir(), '/') . '/seosq-turn-public-ip';
    if (is_readable($cache) && @filemtime($cache) > time() - 21600) {
        $ip = trim((string) @file_get_contents($cache));
        if (wo_turn_ip_usable($ip)) {
            return $ip;
        }
    }
    // 刚探测失败过就先不再试，避免探测超时拖慢通话建立
    $fail = $cache . '.fail';
    if (is_readable($fail) && @filemtime($fail) > time() - 600) {
        return '';
    }
    $ip = wo_turn_stun_ip();
    if ($ip === '') {
        $ip = wo_turn_http_ip();
    }
    if ($ip !== '') {
        @file_put_contents($cache, $ip);
        @unlink($fail);
        return $ip;
    }
    @file_put_contents($fail, (string) time());
    return '';
}

/** 通过 STUN Binding 取本机出口公网 IP（不依赖任何第三方 HTTP 接口） */
function wo_turn_stun_ip() {
    $txid = '';
    for ($i = 0; $i < 12; $i++) {
        $txid .= chr(mt_rand(0, 255));
    }
    $req = pack('n', 0x0001) . pack('n', 0) . pack('N', 0x2112A442) . $txid;
    foreach (array('stun.l.google.com:19302', 'stun1.l.google.com:19302') as $srv) {
        $fp = @stream_socket_client('udp://' . $srv, $errno, $errstr, 1);
        if (!$fp) {
            continue;
        }
        stream_set_timeout($fp, 1);
        @fwrite($fp, $req);
        $res = @fread($fp, 1024);
        fclose($fp);
        if (!is_string($res) || strlen($res) < 20 || substr($res, 0, 2) !== "\x01\x01") {
            continue;
        }
        $hdr = unpack('nlen', substr($res, 2, 2));
        $end = min(strlen($res), 20 + (int) $hdr['len']);
        for ($p = 20; $p + 4 <= $end;) {
            $a = unpack('ntype/nlen', substr($res, $p, 4));
            $v = substr($res, $p + 4, (int) $a['len']);
            // XOR-MAPPED-ADDRESS(0x8020) / MAPPED-ADDRESS(0x0020)，只处理 IPv4
            if (($a['type'] === 0x8020 || $a['type'] === 0x0020) && strlen($v) >= 8 && ord($v[1]) === 0x01) {
                $raw = substr($v, 4, 4);
                if ($a['type'] === 0x8020) {
                    $raw = $raw ^ pack('N', 0x2112A442);
                }
                $ip = @inet_ntop($raw);
                if ($ip !== false && wo_turn_ip_usable($ip)) {
                    return $ip;
                }
            }
            $p += 4 + (int) $a['len'] + ((4 - ((int) $a['len'] % 4)) % 4);
        }
    }
    return '';
}

/** 兜底：用 IP 回显服务探测 */
function wo_turn_http_ip() {
    if (!ini_get('allow_url_fopen')) {
        return '';
    }
    $ctx = stream_context_create(array('http' => array('timeout' => 2, 'user_agent' => 'seosq-turn-check')));
    foreach (array('https://api.ipify.org', 'https://ipv4.icanhazip.com') as $url) {
        $ip = trim((string) @file_get_contents($url, false, $ctx));
        if (wo_turn_ip_usable($ip)) {
            return $ip;
        }
    }
    return '';
}

/** 只接受公网 IPv4（排除内网/回环/链路本地/CGNAT），避免把 10.x 这类地址下发给浏览器 */
function wo_turn_ip_usable($ip) {
    if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    $l = ip2long($ip);
    $private = array(
        array('10.0.0.0', '10.255.255.255'),
        array('100.64.0.0', '100.127.255.255'),
        array('127.0.0.0', '127.255.255.255'),
        array('169.254.0.0', '169.254.255.255'),
        array('172.16.0.0', '172.31.255.255'),
        array('192.168.0.0', '192.168.255.255'),
        array('0.0.0.0', '0.255.255.255')
    );
    foreach ($private as $range) {
        if ($l >= ip2long($range[0]) && $l <= ip2long($range[1])) {
            return false;
        }
    }
    return true;
}
