<?php
/**
 * ============================================================
 *  跨域 OAuth 客户端配置 —— 去水印小程序
 *  对接 Jerrt OAuth 授权服务器 (http://api.ijerrt.cn)
 * ------------------------------------------------------------
 *  本文件同时被 wm_api.php 与 wm_callback.php 引用。
 *  部署时只需修改本文件里的常量即可。
 * ============================================================
 */

// ---------- 会话 ----------
session_start([
    'cookie_lifetime' => 60 * 60 * 24 * 7,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

// ============================================================
//  1) 应用基础信息
// ============================================================
define('APP_NAME',     '去水印工具');
define('FREE_TRIES',   1);      // 未登录用户的免费解析次数（用完后必须登录）

// ============================================================
//  2) OAuth 授权服务器（来自 OAUTH.md，请勿改动地址）
// ============================================================
define('OAUTH_AUTHORIZE', 'http://api.ijerrt.cn/oauth/oauth.php?action=authorize');
define('OAUTH_TOKEN',     'http://api.ijerrt.cn/oauth/oauth_token.php');
define('OAUTH_USERINFO',  'http://api.ijerrt.cn/oauth/oauth.php?action=userinfo');
define('OAUTH_PROFILE',   'http://api.ijerrt.cn/oauth/oauth.php?action=profile'); // 个人中心

// ============================================================
//  3) 在 oauth-admin.php 后台「新建应用」后，把下面两项填进来
//     —— redirect_uri 必须与后台登记的一致，且指向本域名下的 wm_callback.php
// ============================================================
define('CLIENT_ID',     'cid_9d94029f5aa4');               // 已在 api.ijerrt.cn 后台「去水印视频解析平台」应用获取
define('CLIENT_SECRET', '682989560d7b20def8fe5db1e56769498c2ef6f770ceb5b4'); // 已同步更新（仅显示一次，已妥善保存）

// 本应用的回调地址：你部署在 http://vplayer.ijerrt.cn/ 根目录，回调即根目录下的 wm_callback.php
define('REDIRECT_URI',  'http://vplayer.ijerrt.cn/wm_callback.php');

// 申请的权限范围（保持默认即可）
define('OAUTH_SCOPE',   'profile');   // 与后台应用登记的 scope 保持一致（后台仅登记 profile）

// ============================================================
//  4) 上游解析服务
//     主源：api.bugpk.com（公益免费，实测稳定，支持 抖音/快手/小红书/B站）
//     兜底：api.douyin.wtf（开源项目，支持 抖音/TikTok 混合解析）
//     B站另走官方接口（无需第三方，服务端直接解析），官方失败再回退 bugpk
// ============================================================
define('BUGPK_BASE', 'https://api.bugpk.com/api');

// bugpk 平台路径映射：detect_platform 返回的平台名 -> bugpk 接口路径
define('BUGPK_PLAT', [
    'douyin'   => 'douyin',
    'ks'       => 'kuaishou',
    'xhs'      => 'xhs',
    'bilibili' => 'bilibili',
]);

// Bilibili 官方接口（无需第三方，服务端直接解析）
define('BILI_VIEW',     'https://api.bilibili.com/x/web-interface/view');
define('BILI_PLAYURL',  'https://api.bilibili.com/x/player/playurl');
define('BILI_REFERER',  'https://www.bilibili.com');

// 抖音兜底源（bugpk 限流/失败时）
define('DOUYINWTF_BASE', 'https://api.douyin.wtf/api');

// 剪辑功能：视频代理允许的 CDN 域名（防开放代理被滥用）
define('PROXY_ALLOW', [
    'bilivideo.com', 'bilibili.com', 'douyin', 'tiktok', 'kuaishou', 'gifshow',
    'byteimg.com', 'muscdn.com', 'akamaized.net', 'alicdn.com', 'ixigua.com',
    'chenzhongtech.com', 'snssdk.com', 'amemv.com', 'tiktokcdn.com', 'volcstatic.com',
    'douyinvod.com', 'zjcdn.com', 'bytecdn.com', 'bytedance', 'tospush',
    // 剪辑引擎 wasm 各镜像（同源代理需放行，否则 COEP 下跨域 wasm 会被拦截）
    'npmmirror.com', 'jsdelivr.net',
]);

// ============================================================
//  5) WAF 绕过：api.ijerrt.cn 对 OAuth 的 token / userinfo 端点
//     做了 JS 反爬挑战（要求 __test cookie）。
//     由于这是“服务端调用服务端”，没有浏览器 JS 来算 cookie，
//     这里用 PHP 的 openssl(AES-128-CBC) 复现挑战算法自动算出 cookie。
//     挑战参数 a/b/c 在服务器上是固定的，因此下面的缓存值长期有效；
//     若服务器更换挑战，本文件会自动从返回的 HTML 中重新解析并计算。
// ============================================================
define('WAF_COOKIE_CACHE', '__test=4fcd9064dbff935eafae79aab59f5c64');

// ============================================================
//  以下为实现细节，一般无需改动
// ============================================================

/**
 * 从 InfinityFree 挑战页 HTML 中解析 a/b/c 并复现 slowAES 解密，
 * 得到 __test cookie 的值（十六进制字符串）。
 */
function waf_compute_cookie($html) {
    if (!preg_match_all('/toNumbers\("([0-9a-f]+)"\)/', $html, $m) || count($m[1]) < 3) {
        return false;
    }
    list($a, $b, $c) = $m[1];
    $key = hex2bin($a);   // 16 字节
    $iv  = hex2bin($b);   // 16 字节
    $ct  = hex2bin($c);   // 16 字节
    $pt  = openssl_decrypt($ct, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($pt === false) return false;
    return bin2hex($pt);
}

/**
 * 通用 curl 请求。
 */
function do_curl($url, $method = 'GET', $postfields = null, $headers = [], $cookie = '') {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; WatermarkTool/1.0)',
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($postfields !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
    }
    $h = $headers;
    if ($cookie) $h[] = "Cookie: $cookie";
    if ($h) curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['body' => $body === false ? '' : $body, 'http' => $http, 'error' => $err];
}

/**
 * 带 WAF 绕过的请求：首次不带 cookie，若命中挑战则自动算 cookie 重试。
 */
function waf_fetch($url, $method = 'GET', $postfields = null, $headers = []) {
    $cookie = defined('WAF_COOKIE_CACHE') ? WAF_COOKIE_CACHE : '';
    $r = do_curl($url, $method, $postfields, $headers, $cookie);
    if (strpos($r['body'], 'slowAES') !== false || strpos($r['body'], '__test') !== false) {
        $val = waf_compute_cookie($r['body']);
        if ($val) {
            $r = do_curl($url, $method, $postfields, $headers, "__test=$val");
        }
    }
    return $r;
}

/**
 * 从可能嵌套的响应中取字段（兼容 {x} 与 {data:{x}} 两种结构）。
 */
function pick($data, $keys) {
    if (!is_array($data)) return null;
    $merged = $data;
    if (isset($data['data']) && is_array($data['data'])) {
        $merged = array_merge($data, $data['data']);
    }
    foreach ($keys as $k) {
        if (isset($merged[$k]) && $merged[$k] !== '' && $merged[$k] !== null) return $merged[$k];
    }
    return null;
}

/**
 * 用授权码向 OAuth 服务器换取 access_token（服务端调用，带 client_secret）。
 */
function oauth_exchange_code($code) {
    $post = http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => CLIENT_ID,
        'client_secret' => CLIENT_SECRET,
        'redirect_uri'  => REDIRECT_URI,
    ]);
    $r = waf_fetch(OAUTH_TOKEN, 'POST', $post, ['Content-Type: application/x-www-form-urlencoded']);
    $data = json_decode($r['body'], true);
    if (!is_array($data)) {
        return ['error' => 'bad_response', 'error_description' => '令牌端点返回非 JSON：' . substr($r['body'], 0, 120)];
    }
    return $data;
}

