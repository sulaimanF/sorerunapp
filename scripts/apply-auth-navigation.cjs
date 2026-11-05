const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const ts = require(path.join(root, 'frontend/node_modules/typescript'));
const updates = new Map();
const removals = new Set();
function source(file) { return fs.readFileSync(path.join(root, file), 'utf8').replace(/\r\n/g, '\n'); }
function ast(file, text) { return ts.createSourceFile(file, text, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS); }
function applyEdits(text, edits) { return edits.sort((a,b)=>b.start-a.start).reduce((s,e)=>s.slice(0,e.start)+e.text+s.slice(e.end),text); }
function getName(node) { return node && (ts.isIdentifier(node) || ts.isStringLiteral(node)) ? node.text : ''; }
function walk(node, visit) { visit(node); ts.forEachChild(node, child => walk(child, visit)); }
const service = source('frontend/src/features/auth/services.ts');
if (!/resendVerification\(email:\s*string\)/.test(service) || !/api\.post\(path,\s*\{\}\)/.test(service)) {
  throw new Error('Prasyarat email-first belum dikenali. Terapkan revisi verifikasi sebelum login terlebih dahulu; services.ts tidak diubah oleh paket ini.');
}
const configFile='frontend/next.config.ts';
const config=source(configFile), tree=ast(configFile,config);
let configObject;
walk(tree,node=>{if(ts.isVariableDeclaration(node)&&getName(node.name)==='nextConfig'&&node.initializer&&ts.isObjectLiteralExpression(node.initializer)) configObject=node.initializer;});
if(!configObject) throw new Error('nextConfig harus berupa object literal; tidak ada shared file yang ditulis.');
const redirects=configObject.properties.find(p=>getName(p.name)==='redirects');
const edits=[];
if(!redirects) {
  const start=configObject.getStart(tree)+1;
  edits.push({start,end:start,text:'\n  async redirects() {\n    return [{ source: "/", destination: "/login", permanent: false }];\n  },'});
} else {
  if(!ts.isMethodDeclaration(redirects)||!redirects.body) throw new Error('Bentuk redirects() tidak dikenali; konfigurasi lain tidak disentuh.');
  const returns=[]; walk(redirects.body,n=>{if(ts.isReturnStatement(n)&&n.expression&&ts.isArrayLiteralExpression(n.expression))returns.push(n.expression);});
  if(returns.length!==1) throw new Error('redirects() harus mengembalikan satu array literal agar dapat dipatch secara aman.');
  const list=returns[0];
  const entries=list.elements.filter(e=>ts.isObjectLiteralExpression(e)&&e.properties.some(p=>getName(p.name)==='source'&&ts.isPropertyAssignment(p)&&ts.isStringLiteral(p.initializer)&&p.initializer.text==='/'));
  if(entries.length>1) throw new Error('Ada beberapa redirect untuk /; tidak ada perubahan shared file ditulis.');
  if(!entries.length) {
    const start=list.getStart(tree)+1;
    edits.push({start,end:start,text:'\n      { source: "/", destination: "/login", permanent: false },'});
  } else {
    const entry=entries[0];
    const destination=entry.properties.find(p=>getName(p.name)==='destination');
    const permanent=entry.properties.find(p=>getName(p.name)==='permanent');
    if(!destination||!permanent||!ts.isPropertyAssignment(destination)||!ts.isPropertyAssignment(permanent))throw new Error('Redirect / tidak mempunyai destination/permanent yang dapat dipatch.');
    if(entry.properties.some(p=>['has','missing'].includes(getName(p.name))))throw new Error('Redirect / bersyarat; perlu review source sebelum menggantinya.');
    edits.push({start:destination.initializer.getStart(tree),end:destination.initializer.end,text:'"/login"'});
    edits.push({start:permanent.initializer.getStart(tree),end:permanent.initializer.end,text:'false'});
  }
}
const next=applyEdits(config,edits); if(next!==config) updates.set(configFile,next);
const rootPage='frontend/src/app/page.tsx';
if(fs.existsSync(path.join(root,rootPage))) {
  const original=source(rootPage);
  const simple=/^\s*import\s*\{\s*redirect\s*\}\s*from\s*["']next\/navigation["'];?\s*export\s+default\s+function\s+Page\(\)\s*\{\s*redirect\(["']\/(?:beranda|login)["']\);?\s*\}\s*$/;
  if(!simple.test(original)) throw new Error('src/app/page.tsx mempunyai logika selain redirect sederhana; tidak dihapus. Tidak ada perubahan shared file ditulis.');
  removals.add(rootPage);
}
// Preserve any profile-stage tests. Positive login helpers explicitly visit their target after home.
for(const name of ['membership.spec.ts','profile.spec.ts']) {
  const file='frontend/tests/browser/'+name;
  if(!fs.existsSync(path.join(root,file)))continue;
  const text=source(file), parsed=ast(file,text), changes=[];
  walk(parsed,node=>{
    if(!ts.isFunctionDeclaration(node)||!node.name||!/^login(?:Member)?$/.test(node.name.text)||!node.body)return;
    const body=node.body.getText(parsed);
    if(body.includes('auth-navigation: fixed landing')||!body.includes('Masuk')||!body.includes('/login'))return;
    if(node.parameters.some(p=>getName(p.name)==='returnPath')) {
      const statement=node.body.statements.find(s=>s.getText(parsed).includes('toHaveURL')&&s.getText(parsed).includes('returnPath'));
      if(!statement) return;
      const start=statement.getStart(parsed);
      changes.push({start,end:start,text:'// auth-navigation: fixed landing, then explicit member navigation.\n  await expect(page).toHaveURL(/\\/beranda$/);\n  if (returnPath !== "/beranda") await page.goto(returnPath);\n  '});
    } else if(body.includes('/profil')) {
      const statement=node.body.statements.find(s=>s.getText(parsed).includes('toHaveURL')&&s.getText(parsed).includes('profil'));
      if(!statement)return;
      const start=statement.getStart(parsed);
      changes.push({start,end:start,text:'// auth-navigation: fixed landing, then explicit profile navigation.\n  await expect(page).toHaveURL(/\\/beranda$/);\n  await page.goto("/profil");\n  '});
    }
  });
  if(changes.length)updates.set(file,applyEdits(text,changes));
}
if(fs.existsSync(path.join(root,'.gitignore'))) {
  const original=source('.gitignore');
  if(!original.includes('.auth-navigation-backup/'))updates.set('.gitignore',original.trimEnd()+'\n\n# Source backup for approved auth navigation\n.auth-navigation-backup/\n');
}
if(!updates.size&&!removals.size) { console.log('Konfigurasi navigasi sudah diterapkan.');process.exit(0); }
const backup=path.join(root,'.auth-navigation-backup',new Date().toISOString().replace(/[:.]/g,'-'));
for(const file of new Set([...updates.keys(),...removals])) {
  const to=path.join(backup,file);fs.mkdirSync(path.dirname(to),{recursive:true});fs.copyFileSync(path.join(root,file),to);
}
for(const [file,text] of updates){fs.writeFileSync(path.join(root,file),text);console.log('Diperbarui: '+file);}
for(const file of removals){fs.unlinkSync(path.join(root,file));console.log('Redirect halaman dipindahkan ke next.config.ts; backup dibuat: '+file);}
console.log('Backup source: .auth-navigation-backup/. Jangan commit folder backup.');
