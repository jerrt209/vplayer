<?php
/**
 * ============================================================
 *  跨域 OAuth 客户端配置 —— 去水印小程序
 *  对接 Jerrt OAuth 授权服务器 (https://api.ijerrt.cn)
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
define('OAUTH_AUTHORIZE', 'https://api.ijerrt.cn/oauth/oauth.php?action=authorize');
define('OAUTH_TOKEN',     'https://api.ijerrt.cn/oauth/oauth_token.php');
define('OAUTH_USERINFO',  'https://api.ijerrt.cn/oauth/oauth.php?action=userinfo');
define('OAUTH_PROFILE',   'https://api.ijerrt.cn/oauth/oauth.php?action=profile'); // 个人中心

// ============================================================
//  3) 在 oauth-admin.php 后台「新建应用」后，把下面两项填进来
//     —— redirect_uri 必须与后台登记的一致，且指向本域名下的 wm_callback.php
// ============================================================
define('CLIENT_ID',     'cid_95a15bf6bfcd');               // 在 api.ijerrt.cn 后台「新建应用」后获取的 client_id
define('CLIENT_SECRET', 'f51a2f767efb89e6fa3099287977adf907433f6e812f34eb');   // 新建应用时后台展示一次的 client_secret（仅显示一次，请妥善保存）

// 本应用的回调地址：你部署在 https://vplayer.ijerrt.cn/ 根目录，回调即根目录下的 wm_callback.php
// 必须与后台登记的回调地址【完全一致】（含 https 和末尾文件名）
define('REDIRECT_URI',  'https://vplayer.ijerrt.cn/wm_callback.php');

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
// 可选：登录 Cookie。留空则按 B站游客上限解析（合流直链最高 720P）。
// 填入从 bilibili.com 登录后 Cookie 里复制的 SESSDATA（形如 SESSDATA=xxxx），
// 即可解锁 1080P / 4K 等高清合流直链（仍保持音视频合一，便于下载与剪辑）。
// 注意：SESSDATA 会过期，失效后官方接口会自动回退到 720P / bugpk，不影响使用。
define('BILI_COOKIE',   '');
// 可选：未登录时的 buvid3（B站风控指纹）。留空用默认值；遇到官方接口被风控
// 「啥都木有」时，可在浏览器打开 bilibili.com 按 F12 从 Cookie 复制 buvid3 填到这里，
// 可提高官方接口成功率（第三方源 bugpk 不受影响，仍可正常解析）。
define('BILI_BUVID3',   '');

// 抖音兜底源（bugpk 限流/失败时）
define('DOUYINWTF_BASE', 'https://api.douyin.wtf/api');

// ============================================================
//  5.1) 可自托管的“本地化 / 第一方”解析器（强烈推荐）
//  第三方公共接口随时可能限流 / 关停（实测 bugpk 会返回 429 满载），
//  把解析能力部署到你自己的服务器 / Cloudflare Worker（见 cloudflare-worker/parse.js），
//  在这里填入地址，系统会优先使用它，不再单一依赖第三方。
//  兼容返回：{code:200,data:{url|video_url,...}} 或本系统统一结构。留空则不使用。
// ============================================================
define('LOCAL_PARSER_URL', 'https://vplayer.ijerrt.cn/parser');   // 已部署本地解析器，优先使用，不再依赖第三方

// 多源并行竞速超时（秒）：同时向多个源发请求，谁先成功用谁，显著加快连接速度
define('PARSE_RACE_TIMEOUT', 15);
// 限流 / 失败源的“负缓存”时长（秒）：短期內不反复打挂掉的源，进一步提速
define('PARSE_NEG_CACHE', 90);

// 剪辑功能：视频代理允许的 CDN 域名（防开放代理被滥用）
// 注意：B站 CDN 域名已从 bilivideo.com 迁移到 mountaintoys.cn / bilivideo.cn 等新域名，
// 若白名单漏掉会导致「解析成功但下载 403 host not allowed」。
define('PROXY_ALLOW', [
    'bilivideo.com', 'bilibili.com', 'bilivideo.cn', 'mountaintoys.cn',
    // B站封面图床（防盗链域名，封面需经代理才能加载）
    'hdslb.com', 'biliimg.com',
    'douyin', 'tiktok', 'kuaishou', 'gifshow',
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
function do_curl($url, $method = 'GET', $postfields = null, $headers = [], $cookie = '', $timeout = 25) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(8, (int)$timeout),
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
 * 兼容两种情况：
 *   1) 老的 InfinityFree JS 反爬挑战（slowAES / __test cookie）；
 *   2) 其它常见 WAF 挑战页（如 Cloudflare 的「Just a moment」、腾讯云 WAF 的 JS 挑战）。
 * 对第 2 类无法用纯 PHP 计算 cookie，此时明确返回挑战标记，供上层报出可读错误。
 */
