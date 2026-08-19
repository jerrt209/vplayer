# 去水印小程序 · 跨域 OAuth 登录版

一个**独立部署、不在同域**的短视频去水印工具，通过标准 **OAuth 2.0 授权码流程**接入你部署在
`https://api.ijerrt.cn/` 的 Jerrt 账号体系。

- 界面：液态玻璃 + 动态高光，配色克制（深色 + 紫蓝），对齐 OAuth 中心风格
- 仅允许 OAuth 登录用户长期使用；**未登录用户仅 1 次免费体验**
- 用完免费次数后弹出**仿爱豆模拟器的登录弹窗**（点按钮才跳转授权，不直接跳 OAuth 中心）
- 右上角「个人中心」按钮，跳转 Jerrt 会员中心
- 支持 抖音 / 快手 / 小红书 / TikTok / **Bilibili** 去水印
- 新增「在线分段剪辑」：解析后输入开始/结束时间，浏览器本地用 ffmpeg.wasm 截取任意片段并下载

---

## 一、对接契约（来自 OAUTH.md）

| 用途 | 地址 |
| --- | --- |
| 授权端点 | `https://api.ijerrt.cn/oauth/oauth.php?action=authorize` |
| 令牌端点 | `https://api.ijerrt.cn/oauth/oauth_token.php` |
| 用户信息 | `https://api.ijerrt.cn/oauth/oauth.php?action=userinfo`（**仅 `Authorization: Bearer <token>` 头**，新版已移除 `?access_token=` 查询参数用法） |
| 个人中心 | `https://api.ijerrt.cn/oauth/oauth.php?action=profile` |
| 管理后台 | `https://api.ijerrt.cn/oauth/oauth-admin.php` |

> **新版 OAuth 适配（破坏性升级）**：授权服务器升级后强制 **PKCE（S256）** 并要求 `state` 防 CSRF；
> 用户信息端点**不再接受 URL 令牌**，仅认 `Authorization: Bearer` 头。本包已同步适配：
> `login_url` 生成 `code_verifier`/`code_challenge`（S256）并存入会话；`wm_callback.php` 换码时补交 `code_verifier`；
> `oauth_userinfo()` 改为仅用 Bearer 头。令牌端点仍用 `client_id`+`client_secret` 表单（client_secret_post），与新服务器兼容。

> 令牌端点要求 `client_id` + `client_secret`；用户信息端点要求 `Bearer` 令牌。两端点都做了
> api.ijerrt.cn 若启用 JS 反爬挑战（`__test` cookie），本包已在服务端用 PHP `openssl` 自动绕过（见第三节）。

---

## 二、授权流程（跨域，标准授权码）

```
浏览器(你的域名)                你的服务器(wm_callback.php)              Jerrt OAuth 服务器
     │                                   │                                   │
     │ 1. 点“前往登录” → 拿 authorizeUrl  │                                   │
     │ ─────────────────────────────────>│                                   │
     │ 2. 302 跳转 authorize?...&state=    │                                   │
     │ ────────────────────────────────────────────────────────────────────>│
     │ 3. 用户在 Jerrt 登录并授权           │                                   │
     │ <── 302 跳转 你的域名/wm_callback.php?code=..&state=.. ────────────────│
     │                                   │ 4. 校验 state                      │
     │                                   │ 5. POST oauth_token.php (带secret) │
     │                                   │ ──────────────────────────────────>│
     │                                   │ 6. <── access_token                │
     │                                   │ 7. GET userinfo (Bearer) ─────────>│
     │                                   │ 8. <── 用户信息                     │
     │                                   │ 9. 写入本地 session                 │
     │ <── 302 跳转 index.html ──────────│                                   │
     │ 10. 调 wm_api.php?action=check → 已登录 │                               │
```

关键点：跨域部署**无法复用同域共享会话**，因此本包用授权码流程——
由 `wm_callback.php` 在服务端用 `client_secret` 换 token、再用 token 取用户信息，
最后存到**你自己域名下的 PHP 会话**里。整个过程 `client_secret` 只存在于服务端，不暴露给前端。

