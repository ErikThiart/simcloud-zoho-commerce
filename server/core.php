<?php
declare(strict_types=1);

function sc_text(mixed $value): string {
    return is_scalar($value) ? trim(strip_tags((string) $value)) : '';
}

function sc_id(mixed $value): string {
    $value = sc_text($value);
    return preg_match('/^[1-9][0-9]{0,29}$/D', $value) ? $value : '';
}

function sc_cents(mixed $value): ?int {
    if (!is_scalar($value) || !preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', (string) $value)) {
        return null;
    }
    $parts = explode('.', (string) $value);
    return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
}

function sc_decimal(int $value): string {
    return sprintf('%d.%02d', intdiv($value, 100), $value % 100);
}

function sc_phone(mixed $value): string {
    $value = preg_replace('/[\s()-]+/', '', sc_text($value));
    if (preg_match('/^0[6-8]\d{8}$/D', $value)) {
        $value = '+27' . substr($value, 1);
    } elseif (preg_match('/^27[6-8]\d{8}$/D', $value)) {
        $value = '+' . $value;
    }
    return preg_match('/^\+27[6-8]\d{8}$/D', $value) ? $value : '';
}

function sc_network(mixed $value): string {
    $key = strtolower(str_replace([' ', '-', '_'], '', sc_text($value)));
    $key = preg_replace('/^(?:pd|p)/', '', $key);
    return ['mtn' => 'mtn', 'vodacom' => 'vodacom', 'cellc' => 'cellc', 'heita' => 'heita', 'telkom' => 'heita'][$key] ?? '';
}

function sc_logo(array $product): string {
    if (in_array($product['service'] ?? '', ['airtime', 'data'], true)) {
        $network = $product['network'] ?? '';
        return in_array($network, ['mtn', 'vodacom', 'cellc', 'heita'], true)
            ? 'networks/' . ($network === 'heita' ? 'telkom' : $network) . '.jpg' : '';
    }
    if (($product['service'] ?? '') !== 'vas') {
        return '';
    }
    $path = parse_url(sc_text($product['logo_url'] ?? ''), PHP_URL_PATH);
    $host = strtolower((string) parse_url(sc_text($product['logo_url'] ?? ''), PHP_URL_HOST));
    $file = 'vas/' . basename(is_string($path) ? $path : '');
    if (in_array($host, ['simcloud.co.za', 'www.simcloud.co.za'], true) && is_file(__DIR__ . '/../app/images/' . $file)) {
        return $file;
    }
    $slug = preg_replace('/[^a-z0-9]/', '', strtolower(sc_text($product['slug'] ?? $product['name'] ?? '')));
    $slug = ['hussargrill' => 'hussarsgrill', 'johndorys' => 'johndory', 'pickandpay' => 'picknpay', 'takealot' => 'takealotcom'][$slug] ?? $slug;
    foreach (glob(__DIR__ . '/../app/images/vas/*.jpg') ?: [] as $candidate) {
        if (str_replace('-', '', pathinfo($candidate, PATHINFO_FILENAME)) === $slug) {
            return 'vas/' . basename($candidate);
        }
    }
    return '';
}