function waf_fetch($url, $method = 'GET', $postfields = null, $headers = []) {
    $cookie = defined('WAF_COOKIE_CACHE') ? WAF_COOKIE_CACHE : '';
    $r = do_curl($url, $method, $postfields, $headers, $cookie);
    $body = $r['body'];
    // InfinityFree 老式挑战：可纯 PHP 复现 cookie
    if (strpos($body, 'slowAES') !== false || strpos($body, '__test') !== false) {
        $val = waf_compute_cookie($body);
        if ($val) {
            $r = do_curl($url, $method, $postfields, $headers, "__test=$val");
        }
        return $r;
    }
    // 其它 WAF 挑战页（Cloudflare / 腾讯云等）：无 JS 环境无法自动绕过，打上标记
    if (stripos($body, 'cf-chl') !== false || stripos($body, 'challenge') !== false
        || stripos($body, 'Just a moment') !== false || stripos($body, '校验') !== false
        || stripos($body, 'verify') !== false) {
        $r['_waf_challenge'] = true;
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
function oauth_exchange_code($code, $code_verifier = '', $attempts = 2) {
    $post = http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => CLIENT_ID,
        'client_secret' => CLIENT_SECRET,
        'redirect_uri'  => REDIRECT_URI,
        'code_verifier' => $code_verifier,   // 新版 OAuth 强制 PKCE，换码必须带上
    ]);
    $last = null;
    for ($i = 0; $i < $attempts; $i++) {
        $r    = waf_fetch(OAUTH_TOKEN, 'POST', $post, ['Content-Type: application/x-www-form-urlencoded']);
        $http = isset($r['http']) ? (int)$r['http'] : 0;
        $data = json_decode($r['body'], true);
        // WAF 挑战页（无 JS 无法绕过）：直接给出可诊断错误，不做无意义重试
        if (!empty($r['_waf_challenge'])) {
            return ['error' => 'waf_challenge',
                    'error_description' => '令牌端点被 WAF 拦截（需浏览器 JS 校验）。请检查授权服务器是否对服务器间调用开放白名单。'];
        }
        // 可重试：网络错误 / 5xx / 空响应（瞬时故障）；4xx 与明确 JSON 错误不重试
        $retryable = (!empty($r['error']) || $http >= 500
            || !is_array($data) || ($http === 0 && trim((string)$r['body']) === ''));
        if (!$retryable) {
            return is_array($data)
                ? $data
                : ['error' => 'bad_response', 'error_description' => '令牌端点返回非 JSON（HTTP ' . $http . '）：' . substr($r['body'], 0, 120)];
        }
        $last = ['error' => 'bad_response', 'error_description' => '令牌端点暂不可用（第' . ($i + 1) . '次尝试），正在重试…'];
        if ($i < $attempts - 1) usleep(400000); // 退避 400ms
    }
    return $last ?: ['error' => 'bad_response', 'error_description' => '令牌端点多次重试仍失败'];
}

/**
 * 用 access_token 拉取用户信息。
 * 注意：新版 OAuth 服务器已移除「URL 查询参数传令牌」（?access_token=），
 * 令牌只能经 Authorization: Bearer 头传递，否则会被拒绝。
 */
