<?php
/**
 * ============================================================
 *  去水印小程序 · 前端接口（wm_api.php）
 *  动作：check / login_url / logout / watermark / proxy_video / oauth_ping
 * ============================================================
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 读取前端 JSON / 表单请求体
$raw  = file_get_contents('php://input');
$post = json_decode($raw, true);
if (!is_array($post)) {
    parse_str($raw, $postForm);
    $post = is_array($postForm) ? $postForm : [];
}
$post = array_merge($_POST, $post);
$action = $post['action'] ?? ($_GET['action'] ?? '');

// 会话里记录免费次数（未登录用户）
if (!isset($_SESSION['free_used'])) $_SESSION['free_used'] = 0;

function api_ok($data)   { echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE); }
function api_err($msg, $extra = []) {
    echo json_encode(array_merge(['success' => false, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE);
}

switch ($action) {

    // 查询登录态 + 剩余免费次数
    case 'check':
        $user = $_SESSION['oauth_user'] ?? null;
        api_ok([
            'logged_in' => !empty($user),
            'user'      => $user ? ['username' => $user['username']] : null,
            'free_left' => FREE_TRIES - $_SESSION['free_used'],
        ]);
        break;

    // 返回授权地址（弹窗点“前往登录”时调用）
    case 'login_url':
        if (strpos(REDIRECT_URI, '你的域名') !== false) {
            api_err('尚未配置回调地址：请修改 config.php 的 REDIRECT_URI 为你的真实域名');
            break;
        }
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;
        $_SESSION['oauth_next']  = 'index.html';
        // PKCE（S256）—— 新版 OAuth 对所有客户端强制要求
        $verifier  = bin2hex(random_bytes(32));                                  // 64 位 hex，符合 [43,128] 长度
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $_SESSION['oauth_verifier'] = $verifier;                                 // 换码时取用，防篡改
        $params = http_build_query([
            'response_type'          => 'code',
            'client_id'              => CLIENT_ID,
            'redirect_uri'           => REDIRECT_URI,
            'scope'                  => OAUTH_SCOPE,
            'state'                  => $state,
            'code_challenge'         => $challenge,
            'code_challenge_method'  => 'S256',
        ]);
        api_ok(['url' => OAUTH_AUTHORIZE . '&' . $params]);
        break;

    case 'logout':
        session_destroy();
        api_ok([]);
        break;

    // 探测 OAuth 授权中心（api.ijerrt.cn）连通性，供前端呼吸灯显示
    case 'oauth_ping':
        $t0 = microtime(true);
        $r = waf_fetch(OAUTH_AUTHORIZE, 'GET');
        $latency = max(0, round((microtime(true) - $t0) * 1000));
        $http    = isset($r['http']) ? (int)$r['http'] : 0;
        $reachable = empty($r['error']) && $http >= 200 && $http < 500;
        api_ok([
            'connected' => $reachable,
            'latency'   => $latency,
            'http'      => $http,
        ]);
        break;

    // 解析去水印
    case 'watermark':
        $user = $_SESSION['oauth_user'] ?? null;
        if (empty($user)) {
            if ($_SESSION['free_used'] >= FREE_TRIES) {
                api_err('今日免费次数已用完，请登录后继续使用', ['need_login' => true]);
                break;
            }
        }
        $text = trim($post['url'] ?? '');
        $url  = extract_url_from_text($text);
        if (!$url) {
            api_err('未能从输入中提取到有效链接，请粘贴分享链接或完整文案');
            break;
        }
        $quality = trim($post['quality'] ?? 'auto');
        // 短缓存：相同链接+画质 600s 内直接复用，降低对上游（尤其第三方）的调用压力与失败率
        $cacheKey = 'wm_' . md5($url . '|' . $quality);
        $cached   = cache_get($cacheKey);
        if ($cached !== null) {
            $result = $cached;
        } else {
            $result = proxy_parse($url, $quality);
            if ($result['success']) {
                // 剔除内部字段（_src 来源标记 / _rate 限流标记）再缓存与返回
                foreach (['_src', '_rate'] as $k) unset($result[$k]);
                cache_set($cacheKey, $result, 600);
            }
        }
        if (!$result['success']) {
            api_err($result['msg']);
            break;
        }
        if (empty($user)) $_SESSION['free_used']++;
        api_ok($result);
        break;

    // 视频代理（为剪辑功能提供跨域 CORS 直链，支持 Range 断点续传）
    case 'proxy_video':
        $u = trim($_GET['url'] ?? '');
        if (!$u || !filter_var($u, FILTER_VALIDATE_URL)) {
            http_response_code(400); exit('invalid url');
        }
        $host = parse_url($u, PHP_URL_HOST) ?: '';
        $ok = false;
        foreach (PROXY_ALLOW as $allow) {
            $q = preg_quote($allow, '/');
            if (strpos($allow, '.') !== false) {
                // 完整域名后缀：必须出现在主机末尾，且前方为边界（. 或开头），防 evilbilivideo.com 绕过
                if (preg_match('/(?:^|\.)' . $q . '$/i', $host)) { $ok = true; break; }
            } else {
                // 关键词：须作为独立域名标签（前后为 . 或边界），防 xdouyin.com 之类绕过
                if (preg_match('/(?:^|\.)' . $q . '(?:\.|$)/i', $host)) { $ok = true; break; }
            }
        }
        if (!$ok) { http_response_code(403); exit('host not allowed'); }
        // 透传前端指定的下载文件名（已 URL 编码），用于在响应头里要求浏览器“下载”而非播放
        $name = trim($_GET['name'] ?? '');
        stream_proxy($u, $name);
        break;

    default:
        api_err('未知操作');
}

/**
 * 从一段分享文案中提取第一个 http(s) 链接（兼容抖音/快手/小红书/TikTok/B站 文案）。
 */
