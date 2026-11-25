<?php
/**
 * Behavioral test harness for the Delegat Bridge bulk v2 and batch product-read contracts.
 *
 * Stubs the minimal OpenCart framework surface (Controller base class, db,
 * config, request, response) with an in-memory SQL emulation of just the
 * table operations the bulk endpoints use, then exercises the controller the
 * same way the HTTP router does. Run: php scripts/test_bulk_v2.php
 *
 * This is a logic harness, not a substitute for a live OpenCart smoke test:
 * it verifies per-row attribution, identity resolution, warehouse rejection,
 * transaction begin/commit/rollback ordering and response shape exactly as
 * the bridge code expresses them.
 */
namespace Opencart\System\Engine {
    class Controller {
        protected $registry = [];
        public function __get($key) {
            return $this->registry[$key] ?? null;
        }
        public function __set($key, $value) {
            $this->registry[$key] = $value;
        }
    }
}

namespace Opencart\Catalog\Controller\Extension\DelegatBridge\Api {
    if (!defined('DB_PREFIX')) {
        define('DB_PREFIX', 'oc_');
    }
    if (!defined('DIR_IMAGE')) {
        define('DIR_IMAGE', sys_get_temp_dir() . '/delegat_test_image/');
    }
}

namespace {
    use Opencart\Catalog\Controller\Extension\DelegatBridge\Api\Delegat;

    require_once __DIR__ . '/../extension/delegat_bridge/catalog/controller/api/delegat.php';

    /** In-memory emulation of the slice of MySQL the bulk endpoints use. */
    final class FakeDbResult {
        public array $rows = [];
        public array $row = [];
        public int $num_rows = 0;
    }

    final class FakeDb {
        public array $products = [];      // product_id => row
        public array $specials = [];      // list of rows
        public array $descriptions = [];
        public array $productImages = []; // list of product_image rows
        public array $log = [];           // every SQL statement in order
        public array $existingColumns = ['sku' => true, 'model' => true, 'variant' => true];
        public int $lastId = 0;
        public ?string $failOnPattern = null;   // regex; first matching write throws
        public ?string $failOnSelectPattern = null; // regex; first matching read throws
        public bool $failOnce = true;
        public array $existingTables = ['product_special' => true];
        private ?array $txSnapshot = null;

        private function productIsActive(array $product): bool {
            return !array_key_exists('status', $product) || (int)$product['status'] === 1;
        }

        public function escape($v): string {
            return str_replace(['\\', "'", "\0", "\n", "\r", "\x1a"], ['\\\\', "\\'", '\\0', '\\n', '\\r', '\\Z'], (string)$v);
        }

        public function getLastId() {
            return $this->lastId;
        }

