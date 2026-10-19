(function (root) {
  'use strict';
  function bodyObject(payload) {
    if (typeof payload === 'string') {
      try { payload = JSON.parse(payload); } catch (_) { throw new Error('The connector returned an unreadable response.'); }
    }
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
      throw new Error('The connector response is incomplete.');
    }
    return payload;
  }
  function unwrap(response) {
    let data = response?.data;
    let payload = bodyObject(data?.body);
    // The installed SDK can wrap the invocation result inside its own body.
    if (payload.code === 0 && Number.isInteger(payload.data?.status_code) && Object.hasOwn(payload.data, 'body')) {
      data = payload.data; payload = bodyObject(data.body);
    }
    if (payload.error) { throw new Error(typeof payload.error === 'string' ? payload.error : 'The connector could not complete this request.'); }
    if (Number.isInteger(data?.status_code) && data.status_code >= 400) {
      const code = String(payload.code ?? payload.CODE ?? '');
      const detail = /^-?[0-9]{1,6}$/.test(code) ? ', service code ' + code : '';
      throw new Error('Zoho could not reach the connector (HTTP ' + data.status_code + detail + '). Check the secure connection or contact support.');
    }
    return payload;
  }
  function createClient(sdk, bindings, organizationId) {
    if (!/^[1-9][0-9]{0,29}$/.test(organizationId)) { throw new Error('The Zoho organization could not be verified.'); }
    return async function request(action, data = {}) {
      if (!['health', 'catalogue', 'import', 'fixed', 'order'].includes(action) || !/^ac_[A-Za-z0-9_]+$/.test(bindings[action] || '')) {
        throw new Error('The secure connector is not configured. Complete the developer installation guide first.');
      }
      const options = { api_configuration_key: bindings[action], header: { 'X-SIMcloud-Organization': organizationId } };
      if (['import', 'fixed'].includes(action)) { options.body = { mode: 'raw', raw: JSON.stringify(data) }; }
      else if (action === 'order') {
        if (!/^[1-9][0-9]{0,29}$/.test(String(data.order_id || ''))) { throw new Error('Enter a valid Zoho sales order ID.'); }
        options.url_query = { order_id: String(data.order_id) };
      }
      let response;
      try { response = await sdk.request(options); } catch (error) {
        const code = String(error?.code ?? error?.data?.code ?? '');
        if (code === '1071') { throw new Error('Zoho requires identity verification. Refresh Commerce, complete its password/OTP check, then reopen SIMcloud.'); }
        const detail = /^-?[0-9]{1,6}$/.test(code) ? ' (Zoho code ' + code + ')' : '';
        throw new Error('The secure connector could not be reached' + detail + '. Check the Zoho connection and API configurations.');
      }
      return unwrap(response);
    };
  }
  function filterProducts(products, text, service) {
    const query = String(text || '').trim().toLowerCase();
    return products.filter(product => (!service || product.service === service) && String(product.name).toLowerCase().includes(query));
  }
  async function importProducts(skus, request, onProgress = () => {}) {
    const unique = [...new Set(skus)];
    const report = { created: 0, existing: 0, images: 0, warnings: [] };
    for (let offset = 0; offset < unique.length; offset += 3) {
      const batch = await request('import', { skus: unique.slice(offset, offset + 3) });
      for (const key of ['created', 'existing', 'images']) {
        if (!Number.isInteger(batch[key]) || batch[key] < 0) { throw new Error('Import report is incomplete. Check the last batch before continuing.'); }
        report[key] += batch[key];
      }
      report.warnings.push(...(Array.isArray(batch.warnings) ? batch.warnings : []));
      onProgress(Math.min(offset + 3, unique.length), unique.length, report);
    }
    return report;
  }
  const api = { unwrap, createClient, filterProducts, importProducts };
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
  else { root.SimcloudClient = api; }
})(typeof window !== 'undefined' ? window : globalThis);