function oauth_userinfo($access_token, $attempts = 2) {
    $url = OAUTH_USERINFO;   // 不再拼接 ?access_token=，仅用 Bearer 头
    $last = null;
    for ($i = 0; $i < $attempts; $i++) {
        $r    = waf_fetch($url, 'GET', null, ["Authorization: Bearer $access_token"]);
        $http = isset($r['http']) ? (int)$r['http'] : 0;
        $data = json_decode($r['body'], true);
        if (!empty($r['_waf_challenge'])) {
            return ['error' => 'waf_challenge', 'error_description' => '用户信息端点被 WAF 拦截'];
        }
        $retryable = (!empty($r['error']) || $http >= 500
            || !is_array($data) || ($http === 0 && trim((string)$r['body']) === ''));
        if (!$retryable) {
            if (isset($data['error'])) return $data; // 如 invalid_token（明确错误，不重试）
            return is_array($data) ? $data : ['error' => 'bad_response', 'error_description' => '用户信息端点返回非 JSON'];
        }
        $last = ['error' => 'bad_response', 'error_description' => '用户信息端点暂不可用（第' . ($i + 1) . '次尝试），正在重试…'];
        if ($i < $attempts - 1) usleep(400000);
    }
    return $last ?: ['error' => 'bad_response', 'error_description' => '用户信息端点多次重试仍失败'];
}

/**
 * 判断链接属于哪个平台，映射到上游路径。
 */
function detect_platform($url) {
    $u = strtolower($url);
    if (strpos($u, 'bilibili') !== false || strpos($u, 'b23.tv') !== false) return 'bilibili';
    if (strpos($u, 'douyin') !== false || strpos($u, 'iesdouyin') !== false) return 'douyin';
    if (strpos($u, 'tiktok') !== false) return 'tiktok';
    if (strpos($u, 'kuaishou') !== false || strpos($u, 'gifshow') !== false || strpos($u, 'chenzhongtech') !== false) return 'ks';
    if (strpos($u, 'xiaohongshu') !== false || strpos($u, 'xhslink') !== false) return 'xhs';
    if (strpos($u, 'ixigua') !== false || strpos($u, 'xigua') !== false) return 'xigua';
    if (strpos($u, 'weibo') !== false) return 'weibo';
    if (strpos($u, 'zhihu') !== false) return 'zhihu';
    if (strpos($u, 'youtu') !== false) return 'youtube';
    if (strpos($u, 'twitter') !== false || strpos($u, 'x.com') !== false) return 'twitter';
    if (strpos($u, 'channels.weixin') !== false || strpos($u, 'finder') !== false) return 'wechat';
    return 'douyin'; // 默认按抖音处理
}

/**
 * 调用上游解析并归一化结果。
 * 核心改进：多源“并行竞速”（curl_multi）——同时向 本地解析器 / B站官方 / bugpk / douyin.wtf
 * 发请求，谁先成功就用谁，彻底避免“等挂掉的源超时”导致的慢。
 *  - B站：第一方官方接口（api.bilibili.com）直连优先，失败再并行尝试 本地解析器 + bugpk
 *  - 抖音/快手/小红书/TikTok：本地解析器(若配置) → bugpk → douyin.wtf，并行竞速
 *  $quality : 前端画质偏好（auto/720/1080/4k），仅对 B站生效
 */
