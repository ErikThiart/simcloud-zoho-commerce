<?php
declare(strict_types=1);

function sc_existing_products(callable $zoho): array {
    $existing = [];
    for ($page = 1; $page <= 500; $page++) {
        $body = $zoho('GET', '/products', ['page_start_from' => $page, 'per_page' => 200, 'filter_by' => 'Status.All']);
        if (!is_array($body['products'] ?? null) || !is_bool($body['page_context']['has_more_page'] ?? null)) {
            throw new RuntimeException('Zoho product pagination is incomplete. Import stopped to avoid duplicates.');
        }
        foreach ($body['products'] as $product) {
            if (!is_array($product)) { throw new RuntimeException('Malformed Zoho product record.'); }
            foreach ($product['variants'] ?? [] as $variant) {
                $sku = sc_text($variant['sku'] ?? '');
                if (!str_starts_with($sku, 'sc-')) { continue; }
                if (isset($existing[$sku])) { throw new RuntimeException('Duplicate SIMcloud SKU in Zoho. Resolve before importing.'); }
                $existing[$sku] = ['product_id' => sc_id($product['product_id'] ?? ''), 'variant_id' => sc_id($variant['variant_id'] ?? ''),
                    'has_image' => sc_id($product['document_id'] ?? '') !== ''];
                if ($existing[$sku]['product_id'] === '' || $existing[$sku]['variant_id'] === '') {
                    throw new RuntimeException('Incomplete existing product identity.');
                }
            }
        }
        if (!$body['page_context']['has_more_page']) { return $existing; }
    }
    throw new RuntimeException('Product pagination limit reached. Import stopped.');
}

function sc_product_body(array $product): array {
    return ['name' => $product['name'], 'url' => $product['sku'], 'product_type' => 'service', 'variant_type' => 'sales_and_purchases',
        'product_description' => htmlspecialchars($product['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'product_short_description' => htmlspecialchars($product['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'show_in_storefront' => true, 'is_returnable' => false, 'seo_title' => $product['name'],
        'variants' => [['rate' => $product['amount'], 'sku' => $product['sku'], 'maximum_order_quantity' => '1']]];
}

function sc_import(PDO $db, array $config, array $products, callable $zoho): array {
    $existing = sc_existing_products($zoho);
    $report = ['created' => 0, 'existing' => 0, 'images' => 0, 'warnings' => []];
    // Callers process bounded batches. A durable claim remains after an uncertain create.
    foreach ($products as $product) {
        $sku = $product['sku'];
        $known = $existing[$sku] ?? null;
        $stmt = $db->prepare('SELECT * FROM products WHERE sku=?'); $stmt->execute([$sku]);
        $saved = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($saved && $saved['state'] === 'needs_review' && !$known) { $report['warnings'][] = $sku . ': previous import requires review.'; continue; }
        if ($saved && $saved['state'] === 'creating' && !$known) { $report['warnings'][] = $sku . ': create outcome unknown; reconcile before retrying.'; continue; }
        if ($saved && $saved['state'] === 'imported' && (!$known || $saved['variant_id'] !== $known['variant_id'])) {
            $report['warnings'][] = $sku . ': merchant removed or changed product; preserved.'; continue;
        }
        if (!$saved) {
            $stmt = $db->prepare('INSERT OR IGNORE INTO products(sku,state,snapshot) VALUES(?,\'creating\',?)');
            $stmt->execute([$sku, json_encode($product, JSON_THROW_ON_ERROR)]);
            if ($stmt->rowCount() !== 1) { $report['warnings'][] = $sku . ': another import owns this product.'; continue; }
        }
        if (!$known) {
            try {
                $body = $zoho('POST', '/products', sc_product_body($product));
                $record = $body['product'] ?? [];
                $variant = $record['variants'][0] ?? [];
                $known = ['product_id' => sc_id($record['product_id'] ?? ''), 'variant_id' => sc_id($variant['variant_id'] ?? ''), 'has_image' => false];
                if ($known['product_id'] === '' || $known['variant_id'] === '' || ($variant['sku'] ?? '') !== $sku) {
                    throw new RuntimeException('Product create identity mismatch.');
                }
                $report['created']++;
            } catch (Throwable $error) {
                $stmt = $db->prepare('UPDATE products SET state=\'needs_review\',message=\'Create outcome unknown. Inspect Zoho before another import.\' WHERE sku=?');
                $stmt->execute([$sku]); $report['warnings'][] = $sku . ': create outcome requires review.'; sc_log('product_create_requires_review', [], (int) $error->getCode()); continue;
            }
        } else { $report['existing']++; }
        $stmt = $db->prepare('UPDATE products SET product_id=?,variant_id=?,state=\'imported\',message=\'\' WHERE sku=?');
        $stmt->execute([$known['product_id'], $known['variant_id'], $sku]);
        if (!$known['has_image'] && ($product['logo'] ?? '') !== '') {
            try { $zoho('POST', '/products/' . $known['product_id'] . '/images', [], $product['logo']); $report['images']++; }
            catch (Throwable $error) { $report['warnings'][] = $sku . ': product imported; image needs review.'; }
        }
    }
    return $report;
}