/**
 * 用 access_token 拉取用户信息。
 * 注意：实测该服务器从「查询参数 access_token」读取令牌（OAUTH.md 提到的
 * Bearer 头未生效），故这里走查询参数，同时附带 Bearer 头以兼容规范。
 */
function oauth_userinfo($access_token) {
    $url = OAUTH_USERINFO . '&access_token=' . urlencode($access_token);
    $r = waf_fetch($url, 'GET', null, ["Authorization: Bearer $access_token"]);
    $data = json_decode($r['body'], true);
    if (!is_array($data)) {
        return ['error' => 'bad_response', 'error_description' => '用户信息端点返回非 JSON'];
    }
    if (isset($data['error'])) return $data; // 如 invalid_token
    return $data;
}

/**
 * 判断链接属于哪个平台，映射到上游路径。
 */
function detect_platform($url) {
    $u = strtolower($url);
    if (strpos($u, 'bilibili') !== false || strpos($u, 'b23.tv') !== false) return 'bilibili';
    if (strpos($u, 'douyin') !== false || strpos($u, 'iesdouyin') !== false || strpos($u, 'tiktok') !== false) {
        return strpos($u, 'tiktok') !== false ? 'tiktok' : 'douyin';
    }
    if (strpos($u, 'kuaishou') !== false || strpos($u, 'gifshow') !== false || strpos($u, 'chenzhongtech') !== false) return 'ks';
    if (strpos($u, 'xiaohongshu') !== false || strpos($u, 'xhslink') !== false || strpos($u, 'xhs') !== false) return 'xhs';
    return 'douyin'; // 默认
}

