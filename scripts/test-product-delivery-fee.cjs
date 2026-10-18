const fs=require('fs'),vm=require('vm'),assert=require('assert');
const src=fs.readFileSync(require('path').join(__dirname, '../extension/content/product.js'),'utf8');
const helper=src.slice(src.indexOf('  function productDeliveryFee('),src.indexOf('  // 상품정보(제목·업체명'));
const ctx={};vm.createContext(ctx);vm.runInContext(helper,ctx);
for(const [input,expected] of [[{},null],[{deliveryFeeType:'FREE'},0],[{baseFee:3000},3000],[{deliveryFee:2500},2500],[{deliveryFee:{baseFee:3500}},3500],[{deliveryFee:{deliveryFeeType:'FREE'}},0],[{baseFee:''},null],[{baseFee:'bad'},null],[{baseFee:'3,000'},3000],[{deliveryFee:0},0]]) assert.strictEqual(ctx.productDeliveryFee({productDeliveryInfo:input}),expected);
assert.strictEqual(ctx.productDeliveryFee({}),null);console.log('11 shipping parser cases passed (synthetic fixtures)');