---

## 三、WAF 绕过说明（重要）

Jerrt OAuth 服务器（api.ijerrt.cn）若对 `oauth_token.php` 与 `oauth.php?action=userinfo` 启用了
JS 反爬挑战：未带 `__test` cookie 时返回一段用 `aes.js + slowAES` 算 cookie 的 HTML，而非 JSON。
因为“服务端调用服务端”没有浏览器 JS 来算 cookie，本包在 `config.php` 里用 PHP 复现了该算法：

```php
// AES-128-CBC 解密挑战参数 a/b/c，得到 __test cookie 值
$pt = openssl_decrypt(hex2bin($c), 'AES-128-CBC', hex2bin($a), OPENSSL_RAW_DATA, hex2bin($b));
```

- 挑战参数在服务器上是**固定的**，所以 `config.php` 里预置的 `WAF_COOKIE_CACHE` 长期有效；
- 若服务器更换挑战，`waf_fetch()` 会自动从返回的 HTML 重新解析并计算，无需改代码。

---

## 三·五、授权链路稳定性加固（本次新增）

针对“授权码换 token / 取用户信息 / 呼吸灯探测”三处做了容错，避免瞬时抖动把用户踢下线或把状态灯卡死：

- **后端换码与取信息加重试退避**：`oauth_exchange_code()` / `oauth_userinfo()` 对**网络错误、5xx、空响应**自动重试 1 次（间隔 400ms）；**4xx 与明确 JSON 错误（如 `invalid_token`）不重试**，避免无效重试拖慢登录。
- **后端已有 25s 超时**：`waf_fetch()` 全局 `CURLOPT_TIMEOUT=25`，OAuth 调用不会无限挂起。
- **前端 `api()` 加 12s `AbortController` 超时**：即便后端偶发慢响应，前端也不会长时间阻塞在 `await`。
- **前端 `checkOAuth()` 加“调用中锁”**：`setInterval(30s)` / `online` / `focus` 事件并发触发时不会堆叠 pending 请求；单次抖动后自动重试一次再决定是否翻红，避免瞬时失败误判“未连接”。
- **呼吸灯只反映授权中心可达性**（不校验登录态），语义明确；登录态仍由 `oauth_user` 会话保证。

> 注：本包已在 PHP 8.3 CLI 下完成**运行时验证**（非仅静态检查）：`php -l` 四个文件无语法错误；用 PHP 内置服务器 + curl 实测 `check / login_url / logout / watermark(无效·不支持·触网失败) / oauth_ping` 全部优雅返回、无 500；tokenizer 交叉扫描 0 个未定义函数调用；纯逻辑（`detect_platform` 全平台 / `build_result` / `pick` / `cache` / `ncache`）运行时冒烟全绿。
> **本次修复的生产级 bug**：`watermark` 动作依赖的 `cache_get()/cache_set()` 此前**从未定义**，会导致所有进入解析缓存逻辑的路径（即真实解析请求）直接 500 空响应；已补齐文件型结果缓存（带 TTL）。前端测试因打桩未触达该路径，故此前未被发现——这正是缺少真实 PHP 运行时的代价。

---

## 四、部署步骤（本次已为你配好）

> **迁移说明**：应用域名已迁移至解析系统 `https://vplayer.ijerrt.cn`，OAuth 授权服务器为 `https://api.ijerrt.cn`。
> 部署前请确认已在 `api.ijerrt.cn` 后台用新回调 `https://vplayer.ijerrt.cn/wm_callback.php` 重建应用，
> 并把对应的 `CLIENT_ID` / `CLIENT_SECRET` 写入 `config.php`。
> 直接把下面文件传到 `https://vplayer.ijerrt.cn/` 根目录即可。

