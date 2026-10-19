const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const {chromium}=require('playwright');
const code=fs.readFileSync('extension/captcha-detector.js','utf8');
test('rendered challenge only: normal results, hidden elements, login, text, iframe and dimensions',async()=>{
 const browser=await chromium.launch({headless:true});
 try {
  const page=await browser.newPage();
  await page.route('https://**/*',r=>r.fulfill({body:'<html><body></body></html>',contentType:'text/html'}));
  await page.goto('https://m.search.naver.com/search.naver?query=test');
  const challenge='<p>보안문자를 입력해 주세요</p><input id="captcha"><img id="captchaImg" style="width:100px;height:50px">';
  const check=async(html,expected,signal)=>{
   await page.setContent(html);await page.addScriptTag({content:code});
   const r=await page.evaluate(()=>RfCaptcha.inspect());
   assert.equal(r.captcha,expected,html);
   if(signal)assert.equal(r.evidence.signal,signal);
   assert.equal(r.evidence.visible,expected);
   assert.equal(JSON.stringify(r).includes('query='),false);
  };
  await check(challenge,true,'visible-challenge');
  await check('<div hidden>'+challenge+'</div>',false);
  await check('<div style="visibility:hidden">'+challenge+'</div>',false);
  await check('<div style="opacity:0">'+challenge+'</div>',false);
  await check('<script>const s="보안문자 captcha"</script><p>상품이 없습니다</p>',false);
  await check('<p>보안문자 captcha 보안 확인 상품 설명</p>',false);
  await check('<p>검색 결과가 없습니다</p>',false);
  await check('',false);
  await check('<div data-shopping-block>정상 검색 상품</div>'+challenge,false,'normal-results');
  await check('<iframe src="https://captcha.naver.com/challenge" width="300" height="150"></iframe>',true,'visible-gate-iframe');
  await check('<iframe hidden src="https://captcha.naver.com/challenge"></iframe>',false);
  await check('<iframe style="width:0;height:0;border:0" src="https://ncpt.naver.com/a"></iframe>',false);
  await check('<iframe src="https://nid.naver.com/nidlogin.login"></iframe>',false);
  await page.goto('https://nid.naver.com/nidlogin.login');
  await check('<p>로그인 보안 확인</p><input id="id"><input type="password">',false);
  await page.goto('https://captcha.naver.com/challenge');
  await check('<p>보안문자를 입력하세요</p><input name="captcha">',true);
 } finally {await browser.close();}
});
test('original raw HTML regexp falsely classifies hidden and script-only captcha words',()=>{
 const old=fs.readFileSync('extension/background.js','utf8');
 assert.equal(old.includes(".test(r.text || '')"),false);
 const legacy=/보안문자|자동\\s*입력\\s*방지/;
 assert.equal(legacy.test('<script>const unused="보안문자"</script><div data-shopping-block>정상</div>'),true);
});
test('stale recovery does not manufacture captcha or navigate to alternate queries',async()=>{
 const data={rfSerpRecovery:{keyword:'test',current:'test',since:0,attempts:0},rfSerpTabKeyword:'test',rfSerpTabId:1};let navigations=0;
 const chrome={storage:{session:{get:async()=>data,set:async d=>Object.assign(data,d)}},tabs:{get:async()=>({id:1,url:'https://m.search.naver.com/search.naver?query=test'}),sendMessage:async()=>({ok:false,captcha:false,evidence:{visible:false}}),update:async()=>navigations++}};
 const ctx=vm.createContext({chrome,URL,Date});vm.runInContext(fs.readFileSync('extension/shop-serp-tab.js','utf8'),ctx);
 const r=await new ctx.ShopSerpTab().peek('test');assert.equal(r.captcha,false);assert.equal(r.retryable,true);assert.equal(data.rfSerpRecovery,null);assert.equal(navigations,0);
});
test('server numeric rule is bounded and only narrows short-page detection',async()=>{
 const browser=await chromium.launch({headless:true});
 try {
  const page=await browser.newPage();await page.route('https://**/*',r=>r.fulfill({body:'<html><body></body></html>'}));
  await page.goto('https://captcha.naver.com/challenge');
  await page.setContent('<p>보안문자 '+ 'x'.repeat(500) +'</p><input id="captcha">');
  await page.addScriptTag({content:code});
  const detect=value=>page.evaluate(v=>{RfCaptcha.rules={max_page_text_chars:v};return RfCaptcha.inspect().captcha;},value);
  assert.equal(await detect(200),false);assert.equal(await detect(4000),true);
  assert.equal(await detect(999999),true);assert.equal(await detect('alert(1)'),true);
 } finally {await browser.close();}
});