function proxy_parse($url, $quality = 'auto') {
    $platform = detect_platform($url);
    $qn = quality_to_qn($quality);

    // 当前已支持解析的平台
    $supported = ['bilibili', 'douyin', 'ks', 'xhs', 'tiktok'];
    if (!in_array($platform, $supported, true)) {
        return ['success' => false, 'msg' => '暂不支持该平台（' . platform_label($platform) . '）。当前支持：抖音 / 快手 / 小红书 / B站 / TikTok'];
    }

    // B站：官方接口优先（香港等可直连 B站 的主机能拿到 duration + 高清合流），
    // 官方失败（被风控/慢/超时）则快速回退到 bugpk + 本地解析器并行竞速。
    // 关键点：官方接口用短超时（8s），避免在香港偶发慢或被风控时拖死解析——
    // 一旦官方超时/失败，立刻交给 bugpk（多源竞速，谁先成功用谁）。
    if ($platform === 'bilibili') {
        // 1) 官方直连优先（短超时，成功则 duration/画质最全）
        $r = bili_parse($url, BILI_COOKIE, $qn, 8);
        if ($r['success']) return $r;

        // 2) 官方失败 → bugpk + 本地解析器并行竞速（谁先成功用谁）
        $jobs = [];
        if (LOCAL_PARSER_URL) {
            $jobs[] = ['src' => 'local', 'url' => local_url($url),
                       'normalize' => function ($b, $h, $e) use ($url) { return local_parse($url, 'bilibili', $b, $h, $e); }];
        }
        $jobs[] = ['src' => 'bugpk', 'url' => bugpk_url($url, 'bilibili'),
                   'normalize' => function ($b, $h, $e) use ($url) { return bugpk_normalize($b, $h, $e, $url, 'bilibili'); }];
        $race = race_sources($jobs, $url);
        if ($race) return $race;

        // 3) bugpk 偶发 429 满载（retry_after≈3s）：短暂等待后重试一次
        usleep(600000);
        $r2 = bugpk_parse($url, 'bilibili');
        if ($r2['success']) return $r2;

        $tried = array_map('source_label', array_merge(['bilibili-official'], array_column($jobs, 'src')));
        return ['success' => false, 'msg' => 'Bilibili 解析失败：官方接口与第三方源均不可用。已尝试：' . implode('、', $tried) . '。请稍后重试，或在 config.php 配置 LOCAL_PARSER_URL 自托管解析'];
    }

    // 抖音 / 快手 / 小红书 / TikTok：多源并行竞速（谁先成功用谁，显著提速）
    $jobs = [];
    if (LOCAL_PARSER_URL) {
        $jobs[] = ['src' => 'local', 'url' => local_url($url),
                   'normalize' => function ($b, $h, $e) use ($url, $platform) { return local_parse($url, $platform, $b, $h, $e); }];
    }
    $jobs[] = ['src' => 'bugpk', 'url' => bugpk_url($url, $platform),
               'normalize' => function ($b, $h, $e) use ($url, $platform) { return bugpk_normalize($b, $h, $e, $url, $platform); }];
    if ($platform === 'douyin' || $platform === 'tiktok') {
        $jobs[] = ['src' => 'douyinwtf', 'url' => dywtf_url($url),
                   'normalize' => function ($b, $h, $e) use ($url) { return douyinwtf_normalize($b, $h, $e, $url); }];
    }
    $race = race_sources($jobs, $url);
    if ($race) return $race;

    $tried = array_map('source_label', array_column($jobs, 'src'));
    return ['success' => false,
            'msg' => '所有解析源暂时不可用（可能第三方接口限流）。已尝试：' . implode('、', $tried) . '。请稍后重试，或在 config.php 配置自托管 LOCAL_PARSER_URL'];
}

/**
 * 前端画质偏好 -> B站 qn 等级
 *  auto: 127（请求最高可用）  720: 64  1080: 80  4k: 127(配合 fourk)
 */
function quality_to_qn($quality) {
    switch ($quality) {
        case '720':  return 64;
        case '1080': return 80;
        case '4k':   return 127;
        case 'auto':
        default:     return 127;
    }
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
    $r = do_curl(bugpk_url($url, $platform), 'GET');
    return bugpk_normalize($r['body'], $r['http'], $r['error'], $url, $platform);
}
function bugpk_normalize($body, $http, $err, $url, $platform) {
    if ($err || $http == 429) {
        return ['success' => false, 'msg' => '解析源(bugpk)：' . ($err ?: '限流满载'), '_rate' => ($http == 429)];
    }
    $raw = json_decode($body, true);
    if (!is_array($raw) || ($raw['code'] ?? -1) != 200 || empty($raw['data'])) {
        return ['success' => false, 'msg' => '解析源(bugpk)：' . ($raw['msg'] ?? ($raw['message'] ?? '未能解析'))];
    }
    return build_result($raw['data'], $url, $platform, 'bugpk');
}

