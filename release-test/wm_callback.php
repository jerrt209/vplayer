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

// PKCE：取回发起授权时保存的 code_verifier，换码时必须一并提交
$verifier = $_SESSION['oauth_verifier'] ?? '';

// 1) 用授权码换 token（带上 PKCE verifier）
$tokenResp = oauth_exchange_code($code, $verifier);
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
unset($_SESSION['oauth_verifier']);

// 4) 完成页：自动关闭弹窗 + 通知父窗口刷新登录态。
//    不要直接跳 index.html —— 那会让弹窗停留在首页且永不自动关闭。
//    这里输出一个极简页面：立即向父窗口 postMessage({type:'oauth_done'})，
//    并尝试 window.close() 自关；若无法自关（整页跳转场景），自动跳回首页。
$next = $_SESSION['oauth_next'] ?? 'index.html';
unset($_SESSION['oauth_next']);
?>
<!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>登录成功</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0e0d22;color:#f5f2ff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC",sans-serif;}
.box{text-align:center;padding:32px;}
svg{display:block;margin:0 auto 16px;}
p{color:#bdb6e6;margin:0;}
</style>
</head><body>
<div class="box">
<svg width="52" height="52" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="11" fill="#30d158"/><path d="M7 12.4l3.3 3.3L17 9" stroke="#0a0a0a" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
<p>登录成功，正在返回…</p>
</div>
<script>
(function(){
  var next = <?php echo json_encode($next); ?>;
  // 1) 通知父窗口（若本窗口是被 window.open 打开的弹窗）
  try {
    if (window.opener && !window.opener.closed) {
      window.opener.postMessage({ type: 'oauth_done' }, '*');
    }
  } catch (e) {}
  // 2) 尝试自关（弹窗场景）；浏览器拒绝自关（非脚本打开的窗口）则跳回首页
  try {
    window.close();
  } catch (e) {}
  // 3) 兜底：若 window.close() 未生效（本页仍可见），短延时后跳回首页
  setTimeout(function(){ location.href = next; }, 600);
})();
</script>
</body></html>
