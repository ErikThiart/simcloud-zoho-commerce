'use strict';
(function () {
  const byId = id => document.getElementById(id);
  const state = { products: [], selected: new Set(), request: null, busy: false, live: false };
  function notice(text, error = false) { byId('notice').textContent = text; byId('notice').className = error ? 'error' : ''; }
  function tab(name) {
    for (const section of ['setup', 'catalogue', 'orders']) { byId(section).hidden = section !== name; }
    for (const button of document.querySelectorAll('[data-tab]')) { button.setAttribute('aria-selected', String(button.dataset.tab === name)); }
  }
  function money(amount) { return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR' }).format(Number(amount)); }
  function node(tag, text, className = '') { const element = document.createElement(tag); element.textContent = text; if (className) { element.className = className; } return element; }
  function visible() { return SimcloudClient.filterProducts(state.products, byId('search').value, byId('service').value); }
  function render() {
    const items = visible(); const container = byId('products'); container.replaceChildren();
    byId('count').textContent = items.length + ' products';
    byId('select-all').checked = items.length > 0 && items.every(product => state.selected.has(product.sku));
    byId('select-all').indeterminate = items.some(product => state.selected.has(product.sku)) && !byId('select-all').checked;
    byId('import').disabled = state.busy || state.selected.size === 0;
    byId('import').textContent = 'Import selected' + (state.selected.size ? ' (' + state.selected.size + ')' : '');
    if (!items.length) { container.append(node('p', 'No matching products. Connect your account or change your search.', 'empty')); }
    for (const product of items) {
      const card = node('label', '', 'product'); const check = document.createElement('input'); check.type = 'checkbox';
      check.checked = state.selected.has(product.sku); check.disabled = state.busy; check.setAttribute('aria-label', 'Select ' + product.name);
      check.addEventListener('change', () => { if (check.checked) { state.selected.add(product.sku); } else { state.selected.delete(product.sku); } render(); });
      card.append(check);
      if (/^(networks|vas)\/[a-z0-9-]+\.jpg$/.test(product.logo || '')) {
        const image = document.createElement('img'); image.src = 'images/' + product.logo; image.alt = product.name + ' brand'; image.loading = 'lazy'; card.append(image);
      } else { card.append(node('span', product.service === 'electricity' ? '⚡' : 'SC', 'fallback')); }
      card.append(node('strong', product.name), node('small', product.service === 'data' ? 'Data bundle' : 'VAS voucher'));
      const price = node('span', money(product.amount), 'price'); price.append(node('small', 'retail price')); card.append(price); container.append(card);
    }
  }
  async function run(operation) {
    if (state.busy) { return; } state.busy = true; byId('connect').disabled = true; render();
    try { await operation(); } catch (error) { notice(error instanceof Error ? error.message : 'The request could not be completed.', true); }
    finally { state.busy = false; byId('connect').disabled = !state.request; render(); }
  }
  async function connect() {
    notice('Checking your connection and loading the latest products…');
    const health = await state.request('health');
    if (health.status !== 'connected') { throw new Error('SIMcloud connection could not be verified.'); }
    state.live = health.live_enabled === true;
    byId('mode').textContent = state.live ? 'Live fulfilment enabled' : 'Live fulfilment disabled';
    byId('mode').classList.toggle('live', state.live);
    byId('connection-details').textContent = 'Connected · Balance: ' + (health.balance == null ? 'unavailable' : money(health.balance)) + ' · Checked ' + new Date(health.checked_at).toLocaleString('en-ZA');
    const catalogue = await state.request('catalogue');
    if (!Array.isArray(catalogue.products)) { throw new Error('The catalogue response is incomplete.'); }
    state.products = catalogue.products; state.selected.clear(); render(); tab('catalogue');
    byId('catalogue-warnings').textContent = Array.isArray(catalogue.warnings) ? catalogue.warnings.join(' ') : '';
    notice('Your catalogue is ready. Select the products you want to add to your store.');
  }
  for (const button of document.querySelectorAll('[data-tab]')) { button.addEventListener('click', () => tab(button.dataset.tab)); }
  byId('connect').addEventListener('click', () => run(connect));
  byId('search').addEventListener('input', render); byId('service').addEventListener('change', render);
  byId('select-all').addEventListener('change', event => { if (state.busy) { return; } for (const product of visible()) { if (event.target.checked) { state.selected.add(product.sku); } else { state.selected.delete(product.sku); } } render(); });
  byId('import').addEventListener('click', () => run(async () => {
    notice('Importing selected products and their default logos…');
    const report = await SimcloudClient.importProducts([...state.selected], state.request, (done, total) => { byId('import-report').textContent = 'Processed ' + done + ' of ' + total + ' products.'; });
    byId('import-report').textContent = report.created + ' created · ' + report.existing + ' existing preserved · ' + report.images + ' images added.';
    byId('catalogue-warnings').textContent = report.warnings.join(' '); state.selected.clear(); notice('Product import finished. Review the report and your storefront.');
  }));
  byId('fixed-service').addEventListener('change', event => { byId('fixed-network').disabled = event.target.value === 'electricity'; byId('fixed-amount').min = event.target.value === 'electricity' ? '55' : '2'; byId('fixed-amount').max = event.target.value === 'electricity' ? '1000' : '999'; byId('fixed-amount').step = event.target.value === 'electricity' ? '1' : '0.01'; });
  byId('fixed-form').addEventListener('submit', event => { event.preventDefault(); run(async () => {
    if (!state.request) { throw new Error('Connect your account first.'); }
    const report = await state.request('fixed', { service: byId('fixed-service').value, network: byId('fixed-network').value, amount: byId('fixed-amount').value });
    notice(report.warnings?.length ? report.warnings.join(' ') : 'Product ready. Existing merchant details were preserved.');
  }); });
  byId('order-form').addEventListener('submit', event => { event.preventDefault(); run(async () => {
    if (!state.request) { throw new Error('Connect your account first.'); }
    const data = await state.request('order', { order_id: byId('order-id').value });
    if (!Array.isArray(data.rows)) { throw new Error('Order status response is incomplete.'); }
    const results = byId('order-results'); results.replaceChildren();
    for (const row of data.rows) {
      const card = node('article', '', 'order-row'); card.append(node('strong', row.name), node('p', row.state.replaceAll('_', ' ') + ' · ' + money(row.amount)));
      card.append(node('small', 'Provider: ' + (row.provider_id || 'No confirmed submission') + ' · Reference: ' + row.reference));
      if (row.message) { card.append(node('p', row.message)); }
      if (row.result?.length) {
        const reveal = node('button', 'Show delivery codes'); reveal.type = 'button';
        reveal.addEventListener('click', () => { reveal.remove(); for (const entry of row.result) { card.append(node('p', entry.description), node('code', entry.code)); } }); card.append(reveal);
      }
      results.append(card);
    }
    if (!data.rows.length) { results.append(node('p', 'No SIMcloud fulfilment records exist for this order yet.', 'details')); }
  }); });
  async function boot() {
    // Demo is available only through an explicit preview URL; it never becomes a live transport.
    if (new URLSearchParams(location.search).get('demo') === '1') {
      byId('preview').hidden = false;
      const response = await fetch('demo.json'); const fixture = await response.json();
      state.request = async (action, input = {}) => {
        if (action === 'health') { return { status: 'connected', live_enabled: false, balance: 1250, checked_at: new Date().toISOString() }; }
        if (action === 'catalogue') { return fixture; }
        if (action === 'import') { return { created: input.skus.length, existing: 0, images: input.skus.length, warnings: [] }; }
        if (action === 'fixed') { return { created: 1, warnings: [] }; }
        return { rows: [{ name: 'MTN 1GB data bundle', amount: '29.00', state: 'delivered', reference: 'demonstration-only', provider_id: '12345', result: [] }] };
      };
      byId('connection-details').textContent = 'Demonstration only. No real products will be created.'; byId('connect').disabled = false; return;
    }
    if (!window.ZFAPPS) { throw new Error('Open SIMcloud inside Zoho Commerce, or use the documented demonstration preview.'); }
    await ZFAPPS.extension.init();
    const response = await ZFAPPS.get('organization');
    const organization = response?.organization || response?.data?.organization;
    const id = String(organization?.organization_id || '');
    state.request = SimcloudClient.createClient(ZFAPPS, window.SIMCLOUD_CONFIG || {}, id);
    const bound = ['health', 'catalogue', 'import', 'fixed', 'order'].every(key => /^ac_[A-Za-z0-9_]+$/.test(SIMCLOUD_CONFIG[key] || ''));
    if (!bound) { throw new Error('Complete the secure connector setup in the installation guide. This draft cannot access your account yet.'); }
    byId('connection-details').textContent = 'Ready to connect. Setup reads your balance and catalogue; it makes no prepaid purchase.';
    byId('connect').disabled = false;
    try { const record = await ZFAPPS.get('salesorder'); if (record?.salesorder?.salesorder_id) { byId('order-id').value = record.salesorder.salesorder_id; } } catch (_) { /* Global sidebar has no order context. */ }
  }
  boot().catch(error => { state.request = null; byId('connect').disabled = true; byId('connection-details').textContent = error.message; notice(error.message, true); });
})();