/**
 * douyin.wtf 兜底解析（开源，支持 抖音/TikTok 混合）。
 */
function douyinwtf_parse($url) {
    $r = do_curl(dywtf_url($url), 'GET');
    return douyinwtf_normalize($r['body'], $r['http'], $r['error'], $url);
}
function douyinwtf_normalize($body, $http, $err, $url) {
    if ($err) return ['success' => false, 'msg' => 'douyin.wtf：' . $err];
    $raw = json_decode($body, true);
    if (!is_array($raw)) return ['success' => false, 'msg' => 'douyin.wtf：返回异常'];
    $video = $raw['nwm_video_url'] ?? $raw['video_url'] ?? ($raw['video'] ?? '');
    if (!$video) return ['success' => false, 'msg' => 'douyin.wtf：未获取到视频地址'];
    $d = $raw;
    $d['url'] = $video;   // 统一字段，交给 build_result
    return build_result($d, $url, detect_platform($url), 'douyinwtf');
}

// ===================== Bilibili 官方接口解析 =====================
function bili_headers($cookie = '') {
    $h = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Referer: ' . BILI_REFERER,
        'Accept: application/json, text/plain, */*',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
    ];
    // B站 view/playurl 接口对未登录请求仍建议带 buvid3，降低被风控「啥都木有」的概率
    if ($cookie !== '') {
        $h[] = 'Cookie: ' . $cookie;
    } else {
        $h[] = 'Cookie: buvid3=' . (defined('BILI_BUVID3') && BILI_BUVID3 ? BILI_BUVID3 : '00000000-0000-0000-0000-000000000000infoc');
    }
    return $h;
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
 * 通过 Bilibili 官方接口（api.bilibili.com，第一方直连，不依赖任何第三方聚合器）解析视频，
 * 返回合流直链（音视频合一，便于剪辑）。这是 B站解析的“本地/官方直连”主路。
 *  $cookie : 传入 SESSDATA 可解锁 1080P/4K 等高清（仍保持合流）
 *  $qn     : 目标画质等级（用户在前端自选），服务端按账号权限回落到可用最高
 */
