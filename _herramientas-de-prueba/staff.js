const { chromium } = require('playwright');
const B='http://127.0.0.1:8081', PASS='TestLocal#2026'; const T=Date.now().toString().slice(-5);
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1400,height:900}});
await p.goto(B+'/ingresar');await p.fill('input[type="email"]','admin@example.com');await p.fill('input[type="password"]',PASS);await p.click('button[type="submit"]');await p.waitForTimeout(2500);
await p.goto(B+'/admin');await p.waitForTimeout(2000);
const adm=p.locator('div.fixed.inset-0.z-50').first();
await adm.getByRole('button',{name:/^Staff$/}).click();await p.waitForTimeout(1500);
console.log('admin card:', await p.getByText('ADMINISTRADOR').count());
await p.screenshot({path:'staff1.png'});
await p.getByRole('button',{name:/Agregar al staff/}).click();await p.waitForTimeout(500);
// convertir cliente existente
await p.getByRole('button',{name:/Convertir un cliente/}).click();
await p.fill('input[placeholder^="Buscar cliente"]','free@');await p.getByRole('button',{name:/^Buscar$/}).click();await p.waitForTimeout(1200);
await p.screenshot({path:'staff2.png'});
await p.getByRole('button',{name:/Hacer colaborador/}).first().click();await p.waitForTimeout(1500);
console.log('convertido msg:', await p.getByText(/ahora es colaborador/).count());
// crear nuevo
await p.getByRole('button',{name:/Agregar al staff/}).click();await p.waitForTimeout(400);
const inputs=p.locator('form input');
await p.locator('form input').nth(0).fill('Colab '+T);await p.locator('form input').nth(1).fill('colab'+T+'@example.com');await p.locator('form input').nth(2).fill('ClaveSegura#99');
await p.getByRole('button',{name:/Crear colaborador/}).click();await p.waitForTimeout(2000);
console.log('creado msg:', await p.getByText(/Colaborador creado/).count());
await p.screenshot({path:'staff3.png'});
await b.close()})().catch(e=>{console.error('ERR',String(e).slice(0,400));process.exit(1)});
