const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { unwrap, createClient, filterProducts, importProducts } = require(path.join(process.env.SC_TEST_RUNTIME || path.join(__dirname, '..'), 'app/js/client.js'));

test('SDK response envelope accepts object and JSON, rejects missing/invalid bodies', () => {
  assert.deepEqual(unwrap({ data: { body: '{"status":"connected"}' } }), { status: 'connected' });
  assert.deepEqual(unwrap({ data: { body: { products: [] } } }), { products: [] });
  assert.deepEqual(unwrap({ data: { body: JSON.stringify({ code: 0, data: { status_code: 200, body: '{"status":"connected"}' } }) } }), { status: 'connected' });
  const failure = { code: 0, data: { status_code: 400, body: '{"code":3028,"errorMessage":"ISC_UNSCOPED_SERVICE","status":"Failure"}' } };
  assert.throws(() => unwrap({ data: { body: JSON.stringify(failure) } }), /HTTP 400, service code 3028/);
  assert.throws(() => unwrap(failure), /HTTP 400, service code 3028/);
  for (const body of [null, [], 'broken', 'null', 23]) { assert.throws(() => unwrap({ data: { body } })); }
  assert.throws(() => unwrap({ data: { body: { error: 'Authorization failed.' } } }), /Authorization/);
});
test('secure transport passes API names and organization, never credentials or raw URLs', async () => {
  let options;
  const sdk = { request: async value => { options = value; return { data: { body: { status: 'connected' } } }; } };
  const request = createClient(sdk, { health: 'ac_com_health', import: 'ac_com_import', order: 'ac_com_order' }, '12345');
  await request('health');
  assert.equal(options.api_configuration_key, 'ac_com_health');
  assert.deepEqual(options.header, { 'X-SIMcloud-Organization': '12345' });
  assert.equal('url' in options, false);
  assert.equal('Authorization' in options.header, false);
  await request('import', { skus: ['sc-data-1-1000'] });
  assert.deepEqual(JSON.parse(options.body.raw), { skus: ['sc-data-1-1000'] });
  await request('order', { order_id: '45' });
  assert.deepEqual(options.url_query, { order_id: '45' });
  await assert.rejects(request('order', { order_id: '../other' }));
  await assert.rejects(request('enqueue'), /not configured/);
  assert.throws(() => createClient(sdk, {}, '0'), /organization/);
});
test('transport errors never expose tokens, request bodies or SDK diagnostics', async () => {
  const request = createClient({ request: async () => { throw new Error('Bearer SECRET +27820000000'); } }, { health: 'ac_com_health' }, '1');
  await assert.rejects(request('health'), error => !error.message.includes('SECRET') && !error.message.includes('+27'));
  const coded = createClient({ request: async () => { throw { code: 15, message: 'Bearer SECRET' }; } }, { health: 'ac_com_health' }, '1');
  await assert.rejects(coded('health'), error => error.message.includes('Zoho code 15') && !error.message.includes('SECRET'));
  const unsafe = createClient({ request: async () => { throw { code: 'Bearer SECRET' }; } }, { health: 'ac_com_health' }, '1');
  await assert.rejects(unsafe('health'), error => !error.message.includes('SECRET'));
  const reauth = createClient({ request: async () => { throw { code: 1071 }; } }, { health: 'ac_com_health' }, '1');
  await assert.rejects(reauth('health'), /identity verification/);
});
test('catalogue filters and bounded deduplicated imports preserve actionable totals', async () => {
  const products = [{ name: 'MTN 1GB', service: 'data' }, { name: 'Shoprite', service: 'vas' }];
  assert.equal(filterProducts(products, 'mtn', '').length, 1);
  assert.equal(filterProducts(products, '', 'vas').length, 1);
  const batches = []; const progress = [];
  const report = await importProducts(['a', 'b', 'c', 'd', 'a'], async (action, { skus }) => {
    assert.equal(action, 'import'); batches.push(skus);
    return { created: skus.length, existing: 0, images: skus.length, warnings: [] };
  }, (done, total) => progress.push([done, total]));
  assert.deepEqual(batches, [['a', 'b', 'c'], ['d']]);
  assert.deepEqual(progress, [[3, 4], [4, 4]]);
  assert.equal(report.created, 4); assert.equal(report.images, 4);
});
test('uncertain import stops without retrying a failed batch', async () => {
  let calls = 0;
  await assert.rejects(importProducts(['a', 'b', 'c', 'd'], async () => { calls++; throw new Error('timeout'); }));
  assert.equal(calls, 1);
});
