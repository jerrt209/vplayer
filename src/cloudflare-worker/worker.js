/**
 * ============================================================
 *  去水印工具 · Cloudflare Worker（自托管“本地化/第一方”解析 + 视频代理）
 *  —— 一个 Worker 同时提供：
 *     1) GET /parse?url=<分享链接>   第一方解析（B站官方直连 + 多源并行竞速 + 边缘缓存）
 *     2) GET /proxy?url=<视频直链>&name=<文件名>   就近视频代理（下载/剪辑加速）
 *
 *  部署后把地址填到前端 PROXY 与本系统 config.php 的 LOCAL_PARSER_URL，
 *  即可摆脱对第三方公共接口的单一依赖（第三方随时可能限流/关停）。
 * ============================================================
 */

// ——— 可配置项（在 wrangler.toml 的 [vars] 里填写） ———
// 指向你自己部署的 douyin.wtf（或任意兼容 /?url= 的解析服务），作为“本地第一方”源优先尝试。
// 留空则只用下方公共源做并行竞速。强烈建议自托管一个 douyin.wtf 以获得稳定无水印解析。
const SELF_HOSTED = '';

// 视频代理允许的 CDN 域名（防开放代理被滥用，与 PHP 端 PROXY_ALLOW 保持一致）
// B站 CDN 域名已迁移（bilivideo.com → mountaintoys.cn / bilivideo.cn 等）
const PROXY_ALLOW = [
  'bilivideo.com', 'bilibili.com', 'bilivideo.cn', 'mountaintoys.cn',
  'douyin', 'tiktok', 'kuaishou', 'gifshow',
  'byteimg.com', 'muscdn.com', 'akamaized.net', 'alicdn.com', 'ixigua.com',
  'chenzhongtech.com', 'snssdk.com', 'amemv.com', 'tiktokcdn.com', 'volcstatic.com',
  'douyinvod.com', 'zjcdn.com', 'bytecdn.com', 'bytedance', 'tospush',
];

// 边缘缓存（按 url 缓存解析结果 10 分钟；Worker 实例级，重启即清空，足够抗重复请求）
const parseCache = new Map();
const CACHE_TTL = 10 * 60 * 1000;

function corsHeaders(extra = {}) {
  return new Headers(Object.assign({
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, OPTIONS',
    'Access-Control-Allow-Headers': 'Range, Content-Type',
    'Access-Control-Max-Age': '86400',
  }, extra));
}

function json(data, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: corsHeaders({ 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' }),
  });
}

/* ===================== 解析部分 ===================== */

function detect(v) {
  v = (v || '').toLowerCase();
  if (v.includes('bilibili') || v.includes('b23.tv')) return 'bilibili';
  if (v.includes('douyin') || v.includes('iesdouyin')) return 'douyin';
  if (v.includes('tiktok')) return 'tiktok';
  if (v.includes('kuaishou') || v.includes('gifshow') || v.includes('chenzhongtech')) return 'ks';
  if (v.includes('xiaohongshu') || v.includes('xhslink')) return 'xhs';
  return 'douyin';
}
function mapBugpk(p) {
  return { douyin: 'douyin', ks: 'kuaishou', xhs: 'xhs', bilibili: 'bilibili' }[p] || p;
}

// 把任意上游返回归一化为本系统统一结构
function norm(raw, source) {
  if (!raw || typeof raw !== 'object') return { success: false, msg: source + '：返回异常' };
  if (raw.success === true && raw.video_url) return Object.assign({}, raw, { source });
  const code = raw.code != null ? raw.code : raw.status;
  const d = (raw.data && typeof raw.data === 'object') ? raw.data : raw;
  const video = d.url || d.video_url || raw.nwm_video_url || raw.video || '';
  if ((code === 200 || code === 0 || code === 1) && video) {
    const author = (d.author && typeof d.author === 'object') ? (d.author.name || '') : (d.author || d.auther || d.author_name || raw.author || '');
    return {
      success: true, platform: raw.platform || d.platform || source,
      video_url: video,
      cover_url: d.cover || d.poster || raw.cover || '',
      title: d.title || d.desc || raw.title || '',
      author,
      duration: ((d.duration || raw.duration || 0) / 1000) || 0,
      source,
    };
  }
  return { success: false, msg: source + '：' + (raw.msg || raw.message || '未返回数据') };
}