### 1) 上传到根目录
把以下文件**直接放到 `https://vplayer.ijerrt.cn/` 根目录**（不要套子目录）：
```
index.html        →  https://vplayer.ijerrt.cn/index.html
config.php        →  https://vplayer.ijerrt.cn/config.php
wm_api.php        →  https://vplayer.ijerrt.cn/wm_api.php
wm_callback.php   →  https://vplayer.ijerrt.cn/wm_callback.php
README.md         →  https://vplayer.ijerrt.cn/README.md
```
要求：PHP ≥ 7.0，且启用 `openssl`、`curl` 扩展（绝大多数主机默认启用）。

> 注意：`wm_callback.php` 是回调地址，必须正好位于根目录（`/wm_callback.php`），
> 否则与后台登记、以及 `config.php` 里的 `REDIRECT_URI` 不一致，授权会失败。

### 2) 访问
打开 `https://vplayer.ijerrt.cn/` 即可。

### 3)（可选）换其他域名 / 子目录
若以后改部署位置，需两步保持一致：
1. 登录 `https://api.ijerrt.cn/oauth/oauth-admin.php`，删除原 `去水印工具` 应用，
   用新回调地址 `https://新域名/路径/wm_callback.php` 重建（后台只能停用/删除，无就地编辑）。
2. 把 `config.php` 的 `CLIENT_ID` / `CLIENT_SECRET` / `REDIRECT_URI` 改成新值。

---

## 五、文件清单

| 文件 | 作用 |
| --- | --- |
| `index.html` | 前端界面（液态玻璃蓝调 + 登录弹窗 + 解析结果 + 分段剪辑） |
| `config.php` | 全部配置 + WAF 绕过 + OAuth/Bilibili 辅助函数 |
| `wm_api.php` | 前端接口：`check` / `login_url` / `logout` / `watermark` / `proxy_video` |
| `wm_callback.php` | OAuth 回调：换 token → 取用户 → 写会话 → 跳转 |
| `README.md` | 本文档 |

### 功能特性
- **多平台解析**：抖音 / TikTok / 快手 / 小红书 / B站（官方直连第一方），并识别西瓜、微博、知乎、YouTube、X、视频号。
- **多源并行竞速**：`curl_multi` 同时请求多个解析源，谁先成功用谁；失败源负缓存，连接更快更稳。
- **本地化 / 第一方**：可配置 `LOCAL_PARSER_URL` 指向你自托管的解析服务（推荐 Cloudflare Worker），公共接口仅作兜底。
- **批量解析**：每行一个链接，并发解析并逐条展示结果（复制 / 下载）。
- **历史记录**：本地保存最近 30 条解析记录，一键载入重解析。
- **明暗主题**：一键切换并持久化。
- **平台实时识别**：输入时即时显示识别到的平台。
- **在线分段剪辑**：浏览器本地 ffmpeg.wasm 截取 2–3 段，支持倍速。
- **性能优化**：边缘预连接（preconnect/dns-prefetch）、ffmpeg 空闲预热、视频封面懒加载，不砍动效。

---

## 五·二、平台与解析说明

- **抖音 / 快手 / 小红书 / TikTok**：采用**多源并行竞速**架构（`curl_multi` 同时向多个源发请求，谁先成功用谁），不再“等挂掉的源超时”。默认源为 `api.bugpk.com` 与开源 `api.douyin.wtf`；若配置了 `LOCAL_PARSER_URL`（你自己的解析服务），则**优先走本地第一方**，公共接口仅作兜底。失败源会被短期“负缓存”，进一步提速。全部失败时错误信息会列出已尝试的源，便于排查。
- **Bilibili**：**服务端直连官方接口**（`api.bilibili.com` 的 `view` + `playurl`）解析，无需第三方，是 B站的“本地/第一方”主路。官方失败才回退并行尝试 本地解析器 + bugpk。返回音视频合一的直链，便于剪辑。
  - 要求你的主机能访问 `api.bilibili.com` 与 `bilivideo.com`（主流公共主机通常可以）。
  - 直链带时效（约 1 小时），请解析后尽快使用。
  - **清晰度说明**：B 站对未登录（游客）请求，合流直链最高仅 **720P**，这是 B 站接口限制而非解析 Bug。解析前可在界面「清晰度」选择器选 自动 / 720P / 1080P / 4K（仅对 B站生效，其他平台自动忽略）；若需 **1080P / 4K**，在 `config.php` 的 `BILI_COOKIE` 填入你登录后复制的 `SESSDATA`（格式 `SESSDATA=xxxx`），官方接口即会按账号权限返回更高画质，且仍保持音视频合一；Cookie 失效或游客选高画质时会自动回退 720P 并提示。结果区会显示「官方直连 / 本地解析 / 第三方备用」来源徽标。

