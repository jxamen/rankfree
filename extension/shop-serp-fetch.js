/* Tab-free integrated search. JSON only: never execute Naver scripts. */
class ShopSerpFetch {
  static lane = Promise.resolve();
  static run(keyword, target, options) {
    const job = this.lane.then(async () => {
      await options.wait(Math.max(1000, Number(options.gap) || 4000));
      const result = await this.collect(keyword, target, options);
      if (result.fetch_stopped) await options.stop(result);
      return result;
    });
    this.lane = job.catch(() => {});
    return job;
  }
  static parse(html) {
    const marker = /naver\s*\.\s*search\s*\.\s*ext\s*\.\s*newshopping\s*(?:\.\s*shopping|\[\s*["']shopping["']\s*\])\s*\.\s*_INITIAL_STATE\s*=\s*/g;
    const match = marker.exec(html);
    if (!match) return {status: /<html\b/i.test(html) && /<\/html>/i.test(html) && !/newshopping|shp_gui_root|shp_tli_root/.test(html) ? 'area_absent' : 'parse_failed', items:[]};
    try {
      const start = marker.lastIndex;
      if (html[start] !== '{') throw Error('object');
      let depth = 0, quoted = false, escaped = false, end = -1;
      for (let i = start; i < html.length; i++) {
        const c = html[i];
        if (quoted) { if (escaped) escaped = false; else if (c === '\\') escaped = true; else if (c === '"') quoted = false; continue; }
        if (c === '"') quoted = true;
        else if (c === '{' || c === '[') depth++;
        else if ((c === '}' || c === ']') && --depth === 0) { end = i + 1; break; }
      }
      if (end < 0) throw Error('truncated');
      const state = JSON.parse(html.slice(start, end));
      const pages = state.initProps?.pagedSlot;
      if (!/^shp_[a-z0-9_]+$/.test(state.areaCode) || !Array.isArray(pages) || !pages.length) throw Error('pages');
      const items = []; let organic = 0;
      for (const [index, page] of pages.entries()) {
        if (page.page !== index + 1 || !Array.isArray(page.slots)) throw Error('page order');
        for (const slot of page.slots) {
          const cards = slot.slotType === 'CARD' ? [slot.data] : slot.slotType === 'HORIZONTAL_LIST' && Array.isArray(slot.data) ? slot.data : null;
          if (!cards) throw Error('slot');
          for (const card of cards) {
            if (!card || !['string','number'].includes(typeof card.nvMid) || typeof card.sourceType !== 'string' || typeof card.cardType !== 'string' || typeof card.productName !== 'string') throw Error('card');
            const ad = ['AD','AITEMS_FORYOU_AD'].includes(card.sourceType) || card.cardType === 'AD_CARD';
            const item = {};
            for (const key of ['nvMid','channelProductId','mallName','productName','sourceType','cardType','salePrice','discountedSalePrice']) {
              if (card[key] != null && !['string','number','boolean'].includes(typeof card[key])) throw Error('field');
              if (card[key] != null) item[key] = card[key];
            }
            items.push({...item, page:page.page, raw_rank:card.rank ?? null, screen_rank:items.length + 1, organic_rank:ad ? null : ++organic, is_ad:ad});
          }
        }
      }
      return {status:'ok', area_code:state.areaCode, pages:pages.length, items};
    } catch (_) { return {status:'parse_failed',items:[]}; }
  }
  static rank(parsed, target = {}) {
    const norm = x => String(x || '').trim().replaceAll(' ','').toLowerCase();
    const matches = item => target.product_id ? String(item[target.id_kind === 'nvmid' ? 'nvMid' : 'channelProductId'] ?? '') === String(target.product_id) : !!norm(target.mall_name) && norm(item.mallName).includes(norm(target.mall_name));
    const found = parsed.items.filter(matches);
    return {status:parsed.status, pages:parsed.pages || 0, received:parsed.items.length, rank:found.find(x => !x.is_ad)?.organic_rank || 0, ad:found.some(x => x.is_ad)};
  }
  static html(parsed) {
    // Existing server matcher consumes the same flattened order and organic ranks.
    const slots = parsed.items.map(item => ({slotType:'CARD',data:{...item,sourceType:item.is_ad ? 'AD' : 'SAS',rank:item.organic_rank || 0}}));
    return 'naver.search.ext.newshopping.shopping._INITIAL_STATE = ' + JSON.stringify({areaCode:parsed.area_code || 'shp_empty',initProps:{pagedSlot:[{page:1,slots}]}}).replaceAll('<','\\u003c') + ';';
  }
  static async collect(keyword, target, {read, wait, gap = 4000}) {
    const started = Date.now(), results = {}, parsed = {};
    for (const device of ['mobile','pc']) {
      if (device === 'pc') await wait(Math.max(1000, Number(gap) || 4000));
      const url = device === 'mobile' ? 'https://m.search.naver.com/search.naver?where=m&query=' : 'https://search.naver.com/search.naver?where=nexearch&query=';
      const response = await read(url + encodeURIComponent(keyword));
      if (response.fetch_stopped) return response;
      const raw = response.text || '';
      // A restriction response is not a zero rank. Stop without fallback or solving it.
      const text = raw.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,'').replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi,'');
      const restricted = /https:\/\/(?:captcha|ncpt)\.naver\.com\//.test(response.url || '') || /<title[^>]*>[^<]*(?:보안 확인|접근 제한)/i.test(text) || /<(?:form|iframe)[^>]*(?:captcha|ncpt)/i.test(text);
      if ([403,429].includes(response.status) || restricted) return {ok:false,blocked:true,fetch_stopped:true,status:response.status, message:'통합검색 보안 확인·요청 제한 — 수집을 중단했습니다.', device,elapsed_ms:Date.now()-started};
      if (!response.ok) return {ok:false,status:response.status,message:'통합검색 응답을 받지 못했습니다.',device};
      parsed[device] = this.parse(raw);
      results[device] = this.rank(parsed[device], target);
      if (parsed[device].status === 'parse_failed') return {ok:false,status:response.status,message:'통합검색 구조를 읽지 못했습니다 — 순위를 저장하지 않습니다.',device,device_results:results};
    }
    return {ok:true,status:200,captcha:false,result_source:'integrated',html:this.html(parsed.mobile),device_results:results,elapsed_ms:Date.now()-started};
  }
}
if (typeof module !== 'undefined') module.exports = ShopSerpFetch;
