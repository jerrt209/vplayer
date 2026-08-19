<?php
/**
 * OAuth 回调地址（授权码流程的“服务端换码”环节）
 * ------------------------------------------------------------
 *  OAuth 服务器在用户授权后，将浏览器重定向到本文件：
 *    wm_callback.php?code=XXXX&state=YYYY
 *  本文件负责：
 *    1. 校验 state（防 CSRF）
 *    2. 用 client_secret 向 oauth_token.php 换取 access_token
 *    3. 用 access_token 调 oauth.php?action=userinfo 取用户信息
 *    4. 写入本地 session 并跳回首页
 */

require_once __DIR__ . '/config.php';

$code  = trim($_GET['code']  ?? '');
$state = trim($_GET['state'] ?? '');

if ($code === '') {
    http_response_code(400);
    exit('缺少授权码（code），授权失败。');
}

// state 校验：必须与发起授权时本地保存的一致
if (empty($_SESSION['oauth_state']) || !hash_equals((string)$_SESSION['oauth_state'], $state)) {
    http_response_code(400);
    exit('state 校验失败，可能是跨站请求伪造，已拒绝。');
}

// 1) 用授权码换 token
$tokenResp = oauth_exchange_code($code);
$accessToken = pick($tokenResp, ['access_token', 'accessToken']);

if (!$accessToken) {
    $desc = $tokenResp['error_description'] ?? ($tokenResp['error'] ?? '未知错误');
    http_response_code(401);
    exit('登录失败：' . htmlspecialchars($desc, ENT_QUOTES) . '（请检查后台应用的 client_id / client_secret / redirect_uri 是否一致）');
}

// 2) 用 token 取用户信息
$userResp = oauth_userinfo($accessToken);
$username = pick($userResp, ['username', 'user', 'name', 'sub', 'uid', 'nickname']);

if ($username === null) {
    $desc = $userResp['error_description'] ?? ($userResp['error'] ?? '未知错误');
    http_response_code(401);
    exit('获取用户信息失败：' . htmlspecialchars($desc, ENT_QUOTES));
}

// 3) 写入本地会话
$_SESSION['oauth_user'] = [
    'username'     => $username,
    'access_token' => $accessToken,
    'raw'          => $userResp,
];
unset($_SESSION['oauth_state']);

// 4) 跳回首页（保留原来的跳转目标）
$next = $_SESSION['oauth_next'] ?? 'index.html';
unset($_SESSION['oauth_next']);

header('Location: ' . $next);
exit;
