/**
 * ============================================================
 *  去水印工具 · 视频代理（Cloudflare Worker 改写版）
 *  —— 替代原 PHP 的 proxy_video，跑在 Cloudflare 全球边缘（含香港/新加坡），
 *     让「下载视频 / 剪辑」从跨洲中继变为就近回源，下载速度大幅提升。
 *
 *  路由：GET /proxy?url=<视频直链>&name=<下载文件名>
 *  特点：同源白名单、按 host 伪造 Referer/UA、转发 Range、CORS、流式转发。
 * ============================================================
 */

// 视频代理允许的 CDN 域名（防开放代理被滥用，与 PHP 端 PROXY_ALLOW 保持一致）
const PROXY_ALLOW = [
  'bilivideo.com', 'bilibili.com', 'douyin', 'tiktok', 'kuaishou', 'gifshow',
  'byteimg.com', 'muscdn.com', 'akamaized.net', 'alicdn.com', 'ixigua.com',
  'chenzhongtech.com', 'snssdk.com', 'amemv.com', 'tiktokcdn.com', 'volcstatic.com',
  'douyinvod.com', 'zjcdn.com', 'bytecdn.com', 'bytedance', 'tospush',
];

// 按目标 CDN 返回合适的 Referer / Origin（避免硬编码导致跨站视频被拒）
function refererForHost(host) {
  const h = (host || '').toLowerCase();
  if (h.includes('bilibili'))   return 'https://www.bilibili.com';
  if (h.includes('douyin') || h.includes('amemv') || h.includes('snssdk') ||
      h.includes('zjcdn') || h.includes('douyinvod')) return 'https://www.douyin.com';
  if (h.includes('tiktok'))     return 'https://www.tiktok.com';
  if (h.includes('kuaishou') || h.includes('gifshow')) return 'https://www.kuaishou.com';
  if (h.includes('xiaohongshu')) return 'https://www.xiaohongshu.com';
  return 'https://' + (host || '');
}

function corsHeaders(extra = {}) {
  return new Headers(Object.assign({
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, OPTIONS',
    'Access-Control-Allow-Headers': 'Range, Content-Type',
    'Access-Control-Max-Age': '86400',
  }, extra));
}

export default {
  async fetch(request) {
    const url = new URL(request.url);

    // 预检（前端若带 Range 头会触发）
    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: corsHeaders() });
    }

    if (url.pathname !== '/proxy') {
      return new Response('not found', { status: 404 });
    }

    const target = url.searchParams.get('url');
    const name = url.searchParams.get('name') || 'video.mp4';

    if (!target || !/^https?:\/\//i.test(target)) {
      return new Response('invalid url', { status: 400 });
    }

    let host = '';
    try { host = new URL(target).hostname; } catch (e) {
      return new Response('bad url', { status: 400 });
    }

    // 白名单校验
    const allowed = PROXY_ALLOW.some(a => host.includes(a));
    if (!allowed) {
      return new Response('host not allowed', { status: 403 });
    }

    const referer = refererForHost(host);
    const upHeaders = new Headers();
    upHeaders.set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36');
    upHeaders.set('Referer', referer);
    upHeaders.set('Origin', referer);
    const range = request.headers.get('Range');
    if (range) upHeaders.set('Range', range);

    let upstream;
    try {
      upstream = await fetch(target, { headers: upHeaders, redirect: 'follow' });
    } catch (e) {
      return new Response('upstream error: ' + e.message, { status: 502 });
    }

    // 流式转发：直接返回上游 body，Worker 几乎不占 CPU（I/O 型），适合大视频
    const out = corsHeaders();
    out.set('Content-Type', upstream.headers.get('Content-Type') || 'application/octet-stream');
    out.set('Content-Disposition',
      `attachment; filename="${name}"; filename*=UTF-8''${encodeURIComponent(name)}`);
    out.set('Cache-Control', 'no-store');
    if (upstream.headers.has('Content-Length')) out.set('Content-Length', upstream.headers.get('Content-Length'));
    if (upstream.headers.has('Accept-Ranges'))  out.set('Accept-Ranges', upstream.headers.get('Accept-Ranges'));
    if (upstream.headers.has('Content-Range'))  out.set('Content-Range', upstream.headers.get('Content-Range'));

    const status = upstream.status === 206 ? 206 : 200;
    return new Response(upstream.body, { status, headers: out });
  }
};