function bili_parse($url, $cookie = BILI_COOKIE, $qn = 127, $timeout = 8) {
    $id = bili_resolve($url);
    if (!$id) return ['success' => false, 'msg' => '无法识别 Bilibili 视频链接'];
    $param = isset($id['bvid']) ? ('bvid=' . $id['bvid']) : ('aid=' . $id['aid']);
    $view  = do_curl(BILI_VIEW . '?' . $param, 'GET', null, bili_headers($cookie), '', $timeout);
    $v = json_decode($view['body'], true);
    if (!is_array($v) || ($v['code'] ?? -1) != 0) {
        return ['success' => false, 'msg' => 'Bilibili 信息接口异常：' . ($v['message'] ?? '未知错误')];
    }
    $d     = $v['data'];
    $cid   = $d['cid'];
    $title = $d['title'] ?? '';
    // B站 view 接口返回的 pic 是 http:// 前缀，https 站点直接引用会被浏览器判为
    // “混合内容”拦截（封面永远不显示）。这里统一升级为 https，并追加尺寸参数
    // @672w_378h_1c 取 16:9 高清封面，去掉可能干扰的旧尺寸标记。
    $cover = $d['pic'] ?? '';
    if ($cover) {
        if (strpos($cover, 'http://') === 0) $cover = 'https://' . substr($cover, 7);
        if (strpos($cover, '?') === false && strpos($cover, '@') === false) {
            $cover = rtrim($cover, '/') . '@672w_378h_1c.webp';
        }
    }
    $dur   = $d['duration'] ?? 0;
    // fnval=0 取合流直链（音视频合一，便于下载与剪辑），fourk=1 允许 4K
    $pl = do_curl(BILI_PLAYURL . '?' . $param . '&cid=' . $cid . '&qn=' . (int)$qn . '&fnval=0&fourk=1', 'GET', null, bili_headers($cookie), '', $timeout);
    $p = json_decode($pl['body'], true);
    if (!is_array($p) || ($p['code'] ?? -1) != 0 || empty($p['data']['durl'])) {
        return ['success' => false, 'msg' => 'Bilibili 获取直链失败：' . ($p['message'] ?? '未知错误')];
    }
    $video = $p['data']['durl'][0]['url'];
    // B站 playurl 的 durl[].url 可能是 http:// 前缀；https 站点里浏览器/下载器对
    // http 直链会降级或限速，统一升级为 https（B站 CDN 同时支持 http/https）。
    if (strpos($video, 'http://') === 0) $video = 'https://' . substr($video, 7);
    $accept = $p['data']['accept_quality'] ?? [];
    // 若用户自选的画质高于当前账号/游客可用上限，给提示（如游客选 1080P 实际回落 720P）
    $qnote = '';
    if (!empty($accept) && (int)$qn > max(array_map('intval', $accept))) {
        $qnote = $cookie !== ''
            ? '当前账号暂无更高画质，已返回可用最高画质'
            : '未配置登录 Cookie，B站游客合流直链最高 720P；如需 1080P/4K 请在 config.php 配置 BILI_COOKIE（SESSDATA）';
    }
    return [
        'success'    => true,
        'platform'   => 'bilibili',
        'video_url'  => $video,
        'cover_url'  => $cover,
        'title'      => $title,
        'duration'   => $dur,
        'source'     => 'bilibili-official',   // 官方直连
        'quality_note' => $qnote,
    ];
}

// ============================================================
//  自托管“本地化”解析器 + 多源并行竞速
// ============================================================

function bugpk_url($url, $platform) {
    $path = BUGPK_PLAT[$platform] ?? $platform;
    return BUGPK_BASE . '/' . $path . '/?url=' . urlencode($url);
}
function dywtf_url($url) {
    return DOUYINWTF_BASE . '?url=' . urlencode($url) . '&minimal=true';
}
function local_url($url) {
    return rtrim(LOCAL_PARSER_URL, '/') . '/?url=' . urlencode($url);
}

/**
 * 自托管“本地化/第一方”解析器：返回统一结构。
 * 兼容两种返回：{code:200,data:{url|video_url,...}} 或本系统统一结构。
 */
function local_parse($url, $platform, $body = null, $http = 0, $err = '') {
    if (!LOCAL_PARSER_URL) return ['success' => false, 'msg' => '未配置 LOCAL_PARSER_URL'];
    if ($body === null) {
        $r = do_curl(local_url($url), 'GET');
        $body = $r['body']; $http = $r['http']; $err = $r['error'];
    }
    if ($err || ($http && $http >= 500)) return ['success' => false, 'msg' => '本地解析器：' . ($err ?: '服务端错误')];
    $raw = json_decode($body, true);
    if (!is_array($raw)) return ['success' => false, 'msg' => '本地解析器返回异常'];
    $code = isset($raw['code']) ? $raw['code'] : (isset($raw['status']) ? $raw['status'] : 200);
    $d = (isset($raw['data']) && is_array($raw['data'])) ? $raw['data'] : $raw;
    if (($code != 200 && $code != 0 && $code != 1) || empty($d)) {
        return ['success' => false, 'msg' => '本地解析器：' . ($raw['msg'] ?? $raw['message'] ?? '未返回数据')];
    }
    $video = $d['url'] ?? $d['video_url'] ?? ($raw['nwm_video_url'] ?? ($raw['video'] ?? ''));
    if (!$video && !empty($d['video_backup'][0]['url'])) $video = $d['video_backup'][0]['url'];
    if (!$video) return ['success' => false, 'msg' => '本地解析器未获取到视频地址'];
    $dd = $d; $dd['url'] = $video;
    return build_result($dd, $url, $platform, 'local');
}

