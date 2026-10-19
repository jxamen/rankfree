const fs = require('fs'), vm = require('vm'), assert = require('assert');
const source=fs.readFileSync(require('path').join(__dirname, '../extension/content/product.js'),'utf8');
const functions=source.slice(source.indexOf('  function shippingText()'),source.indexOf('  // 상품정보(제목'));
const ctx={document:{body:{innerText:''}}, TextEncoder}; vm.createContext(ctx);vm.runInContext(functions,ctx);
for(const [input,text,expected] of [
 [{productDeliveryInfo:{deliveryFeeType:'FREE'}},'',0],
 [{productDeliveryInfo:{deliveryFee:{baseFee:'3,000',deliveryFeeType:'CONDITIONAL_FREE',freeConditionalAmount:30000}}},'',3000],
 [{},'배송비 3,000원 (30,000원 이상 무료)',3000],
 [{},'배송비 4,000원 착불 묶음배송',4000],
 [{},'무료배송',0], [{},'착불',null], [{},'조건부 무료 30,000원 이상',null],
 [{productDeliveryInfo:{baseFee:''}},'',null],
]) { ctx.document.body.innerText=text; assert.strictEqual(ctx.productDeliveryFee(input),expected); }
ctx.document.body.innerText='배송비 착불 문의 홍길동 010-1234-5678 test@example.com';
const diagnostic=ctx.deliveryDiagnostic({productDeliveryInfo:{deliveryFeeType:'COD',address:'서울시 비밀주소',phone:'01012345678'},shippingInfo:{baseFee:3000}});
assert(Buffer.byteLength(diagnostic)<=2048);assert(!/홍길동|서울시|010|example/.test(diagnostic));assert(diagnostic.includes('COD'));
console.log('배송비 파서 8 cases 및 진단 개인정보/2KiB 검증 통과');