function sc_catalogue(array $data, array $vas): array {
    $products = [];
    $warnings = [];
    $pairs = [];
    foreach ($data as $row) {
        if (!is_array($row)) { continue; }
        $amount = sc_cents($row['amount'] ?? null);
        $network = sc_network($row['network'] ?? '');
        if ($network !== '' && $amount !== null) {
            $pairs[$network . ':' . $amount][sc_id($row['product_id'] ?? '')] = true;
        }
    }
    foreach (['data' => $data, 'vas' => $vas] as $service => $rows) {
        foreach ($rows as $row) {
            if (!is_array($row)) { $warnings[] = 'Malformed catalogue row skipped.'; continue; }
            $id = sc_id($row['product_id'] ?? '');
            $network = $service === 'data' ? sc_network($row['network'] ?? '') : '';
            $amounts = $service === 'data' ? [$row['amount'] ?? null] : ($row['available_amounts'] ?? []);
            if ($id === '' || !is_array($amounts) || ($service === 'data' && $network === '')) {
                $warnings[] = 'Incomplete catalogue product skipped.'; continue;
            }
            foreach ($amounts as $value) {
                $cents = sc_cents($value);
                if ($cents === null || $cents < 1 || ($service === 'data' && count($pairs[$network . ':' . $cents] ?? []) !== 1)) {
                    $warnings[] = 'Invalid or ambiguous catalogue amount skipped.'; continue;
                }
                $name = sc_text($service === 'data' ? ($row['description'] ?? '') : ($row['voucher_type'] ?? ''));
                if ($name === '') { $warnings[] = 'Unnamed catalogue product skipped.'; continue; }
                if ($service === 'data') {
                    $brand = ['mtn' => 'MTN', 'vodacom' => 'Vodacom', 'cellc' => 'Cell C', 'heita' => 'Telkom'][$network];
                    $plainName = preg_replace('/[^a-z0-9]/', '', strtolower($name));
                    if (!str_contains($plainName, str_replace(' ', '', strtolower($brand)))) { $name = $brand . ' ' . $name; }
                }
                $sku = 'sc-' . $service . '-' . $id . '-' . $cents;
                $product = ['sku' => $sku, 'service' => $service, 'product_id' => $id, 'network' => $network,
                    'amount' => sc_decimal($cents), 'name' => $name . ($service === 'vas' ? ' — R' . sc_decimal($cents) : ''),
                    'slug' => sc_text($row['slug'] ?? ''), 'logo_url' => sc_text($row['logo_url'] ?? ''),
                    'description' => sc_text($row['groupname'] ?? $name)];
                $product['logo'] = sc_logo($product);
                if ($product['logo'] === '') { $warnings[] = 'No bundled logo for ' . $sku . '.'; }
                if (isset($products[$sku]) && $products[$sku] !== $product) {
                    throw new RuntimeException('Conflicting catalogue rows share a product identifier.');
                }
                $products[$sku] = $product;
            }
        }
    }
    return ['products' => array_values($products), 'warnings' => array_values(array_unique($warnings))];
}

function sc_fixed_product(array $input): array {
    $service = sc_text($input['service'] ?? '');
    $amount = sc_cents($input['amount'] ?? null);
    $network = sc_network($input['network'] ?? '');
    if ($amount === null || !in_array($service, ['airtime', 'electricity'], true)
        || ($service === 'airtime' && ($amount < 200 || $amount > 99900 || $network === ''))
        || ($service === 'electricity' && ($amount < 5500 || $amount > 100000 || $amount % 100 !== 0))) {
        throw new RuntimeException('Choose airtime R2–R999 with a network, or whole-rand electricity R55–R1000.');
    }
    $sku = 'sc-' . $service . '-' . ($network ?: 'meter') . '-' . $amount;
    $name = $service === 'electricity' ? 'Prepaid electricity' : strtoupper($network === 'heita' ? 'Telkom' : $network) . ' airtime';
    $product = ['sku' => $sku, 'service' => $service, 'product_id' => '', 'network' => $network,
        'amount' => sc_decimal($amount), 'name' => $name . ' — R' . sc_decimal($amount), 'description' => $name];
    $product['logo'] = sc_logo($product);
    return $product;
}

function sc_paid(array $order, array $config): bool {
    if (($order['currency_code'] ?? '') !== 'ZAR' || ($order['invoiced_status'] ?? '') !== 'paid'
        || !in_array($order['order_status'] ?? '', ['confirmed', 'completed'], true)
        || ($order['is_offline_payment'] ?? null) !== false || ($order['is_test_order'] ?? null) !== false
        || !in_array($order['payment_mode'] ?? '', $config['verified_payment_modes'] ?? [], true)) {
        return false;
    }
    $total = sc_cents($order['total'] ?? null);
    $paid = 0;
    foreach ($order['payments'] ?? [] as $payment) {
        if (!is_array($payment) || sc_id($payment['payment_id'] ?? '') === '' || sc_text($payment['online_transaction_id'] ?? '') === '') { return false; }
        $amount = sc_cents($payment['amount'] ?? null);
        if ($amount === null || $amount < 1) { return false; }
        $paid += $amount;
    }
    return $total !== null && $total > 0 && $paid >= $total;
}

function sc_snapshot(array $product, array $line, array $config): array {
    if ((string) ($line['quantity'] ?? '') !== '1') {
        throw new RuntimeException('Prepaid items require quantity one. Place separate lines for separate recipients.');
    }
    $fields = [];
    foreach ($line['item_custom_fields'] ?? [] as $field) {
        if (is_array($field) && isset($field['api_name'])) {
            if (array_key_exists((string) $field['api_name'], $fields)) { throw new RuntimeException('Duplicate recipient fields require review.'); }
            $fields[(string) $field['api_name']] = $field['value'] ?? '';
        }
    }
    $recipient = sc_phone($fields[$config['recipient_field']] ?? '');
    $meter = sc_text($fields[$config['meter_field']] ?? '');
    if (in_array($product['service'], ['airtime', 'data', 'electricity'], true) && $recipient === '') {
        throw new RuntimeException('A valid South African recipient number is required.');
    }
    if ($product['service'] === 'electricity' && !preg_match('/^[a-zA-Z0-9]{1,50}$/D', $meter)) {
        throw new RuntimeException('A valid meter number is required.');
    }
    return array_merge($product, ['recipient' => $recipient, 'meter' => $meter]);
}