/**
 * 统一字段提取：各源（bugpk / douyin.wtf / 本地解析器）归一化为本系统结构。
 */
function build_result($d, $url, $platform, $source, $extra = []) {
    $video = $d['url'] ?? $d['video_url'] ?? '';
    if (!$video && !empty($d['video_backup'][0]['url'])) $video = $d['video_backup'][0]['url'];
    // 视频直链统一升级 https：http 直链在 https 站点会触发混合内容拦截/浏览器降级限速。
    if ($video && strpos($video, 'http://') === 0) $video = 'https://' . substr($video, 7);
    $author = '';
    if (isset($d['author']))          $author = is_array($d['author']) ? ($d['author']['name'] ?? '') : (string)$d['author'];
    elseif (isset($d['auther']))       $author = (string)$d['auther'];
    elseif (isset($d['author_name']))  $author = (string)$d['author_name'];
    $music = '';
    if (isset($d['music']) && is_array($d['music'])) $music = $d['music']['url'] ?? '';
    elseif (isset($d['music']) && is_string($d['music'])) $music = $d['music'];
    // 时长单位智能判断：> 1000 视为毫秒，否则视为秒（各上游返回单位不一，bugpk 返秒、抖音返毫秒）
    $duration = 0;
    if (isset($d['duration']) && is_numeric($d['duration'])) {
        $dv = (float)$d['duration'];
        $duration = $dv > 1000 ? round($dv / 1000, 1) : round($dv, 1);
    }
    // 封面提取：兼容各上游的多种字段名与嵌套形式（cover 可能为字符串、数组或嵌套对象）。
    $cover = '';
    if (isset($d['cover'])) {
        if (is_string($d['cover'])) $cover = $d['cover'];
        elseif (is_array($d['cover'])) $cover = $d['cover']['url'] ?? $d['cover']['url_list'][0] ?? '';
    }
    if (!$cover) $cover = $d['poster'] ?? '';
    if (!$cover) $cover = $d['cover_url'] ?? '';
    if (!$cover) $cover = $d['images'] ?? $d['image'] ?? $d['thumb'] ?? $d['thumbnail'] ?? '';
    if (!$cover && !empty($d['video_cover'])) $cover = $d['video_cover'];
    if (is_array($cover)) $cover = $cover['url'] ?? ($cover[0] ?? '');
    // https 站点引用 http 封面会被浏览器判为“混合内容”拦截（封面不显示）。
    // 对 B站图床(hdslb/biliimg)及常见图床统一升级为 https（这些 CDN 均支持 https）。
    if ($cover) {
        if (strpos($cover, 'http://') === 0) $cover = 'https://' . substr($cover, 7);
    }
    $r = [
        'success'   => true,
        'platform'  => $platform,
        'video_url' => $video,
        'cover_url' => (string)$cover,
        'title'     => $d['title'] ?? ($d['desc'] ?? ($d['description'] ?? '')),
        'author'    => $author,
        'music_url' => $music,
        'duration'  => $duration,
        'source'    => $source,
    ];
    return array_merge($r, $extra);
}

/**
 * 多源并行竞速核心：用 curl_multi 同时发起多个 HTTP GET，
 * 谁先成功就用谁（命中即提前关闭其余句柄），显著加快连接速度。
 */