> **彻底摆脱第三方依赖（推荐）**：第三方公共接口随时可能限流 / 关停（实测 `api.bugpk.com` 会返回 `429 满载`）。见 `cloudflare-worker/README.md`：部署一个 Cloudflare Worker（免费），它同时提供 **`/parse` 第一方解析**（B站官方直连 + 多源并行竞速 + 边缘缓存）与 **`/proxy` 视频代理**。把 Worker 的 `/parse` 地址填到 `config.php` 的 `LOCAL_PARSER_URL`，再把 Worker 地址填到前端 `const PROXY`，即可让解析与下载都走你自己的边缘节点。

---

## 五·三、在线分段剪辑

解析出视频后，在结果卡片底部输入**开始时间 / 结束时间**（格式 `mm:ss`，如 `02:30`），点击「截取片段并下载」：

1. 浏览器首次会从 CDN 加载 ffmpeg.wasm 引擎（单线程版，无需特殊响应头）。
2. 视频经 `wm_api.php?action=proxy_video` 服务端代理转发（添加 CORS 头，支持 Range），供 ffmpeg.wasm 在本地读取。
3. 用 `-ss 开始 -to 结束 -c copy` 流拷贝截取，生成 mp4 直接下载。

> 说明：剪辑在浏览器本地完成、不占用服务器算力；但大视频（如 10 分钟 480P）需先在内存中下载整段，单线程处理较慢，且依赖你主机到视频 CDN 的带宽。免费主机对大文件流的超时限制可能中断超长视频的代理。

> 安全：`proxy_video` 仅允许白名单内的视频 CDN 域名（见 `config.php` 的 `PROXY_ALLOW`），避免被当作开放代理滥用。

---

## 六、常见问题

**Q：未登录用户为什么只能 1 次？**
免费次数按浏览器会话（PHP session）计，默认 `FREE_TRIES=1`，可在 `config.php` 调整。
用完弹登录窗，登录后无限使用。

**Q：解析失败 / 上游无响应？**
默认上游是 `api.codelife.cc`（公共免费服务，稳定性有限）。可在 `config.php` 把
`UPSTREAM_BASE` 换成你自己的解析服务，`proxy_parse()` 已兼容多种返回字段。

**Q：登录后提示“state 校验失败”？**
通常是回调时 session 丢失（如跨子域、或 `wm_callback.php` 与 `index.html` 不在同一会话域）。
确保两者在同一域名/目录下部署，且 `REDIRECT_URI` 与后台登记**完全一致**（含 `https`、路径、末尾文件名）。

**Q：登录后提示“客户端密钥错误”？**
`CLIENT_ID` / `CLIENT_SECRET` 与后台应用不一致，或 `redirect_uri` 不匹配。

**Q：token / userinfo 偶尔拿不到？**
若 Jerrt 更换了 WAF 挑战，`config.php` 会自动重算 cookie；如仍失败，刷新
`WAF_COOKIE_CACHE` 为最新值即可（一般无需操作）。

---

## 七、安全提示
- `client_secret` 仅存于服务端 `config.php`，切勿提交到公开仓库或前端。
- `wm_callback.php` 用 `state` + `hash_equals` 防 CSRF，并校验会话中的 state。
- 会话 cookie 已设 `HttpOnly` 与 `SameSite=Lax`；当前为 http 部署，请勿设 `Secure`（仅启用 https 时才应加 `Secure`）。
