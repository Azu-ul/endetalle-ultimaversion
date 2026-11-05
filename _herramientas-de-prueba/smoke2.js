const { chromium } = require('playwright');
const B='http://127.0.0.1:8081', PASS='TestLocal#2026';
const res=[]; const ok=(n,c,x='')=>res.push([c?'OK ':'FALLA',n,x]);
async function login(p,e){await p.goto(B+'/ingresar');await p.fill('input[type="email"]',e);await p.fill('input[type="password"]',PASS);await p.click('button[type="submit"]');await p.waitForTimeout(2500);}
(async()=>{const b=await chromium.launch();
for (const [email,label,expect] of [['free@example.com','free',/Desbloque/i],['est@example.com','estudiante',/Comprar el plan Pro/i]]){
  const ctx=await b.newContext({viewport:{width:1400,height:1000}});const p=await ctx.newPage();
  await login(p,email);await p.goto(B+'/detalle/detalle-prueba');await p.waitForTimeout(2500);
  await p.getByRole('button',{name:/SketchUp/}).first().click();await p.waitForTimeout(700);
  ok(label+': modal de bloqueo SKP',await p.getByText(expect).count()>0);
  await p.screenshot({path:'lock-'+label+'.png'});
  await ctx.close();
}
// Pro: Actualizar plan / Cancelar
const ctx=await b.newContext({viewport:{width:1400,height:1000}});const p=await ctx.newPage();
await login(p,'prueba@example.com');await p.goto(B+'/mi-cuenta');await p.waitForTimeout(2500);
await p.screenshot({path:'micuenta.png',fullPage:true});
console.log('BTNS:',(await p.locator('button').allInnerTexts()).map(t=>t.trim().replace(/\n/g,' ')).filter(Boolean).slice(-22).join(' | '));
ok('mi-cuenta: sin pestaña Mensajes y Consultas',await p.getByText('Mensajes y Consultas').count()===0);
await p.getByRole('button',{name:/Mi Suscripci/}).first().click();await p.waitForTimeout(800);
await p.screenshot({path:'suscripcion.png',fullPage:true});
console.log('SUBBTNS:',(await p.locator('main button, button').allInnerTexts()).map(t=>t.trim()).filter(t=>/plan|cancel|suscri/i.test(t)).join(' | '));
const act=p.getByRole('button',{name:/Actualizar plan/i}).first();
ok('mi-cuenta: botón Actualizar plan',await act.count()>0);
if(await act.count()){await act.click();await p.waitForTimeout(1200);ok('Actualizar plan lleva a /planes',p.url().includes('/planes'),p.url());}
await p.goto(B+'/mi-cuenta');await p.waitForTimeout(2000);await p.getByRole('button',{name:/Mi Suscripci/}).first().click();await p.waitForTimeout(800);
const can=p.getByRole('button',{name:/Cancelar suscripci/i}).first();
ok('mi-cuenta: botón Cancelar suscripción visible',await can.count()>0);
if(await can.count()){p.once('dialog',d=>d.accept());await can.click();await p.waitForTimeout(2000);
 await p.screenshot({path:'cancelada.png',fullPage:true});
 console.log('TEXT after cancel has "cancel":',(await p.locator('body').innerText()).match(/cancel[^\n]{0,80}/ig));}
console.log(res.map(r=>r.join(' | ')).join('\n'));await b.close()})().catch(e=>{console.error('ERROR',String(e).slice(0,500));process.exit(1)});
