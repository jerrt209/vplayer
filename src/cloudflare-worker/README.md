# 自托管 Cloudflare Worker（本地化解析 + 视频代理）

一个 Worker 同时提供两件「第一方 / 本地化」能力，彻底摆脱对第三方公共解析接口的单一依赖
（公共接口随时可能限流 / 关停，例如 bugpk 实测会返回 `429 满载`）：

1. **`/parse?url=<分享链接>` —— 第一方解析**
   - B站：**官方接口 `api.bilibili.com` 直连**，无需任何第三方、无需签名，返回合流直链。
   - 抖音 / 快手 / 小红书 / TikTok：**多源并行竞速**（你自托管的解析器 → bugpk → douyin.wtf），
     谁先成功用谁，并在边缘缓存 10 分钟。
2. **`/proxy?url=<视频直链>&name=<文件名>` —— 就近视频代理**
   - 跑在离国内 CDN 最近的边缘节点（香港 / 新加坡等），下载 / 剪辑从「跨洲中继」变「就近回源」，速度大幅提升。

免费、无需管理服务器。

## 文件
- `worker.js`      —— Worker 入口（解析 + 代理）
- `wrangler.toml`  —— 部署配置（含可选 `SELF_HOSTED`）

## 部署步骤（需要你自己的 Cloudflare 账号，免费）
1. 安装 Node.js 后执行：
   ```bash
   npx wrangler login        # 浏览器授权登录 Cloudflare
   npx wrangler deploy       # 部署到 workers.dev
   ```
2. 部署成功会得到地址，形如：`https://wm-proxy.<你的子域>.workers.dev`
3. （强烈推荐）**自托管一个 douyin.wtf**，把它的地址填到 `wrangler.toml` 的 `SELF_HOSTED`，
   重新 `npx wrangler deploy`。这样抖音/快手/小红书也能走你自己的「第一方」解析，不依赖公共接口：
   ```toml
   vars = { SELF_HOSTED = "https://你自己的-douyin-wtf.域名" }
   ```
   douyin.wtf 开源项目：https://github.com/Evil0ctal/Douyin_TikTok_Download_API

## 接入本系统
- **后端「本地化」解析**（推荐）：打开 `config.php`，把 `LOCAL_PARSER_URL` 改成 Worker 的 `/parse` 地址：
  ```php
  define('LOCAL_PARSER_URL', 'https://wm-proxy.<你的子域>.workers.dev/parse');
  ```
  此后系统解析会**优先走你自己的 Worker（第一方）**，公共接口仅作兜底，稳定性与可控性大幅提升。
- **前端加速下载 / 剪辑**：打开 `index.html`，把顶部 `const PROXY = ''` 改成你的 Worker 地址：
  ```js
  const PROXY = 'https://wm-proxy.<你的子域>.workers.dev';
  ```
- 两者都留空也不影响功能：解析回退到 PHP 多源竞速，下载回退到本站 `proxy_video`。

## 工作原理
- 解析：`/parse` 识别平台 → B站走官方直连；其余走多源并行竞速 + 边缘缓存，返回与本系统统一的
  `{success, video_url, cover_url, title, author, duration, source}` 结构（前端据此显示「官方直连 / 本地解析 / 第三方备用」徽标）。
- 代理：`/proxy` 校验 CDN 白名单 → 按 host 伪造 Referer/UA → 转发 Range → 流式返回；
  响应带 `Access-Control-Allow-Origin: *`，前端可用 `fetch + blob` 跨域真正保存文件，ffmpeg.wasm 也能跨域读取做剪辑。

## 说明
- `*.workers.dev` 在国内偶有波动；若想最稳，可在 Cloudflare 免费 zone 里把一个自有域名绑定到该 Worker。
- 免费套餐对视频流式转发友好（I/O 型，几乎不占 CPU）；超长 B 站长视频仍可能受源站限速影响。