function sc_payload(array $snapshot, string $reference): array {
    $payload = ['amount' => (float) $snapshot['amount'], 'reference' => $reference];
    if (in_array($snapshot['service'], ['airtime', 'data'], true)) {
        $payload += ['msisdn' => $snapshot['recipient'], 'network' => ($snapshot['service'] === 'data' ? 'pd-' : 'p-') . $snapshot['network']];
    } elseif ($snapshot['service'] === 'vas') {
        $payload['product_id'] = (int) $snapshot['product_id'];
    } else {
        $payload = ['amount' => (int) $snapshot['amount'], 'client_reference' => $reference, 'meter_number' => $snapshot['meter'],
            'recipient' => $snapshot['recipient'], 'send_sms' => false];
    }
    return $payload;
}

function sc_ack(array $body, array $snapshot, string $reference): bool {
    $service = $snapshot['service'];
    $field = in_array($service, ['airtime', 'data'], true) ? 'request_id' : 'order_id';
    if (($body['status'] ?? '') !== 'success' || sc_id($body[$field] ?? '') === '' || sc_cents($body['amount'] ?? null) !== sc_cents($snapshot['amount'])) { return false; }
    if ($service === 'electricity') {
        return ($body['submitted_reference'] ?? '') === $reference && sc_text($body['client_reference'] ?? '') !== ''
            && ($body['meter_number'] ?? '') === $snapshot['meter'] && sc_phone($body['recipient'] ?? '') === $snapshot['recipient'];
    }
    if (($body['service'] ?? '') !== $service || ($body['reference'] ?? '') !== $reference) { return false; }
    return $service === 'vas' ? (string) ($body['product_id'] ?? '') === $snapshot['product_id']
        : sc_phone($body['msisdn'] ?? '') === $snapshot['recipient'] && sc_network($body['network'] ?? '') === $snapshot['network'];
}

function sc_status(array $body, array $snapshot, array $row): array {
    $service = $snapshot['service'];
    $status = $body['status'] ?? '';
    if ($service === 'electricity') {
        if ($status === 'pending') { return ['state' => 'pending', 'result' => []]; }
        $details = $body['transaction_details'] ?? [];
        if (($details['meter_number'] ?? '') !== $snapshot['meter'] || sc_phone($details['recipient'] ?? '') !== $snapshot['recipient']) {
            throw new RuntimeException('Electricity response identity mismatch.');
        }
    } elseif (!sc_ack(array_merge($body, ['status' => 'success']), $snapshot, $row['reference'])
        || (string) ($body['request_id'] ?? $body['order_id'] ?? '') !== (string) $row['provider_id']) {
        throw new RuntimeException('Provider response identity mismatch.');
    }
    if (in_array($status, ['queued', 'pending'], true)) { return ['state' => 'pending', 'result' => []]; }
    if (in_array($status, ['failed', 'cancelled'], true)) { return ['state' => 'failed', 'result' => []]; }
    if ($status !== (in_array($service, ['vas', 'electricity'], true) ? 'success' : 'delivered')) {
        throw new RuntimeException('Unknown provider status requires review.');
    }
    $result = [];
    $list = $service === 'vas' ? ($body['vouchers'] ?? []) : ($service === 'electricity' ? ($body['recharge_pin_information'] ?? []) : []);
    if (!is_array($list)) { throw new RuntimeException('Malformed delivery result.'); }
    foreach ($list as $entry) {
        if (!is_array($entry)) { throw new RuntimeException('Malformed delivery entry.'); }
        $code = sc_text($entry[$service === 'vas' ? 'voucher_code' : 'pin'] ?? '');
        if ($code === '') { throw new RuntimeException('Missing redeemable code.'); }
        $result[] = ['code' => $code, 'description' => sc_text($entry['voucher_type'] ?? $entry['description'] ?? ''),
            'expiry' => sc_text($entry['expiry_date'] ?? ''), 'units' => sc_text($entry['units'] ?? '')];
    }
    if (in_array($service, ['vas', 'electricity'], true) && !$result) { throw new RuntimeException('No redeemable codes returned.'); }
    return ['state' => 'delivered', 'result' => $result];
}
