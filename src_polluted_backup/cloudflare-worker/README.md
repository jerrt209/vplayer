# 视频代理 Cloudflare Worker（加速下载 / 剪辑）

把原来 PHP 的 `proxy_video` 改写成 Cloudflare Worker，跑在离国内 CDN 最近的边缘节点
（香港 / 新加坡等），让「下载视频」「在线剪辑」从「跨洲中继」变成「就近回源」，下载速度大幅提升。
免费、无需管理服务器。

## 文件
- `worker.js`      —— Worker 入口（代理逻辑）
- `wrangler.toml`  —— 部署配置

## 部署步骤（需要你自己的 Cloudflare 账号，免费）
1. 安装 Node.js 后执行：
   ```bash
   npx wrangler login        # 浏览器授权登录 Cloudflare
   npx wrangler deploy       # 部署到 workers.dev
   ```
2. 部署成功会得到地址，形如：`https://wm-proxy.<你的子域>.workers.dev`
3. 打开 `index.html`，把顶部
   ```js
   const PROXY = '';
   ```
   改成你的 Worker 地址：
   ```js
   const PROXY = 'https://wm-proxy.<你的子域>.workers.dev';
   ```
4. 重新上传 `index.html` 到 `vplayer.ijerrt.cn` 即可生效。

## 工作原理
- 前端「下载视频 / 下载音乐 / 剪辑」统一调用 `/proxy?url=<直链>&name=<文件名>`。
- Worker 校验 CDN 白名单 → 按 host 伪造 Referer/UA → 转发 Range → 流式返回视频流。
- Worker 响应带 `Access-Control-Allow-Origin: *`，所以前端可用 `fetch + blob` 跨域真正保存文件
  （不再受跨域 `download` 属性被忽略的限制），ffmpeg.wasm 也能跨域读取视频做剪辑。

## 说明
- `PROXY` 留空时，前端自动回退到本站 PHP 的 `proxy_video`，功能不受影响。
- `*.workers.dev` 在国内偶有波动；若想最稳，可在 Cloudflare 免费 zone 里把一个自有域名
  （A/AAAA 或 CNAME）绑定到该 Worker，路由更优。
- 免费套餐对视频流式转发友好（I/O 型，几乎不占 CPU）；超长 B 站长视频仍可能受源站限速影响。
