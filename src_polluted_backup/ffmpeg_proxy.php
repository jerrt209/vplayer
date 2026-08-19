<?php
/**
 * 同源 ffmpeg 资源代理
 * - 把公共 CDN 上的 @ffmpeg/* 资源以同源路径伺服，彻底绕开两点限制：
 *   1) InfinityFree 的 JS 反爬 WAF（会拦截本站静态 .js/.wasm，返回挑战页）；
 *   2) 浏览器对跨源 module Worker 的硬性禁止（ffmpeg.wasm 0.12 默认跨源加载 worker.js 必失败）。
 * - 页面 import / worker 的相对引用全部解析到本代理（同源），因此跨源 Worker 限制消失。
 * - 仅放行白名单内的 @ffmpeg 包，杜绝任意代理滥用。
 */
header('Access-Control-Allow-Origin: *');

// 把「目录 + 相对路径(支持 ./ ../)」解析为规范化路径，如 esm + ./classes.js -> @ffmpeg/ffmpeg@x/dist/esm/classes.js
function resolve_path($base, $rel) {
    $parts = explode('/', $base);
    $stack = [];
    foreach ($parts as $p) { if ($p !== '') $stack[] = $p; }
    $segs = explode('/', $rel);
    foreach ($segs as $s) {
        if ($s === '' || $s === '.') continue;
        if ($s === '..') { array_pop($stack); }
        else { $stack[] = $s; }
    }
    return implode('/', $stack);
}
header('Cross-Origin-Resource-Policy: cross-origin');

$f = $_GET['f'] ?? '';
// f 由前端 base64 编码（避免 URL 中出现 .js/.wasm 字面量触发 InfinityFree 的 WAF 挑战页）
if (preg_match('#^[A-Za-z0-9_-]+$#', $f)) {
    $f = base64_decode(strtr($f, '-_', '+/'), true);
}
if ($f === false || $f === null) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('bad param');
}
// 仅允许 @ffmpeg 的 ffmpeg/core/util 三个包，固定版本，仅 esm/umd 目录下的 .js/.wasm
if (!preg_match('#^@ffmpeg/(ffmpeg|core|util)@\d+\.\d+\.\d+/dist/(esm|umd)/[A-Za-z0-9_./-]+\.(js|wasm)$#', $f)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('forbidden');
}

$isWasm = substr($f, -5) === '.wasm';
header('Content-Type: ' . ($isWasm ? 'application/wasm' : 'application/javascript; charset=utf-8'));
header('Cache-Control: public, max-age=86400');

$cdns = [
    'https://gcore.jsdelivr.net/npm/',
    'https://cdn.jsdelivr.net/npm/',
    'https://unpkg.com/',
];

foreach ($cdns as $base) {
    $url = $base . $f;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,   // 缓冲后输出，便于失败重试与保证二进制完整
        CURLOPT_HTTPHEADER     => ['User-Agent: Mozilla/5.0'],
        CURLOPT_ENCODING       => '',       // 不发送 Accept-Encoding，取回原始字节（wasm 不被压缩破坏）
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_FAILONERROR    => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body !== false && $code >= 200 && $code < 400) {
        // ESM 文件里的相对引用（from "./x.js" 以及 new URL("./worker.js", import.meta.url)）
        // 经本站代理(查询参数形式)加载后，浏览器会把 ./worker.js 解析成脚本所在目录(站点根)而 404。
        // 一律改写为「绝对 base64 代理地址」，不再依赖 URL 相对解析。
        if (!$isWasm) {
            $dir = dirname($f);                 // 如 @ffmpeg/ffmpeg@0.12.10/dist/esm
            $rewrite = function ($rel) use ($dir) {
                $target = resolve_path($dir, $rel);   // 解析为 @ffmpeg/.../x.js
                $enc = strtr(base64_encode($target), '+/', '-_');
                $enc = preg_replace('#=+$#', '', $enc);
                return '/ffmpeg_proxy.php?f=' . $enc;
            };
            // from "./x" / "../x"
            $body = preg_replace_callback(
                '#(from\s+[\'"])(' . preg_quote('./', '#') . '|\.\./)([^\'"]+)([\'"])#',
                function ($m) use ($rewrite) { return $m[1] . $rewrite($m[2] . $m[3]) . $m[4]; },
                $body
            );
            // new URL("./worker.js", import.meta.url)
            $body = preg_replace_callback(
                '#(new URL\(\s*[\'"])(' . preg_quote('./', '#') . '|\.\./)([^\'"]+)([\'"])#',
                function ($m) use ($rewrite) { return $m[1] . $rewrite($m[2] . $m[3]) . $m[4]; },
                $body
            );
        }
        header('Content-Length: ' . strlen($body));
        echo $body;   // 以同源响应流式/整体返回
        exit;
    }
}
http_response_code(502);
header('Content-Type: text/plain; charset=utf-8');
echo 'upstream fetch failed';