// B站：官方接口第一方直连（无需任何第三方、无需签名）
async function parseBili(url) {
  let u = url;
  if (u.includes('b23.tv')) {
    try { const r = await fetch(u, { redirect: 'follow' }); u = r.url; } catch (e) {}
  }
  const m = u.match(/BV[0-9A-Za-z]+/i);
  if (!m) return { success: false, msg: '无法识别 B站视频链接' };
  const bvid = m[0];
  const headers = {
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
    'Referer': 'https://www.bilibili.com',
  };
  try {
    const view = await (await fetch(`https://api.bilibili.com/x/web-interface/view?bvid=${bvid}`, { headers })).json();
    if (!view || view.code !== 0) return { success: false, msg: 'B站信息接口异常：' + (view && view.message || '未知') };
    const cid = view.data.cid;
    const pl = await (await fetch(`https://api.bilibili.com/x/player/playurl?bvid=${bvid}&cid=${cid}&qn=127&fnval=0&fourk=1`, { headers })).json();
    if (!pl || pl.code !== 0 || !pl.data.durl || !pl.data.durl.length) return { success: false, msg: 'B站获取直链失败：' + (pl && pl.message || '未知') };
    return {
      success: true, platform: 'bilibili',
      video_url: pl.data.durl[0].url, cover_url: view.data.pic,
      title: view.data.title, duration: view.data.duration, source: 'bilibili-official',
    };
  } catch (e) {
    return { success: false, msg: 'B站解析异常：' + e.message };
  }
}

// 抖音/快手/小红书/TikTok：多源并行竞速（谁先成功用谁），结果统一归一化
async function raceParse(url, platform) {
  const sources = [];
  if (SELF_HOSTED) sources.push({ n: '本地解析器', u: `${SELF_HOSTED}/?url=${encodeURIComponent(url)}` });
  sources.push({ n: 'bugpk', u: `https://api.bugpk.com/api/${mapBugpk(platform)}/?url=${encodeURIComponent(url)}` });
  if (platform === 'douyin' || platform === 'tiktok') {
    sources.push({ n: 'douyin.wtf', u: `https://api.douyin.wtf/api?url=${encodeURIComponent(url)}&minimal=true` });
  }
  const fetchOne = async (s) => {
    try {
      const r = await fetch(s.u, { headers: { 'User-Agent': 'Mozilla/5.0' } });
      const j = await r.json().catch(() => null);
      return norm(j, s.n);
    } catch (e) {
      return { success: false, msg: `${s.n}：${e.message}` };
    }
  };
  const results = await Promise.all(sources.map(fetchOne));
  for (const r of results) if (r.success) return r;
  const tried = results.map(r => (r.msg || '').split('：')[0]).join('、');
  return { success: false, msg: `所有解析源暂时不可用（${tried}）。建议自托管 douyin.wtf 并配置 SELF_HOSTED` };
}

async function doParse(url) {
  const platform = detect(url);
  const key = platform + '|' + url;
  const hit = parseCache.get(key);
  if (hit && Date.now() < hit.exp) return hit.data;
  let res;
  if (platform === 'bilibili') res = await parseBili(url);
  else res = await raceParse(url, platform);
  if (res && res.success) parseCache.set(key, { data: res, exp: Date.now() + CACHE_TTL });
  return res || { success: false, msg: '解析失败' };
}

/* ===================== 代理部分 ===================== */

function refererForHost(host) {
  const h = (host || '').toLowerCase();
  if (h.includes('bilibili') || h.includes('bilivideo') || h.includes('mountaintoys')) return 'https://www.bilibili.com';
  if (h.includes('douyin') || h.includes('amemv') || h.includes('snssdk') ||
      h.includes('zjcdn') || h.includes('douyinvod')) return 'https://www.douyin.com';
  if (h.includes('tiktok'))     return 'https://www.tiktok.com';
  if (h.includes('kuaishou') || h.includes('gifshow')) return 'https://www.kuaishou.com';
  if (h.includes('xiaohongshu')) return 'https://www.xiaohongshu.com';
  return 'https://' + (host || '');
}

export default {
  async fetch(request) {
    const url = new URL(request.url);

    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: corsHeaders() });
    }

    // —— 解析路由 ——
    if (url.pathname === '/parse') {
      const target = url.searchParams.get('url');
      if (!target || !/^https?:\/\//i.test(target)) {
        return json({ success: false, msg: '缺少或非法的 url 参数' }, 400);
      }
      const res = await doParse(target);
      return json(res, 200);
    }

    // —— 代理路由 ——
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