/**
 * 调用上游解析并归一化结果：
 *  - B站：官方接口优先，失败回退 bugpk
 *  - 抖音/快手/小红书：bugpk 主，抖音额外 douyin.wtf 兜底
 *  - TikTok：走 douyin.wtf
 */
function proxy_parse($url) {
    $platform = detect_platform($url);

    // B站：bugpk 是国内聚合源，对部署在海外的本服务器更稳；官方接口作为兜底
    if ($platform === 'bilibili') {
        if (isset(BUGPK_PLAT['bilibili'])) {
            $r = bugpk_parse($url, 'bilibili');
            if ($r['success']) return $r;
        }
        $r2 = bili_parse($url);
        if ($r2['success']) return $r2;
        return ['success' => false, 'msg' => 'Bilibili 解析失败：' . ($r['msg'] ?? '请确认链接为公开视频')];
    }

    // TikTok：bugpk 不支持，走 douyin.wtf
    if ($platform === 'tiktok') {
        return douyinwtf_parse($url);
    }

    // 抖音 / 快手 / 小红书：bugpk 主，抖音额外 douyin.wtf 兜底
    if (isset(BUGPK_PLAT[$platform])) {
        $r = bugpk_parse($url, $platform);
        if ($r['success']) return $r;
        if ($platform === 'douyin') {
            $r2 = douyinwtf_parse($url);
            if ($r2['success']) return $r2;
        }
        return $r;
    }

    // 兜底：当作抖音处理
    return bugpk_parse($url, 'douyin');
}

function host_of($url) {
    $p = parse_url($url, PHP_URL_HOST);
    return $p ?: $url;
}

/**
 * bugpk 解析（抖音/快手/小红书/B站），返回统一结构。
 * 成功时 data.url 即为无水印直链。
 */
function bugpk_parse($url, $platform) {
    $path = BUGPK_PLAT[$platform] ?? $platform;
    $api  = BUGPK_BASE . '/' . $path . '/?url=' . urlencode($url);
    $r    = do_curl($api, 'GET');
    $raw  = json_decode($r['body'], true);
    if (!is_array($raw) || ($raw['code'] ?? -1) != 200 || empty($raw['data'])) {
        $msg = $raw['msg'] ?? ($raw['message'] ?? '未能解析');
        return ['success' => false, 'msg' => "解析源($path)：$msg"];
    }
    $d = $raw['data'];
    $video = $d['url'] ?? '';
    if (!$video && !empty($d['video_backup'][0]['url'])) {
        $video = $d['video_backup'][0]['url'];
    }
    $author = '';
    if (isset($d['author'])) {
        $author = is_array($d['author']) ? ($d['author']['name'] ?? '') : (string)$d['author'];
    } elseif (isset($d['auther'])) {   // bugpk 的 bilibili 接口字段拼写为 auther
        $author = (string)$d['auther'];
    }
    $music = '';
    if (isset($d['music']) && is_array($d['music'])) {
        $music = $d['music']['url'] ?? '';
    }
    $duration = isset($d['duration']) ? round((float)$d['duration'] / 1000, 1) : 0; // ms -> s
    return [
        'success'   => true,
        'platform'  => $platform,
        'video_url' => $video,
        'cover_url' => $d['cover'] ?? '',
        'title'     => $d['title'] ?? ($d['desc'] ?? ''),
        'author'    => $author,
        'music_url' => $music,
        'duration'  => $duration,
    ];
}

