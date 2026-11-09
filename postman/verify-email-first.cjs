const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const collection = JSON.parse(fs.readFileSync(__dirname+'/sorerunapp-email-first.postman_collection.json'));
const env = Object.fromEntries(JSON.parse(fs.readFileSync(__dirname+'/sorerunapp-email-first.postman_environment.json')).values.map(v=>[v.key,v.value]));
assert.equal(env.email_link,''); assert.equal(env.verification_url,'');
const signed='http://localhost:8000/api/v1/auth/email-verification/42/'+'a'.repeat(40)+'?expires=1999999999&v=1&signature='+'b'.repeat(64);
const results=[null,'registration_received',null,null,'verification_link_ready',null,'email_verified',null,null,null,'already_verified',null,null,'verification_requested','verification_requested'];
const statuses=[204,201,403,401,200,403,200,401,200,200,200,204,204,202,202];
let assertions=0;
for (const [i,item] of collection.item.entries()) {
  if (i===4) env.email_link='http://localhost:3000/verify-email#verification='+encodeURIComponent(signed);
  const body=results[i]?{data:{result:results[i]}}:[2,5].includes(i)?{error:{code:'EMAIL_NOT_VERIFIED'}}:i===8?{data:{user:{email_verified:true}}}:i===9?{data:{qr_payload:'src-member:v1:synthetic'}}:{};
  const headers=new Map();
  const pm={info:{requestName:item.name},environment:{get:k=>env[k],set:(k,v)=>{env[k]=v;},unset:k=>{delete env[k];}},request:{method:item.request.method,headers:{upsert:({key,value})=>headers.set(key,value)}},cookies:{jar:()=>({get:(url,key,cb)=>{assert.equal(key,'XSRF-TOKEN');cb(null,'synthetic%2Bcsrf');}})},test:(name,fn)=>fn(),expect:v=>({to:{equal:e=>{assert.equal(v,e);assertions++;}}}),response:{json:()=>body,headers:{get:k=>k==='Retry-After'?'60':null},to:{have:{status:n=>{assert.equal(statuses[i],n);assertions++;}}}}};
  const context=vm.createContext({pm,decodeURIComponent,Date,Math,Error,Object,JSON});
  for (const e of [...collection.event,...item.event].filter(e=>e.listen==='prerequest')) new vm.Script(e.script.exec.join('\n')).runInContext(context);
  if(item.request.method==='POST') assert.equal(headers.get('X-XSRF-TOKEN'),'synthetic+csrf');
  for(const e of item.event.filter(e=>e.listen==='test')) new vm.Script(e.script.exec.join('\n')).runInContext(context);
}
assert.equal(env.verification_url,signed);
console.log(`Postman simulation passed: ${collection.item.length} requests, ${assertions} assertions. No real API contacted.`);
