# 迁移到 ijerrt.cn 说明

## 本次域名变更

| 角色 | 旧域名 | 新域名 |
| --- | --- | --- |
| OAuth 授权服务器 | `jerrt-idol.kesug.com` | `api.ijerrt.cn` |
| 解析系统（本包部署域名） | `jerrt.html-5.me` | `vplayer.ijerrt.cn` |

## 已在本包内完成的替换

- **config.php**
  - `OAUTH_AUTHORIZE` / `OAUTH_TOKEN` / `OAUTH_USERINFO` / `OAUTH_PROFILE` 全部指向 `http://api.ijerrt.cn`
  - `REDIRECT_URI` 改为 `http://vplayer.ijerrt.cn/wm_callback.php`
  - WAF 绕过注释同步指向 api.ijerrt.cn
- **index.html**
  - `OAUTH_CENTER`（个人中心）改为 `http://api.ijerrt.cn/oauth/oauth.php?action=profile`
  - 页脚会员中心链接同步更新
- **README.md / cloudflare-worker/README.md**：所有示例域名已更新为 ijerrt.cn

> 注：`wm_api.php` / `wm_callback.php` / `ffmpeg_proxy.php` 不写死解析域名（前端用相对路径 `wm_api.php` 调用），因此无需改动。

## 部署前必须完成（否则登录会失败）

1. 登录 **`http://api.ijerrt.cn/oauth/oauth-admin.php`**，新建应用，回调地址填：
   `http://vplayer.ijerrt.cn/wm_callback.php`
2. 把新应用生成的 **`CLIENT_ID` / `CLIENT_SECRET`** 填入 `config.php`（当前文件里仍是旧服务器 `jerrt-idol.kesug.com` 的占位值，直接上传会导致“客户端密钥错误”）。
3. 确认 `api.ijerrt.cn` 的 WAF / 反爬机制：
   - 若仍要求 `__test` cookie，`config.php` 里的 `WAF_COOKIE_CACHE` 可能需更新为新服务器算出的当前值；
     `waf_fetch()` 在挑战变化时支持自动回退重算，一般无需手动改。
4. 将本包文件上传到 **`vplayer.ijerrt.cn` 根目录**（不要套子目录，`wm_callback.php` 必须正好位于 `/wm_callback.php`）。
5. 浏览器 **硬刷新**（Ctrl+Shift+R / 移动端强刷）访问 `http://vplayer.ijerrt.cn/`。

## 重要提醒

- 旧 `CLIENT_ID = cid_24d364828ec4` 是绑定在 `jerrt-idol.kesug.com` 后台的应用，**在 `api.ijerrt.cn` 上无效**，必须按第 2 步重建并替换。
- 若登录后提示 “state 校验失败”，通常是 `wm_callback.php` 与 `index.html` 不在同一会话域 / `REDIRECT_URI` 与后台登记不一致（含协议 `http`、路径、末尾文件名）。
