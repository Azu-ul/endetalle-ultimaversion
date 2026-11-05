const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8081';
const PASS = 'TestLocal#2026';
const results = [];
const T = Date.now().toString().slice(-5);
const ok = (n, c, extra = '') => { results.push([c ? 'OK ' : 'FALLA', n, extra]); };

async function login(page, email) {
  await page.goto(BASE + '/ingresar');
  await page.waitForSelector('input[type="email"]', { timeout: 15000 });
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', PASS);
  await page.click('button[type="submit"]');
  await page.waitForTimeout(2500);
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));

  // --- Login: ojito de contraseña
  await page.goto(BASE + '/ingresar');
  await page.waitForSelector('input[type="password"]', { timeout: 15000 });
  await page.fill('input[type="password"]', 'abc123');
  const toggle = page.locator('button[aria-label*="contraseña" i]').first();
  ok('login: botón ver contraseña existe', await toggle.count() > 0);
  if (await toggle.count()) {
    await toggle.click();
    ok('login: al tocar el ojo se ve el texto', await page.locator('input[type="text"][value="abc123"], input[type="text"]').count() > 0);
  }

  // --- Admin
  await login(page, 'admin@example.com');
  const logoutBtn = page.getByRole('button', { name: /cerrar sesi[oó]n/i });
  ok('sidebar: botón Cerrar sesión', await logoutBtn.count() > 0);
  ok('sidebar: Mensajes visible para superadmin', await page.getByText('Mensajes', { exact: true }).count() > 0);

  // Panel admin
  await page.goto(BASE + '/admin');
  await page.waitForTimeout(2500);
  ok('panel: título "EnDetalle CMS"', await page.getByText('EnDetalle CMS').count() > 0);
  ok('panel: sin "CMS Admin v7.3"', await page.getByText('v7.3').count() === 0);
  ok('panel: Top 5 de detalles', await page.getByText(/Top 5/i).count() > 0);
  ok('panel: aparece el detalle de prueba en el top', await page.getByText('Detalle de prueba').count() > 0);
  await page.screenshot({ path: 'admin-dashboard.png' });

  // Categorías
  const adm = page.locator('div.fixed.inset-0.z-50').first();
  console.log('MENU:', (await adm.locator('button').allInnerTexts()).map(t=>t.trim().replace(/\n/g,' ')).filter(Boolean).slice(0,30).join(' | '));
  await adm.getByRole('button', { name: /^Categorías$/ }).first().click();
  await page.waitForTimeout(800);
  await page.fill('input[placeholder^="Nombre (ej: Cerramientos)"]', 'Cerr'+T);
  await page.getByRole('button', { name: /Crear categoría/ }).click();
  await page.waitForTimeout(1500);
  ok('categorías: se creó "Cerramientos"', await page.getByText('Categoría «Cerr'+T+'» creada.').count() > 0);
  const sel = page.locator('select').first();
  const optVal = await sel.locator('option', { hasText: 'Cerr'+T }).getAttribute('value');
  await sel.selectOption(optVal);
  await page.fill('input[placeholder^="Nombre (ej: Portones)"]', 'Port'+T);
  await page.getByRole('button', { name: /Crear subcategoría/ }).click();
  await page.waitForTimeout(1500);
  ok('categorías: se creó subcategoría "Portones"', await page.getByText('Subcategoría «Port'+T+'» creada.').count() > 0);
  await page.screenshot({ path: 'admin-categorias.png' });

  // Planes y descuentos
  await adm.getByRole('button', { name: /Planes/ }).first().click();
  await page.waitForTimeout(1000);
  ok('planes: campos de descuento', await page.getByText(/descuento/i).count() > 0);
  await page.screenshot({ path: 'admin-planes.png', fullPage: true });

  // Detalles → editor con autor
  await adm.getByRole('button', { name: /^Detalles$/ }).first().click();
  await page.waitForTimeout(1200);
  await page.screenshot({ path: 'admin-detalles.png' });

  // Cerrar sesión
  await logoutBtn.first().click().catch(() => {});
  await page.waitForTimeout(1500);

  // --- Usuario free: descarga bloqueada
  await login(page, 'prueba@example.com');
  await page.goto(BASE + '/detalle/detalle-prueba');
  await page.waitForTimeout(2500);
  ok('detalle: leyenda verificado por el equipo', await page.getByText('Verificado por el equipo de EnDetalle').count() > 0);
  console.log('DETALLE BTNS:', (await page.locator('button').allInnerTexts()).map(t=>t.trim().replace(/\n/g,' ')).filter(Boolean).join(' | '));
  const dwg = page.getByRole('button', { name: /DWG/i }).first();
  const skp = page.getByRole('button', { name: /SKP/i }).first();
  ok('detalle: botón DWG deshabilitado (sin archivo)', await dwg.count() > 0 && await dwg.isDisabled());
  if (await skp.count()) {
    await skp.click();
    await page.waitForTimeout(600);
    ok('detalle: modal de pago al bajar siendo free', await page.getByText(/Desbloque/i).count() > 0);
    await page.screenshot({ path: 'detalle-bloqueado.png' });
  } else ok('detalle: botón SKP existe', false);

  // Planes (cards)
  await page.goto(BASE + '/planes');
  await page.waitForTimeout(2000);
  await page.screenshot({ path: 'planes.png', fullPage: true });

  ok('sin errores JS no controlados', errors.length === 0, errors.join(' | '));
  console.log(results.map(r => r.join(' | ')).join('\n'));
  await browser.close();
})().catch(e => { console.error('ERROR', e); process.exit(1); });
