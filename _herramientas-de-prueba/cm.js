const { chromium } = require('playwright');
const B='http://127.0.0.1:8081', PASS='TestLocal#2026'; const res=[]; const ok=(n,c,x='')=>res.push([c?'OK ':'FALLA',n,x]);
async function login(p,e){await p.goto(B+'/ingresar');await p.fill('input[type="email"]',e);await p.fill('input[type="password"]',PASS);await p.click('button[type="submit"]');await p.waitForTimeout(2500);}
(async()=>{const b=await chromium.launch();
// usuario común comenta y edita
const c1=await b.newContext({viewport:{width:1400,height:1000}});const u=await c1.newPage();const errs=[];u.on('pageerror',e=>errs.push(String(e)));
await login(u,'est@example.com');await u.goto(B+'/detalle/detalle-prueba');await u.waitForTimeout(2500);
await u.locator('textarea').first().fill('Excelente detalle, gracias!');
await u.getByRole('button',{name:/Publicar|Enviar|Comentar/}).first().click();await u.waitForTimeout(2000);
ok('comentario publicado', await u.getByText('Excelente detalle, gracias!').count()>0);
const dateRe=/\d{1,2} \w{3,4}\.? 20\d\d,? \d{1,2}:\d{2}/;
ok('se ve fecha y hora completa', dateRe.test(await u.locator('body').innerText()), (await u.locator('body').innerText()).match(dateRe)?.[0]);
await u.waitForTimeout(3000);
await u.getByRole('button',{name:/^Editar$/}).first().click();await u.waitForTimeout(400);
await u.locator('textarea').first().fill('Excelente detalle (corregido)');
await u.getByRole('button',{name:/^Guardar$/}).click();await u.waitForTimeout(1800);
ok('comentario editado con la marca "editado"', await u.getByText('Excelente detalle (corregido)').count()>0 && await u.getByText(/editado/).count()>0);
await u.screenshot({path:'comentario.png'});
// admin
const c2=await b.newContext({viewport:{width:1400,height:900},acceptDownloads:true});const a=await c2.newPage();
await login(a,'admin@example.com');await a.goto(B+'/admin');await a.waitForTimeout(2000);
const adm=a.locator('div.fixed.inset-0.z-50').first();
await adm.getByRole('button',{name:/^Comentarios$/}).click();await a.waitForTimeout(1500);
ok('admin ve el comentario con usuario y email', await a.getByText('est@example.com').count()>0 && await a.getByText('Excelente detalle (corregido)').count()>0);
await a.screenshot({path:'admin-comentarios.png'});
await a.getByRole('button',{name:/Editar/}).first().click();await a.waitForTimeout(300);
await a.locator('textarea').first().fill('Moderado por el admin');await a.getByRole('button',{name:/^Guardar$/}).click();await a.waitForTimeout(1500);
ok('admin edita el comentario', await a.getByText('Moderado por el admin').count()>0);
a.once('dialog',d=>d.accept());await a.getByRole('button',{name:/Eliminar/}).first().click();await a.waitForTimeout(1500);
ok('admin elimina el comentario', await a.getByText('Comentario eliminado.').count()>0 && await a.getByText('Moderado por el admin').count()===0);
// backups
await adm.getByRole('button',{name:/^Backups$/}).click();await a.waitForTimeout(1200);
await a.getByRole('button',{name:/Crear backup ahora/}).click();await a.waitForTimeout(5000);
ok('backup manual creado', await a.getByText(/Backup creado: backup-/).count()>0 && await a.getByText('MANUAL').count()>0);
await a.screenshot({path:'admin-backups.png'});
const [dl]=await Promise.all([a.waitForEvent('download',{timeout:20000}),a.getByRole('link',{name:/Descargar/}).first().click()]);
const path=await dl.path();const fs=require('fs');ok('descarga del zip', dl.suggestedFilename().startsWith('backup-')&&fs.statSync(path).size>1000, dl.suggestedFilename()+' '+fs.statSync(path).size+'B');
a.once('dialog',d=>d.accept());await a.getByRole('button',{name:/Eliminar/}).first().click();await a.waitForTimeout(1500);
ok('backup eliminado', await a.getByText('Todavía no hay backups.').count()>0 || await a.getByText('MANUAL').count()<1);
ok('sin errores JS', errs.length===0, errs.join('|'));
console.log(res.map(r=>r.join(' | ')).join('\n'));await b.close()})().catch(e=>{console.error('ERR',String(e).slice(0,500));process.exit(1)});