/**
 * douyin.wtf 兜底解析（开源，支持 抖音/TikTok 混合）。
 */
function douyinwtf_parse($url) {
    $api = DOUYINWTF_BASE . '?url=' . urlencode($url) . '&minimal=true';
    $r   = do_curl($api, 'GET');
    $raw = json_decode($r['body'], true);
    if (!is_array($raw)) {
        return ['success' => false, 'msg' => 'douyin.wtf：返回异常'];
    }
    $video = $raw['nwm_video_url'] ?? $raw['video_url'] ?? ($raw['video'] ?? '');
    if (!$video) {
        return ['success' => false, 'msg' => 'douyin.wtf：未获取到视频地址'];
    }
    $author = '';
    if (isset($raw['author_name']))      $author = $raw['author_name'];
    elseif (isset($raw['author']))        $author = is_array($raw['author']) ? ($raw['author']['name'] ?? '') : (string)$raw['author'];
    $duration = isset($raw['duration']) ? round((float)$raw['duration'] / 1000, 1) : 0;
    return [
        'success'   => true,
        'platform'  => detect_platform($url),
        'video_url' => $video,
        'cover_url' => $raw['cover'] ?? ($raw['poster'] ?? ''),
        'title'     => $raw['desc'] ?? ($raw['title'] ?? ''),
        'author'    => $author,
        'music_url' => $raw['music'] ?? '',
        'duration'  => $duration,
    ];
}

// ===================== Bilibili 官方接口解析 =====================
function bili_headers() {
    return [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        'Referer: ' . BILI_REFERER,
    ];
}

/**
 * 从链接解析出 BV 号或 av 号（自动跟随 b23.tv 短链）。
 */
function bili_resolve($url) {
    if (preg_match('/b23\.tv\/([A-Za-z0-9]+)/i', $url, $m)) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true, CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_USERAGENT => 'Mozilla/5.0',
        ]);
        curl_exec($ch);
        $eff = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        if ($eff) $url = $eff;
    }
    if (preg_match('/BV[0-9A-Za-z]+/i', $url, $m)) return ['bvid' => $m[0]];
    if (preg_match('/\/av(\d+)/i', $url, $m))     return ['aid'  => $m[1]];
    return null;
}

/**
 * 通过 Bilibili 官方接口解析视频，返回合流直链（音视频合一，便于剪辑）。
 */
function bili_parse($url) {
    $id = bili_resolve($url);
    if (!$id) return ['success' => false, 'msg' => '无法识别 Bilibili 视频链接'];
    $param = isset($id['bvid']) ? ('bvid=' . $id['bvid']) : ('aid=' . $id['aid']);
    $view  = do_curl(BILI_VIEW . '?' . $param, 'GET', null, bili_headers());
    $v = json_decode($view['body'], true);
    if (!is_array($v) || ($v['code'] ?? -1) != 0) {
        return ['success' => false, 'msg' => 'Bilibili 信息接口异常：' . ($v['message'] ?? '未知错误')];
    }
    $d     = $v['data'];
    $cid   = $d['cid'];
    $title = $d['title'] ?? '';
    $cover = $d['pic'] ?? '';
    $dur   = $d['duration'] ?? 0;
    $pl = do_curl(BILI_PLAYURL . '?' . $param . '&cid=' . $cid . '&qn=64&fnval=0&fourk=0', 'GET', null, bili_headers());
    $p = json_decode($pl['body'], true);
    if (!is_array($p) || ($p['code'] ?? -1) != 0 || empty($p['data']['durl'])) {
        return ['success' => false, 'msg' => 'Bilibili 获取直链失败：' . ($p['message'] ?? '未知错误')];
    }
    $video = $p['data']['durl'][0]['url'];
    return [
        'success'   => true,
        'platform'  => 'bilibili',
        'video_url' => $video,
        'cover_url' => $cover,
        'title'     => $title,
        'duration'  => $dur,
    ];
}