function multi_fetch($jobs, $timeout = 15) {
    if (empty($jobs)) return ['first' => null, 'all' => []];
    $mh = curl_multi_init();
    $handles = []; $map = [];
    foreach ($jobs as $i => $job) {
        $ch = curl_init($job['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; WatermarkTool/2.0)',
        ]);
        $h = $job['headers'] ?? [];
        if (!empty($job['cookie'])) $h[] = 'Cookie: ' . $job['cookie'];
        if ($h) curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch; $map[(int)$ch] = $i;
    }
    $running = null; $done = []; $first = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.15);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $i  = $map[(int)$ch] ?? null;
            if ($i === null) continue;
            $body = curl_multi_getcontent($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            $res  = call_user_func($jobs[$i]['normalize'], $body, $http, $err);
            $res['_src'] = $jobs[$i]['src'];
            $done[$i] = $res;
            if (!empty($res['success']) && $first === null) $first = $res;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($handles[$i]);
            if ($first !== null) break;
        }
    } while ($running > 0 && $first === null);
    foreach ($handles as $ch) { curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh);
    return ['first' => $first, 'all' => $done];
}

/**
 * 多源竞速 + 负缓存：跳过近期限流/失败的源，命中即用。
 */
function race_sources($jobs, $url) {
    $active = [];
    foreach ($jobs as $job) {
        // 负缓存：该源近期失败，跳过（不再浪费时间轮询死源）
        if (ncache_get('neg_' . md5($job['src'] . '|' . $url)) !== null) continue;
        $active[] = $job;
    }
    if (empty($active)) return null;
    $out = multi_fetch($active, PARSE_RACE_TIMEOUT);
    // 失败且确为错误的源写入负缓存，短期內不再优先尝试
    foreach ($out['all'] as $i => $res) {
        $src = $active[$i]['src'] ?? null;
        if ($src && empty($res['success'])) ncache_set('neg_' . md5($src . '|' . $url), 1, PARSE_NEG_CACHE);
    }
    if ($out['first']) { unset($out['first']['_src']); return $out['first']; }
    return null;
}

function platform_label($p) {
    return [
        'bilibili' => 'B站', 'douyin' => '抖音', 'ks' => '快手', 'xhs' => '小红书', 'tiktok' => 'TikTok',
        'xigua' => '西瓜视频', 'weibo' => '微博', 'zhihu' => '知乎', 'youtube' => 'YouTube',
        'twitter' => 'X/Twitter', 'wechat' => '微信视频号',
    ][$p] ?? $p;
}
function source_label($s) {
    return [
        'local' => '本地解析器', 'bugpk' => '第三方聚合(bugpk)', 'douyinwtf' => '开源(douyin.wtf)',
        'bilibili-official' => 'B站官方直连',
    ][$s] ?? $s;
}

// 失败源负缓存（独立于解析结果缓存，避免依赖 wm_api.php 的缓存函数）
function ncache_dir() {
    $d = sys_get_temp_dir() . '/wm_ncache';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}
function ncache_get($k) {
    $f = ncache_dir() . '/' . $k . '.json';
    if (!is_file($f)) return null;
    $exp = @filemtime($f);
    if ($exp === false || time() >= $exp) return null;
    $v = @file_get_contents($f);
    return $v === false ? null : $v;
}
function ncache_set($k, $v, $ttl) {
    $f = ncache_dir() . '/' . $k . '.json';
    $ok = @file_put_contents($f, $v);
    if ($ok !== false) @touch($f, time() + (int)$ttl);
}

// 解析结果短缓存（降低对上游的重复调用；与 ncache 负缓存相互独立）
function cache_dir() {
    $d = sys_get_temp_dir() . '/wm_cache';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}
function cache_get($key) {
    $f = cache_dir() . '/' . preg_replace('/[^a-z0-9_]/i', '', $key) . '.cache';
    if (!is_file($f)) return null;
    if (time() >= @filemtime($f)) return null;          // 已过期
    $raw = @file_get_contents($f);
    if ($raw === false) return null;
    $v = @json_decode($raw, true);
    return is_array($v) ? $v : null;
}
function cache_set($key, $val, $ttl = 600) {
    $f = cache_dir() . '/' . preg_replace('/[^a-z0-9_]/i', '', $key) . '.cache';
    $ok = @file_put_contents($f, json_encode($val, JSON_UNESCAPED_UNICODE));
    if ($ok !== false) @touch($f, time() + (int)$ttl);
    return $ok !== false;
}
