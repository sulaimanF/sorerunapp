const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = __dirname + '/';
const collection = JSON.parse(fs.readFileSync(root+'sorerunapp-stage05.postman_collection.json'));
const environment = JSON.parse(fs.readFileSync(root+'sorerunapp-stage05.postman_environment.json'));
const env = Object.fromEntries(environment.values.map(v=>[v.key,v.value]));
const items = collection.item.flatMap(f=>f.item);
const scripts = [...collection.event,...items.flatMap(i=>i.event)];
scripts.forEach(e=>new vm.Script(e.script.exec.join('\n')));
assert.equal(items.length,17);
assert.equal(collection.auth.type,'noauth');
assert.equal(env.verification_url,'');
let assertions=0;
function expect(value,negate=false){
 const chain={};
 const check=ok=>{assertions++;assert(negate?!ok:ok);return chain;};
 ['to','be','have'].forEach(k=>Object.defineProperty(chain,k,{get:()=>chain}));
 Object.defineProperty(chain,'not',{get:()=>expect(value,!negate)});
 chain.equal=w=>check(value===w); chain.include=w=>check(value.includes(w));
 chain.match=r=>check(r.test(value)); chain.property=k=>check(value!=null&&Object.hasOwn(value,k));
 chain.a=chain.an=t=>check(t==='object'?value!==null&&typeof value==='object'&&!Array.isArray(value):typeof value===t);
 return chain;
}
const requestId='d6578996-20ac-4d4e-ab72-ef8d187fe122';
const link='http://localhost:8000/api/v1/auth/email-verification/42/'+ 'a'.repeat(40)+'?expires=1999999999&v=1&signature='+'b'.repeat(64);
const replace=text=>text.replace(/\{\{([^}]+)\}\}/g,(_,k)=>env[k]??'');
function run(item,status,body,token='synthetic%2Btoken%3D'){
 const local={};const headers=new Map(item.request.header.map(h=>[h.key,h.value]));
 const pm={
  environment:{get:k=>env[k],set:(k,v)=>{env[k]=v;},unset:k=>{delete env[k];}},
  variables:{set:(k,v)=>{local[k]=v;},replaceIn:replace},
  cookies:{jar:()=>({get:(url,key,callback)=>{assert.equal(url,'http://localhost:8000/');assert.equal(key,'XSRF-TOKEN');callback(null,token);}})},
  request:{url:{toString:()=>item.request.url},headers:{remove:k=>headers.delete(k),upsert:h=>headers.set(h.key,h.value)}},
  response:{code:status,json:()=>body,headers:{get:k=>({'cache-control':'no-store','x-request-id':requestId,'retry-after':'60'}[k.toLowerCase()])},to:{have:{status:n=>assert.equal(status,n)}}},
  test:(name,fn)=>{try{fn();}catch(e){throw new Error(item.name+' '+name,{cause:e});}},expect,
 };
 const ctx=vm.createContext({pm,decodeURIComponent});
 function execute(e){new vm.Script(e.script.exec.join('\n')).runInContext(ctx);}
 execute(collection.event.find(e=>e.listen==='prerequest'));
 item.event.filter(e=>e.listen==='prerequest').forEach(execute);
 if(item.request.method==='POST') assert.equal(headers.get('X-XSRF-TOKEN'),decodeURIComponent(token));
 if(item.protocolProfileBehavior?.disableCookies){assert(item.request.url.startsWith('{{mailpit_base_url}}'));assert(!headers.has('Origin'));}
 if(item.request.body) JSON.parse(item.request.body.raw.replace(/\{\{([^}]+)\}\}/g,(_,k)=>local[k]??env[k]??''));
 execute(collection.event.find(e=>e.listen==='test'));item.event.filter(e=>e.listen==='test').forEach(execute);
 return {local,headers};
}
const envelope=data=>({data,meta:{request_id:requestId}});
const error=code=>({error:{code,message:'Synthetic',fields:{}},meta:{request_id:requestId}});
const identity=active=>envelope({user:{id:42,email:env.test_email,email_verified:active},membership:{status:active?'active':'pending_verification',member_number:active?'SRC-000042':null},profile_complete:true});
const mail={ID:'synthetic-id',Subject:'Verifikasi email sorerunapp',To:[{Address:env.test_email}],HTML:'<a href="'+link.replaceAll('&','&amp;')+'">Verifikasi</a>',Text:''};
const fixtures=[
 [204,null],[201,envelope({result:'registration_received'})],[200,identity(false)],[200,identity(false)],
 [403,error('MEMBERSHIP_CARD_UNAVAILABLE')],[200,{messages:[mail]}],[200,mail],
 [200,envelope({result:'membership_activated',membership:{status:'active'}})],
 [200,identity(true)],[200,envelope({community_name:'Sore Running Club',qr_payload:'src-member:v1:'+'c'.repeat(43),member_number:'SRC-000042'})],
 [200,envelope({result:'already_active'})],[202,envelope({result:'verification_requested'})],
 [204,null],[401,error('UNAUTHENTICATED')],
];
items.slice(0,14).forEach((item,i)=>{run(item,...fixtures[i]);if(i===6)assert.equal(env.verification_url,link);});
assert(!env.verification_url);
run(items[14],202,envelope({result:'verification_requested'}));run(items[15],429,error('RATE_LIMITED'));
env.verification_url=link;run(items[16],403,error('INVALID_VERIFICATION_LINK'));
// Exercise failure paths and safe URL extraction; no network requests are sent.
assert.throws(()=>run(items[5],200,{messages:[]}),/Email belum ada/);
assert(!env.verification_url);
env.mailpit_message_id='synthetic-id';
assert.throws(()=>run(items[6],200,{...mail,To:[{Address:'another@example.test'}]}),/bukan verifikasi/);
env.mailpit_message_id='synthetic-id';
assert.throws(()=>run(items[6],200,{...mail,HTML:'<a href="https://untrusted.example/?signature='+'b'.repeat(64)+'">X</a>'}),/tidak ditemukan/);
env.verification_url='http://localhost:8000.evil.example/api/v1/auth/email-verification/42/x?signature=x';
assert.throws(()=>run(items[7],200,{}),/Ambil tautan/);
env.verification_url=link;assert.throws(()=>run(items[2],200,identity(false),null),/CSRF dahulu/);
env.test_password='test-"quotes"-backslash\\-123';const result=run(items[2],200,identity(false),'rotated%2Btoken%3D');
assert.equal(JSON.parse(result.local.src_login_body).password,env.test_password);
assert.equal(result.headers.get('X-XSRF-TOKEN'),'rotated+token=');
process.stdout.write(JSON.stringify({requests:items.length,scripts:scripts.length,assertions,result:'passed',scope:'Node simulation; no HTTP/PHP/MySQL execution'})+'\n');