function extract_url_from_text($text) {
    if (!$text) return '';
    if (preg_match('/https?:\/\/[^\s"\'<>]+/i', $text, $m)) {
        return rtrim($m[0], ".,;:!?）)】]}'\" \t\n\r");
    }
    return '';
}

/**
 * 根据目标 CDN 域名返回合适的 Referer/Origin（避免硬编码导致跨站视频被拒）。
 */
function referer_for_host($host) {
    $h = strtolower($host ?: '');
    // B站 CDN 域名已迁移（bilivideo.com / bilivideo.cn / mountaintoys.cn 等），统一用 B站 Referer。
    // 注意：`bilivideo` 不含 `bilibili` 子串，必须单独匹配，否则会 fallback 到错误的 https://<host> Referer，
    // 而 B站 CDN 对错误 Referer 直接返回 403（下载失败根因之一）。
    // 封面图床 hdslb.com / biliimg.com 同样有防盗链，也必须带 bilibili Referer（否则 403）。
    if (strpos($h, 'bilibili')    !== false ||
        strpos($h, 'bilivideo')   !== false ||
        strpos($h, 'mountaintoys') !== false ||
        strpos($h, 'hdslb')       !== false ||
        strpos($h, 'biliimg')     !== false) return 'https://www.bilibili.com';
    if (strpos($h, 'douyin')     !== false ||
        strpos($h, 'amemv')      !== false ||
        strpos($h, 'snssdk')     !== false ||
        strpos($h, 'zjcdn')      !== false ||
        strpos($h, 'douyinvod')  !== false) return 'https://www.douyin.com';
    if (strpos($h, 'tiktok')     !== false) return 'https://www.tiktok.com';
    if (strpos($h, 'kuaishou')   !== false ||
        strpos($h, 'gifshow')    !== false) return 'https://www.kuaishou.com';
    if (strpos($h, 'xiaohongshu')!== false) return 'https://www.xiaohongshu.com';
    return 'https://' . ltrim($host, '/');
}

/**
 * 带 CORS 与 Range 支持的视频流代理（供浏览器 <video> 播放 + ffmpeg.wasm 读取跨域视频）。
 * 实现：先发一次 HEAD 探测响应头，再流式转发实体，避免把大文件载入内存。
 *
 * 性能说明：vplayer-base 的零拷贝版本（fopen('php://output') + CURLOPT_FILE），
 * cURL 把整块数据直接 pipe 给输出层，PHP 不参与字节搬运，
 * 不会有每 chunk 回调开销，速度比 WRITEFUNCTION 版通常快 2–4×。
 * 这里再加 CURLOPT_BUFFERSIZE=256KB 把 cURL 内部缓冲放大，再提升一档。
 */