        public function query(string $sql): FakeDbResult {
            $this->log[] = $sql;
            $result = new FakeDbResult();

            $verb = strtoupper(strtok(trim($sql), " 	
"));
            if ($verb === 'START') {
                $this->txSnapshot = ['products' => $this->products, 'specials' => $this->specials, 'descriptions' => $this->descriptions];
                return $result;
            }
            if ($verb === 'COMMIT') {
                $this->txSnapshot = null;
                return $result;
            }
            if ($verb === 'ROLLBACK') {
                if ($this->txSnapshot !== null) {
                    $this->products = $this->txSnapshot['products'];
                    $this->specials = $this->txSnapshot['specials'];
                    $this->descriptions = $this->txSnapshot['descriptions'];
                    $this->txSnapshot = null;
                }
                return $result;
            }

            if ($this->failOnSelectPattern !== null && preg_match($this->failOnSelectPattern, $sql) && stripos($sql, 'SELECT') === 0) {
                if ($this->failOnce) {
                    $this->failOnSelectPattern = null;
                }
                throw new \Exception('simulated read failure');
            }

            if ($this->failOnPattern !== null && preg_match($this->failOnPattern, $sql) && stripos($sql, 'SELECT') !== 0) {
                if ($this->failOnce) {
                    $this->failOnPattern = null;
                }
                throw new \Exception('simulated write failure');
            }

            if (preg_match('/^SELECT TABLE_NAME FROM information_schema\.TABLES WHERE TABLE_SCHEMA = DATABASE\(\) AND TABLE_NAME = \'oc_(\w+)\'$/', $sql, $m)) {
                if (!empty($this->existingTables[$m[1]])) {
                    $result->rows = [['TABLE_NAME' => 'oc_' . $m[1]]];
                    $result->num_rows = 1;
                }
                return $result;
            }

            if (preg_match('/^SHOW COLUMNS FROM `oc_(\w+)` LIKE \'(\w+)\'/', $sql, $m)) {
                if (isset($this->existingColumns[$m[2]])) {
                    $result->rows = [['Field' => $m[2]]];
                    $result->num_rows = 1;
                }
                return $result;
            }

            if (preg_match('/^SELECT product_id FROM `oc_product` WHERE product_id = (\d+)$/', $sql, $m)) {
                if (isset($this->products[(int)$m[1]])) {
                    $result->rows = [['product_id' => (int)$m[1]]];
                    $result->row = $result->rows[0];
                    $result->num_rows = 1;
                }
                return $result;
            }

            if (preg_match('/^SELECT product_id FROM `oc_product` WHERE BINARY `(sku|model)` = \'((?:[^\'\\\\]|\\\\.)*)\'$/', $sql, $m)) {
                $rows = [];
                foreach ($this->products as $pid => $p) {
                    if (($p[$m[1]] ?? null) === stripcslashes($m[2])) {
                        $rows[] = ['product_id' => $pid];
                    }
                }
                $result->rows = $rows;
                $result->num_rows = count($rows);
                return $result;
            }

            // products.batch identity preselection: one OR'ed candidate query.
            if (preg_match('/^SELECT p\.product_id, p\.model, (p\.sku|\'\') AS sku\s+FROM `oc_product` p\s+WHERE p\.status = 1 AND \((.*)\)$/s', $sql, $m)) {
                $conditions = $m[2];
                $ids = [];
                if (preg_match('/p\.product_id IN \(([\d,]+)\)/', $conditions, $idMatch)) {
                    $ids = array_map('intval', explode(',', $idMatch[1]));
                }
                preg_match_all('/BINARY `(sku|model)` = \'((?:[^\'\\\\]|\\\\.)*)\'/', $conditions, $aliasMatches, PREG_SET_ORDER);

                $rows = [];
                foreach ($this->products as $pid => $p) {
                    if (!$this->productIsActive($p)) {
                        continue;
                    }
                    $matched = in_array((int)$pid, $ids, true);
                    foreach ($aliasMatches as $aliasMatch) {
                        if (($p[$aliasMatch[1]] ?? null) === stripcslashes($aliasMatch[2])) {
                            $matched = true;
                            break;
                        }
                    }
                    if ($matched) {
                        $rows[] = [
                            'product_id' => (int)$pid,
                            'model' => (string)($p['model'] ?? ''),
                            'sku' => $m[1] === "''" ? '' : (string)($p['sku'] ?? ''),
                        ];
                    }
                }
                $result->rows = $rows;
                $result->num_rows = count($rows);
                return $result;
            }

            // products.batch payload load: same catalog shape as the paginated listing.
            if (preg_match('/^SELECT\s+p\.product_id,\s+p\.model,\s+.*?\s+FROM `oc_product` p\s+.*?\s+WHERE p\.status = 1 AND p\.product_id IN \(([\d,]+)\)\s+ORDER BY p\.product_id ASC$/s', $sql, $m)) {
                $rows = [];
                foreach (array_map('intval', explode(',', $m[1])) as $pid) {
                    $p = $this->products[$pid] ?? null;
                    if (!is_array($p) || !$this->productIsActive($p)) {
                        continue;
                    }
                    $special = null;
                    foreach ($this->specials as $specialRow) {
                        if ((int)($specialRow['product_id'] ?? 0) === $pid) {
                            $special = (float)($specialRow['price'] ?? 0);
                            break;
                        }
                    }
                    $rows[] = [
                        'product_id' => $pid,
                        'model' => (string)($p['model'] ?? ''),
                        'sku' => (string)($p['sku'] ?? ''),
                        'price' => (float)($p['price'] ?? 0),
                        'special_price' => $special,
                        'quantity' => (int)($p['quantity'] ?? 0),
                        'image' => (string)($p['image'] ?? ''),
                        'name' => (string)($p['name'] ?? ''),
                        'description' => (string)($p['description'] ?? ''),
                        'category_id' => isset($p['category_id']) ? (int)$p['category_id'] : null,
                        'category_name' => isset($p['category_name']) ? (string)$p['category_name'] : null,
                    ];
                }
                usort($rows, fn($a, $b) => $a['product_id'] <=> $b['product_id']);
                $result->rows = $rows;
                $result->num_rows = count($rows);
                return $result;
            }

            if (preg_match('/^SELECT product_id, image FROM `oc_product_image`\s+WHERE product_id IN \(([\d,]+)\)\s+ORDER BY product_id ASC, sort_order ASC, product_image_id ASC$/s', $sql, $m)) {
                $ids = array_map('intval', explode(',', $m[1]));
                $rows = array_values(array_filter($this->productImages, fn($row) => in_array((int)$row['product_id'], $ids, true)));
                usort($rows, function ($a, $b) {
                    return [$a['product_id'], $a['sort_order'] ?? 0, $a['product_image_id'] ?? 0]
                        <=> [$b['product_id'], $b['sort_order'] ?? 0, $b['product_image_id'] ?? 0];
                });
                $result->rows = $rows;
                $result->num_rows = count($rows);
                return $result;
            }

            if (preg_match('/^UPDATE `oc_product` SET price = \'([\d.]+)\', date_modified = NOW\(\) WHERE product_id = (\d+)/', $sql, $m)) {
                $this->products[(int)$m[2]]['price'] = (float)$m[1];
                return $result;
            }

            if (preg_match('/^UPDATE `oc_product` SET quantity = (\d+), date_modified = NOW\(\) WHERE product_id = (\d+)/', $sql, $m)) {
                $this->products[(int)$m[2]]['quantity'] = (int)$m[1];
                return $result;
            }

            if (preg_match('/^DELETE FROM `oc_product_special` WHERE product_id = (\d+) AND customer_group_id = (\d+)/', $sql, $m)) {
                $this->specials = array_values(array_filter(
                    $this->specials,
                    fn($s) => !((int)$s['product_id'] === (int)$m[1] && (int)$s['customer_group_id'] === (int)$m[2])));
                return $result;
            }

            if (preg_match('/^INSERT INTO `oc_product_special` SET\s+product_id = (\d+),\s+customer_group_id = (\d+),\s+priority = (\d+),\s+price = \'([\d.]+)\'/s', $sql, $m)) {
                $this->specials[] = [
                    'product_id' => (int)$m[1],
                    'customer_group_id' => (int)$m[2],
                    'priority' => (int)$m[3],
                    'price' => (float)$m[4],
                    'date_start' => '0000-00-00',
                    'date_end' => '0000-00-00',
                ];
                return $result;
            }

            if (preg_match('/^INSERT INTO `oc_product` SET\s+(.*?)\s*$/s', $sql, $m) && stripos($sql, 'product_special') === false && stripos($sql, 'product_image') === false && stripos($sql, 'product_description') === false && stripos($sql, 'product_to_') === false && stripos($sql, 'product_attribute') === false && stripos($sql, 'product_option') === false) {
                $id = 1;
                foreach (array_keys($this->products) as $k) { $id = max($id, (int)$k + 1); }
                $row = ['product_id' => $id, 'sku' => '', 'model' => '', 'price' => 0.0, 'quantity' => 0];
                if (preg_match_all("/(\w+) = '((?:[^'\\\\]|\\\\.)*)'/", $m[1], $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $pair) {
                        $row[$pair[1]] = stripcslashes($pair[2]);
                    }
                }
                $this->products[$id] = $row;
                $this->lastId = $id;
                return $result;
            }

            if (preg_match('/^UPDATE `oc_product` SET (.*?) WHERE product_id = (\d+)/s', $sql, $m)) {
                $pid = (int)$m[2];
                if (isset($this->products[$pid]) && preg_match_all("/(\w+) = '((?:[^'\\\\]|\\\\.)*)'/", $m[1], $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $pair) {
                        if ($pair[1] !== 'date_modified') {
                            $this->products[$pid][$pair[1]] = stripcslashes($pair[2]);
                        }
                    }
                }
                return $result;
            }

            // Anything else (product graph writes, reads this harness does not
            // drive) is a successful no-op so upsert paths can run.
            return $result;
        }
    }

    final class FakeConfig {
        public function __construct(private array $values) {}
        public function get($key) {
            return $this->values[$key] ?? null;
        }
    }

    final class FakeRequest {
        public array $get = [];
        public array $server = ['REMOTE_ADDR' => '127.0.0.1', 'SERVER_PROTOCOL' => 'HTTP/1.1'];
    }

    final class FakeResponse {
        public array $headers = [];
        public string $output = '';
        public function addHeader($h): void {
            $this->headers[] = $h;
        }
        public function setOutput($o): void {
            $this->output = $o;
        }
    }

    /** Test double invoking the private endpoint methods via reflection, the same way index() routes them. */
    final class TestableDelegat extends Delegat {
        public function callPrivate(string $method, array $payload = []) {
            $m = new \ReflectionMethod(Delegat::class, $method);
            $m->setAccessible(true);
            return $m->invokeArgs($this, $payload === [] ? [] : [$payload]);
        }
        public function callPrivateArgs(string $method, array $args) {
            $m = new \ReflectionMethod(Delegat::class, $method);
            $m->setAccessible(true);
            return $m->invokeArgs($this, $args);
        }
        public function callHealth() {
            return $this->callPrivate('health');
        }
        public function callProducts() {
            return $this->callPrivate('products');
        }
    }

    function makeController(FakeDb $db, array $configValues = []): TestableDelegat {
        $c = new TestableDelegat();
        $c->db = $db;
        $c->config = new FakeConfig($configValues + [
            'module_delegat_bridge_status' => 1,
            'module_delegat_bridge_api_key' => 'test-key',
            'config_customer_group_id' => 1,
            'config_name' => 'Test shop',
            'config_url' => 'https://shop.test/',
        ]);
        $c->request = new FakeRequest();
        $c->response = new FakeResponse();
        return $c;
    }

    $failures = [];
    $tests = 0;
    function check(string $name, bool $cond, string $detail = ''): void {
        global $failures, $tests;
        $tests++;
        if (!$cond) {
            $failures[] = $name . ($detail !== '' ? " — $detail" : '');
            echo "FAIL: $name" . ($detail !== '' ? " — $detail" : '') . "\n";
        } else {
            echo "ok:   $name\n";
        }
    }

    // ---------- health: capability details ----------
    $db = new FakeDb();
    $c = makeController($db);
    $health = $c->callHealth();
    check('health api_version is 1.5.0', ($health['api_version'] ?? '') === '1.5.0');
    $caps = $health['capability_details'] ?? [];
    check('health capability_details complete',
        ($caps['bulk.v2.per_row_results'] ?? null) === true
        && ($caps['bulk.alias_resolution'] ?? null) === true
        && ($caps['bulk.per_row_transactions'] ?? null) === true
        && ($caps['stocks.warehouse_reject'] ?? null) === true
        && ($caps['bulk.max_rows'] ?? null) === 500
        && ($caps['products.batch'] ?? null) === true
        && ($caps['products.batch.max_rows'] ?? null) === 500);
    check('health legacy capabilities retained',
        in_array('prices.bulk', $health['capabilities'] ?? [], true)
        && in_array('products.batch', $health['capabilities'] ?? [], true));

    // ---------- prices/bulk: per-row results with verbatim identity ----------
    $db = new FakeDb();
    $db->products = [
        42 => ['product_id' => 42, 'sku' => 'SKU-42', 'model' => 'M-42', 'price' => 10.0, 'quantity' => 5],
        43 => ['product_id' => 43, 'sku' => '1000', 'model' => 'M-43', 'price' => 20.0, 'quantity' => 3],
    ];
    $c = makeController($db);
    $resp = $c->callPrivate('bulkPrices', ['items' => [
        ['external_product_id' => '42', 'price' => 99.5],
        ['external_product_id' => 'SKU-42', 'id_kind' => 'sku', 'price' => 88.0],
        ['external_product_id' => 'no-such', 'id_kind' => 'sku', 'price' => 1.0],
    ]]);
    $items = $resp['items'] ?? [];
    check('prices: one result row per input row', count($items) === 3, json_encode($items));
    check('prices: row 0 success by numeric product id',
        ($items[0]['success'] ?? null) === true && $items[0]['index'] === 0
        && $items[0]['resolved_product_id'] === 42 && $items[0]['external_product_id'] === '42');
    check('prices: row 1 success by exact sku, verbatim identity preserved',
        ($items[1]['success'] ?? null) === true && $items[1]['external_product_id'] === 'SKU-42'
        && $items[1]['resolved_product_id'] === 42);
    check('prices: row 2 fails product_not_found with verbatim non-numeric identity',
        ($items[2]['success'] ?? null) === false && ($items[2]['error_code'] ?? '') === 'product_not_found'
        && $items[2]['external_product_id'] === 'no-such');
    check('prices: counters consistent', $resp['success_count'] === 2 && $resp['failed_count'] === 1 && $resp['total_count'] === 3);
    check('prices: legacy errors array carries verbatim identity',
        count($resp['errors']) === 1 && $resp['errors'][0]['external_id'] === 'no-such');
    check('prices: base price written', abs(($db->products[42]['price'] ?? 0) - 88.0) < 0.0001);

    // numeric sku must not be confused with a product id
    $db = new FakeDb();
    $db->products = [
        43 => ['product_id' => 43, 'sku' => '1000', 'model' => 'M-43', 'price' => 20.0, 'quantity' => 3],
        1000 => ['product_id' => 1000, 'sku' => 'OTHER', 'model' => 'M-1000', 'price' => 5.0, 'quantity' => 1],
    ];
    $c = makeController($db);
    $resp = $c->callPrivate('bulkPrices', ['items' => [
        ['external_product_id' => '1000', 'id_kind' => 'sku', 'price' => 77.0],
    ]]);
    check('prices: numeric sku with id_kind=sku resolves the sku row, not product 1000',
        ($resp['items'][0]['resolved_product_id'] ?? null) === 43);
    check('prices: product 1000 price untouched by sku row', abs(($db->products[1000]['price'] ?? 0) - 5.0) < 0.0001);

    // same numeric value as bare id is the legacy product-id path
    $resp = $c->callPrivate('bulkPrices', ['items' => [
        ['external_product_id' => '1000', 'price' => 66.0],
    ]]);
    check('prices: bare numeric id keeps legacy product-id semantics',
        ($resp['items'][0]['resolved_product_id'] ?? null) === 1000);

    // Whitespace is significant for exact aliases, while the legacy numeric-id
    // path keeps the old int-cast tolerance for padded digits.
    $db = new FakeDb();
    $db->products = [
        42 => ['product_id' => 42, 'sku' => ' A-42', 'model' => 'M-42', 'price' => 10.0, 'quantity' => 5],
        81 => ['product_id' => 81, 'sku' => 'A-42', 'model' => 'M-81', 'price' => 20.0, 'quantity' => 3],
    ];
    $c = makeController($db);
    $resp = $c->callPrivate('bulkPrices', ['items' => [
        ['external_product_id' => ' A-42', 'id_kind' => 'sku', 'price' => 44.0],
    ]]);
    check('prices: padded sku matches only the verbatim padded alias',
        ($resp['items'][0]['resolved_product_id'] ?? null) === 42
        && abs($db->products[42]['price'] - 44.0) < 0.0001
        && abs($db->products[81]['price'] - 20.0) < 0.0001,
        json_encode([$resp['items'][0] ?? null, $db->products]));
    $resp = $c->callPrivate('bulkPrices', ['items' => [
        ['external_product_id' => ' 42 ', 'price' => 45.0],
    ]]);
    check('prices: padded bare numeric id keeps legacy product-id compatibility',
        ($resp['items'][0]['resolved_product_id'] ?? null) === 42);

    // An alias lookup storage failure is contained to its own row; the next
    // row still resolves and writes, and the response remains per-row v2.
    $db = new FakeDb();
    $db->products = [
        42 => ['product_id' => 42, 'sku' => 'SKU-42', 'model' => 'M-42', 'price' => 10.0, 'quantity' => 5],
        43 => ['product_id' => 43, 'sku' => 'SKU-43', 'model' => 'M-43', 'price' => 20.0, 'quantity' => 3],
    ];
    $db->failOnSelectPattern = '/BINARY `sku`/';
    $c = makeController($db);
    $resp = $c->callPrivate('bulkPrices', ['items' => [
        ['external_product_id' => 'SKU-42', 'id_kind' => 'sku', 'price' => 50.0],
        ['external_product_id' => '43', 'price' => 51.0],
    ]]);
    check('prices: lookup exception is row-contained write_failed',
        count($resp['items'] ?? []) === 2
        && ($resp['items'][0]['success'] ?? null) === false
        && ($resp['items'][0]['error_code'] ?? '') === 'write_failed'
        && ($resp['items'][1]['success'] ?? null) === true,
        json_encode($resp['items'] ?? []));
    check('prices: later row still writes after lookup exception', abs($db->products[43]['price'] - 51.0) < 0.0001);

    // ambiguity: two products share one sku -> row failure, no write
    $db = new FakeDb();
    $db->products = [
        1 => ['product_id' => 1, 'sku' => 'DUP', 'model' => 'x', 'price' => 1.0, 'quantity' => 1],
        2 => ['product_id' => 2, 'sku' => 'DUP', 'model' => 'y', 'price' => 2.0, 'quantity' => 1],
    ];
    $c = makeController($db);
    $resp = $c->callPrivate('bulkPrices', ['items' => [
        ['external_product_id' => 'DUP', 'id_kind' => 'sku', 'price' => 50.0],
    ]]);
    check('prices: ambiguous exact sku fails the row',
        ($resp['items'][0]['success'] ?? null) === false && ($resp['items'][0]['error_code'] ?? '') === 'product_ambiguous');
    check('prices: ambiguous row never writes', abs($db->products[1]['price'] - 1.0) < 0.0001 && abs($db->products[2]['price'] - 2.0) < 0.0001);

    // distinct fields form
    $db = new FakeDb();
    $db->products = [7 => ['product_id' => 7, 'sku' => 'S7', 'model' => 'MDL-7', 'price' => 1.0, 'quantity' => 1]];
    $c = makeController($db);
    $resp = $c->callPrivate('bulkPrices', ['items' => [
        ['sku' => 'S7', 'price' => 10.0],
        ['model' => 'MDL-7', 'price' => 11.0],
        ['product_id' => '7', 'price' => 12.0],
    ]]);
    check('prices: distinct sku/model/product_id fields all resolve',
        array_reduce($resp['items'], fn($c2, $r) => $c2 && $r['success'] && $r['resolved_product_id'] === 7, true));

    // special price inside per-row transaction; failure rolls back base price
    $db = new FakeDb();
    $db->products = [9 => ['product_id' => 9, 'sku' => 'S9', 'model' => 'm', 'price' => 1.0, 'quantity' => 1]];
    $c = makeController($db);
    $resp = $c->callPrivate('bulkPrices', ['items' => [
        ['external_product_id' => '9', 'price' => 30.0, 'special_price' => 25.0],
    ]]);
    check('prices: special price written', count($db->specials) === 1 && abs($db->specials[0]['price'] - 25.0) < 0.0001);
    $tx = array_values(array_filter($db->log, fn($s) => in_array(trim($s), ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true)));
    check('prices: row write wrapped in START TRANSACTION..COMMIT', $tx === ['START TRANSACTION', 'COMMIT'], json_encode($tx));

    $db = new FakeDb();
    $db->products = [9 => ['product_id' => 9, 'sku' => 'S9', 'model' => 'm', 'price' => 1.0, 'quantity' => 1]];
    $db->failOnPattern = '/INSERT INTO `oc_product_special`/';
    $c = makeController($db);
    $resp = $c->callPrivate('bulkPrices', ['items' => [
        ['external_product_id' => '9', 'price' => 30.0, 'special_price' => 25.0],
    ]]);
    check('prices: mid-row failure is a row-level write_failed',
        ($resp['items'][0]['success'] ?? null) === false && ($resp['items'][0]['error_code'] ?? '') === 'write_failed');
    check('prices: base price rolled back atomically', abs($db->products[9]['price'] - 1.0) < 0.0001);
    $tx = array_values(array_filter($db->log, fn($s) => in_array(trim($s), ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true)));
    check('prices: failure path issues ROLLBACK', in_array('ROLLBACK', $tx, true) && !in_array('COMMIT', $tx, true), json_encode($tx));

    // negative price rejected row-level
    $resp = $c->callPrivate('bulkPrices', ['items' => [
        ['external_product_id' => '9', 'price' => -5.0],
    ]]);
    check('prices: negative price rejected', ($resp['items'][0]['error_code'] ?? '') === 'invalid_price');

    // ---------- stocks/bulk ----------
    $db = new FakeDb();
    $db->products = [42 => ['product_id' => 42, 'sku' => 'SKU-42', 'model' => 'M-42', 'price' => 1.0, 'quantity' => 5]];
    $c = makeController($db);
    $resp = $c->callPrivate('bulkStocks', ['items' => [
        ['external_product_id' => '42', 'quantity' => 11],
        ['external_product_id' => '42', 'quantity' => 3, 'warehouse_id' => 'wh-1'],
        ['external_product_id' => 'SKU-42', 'id_kind' => 'sku', 'quantity' => 7],
        ['external_product_id' => '42'],
    ]]);
    $items = $resp['items'];
    check('stocks: plain row applied', ($items[0]['success'] ?? null) === true && $db->products[42]['quantity'] === 7);
    check('stocks: warehouse row rejected, never written globally',
        ($items[1]['success'] ?? null) === false && ($items[1]['error_code'] ?? '') === 'warehouse_unsupported');
    check('stocks: alias row resolves and wins ordering', ($items[2]['success'] ?? null) === true);
    check('stocks: missing quantity rejected instead of zeroing stock',
        ($items[3]['success'] ?? null) === false && ($items[3]['error_code'] ?? '') === 'invalid_quantity');

    $db = new FakeDb();
    $db->products = [
        42 => ['product_id' => 42, 'sku' => 'SKU-42', 'model' => 'M-42', 'price' => 1.0, 'quantity' => 5],
        43 => ['product_id' => 43, 'sku' => 'SKU-43', 'model' => 'M-43', 'price' => 1.0, 'quantity' => 3],
    ];
    $db->failOnSelectPattern = '/BINARY `sku`/';
    $c = makeController($db);
    $resp = $c->callPrivate('bulkStocks', ['items' => [
        ['external_product_id' => 'SKU-42', 'id_kind' => 'sku', 'quantity' => 9],
        ['external_product_id' => '43', 'quantity' => 8],
    ]]);
    check('stocks: lookup exception is row-contained and later rows continue',
        count($resp['items'] ?? []) === 2
        && ($resp['items'][0]['error_code'] ?? '') === 'write_failed'
        && ($resp['items'][1]['success'] ?? null) === true
        && $db->products[43]['quantity'] === 8
        && $db->products[42]['quantity'] === 5,
        json_encode([$resp['items'] ?? [], $db->products]));

    // ---------- products/bulk: explicit index, per-row containment ----------
    $db = new FakeDb();
    $c = makeController($db);
    $resp = $c->callPrivate('bulkProducts', ['items' => [
        ['local_product_id' => 101, 'product' => ['name' => 'A', 'model' => 'A-1', 'sku' => 'SKU-A-1']],
        ['local_product_id' => 102, 'product' => ['model' => 'NO-NAME']],
    ]]);
    $items = $resp['items'];
    check('cards: explicit indexes parallel to input',
        count($items) === 2 && $items[0]['index'] === 0 && $items[1]['index'] === 1);
    if (!($items[0]['success'] ?? null)) { echo 'DEBUG card row0: ' . json_encode($items[0]) . "
"; }
    check('cards: valid row succeeds with product id',
        ($items[0]['success'] ?? null) === true && !empty($items[0]['product_id']));
    $createdCard = $db->products[(int)($items[0]['product_id'] ?? 0)] ?? null;
    check('cards: SQL field parser preserves model and sku verbatim',
        is_array($createdCard) && ($createdCard['model'] ?? '') === 'A-1' && ($createdCard['sku'] ?? '') === 'SKU-A-1',
        json_encode($createdCard));
    check('cards: invalid row fails at its own index without stopping the batch',
        ($items[1]['success'] ?? null) === false && isset($items[1]['error_message']));

    // ---------- products.batch: exact identity read, per-row presence ----------
    $db = new FakeDb();
    $db->products = [
        42 => ['product_id' => 42, 'sku' => 'SKU-42', 'model' => 'M-42', 'price' => 10.0, 'quantity' => 5, 'status' => 1, 'image' => 'main42.jpg', 'name' => 'Product 42', 'description' => '<p>Forty-two</p>', 'category_id' => 7, 'category_name' => 'Catalog'],
        43 => ['product_id' => 43, 'sku' => '1000', 'model' => 'M-43', 'price' => 20.0, 'quantity' => 3, 'status' => 1, 'name' => 'Product 43'],
        44 => ['product_id' => 44, 'sku' => 'case', 'model' => 'M-44', 'price' => 30.0, 'quantity' => 2, 'status' => 1, 'name' => 'Lower case'],
        45 => ['product_id' => 45, 'sku' => 'CASE', 'model' => 'M-45', 'price' => 40.0, 'quantity' => 1, 'status' => 1, 'name' => 'Upper case'],
        46 => ['product_id' => 46, 'sku' => ' A-46', 'model' => 'M-46', 'price' => 50.0, 'quantity' => 8, 'status' => 1, 'name' => 'Padded'],
        47 => ['product_id' => 47, 'sku' => 'A-46', 'model' => 'M-47', 'price' => 60.0, 'quantity' => 9, 'status' => 1, 'name' => 'Unpadded'],
        48 => ['product_id' => 48, 'sku' => 'DUP', 'model' => 'M-48', 'price' => 1.0, 'quantity' => 1, 'status' => 1],
        49 => ['product_id' => 49, 'sku' => 'DUP', 'model' => 'M-49', 'price' => 2.0, 'quantity' => 1, 'status' => 1],
        50 => ['product_id' => 50, 'sku' => 'DISABLED', 'model' => 'M-50', 'price' => 3.0, 'quantity' => 1, 'status' => 0],
        1000 => ['product_id' => 1000, 'sku' => 'OTHER', 'model' => 'M-1000', 'price' => 4.0, 'quantity' => 1, 'status' => 1],
    ];
    $db->specials[] = ['product_id' => 42, 'customer_group_id' => 1, 'priority' => 1, 'price' => 8.5, 'date_start' => '0000-00-00', 'date_end' => '0000-00-00'];
    $db->productImages[] = ['product_id' => 42, 'image' => 'gallery42.jpg', 'sort_order' => 0, 'product_image_id' => 5];
    $c = makeController($db);
    $resp = $c->callPrivate('batchProducts', ['items' => [
        ['external_product_id' => '42'],
        ['external_product_id' => 'SKU-42'],
        ['external_product_id' => '1000', 'id_kind' => 'sku'],
        ['external_product_id' => 'case', 'id_kind' => 'sku'],
        ['external_product_id' => ' A-46', 'id_kind' => 'sku'],
        ['external_product_id' => 'missing', 'id_kind' => 'sku'],
        ['external_product_id' => 'DUP', 'id_kind' => 'sku'],
        ['external_product_id' => '42', 'id_kind' => 'weird'],
        ['external_product_id' => 'DISABLED', 'id_kind' => 'sku'],
        ['model' => 'M-43'],
    ]]);
    $items = $resp['items'] ?? [];
    check('batch read: one explicit row per input index',
        count($items) === 10
        && array_values(array_map(fn($row) => $row['index'] ?? null, $items)) === range(0, 9),
        json_encode($items));
    check('batch read: counters reflect found and unresolved rows',
        ($resp['total_count'] ?? null) === 10 && ($resp['found_count'] ?? null) === 6 && ($resp['unresolved_count'] ?? null) === 4,
        json_encode(['total' => $resp['total_count'] ?? null, 'found' => $resp['found_count'] ?? null, 'unresolved' => $resp['unresolved_count'] ?? null]));
    check('batch read: numeric product id row found with verbatim identity',
        ($items[0]['found'] ?? null) === true && $items[0]['external_product_id'] === '42' && $items[0]['resolved_product_id'] === 42);
    check('batch read: bare nonnumeric alias resolves exactly',
        ($items[1]['found'] ?? null) === true && $items[1]['resolved_product_id'] === 42);
    check('batch read: numeric sku with id_kind resolves sku row, never product id row',
        ($items[2]['found'] ?? null) === true && $items[2]['resolved_product_id'] === 43);
    check('batch read: exact sku match is case-sensitive',
        ($items[3]['found'] ?? null) === true && $items[3]['resolved_product_id'] === 44);
    check('batch read: exact sku match preserves significant whitespace',
        ($items[4]['found'] ?? null) === true && $items[4]['resolved_product_id'] === 46 && $items[4]['external_product_id'] === ' A-46');
    check('batch read: absent alias is explicit product_not_found',
        ($items[5]['found'] ?? null) === false && ($items[5]['error_code'] ?? '') === 'product_not_found' && ($items[5]['resolved_product_id'] ?? null) === null);
    check('batch read: duplicate alias is explicit product_ambiguous',
        ($items[6]['found'] ?? null) === false && ($items[6]['error_code'] ?? '') === 'product_ambiguous');
    check('batch read: unknown explicit id_kind never degrades namespaces',
        ($items[7]['found'] ?? null) === false && ($items[7]['error_code'] ?? '') === 'invalid_id_kind');
    check('batch read: inactive catalog rows stay invisible like the products listing',
        ($items[8]['found'] ?? null) === false && ($items[8]['error_code'] ?? '') === 'product_not_found');
    check('batch read: distinct model field resolves exactly',
        ($items[9]['found'] ?? null) === true && $items[9]['resolved_product_id'] === 43 && $items[9]['external_product_id'] === 'M-43');

    $payload = $items[0]['product'] ?? [];
    check('batch read: payload matches catalog product shape',
        ($payload['external_id'] ?? null) === '42'
        && ($payload['sku'] ?? null) === 'SKU-42'
        && ($payload['model'] ?? null) === 'M-42'
        && ($payload['name'] ?? null) === 'Product 42'
        && ($payload['description'] ?? null) === '<p>Forty-two</p>'
        && abs(($payload['price'] ?? 0) - 10.0) < 0.0001
        && abs(($payload['special_price'] ?? 0) - 8.5) < 0.0001
        && ($payload['quantity'] ?? null) === 5
        && ($payload['category_id'] ?? null) === '7'
        && ($payload['category_name'] ?? null) === 'Catalog',
        json_encode($payload));
    check('batch read: image urls are batched with primary first',
        ($payload['image_urls'] ?? null) === ['https://shop.test/image/main42.jpg', 'https://shop.test/image/gallery42.jpg'],
        json_encode($payload['image_urls'] ?? null));

    $candidateQueries = array_values(array_filter($db->log, fn($sql) => stripos($sql, 'SELECT p.product_id, p.model,') === 0));
    $payloadQueries = array_values(array_filter($db->log, fn($sql) => stripos($sql, 'product_description') !== false && stripos($sql, 'p.product_id IN (') !== false));
    $imageQueries = array_values(array_filter($db->log, fn($sql) => stripos($sql, 'SELECT product_id, image FROM `oc_product_image`') === 0));
    $perRowAliasQueries = array_values(array_filter($db->log, fn($sql) => stripos($sql, 'SELECT product_id FROM `oc_product` WHERE BINARY') === 0));
    check('batch read: identity resolution is one preselect query, not N alias lookups',
        count($candidateQueries) === 1 && count($perRowAliasQueries) === 0,
        json_encode(['candidates' => count($candidateQueries), 'per_row' => count($perRowAliasQueries)]));
    check('batch read: payload and gallery loads are batched once',
        count($payloadQueries) === 1 && count($imageQueries) === 1,
        json_encode(['payload' => count($payloadQueries), 'images' => count($imageQueries)]));

    // ---------- exact table detection ----------
    $db = new FakeDb();
    $db->existingTables = []; // only similarly named tables exist in the shared database
    $c = makeController($db);
    check('tableExists absent case is false for the exact table',
        $c->callPrivateArgs('tableExists', ['product_special']) === false,
        json_encode($db->log));
    $db->existingTables = ['product_special' => true];
    $db->log = [];
    check('tableExists uses exact information_schema equality',
        $c->callPrivateArgs('tableExists', ['product_special']) === true
        && count(array_filter($db->log, fn($s) => stripos($s, 'SHOW TABLES LIKE') !== false)) === 0
        && count(array_filter($db->log, fn($s) => stripos($s, "TABLE_NAME = 'oc_product_special'") !== false)) === 1,
        json_encode($db->log));

    // ---------- ceiling ----------
    $db = new FakeDb();
    $db->products = [1 => ['product_id' => 1, 'sku' => 'x', 'model' => 'y', 'price' => 1.0, 'quantity' => 1]];
    $c = makeController($db);
    $rows = [];
    for ($i = 0; $i < 502; $i++) {
        $rows[] = ['external_product_id' => '1', 'quantity' => 1];
    }
    $resp = $c->callPrivate('bulkStocks', ['items' => $rows]);
    check('ceiling: rows beyond bulk.max_rows fail with too_many_rows',
        ($resp['items'][500]['error_code'] ?? '') === 'too_many_rows' && ($resp['items'][499]['success'] ?? null) === true);
    check('ceiling: counters count applied and failed rows', $resp['success_count'] === 500 && $resp['failed_count'] === 2);

    echo "\n" . ($failures ? 'FAILURES: ' . count($failures) . ' / ' . $tests : 'ALL ' . $tests . ' TESTS PASSED') . "\n";
    exit($failures ? 1 : 0);
}
