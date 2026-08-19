<?php
/**
 * ============================================================
 *  去水印小程序 · 前端接口（wm_api.php）
 *  动作：check / watermark / proxy_video
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

// 会话里记录免费次数（保留字段，但已去除 OAuth 登录中心，不再限制）
if (!isset($_SESSION['free_used'])) $_SESSION['free_used'] = 0;

function api_ok($data)   { echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE); }
function api_err($msg, $extra = []) {
    echo json_encode(array_merge(['success' => false, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE);
}

switch ($action) {

    // 查询登录态（已去除 OAuth 登录中心，固定返回游客态，前端不再依赖）
    case 'check':
        api_ok(['logged_in' => false, 'user' => null, 'free_left' => 0]);
        break;

    // 解析去水印（已去除登录门槛与免费次数限制，直接可用）
    case 'watermark':
        $text = trim($post['url'] ?? '');
        $url  = extract_url_from_text($text);
        if (!$url) {
            api_err('未能从输入中提取到有效链接，请粘贴分享链接或完整文案');
            break;
        }
        $result = proxy_parse($url);
        if (!$result['success']) {
            api_err($result['msg']);
            break;
        }
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
            if (stripos($host, $allow) !== false) { $ok = true; break; }
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
    if (strpos($h, 'bilibili')   !== false) return 'https://www.bilibili.com';
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
 * 带 CORS 与 Range 支持的视频流代理（供 ffmpeg.wasm 在浏览器内读取跨域视频）。
 * 先发一次 HEAD 探测响应头，再流式转发实体，避免把大文件载入内存。
 */
function stream_proxy($url, $filename = '') {
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    $referer = referer_for_host(parse_url($url, PHP_URL_HOST) ?: '');
    $hdrs  = [
        'Referer: ' . $referer,
        'Origin: '  . $referer,
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
    ];
    if ($range) $hdrs[] = "Range: $range";

    // 1) 探测响应头
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_HEADER         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER     => $hdrs,
    ]);
    $head = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ctype = 'application/octet-stream';
    $clen  = null;
    $crange = null;
    $accept = 'bytes';
    foreach (explode("\r\n", $head ?: '') as $line) {
        $lk = strtolower($line);
        if (preg_match('/content-type:\s*(.+)/i', $line, $m))      $ctype = trim($m[1]);
        if (preg_match('/content-length:\s*(\d+)/i', $line, $m))   $clen  = (int)$m[1];
        if (preg_match('/content-range:\s*(.+)/i', $line, $m))     $crange = trim($m[1]);
        if (preg_match('/accept-ranges:\s*(.+)/i', $line, $m))     $accept = trim($m[1]);
    }

    @set_time_limit(0);
    header('Access-Control-Allow-Origin: *');
    header("Content-Type: $ctype");
    header("Accept-Ranges: $accept");
    // 指定了文件名时，要求浏览器“另存为下载”而非直接播放（同源代理 + 该头 = 可靠触发下载）
    if ($filename !== '') {
        $filename = preg_replace('/[\x00-\x1f\/\\\\]/', '', $filename) ?: 'video.mp4';
        header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    }
    if ($range && $code == 206 && $crange !== null) {
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: $crange");
        if ($clen !== null) header("Content-Length: $clen");
        http_response_code(206);
    } elseif ($clen !== null) {
        header("Content-Length: $clen");
    }

    // 2) 流式转发实体
    $out = fopen('php://output', 'wb');
    $ch2 = curl_init($url);
    curl_setopt_array($ch2, [
        CURLOPT_FILE           => $out,
        CURLOPT_HEADER         => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER     => $hdrs,
    ]);
    curl_exec($ch2);
    curl_close($ch2);
    fclose($out);
    exit;
}