function stream_proxy($url, $filename = '') {
    // —— 下载加速：必须关 PHP 输出缓冲，否则 cURL → php://output 时
    //     4KB 缓冲堆满才 flush，视频流被切成无数小段往返 ——
    @ini_set('output_buffering', '0');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) { @ob_end_clean(); }
    @set_time_limit(0);

    $range = $_SERVER['HTTP_RANGE'] ?? '';
    $referer = referer_for_host(parse_url($url, PHP_URL_HOST) ?: '');
    $baseHdrs = [
        'Referer: ' . $referer,
        'Origin: '  . $referer,
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
    ];
    $hdrs = $baseHdrs;
    if ($range !== '') $hdrs[] = "Range: $range";

    // —— 文件名 RFC 5987 双段（保留中文） ——
    $sendAscii = '';
    $cleanForDisp = '';
    if ($filename !== '') {
        $clean = preg_replace('/[\x00-\x1f\x7f\/\\\\]/', '', $filename);
        if (function_exists('mb_strlen')) {
            if (mb_strlen($clean, 'UTF-8') > 80) $clean = mb_substr($clean, 0, 80, 'UTF-8');
        } elseif (strlen($clean) > 120) {
            $clean = substr($clean, 0, 120);
        }
        $cleanForDisp = $clean;
        $sendAscii = preg_replace('/[^\x20-\x7e]/', '', $clean);
        $sendAscii = trim($sendAscii, " \t\n\r\0\x0B.");
        if ($sendAscii === '' || preg_match('/^\.[a-z0-9]{2,4}$/i', $sendAscii)) $sendAscii = 'video';
        $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        $okExt = ['mp4','webm','mkv','flv','mov','m4v','avi','mp3','m4a','aac','wav','jpg','jpeg','png','webp','gif'];
        if ($ext === '' || !in_array($ext, $okExt, true)) {
            if (!preg_match('/\.[a-z0-9]{2,4}$/i', $sendAscii)) $sendAscii .= '.mp4';
        }
    }

    // —— HEAD 探测：拿到 Content-Type + 资源总字节数（用于正确的 Content-Length / Content-Range / 416） ——
    $ctype = 'application/octet-stream';
    $total = null;        // 上游资源总字节数
    $status = 200;
    $chunkLen = null;     // 本次响应体字节数（Range 时=区间长度；否则=全文长度；未知则不设 Content-Length）
    if (function_exists('curl_init')) {
        $headCh = curl_init($url);
        // bytes=0-0 探测：多数 CDN 回 206 + Content-Range，可拿到总大小（优于裸 HEAD 拿不到长度）
        $headHdrs = array_merge($baseHdrs, ['Range: bytes=0-0']);
        curl_setopt_array($headCh, [
            CURLOPT_NOBODY => true, CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => $headHdrs,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);
        $head = curl_exec($headCh);
        curl_close($headCh);
        if ($head) {
            if (preg_match('/content-type:\s*([^\r\n]+)/i', $head, $m)) $ctype = trim($m[1]);
            if (preg_match('/content-range:\s*bytes\s+\d+-\d+\/(\d+|\*)/i', $head, $m)) {
                $total = ($m[1] === '*') ? null : (int)$m[1];
            } elseif (preg_match('/content-length:\s*(\d+)/i', $head, $m)) {
                $total = (int)$m[1];
            }
        }
    }

    // 解析客户端 Range → 正确响应状态 + Content-Range + Content-Length
    // （否则浏览器/下载器拿不到长度，断流也无从察觉；分片 seek 也会错）
    $contentRange = '';
    if ($range !== '') {
        $status = 206;
        $from = $to = null;
        if (preg_match('/bytes=(\d*)-(\d*)/i', $range, $rm)) {
            $from = ($rm[1] === '') ? null : (int)$rm[1];
            $to   = ($rm[2] === '') ? null : (int)$rm[2];
        }
        if ($from !== null && $total !== null && $from >= $total) {
            header('Access-Control-Allow-Origin: *');
            http_response_code(416);
            header('Content-Type: ' . $ctype);
            header('Content-Range: bytes */' . $total);
            header('Content-Length: 0');
            exit;
        }
        if ($from === null && $total !== null) $from = 0;
        if ($to === null && $total !== null) $to = $total - 1;
        if ($from !== null && $to !== null) {
            $chunkLen = $to - $from + 1;
            $contentRange = 'bytes ' . $from . '-' . $to . '/' . ($total !== null ? $total : '*');
        }
    } elseif ($total !== null) {
        $chunkLen = $total;   // 全文已知大小 → 声明 Content-Length，CDN 断流即可被客户端察觉
    }

    // 响应头一次性写出去（cURL 直 stream 到 php://output 期间不能再 header()；注意先 status 再 Content-Range）
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');
    header('Content-Type: ' . $ctype);
    header('Accept-Ranges: bytes');
    http_response_code($status);
    if ($status === 206 && $contentRange !== '') header('Content-Range: ' . $contentRange);
    if ($chunkLen !== null) header('Content-Length: ' . $chunkLen);
    if ($sendAscii !== '') {
        header('Content-Disposition: attachment; filename="' . $sendAscii . '"; filename*=UTF-8\'\'' . rawurlencode($cleanForDisp));
    }

    // —— 零拷贝流式转发：cURL 直接 pipe 到 php://output ——
    $out = fopen('php://output', 'wb');
    if ($out === false) { http_response_code(500); exit('open output failed'); }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $out,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_BUFFERSIZE => 256 * 1024,
        CURLOPT_TCP_NODELAY => true,
        CURLOPT_ENCODING => '',
    ]);
    curl_exec($ch);
    $derr = curl_errno($ch);           // 断流检测：上游在 Content-Length 前断开（如某些节点只发 moov 就断）
    curl_close($ch);
    if ($derr === 18) {
        // CURLE_PARTIAL_FILE——CDN 半路断开。响应头已发出，Body 已截断，
        // 但 Content-Length 已声明，客户端能立刻察觉断流并可用 Range 续传。
        @error_log('stream_proxy partial-file(upstream disconnect) url=' . substr($url, 0, 140));
    }
    @fflush($out);
    @fclose($out);
    exit;
}
