<?php
namespace Opencart\Catalog\Controller\Extension\DelegatBridge\Api;

class Delegat extends \Opencart\System\Engine\Controller {
    public function index() {
        try {
            $this->authenticate();

            $action = isset($this->request->get['action']) ? trim($this->request->get['action']) : 'health';
            $payload = $this->readJsonBody();

            switch ($action) {
                case 'health':
                    $this->json($this->health());
                    return;

                case 'categories':
                    $this->json($this->categories());
                    return;

                case 'categories.upsert':
                case 'categories_upsert':
                    $this->json($this->upsertCategory($payload));
                    return;

                case 'category.fields':
                case 'category_fields':
                    $this->json($this->categoryFields());
                    return;

                case 'products.upsert':
                case 'products_upsert':
                    $this->json($this->upsertProduct($payload));
                    return;

                case 'products.bulk':
                case 'products_bulk':
                    $this->json($this->bulkProducts($payload));
                    return;

                case 'products':
                    $this->json($this->products());
                    return;

                case 'prices.bulk':
                case 'prices_bulk':
                    $this->json($this->bulkPrices($payload));
                    return;

                case 'stocks.bulk':
                case 'stocks_bulk':
                    $this->json($this->bulkStocks($payload));
                    return;

                case 'orders':
                    $this->json($this->orders());
                    return;

                case 'order':
                    $this->json($this->order());
                    return;

                case 'orders.status':
                case 'orders_status':
                    $this->json($this->updateOrderStatus($payload));
                    return;

                case 'promotions':
                    $this->json($this->promotions());
                    return;

                case 'promotion':
                    $this->json($this->promotion());
                    return;

                case 'promotions.products':
                case 'promotions_products':
                    $this->json($this->updatePromotionProducts($payload));
                    return;

                default:
                    $this->jsonError('Unknown action: ' . $action, 404);
                    return;
            }
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: a PHP type error or fatal-ish \Error
            // inside one bulk row must surface as a structured 500 instead of
            // an unparseable white page, so the client can treat the whole
            // request as ambiguous and reconcile instead of blindly replaying.
            $this->rollbackQuietly();
            $this->jsonError($e->getMessage(), 500, array(
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
        }
    }

    // Best-effort rollback for the catastrophic path: if a Throwable escaped
    // a row-level transaction the connection would otherwise hold the partial
    // graph open until request end. A failed rollback (dead connection, no
    // open transaction) is itself swallowed - the response is already a 500.
    private function rollbackQuietly() {
        try {
            $this->db->query("ROLLBACK");
        } catch (\Throwable $ignored) {
        }
    }

    private function authenticate() {
        if (!(int)$this->config->get('module_delegat_bridge_status')) {
            throw new \Exception('Delegat Bridge is disabled');
        }

        $configured_key = trim((string)$this->config->get('module_delegat_bridge_api_key'));
        if ($configured_key === '') {
            throw new \Exception('Delegat Bridge API key is not configured');
        }

        $allowed_ips = $this->parseAllowedIps((string)$this->config->get('module_delegat_bridge_allowed_ips'));
        if ($allowed_ips) {
            $remote_ip = isset($this->request->server['REMOTE_ADDR']) ? $this->request->server['REMOTE_ADDR'] : '';
            if (!in_array($remote_ip, $allowed_ips, true)) {
                $this->jsonError('IP address is not allowed', 403);
                exit;
            }
        }

        $provided_key = $this->getProvidedApiKey();
        if ($provided_key === '' || !hash_equals($configured_key, $provided_key)) {
            $this->jsonError('Invalid API key', 401);
            exit;
        }
    }

    private function health() {
        return array(
            'store_name' => $this->config->get('config_name'),
            'version' => defined('VERSION') ? VERSION : '',
            'api_version' => '1.4.0',
            'store_id' => $this->getDefaultStoreId(),
            'language_id' => $this->getDefaultLanguageId(),
            'capabilities' => array(
                'categories',
                'categories.upsert',
                'category.fields',
                'products',
                'products.upsert',
                'products.bulk',
                'prices.bulk',
                'stocks.bulk',
                'image.download',
                'orders',
                'order',
                'orders.status',
                'promotions',
                'promotion',
                'promotions.products'
            ),
            // Structured feature detection for bulk clients. The legacy flat
            // capabilities array cannot express behavioral contracts, so
            // batching clients gate on these flags instead of api_version
            // string parsing:
            // - bulk.v2.per_row_results: prices/stocks/products bulk responses
            //   carry items[] exactly parallel to the input rows, each with
            //   the input index, the verbatim pre-cast external_product_id,
            //   the resolved numeric product id and per-row success/error.
            // - bulk.alias_resolution: prices/stocks rows resolve product_id,
            //   sku or model exact-match server-side (id_kind or distinct
            //   fields); zero or several matches fail the row, never an
            //   arbitrary LIMIT-1 pick.
            // - bulk.per_row_transactions: every price row (base price plus
            //   special delete/insert) and every card row (the whole product
            //   graph) commits or rolls back atomically.
            // - stocks.warehouse_reject: a stock row carrying warehouse_id is
            //   rejected row-level instead of silently writing global quantity.
            // - bulk.max_rows: rows beyond this per-request ceiling fail
            //   row-level with too_many_rows; earlier rows still apply.
            'capability_details' => array(
                'bulk.v2.per_row_results' => true,
                'bulk.alias_resolution' => true,
                'bulk.per_row_transactions' => true,
                'stocks.warehouse_reject' => true,
                'bulk.max_rows' => 500
            )
        );
    }

    private function categories() {
        $language_id = $this->getDefaultLanguageId();
        $parent_id = isset($this->request->get['parent_id']) && $this->request->get['parent_id'] !== ''
            ? (int)$this->request->get['parent_id']
            : null;
        $search = isset($this->request->get['search']) ? trim($this->request->get['search']) : '';

        $sql = "SELECT c.category_id, c.parent_id, cd.name, c.status
                FROM `" . DB_PREFIX . "category` c
                LEFT JOIN `" . DB_PREFIX . "category_description` cd
                    ON (c.category_id = cd.category_id AND cd.language_id = " . (int)$language_id . ")
                WHERE c.status = 1";

        if ($parent_id !== null) {
            $sql .= " AND c.parent_id = " . (int)$parent_id;
        }

        if ($search !== '') {
            $sql .= " AND cd.name LIKE '%" . $this->db->escape($search) . "%'";
        }

        $sql .= " ORDER BY c.sort_order ASC, cd.name ASC";

        $rows = $this->db->query($sql)->rows;
        $all = $this->getCategoryNameMap($language_id);
        $result = array();

        foreach ($rows as $row) {
            $children_count = $this->countCategoryChildren((int)$row['category_id']);
            $parent_name = isset($all[(int)$row['parent_id']]) ? $all[(int)$row['parent_id']]['name'] : null;

            $result[] = array(
                'id' => (string)$row['category_id'],
                'name' => $row['name'],
                'parent_id' => (int)$row['parent_id'] > 0 ? (string)$row['parent_id'] : null,
                'parent_name' => $parent_name,
                'full_path' => $this->buildCategoryPath((int)$row['category_id'], $all),
                'has_children' => $children_count > 0,
                'children_count' => $children_count
            );
        }

        return $result;
    }

    private function categoryFields() {
        $language_id = $this->getDefaultLanguageId();
        $fields = $this->baseProductFields();

        $attribute_sql = "SELECT a.attribute_id, ad.name, agd.name AS group_name
                          FROM `" . DB_PREFIX . "attribute` a
                          LEFT JOIN `" . DB_PREFIX . "attribute_description` ad
                              ON (a.attribute_id = ad.attribute_id AND ad.language_id = " . (int)$language_id . ")
                          LEFT JOIN `" . DB_PREFIX . "attribute_group_description` agd
                              ON (a.attribute_group_id = agd.attribute_group_id AND agd.language_id = " . (int)$language_id . ")
                          ORDER BY a.sort_order ASC, ad.name ASC";

        foreach ($this->db->query($attribute_sql)->rows as $row) {
            $fields[] = array(
                'key' => 'attr:' . $row['attribute_id'],
                'name' => $row['name'],
                'is_required' => false,
                'type' => 'string',
                'group' => $row['group_name'] ? 'Attributes: ' . $row['group_name'] : 'Attributes',
                'sort_order' => 1000 + (int)$row['attribute_id']
            );
        }

        $option_sql = "SELECT o.option_id, o.type, od.name
                       FROM `" . DB_PREFIX . "option` o
                       LEFT JOIN `" . DB_PREFIX . "option_description` od
                           ON (o.option_id = od.option_id AND od.language_id = " . (int)$language_id . ")
                       ORDER BY o.sort_order ASC, od.name ASC";

        foreach ($this->db->query($option_sql)->rows as $row) {
            $options = array();
            $value_sql = "SELECT ov.option_value_id, ovd.name
                          FROM `" . DB_PREFIX . "option_value` ov
                          LEFT JOIN `" . DB_PREFIX . "option_value_description` ovd
                              ON (ov.option_value_id = ovd.option_value_id AND ovd.language_id = " . (int)$language_id . ")
                          WHERE ov.option_id = " . (int)$row['option_id'] . "
                          ORDER BY ov.sort_order ASC, ovd.name ASC";

            foreach ($this->db->query($value_sql)->rows as $value) {
                $options[] = array(
                    'value' => (string)$value['option_value_id'],
                    'label' => $value['name']
                );
            }

            $type = $row['type'] === 'checkbox' ? 'multiselect' : ($options ? 'select' : 'string');
            $fields[] = array(
                'key' => 'option:' . $row['option_id'],
                'name' => $row['name'],
                'is_required' => false,
                'type' => $type,
                'options' => $options,
                'group' => 'Options',
                'sort_order' => 2000 + (int)$row['option_id']
            );
        }

        return $fields;
    }

    private function upsertCategory($payload) {
        $name = isset($payload['name']) ? trim((string)$payload['name']) : '';
        if ($name === '') {
            throw new \Exception('Category name is required');
        }

        $language_id = isset($payload['language_id']) && $payload['language_id'] !== null
            ? (int)$payload['language_id']
            : $this->getDefaultLanguageId();
        $store_id = isset($payload['store_id']) && $payload['store_id'] !== null
            ? (int)$payload['store_id']
            : $this->getDefaultStoreId();
        $parent_id = isset($payload['parent_id']) && $payload['parent_id'] !== null && $payload['parent_id'] !== ''
            ? (int)$payload['parent_id']
            : 0;
        $sort_order = isset($payload['sort_order']) ? (int)$payload['sort_order'] : 0;
        $description = isset($payload['description']) ? (string)$payload['description'] : '';

        $this->ensureCategorySchema();

        $category_id = $this->resolveCategoryIdByName($name, $parent_id, $language_id);
        if ($category_id > 0) {
            $fields = $this->buildCategoryAssignments($parent_id, $sort_order, false);
            if ($fields) {
                $this->db->query("UPDATE `" . DB_PREFIX . "category` SET " . implode(', ', $fields) . " WHERE category_id = " . (int)$category_id);
            }
        } else {
            $fields = $this->buildCategoryAssignments($parent_id, $sort_order, true);
            if ($fields) {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "category` SET " . implode(', ', $fields));
            } else {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "category` () VALUES ()");
            }
            $category_id = (int)$this->db->getLastId();
        }

        $this->saveCategoryDescription($category_id, $language_id, $name, $description);
        $this->saveCategoryStore($category_id, $store_id);
        $this->saveCategoryPath($category_id, $parent_id);

        $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
        $created_attribute_ids = array();
        foreach ($fields as $field) {
            $field_name = isset($field['name']) ? trim((string)$field['name']) : '';
            if ($field_name === '') {
                continue;
            }

            $group_name = isset($field['group']) && trim((string)$field['group']) !== ''
                ? trim((string)$field['group'])
                : 'Delegat';
            $group_id = $this->ensureAttributeGroup($group_name, $language_id);
            $attribute_id = $this->ensureAttribute($field_name, $group_id, $language_id, isset($field['sort_order']) ? (int)$field['sort_order'] : 0);
            if ($attribute_id > 0) {
                $created_attribute_ids[] = $attribute_id;
            }
        }

        return array(
            'success' => true,
            'category_id' => (string)$category_id,
            'id' => (string)$category_id,
            'name' => $name,
            'fields' => $this->categoryResponseFields($created_attribute_ids, $language_id),
            'message' => 'Category created'
        );
    }

    private function upsertProduct($payload) {
        $product = isset($payload['product']) && is_array($payload['product']) ? $payload['product'] : $payload;

        // Image downloads are network I/O bounded by the remote server's
        // latency: they run BEFORE the database transaction opens, so a slow
        // or failing download never holds row locks on the product graph.
        // Orphaned image files from a later-rolled-back write are harmless.
        $preloaded_images = $this->preDownloadProductImages(
            isset($product['image_urls']) && is_array($product['image_urls']) ? $product['image_urls'] : array());

        $product_id = $this->resolveProductId($product);

        // The whole product graph - base row, descriptions, store and category
        // links, attributes, options, image rows, special price - is one
        // logical write: it commits atomically or rolls back completely, so a
        // failure midway never leaves a half-written card behind.
        $this->db->query("START TRANSACTION");
        try {
            if ($product_id > 0) {
                $this->updateProduct($product_id, $product);
            } else {
                $product_id = $this->createProduct($product);
            }

            $this->saveProductDescriptions($product_id, $product);
            $this->saveProductStore($product_id, $product);
            $this->saveProductCategories($product_id, $product);
            $this->saveProductAttributes($product_id, $product);
            $this->saveProductOptions($product_id, $product);
            $image_result = $this->saveProductImages($product_id, $product, $preloaded_images);
            $this->saveProductSpecial($product_id, $product);

            $this->db->query("COMMIT");
        } catch (\Throwable $write_error) {
            $this->rollbackQuietly();
            throw $write_error;
        }

        return array(
            'success' => true,
            'product_id' => (string)$product_id,
            'sku' => isset($product['sku']) ? (string)$product['sku'] : null,
            'details' => array(
                'images' => $image_result
            )
        );
    }

    private function bulkProducts($payload) {
        $items = isset($payload['items']) && is_array($payload['items']) ? array_values($payload['items']) : array();
        $results = array();

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $item = array();
            }
            $local_product_id = isset($item['local_product_id']) ? (int)$item['local_product_id'] : 0;
            $product = isset($item['product']) && is_array($item['product']) ? $item['product'] : array();

            if (!isset($product['product_id']) && isset($item['existing_external_id']) && $item['existing_external_id'] !== '') {
                $product['product_id'] = $item['existing_external_id'];
            }

            if ($index >= self::BULK_MAX_ROWS) {
                $results[] = array(
                    'index' => $index,
                    'local_product_id' => $local_product_id,
                    'success' => false,
                    'error_code' => 'too_many_rows',
                    'error_message' => 'Row exceeds the per-request ceiling of ' . self::BULK_MAX_ROWS . ' rows (bulk.max_rows); chunk the request and replay the tail'
                );
                continue;
            }

            // \Throwable containment per row: every input row produces exactly
            // one result row at its own input index, even when the failure is
            // a PHP error rather than a catchable exception. Each row's graph
            // is transactional inside upsertProduct, so rows stay independent.
            try {
                $result = $this->upsertProduct($product);
                $results[] = array(
                    'index' => $index,
                    'local_product_id' => $local_product_id,
                    'success' => true,
                    'product_id' => $result['product_id'],
                    'details' => isset($result['details']) ? $result['details'] : null
                );
            } catch (\Throwable $e) {
                $results[] = array(
                    'index' => $index,
                    'local_product_id' => $local_product_id,
                    'success' => false,
                    'error_code' => 'write_failed',
                    'error_message' => $e->getMessage()
                );
            }
        }

        return array('items' => $results);
    }

    private function products() {
        $language_id = $this->getDefaultLanguageId();
        $limit = isset($this->request->get['page_size']) ? (int)$this->request->get['page_size'] : 50;
        if ($limit < 1) $limit = 50;
        if ($limit > 200) $limit = 200;

        $offset = isset($this->request->get['cursor']) ? (int)$this->request->get['cursor'] : 0;
        if ($offset < 0) $offset = 0;

        $search = '';
        if (isset($this->request->get['offer_id_search'])) {
            $search = trim((string)$this->request->get['offer_id_search']);
        } elseif (isset($this->request->get['search'])) {
            $search = trim((string)$this->request->get['search']);
        }

        $sku_select = $this->tableColumnExists('product', 'sku') ? 'p.sku' : "''";
        $where = " WHERE p.status = 1";
        if ($search !== '') {
            $escaped = $this->db->escape($search);
            $where .= " AND (p.model LIKE '%" . $escaped . "%'";
            if (ctype_digit($search)) {
                $where .= " OR p.product_id = " . (int)$search;
            }
            if ($this->tableColumnExists('product', 'sku')) {
                $where .= " OR p.sku LIKE '%" . $escaped . "%'";
            }
            $where .= " OR pd.name LIKE '%" . $escaped . "%')";
        }

        // Special price readback mirrors the write path exactly: the same
        // customer group the write uses, same validity window semantics. An
        // unfiltered readback would compare the written special against
        // another customer group's row and produce false mismatches.
        $special_customer_group_id = $this->getSpecialCustomerGroupId();
        // OpenCart 4.1 dropped product_special in favor of product_discount:
        // probing the table once keeps the catalog listing readable on those
        // shops instead of failing every page with an unknown-table error.
        $special_select = $this->tableExists('product_special')
            ? "(
                        SELECT ps.price
                        FROM `" . DB_PREFIX . "product_special` ps
                        WHERE ps.product_id = p.product_id
                          AND ps.customer_group_id = " . (int)$special_customer_group_id . "
                          AND (ps.date_start = '0000-00-00' OR ps.date_start <= NOW())
                          AND (ps.date_end = '0000-00-00' OR ps.date_end >= NOW())
                        ORDER BY ps.priority ASC, ps.price ASC
                        LIMIT 1
                    )"
            : "NULL";
        $sql = "SELECT
                    p.product_id,
                    p.model,
                    " . $sku_select . " AS sku,
                    p.price,
                    " . $special_select . " AS special_price,
                    p.quantity,
                    p.image,
                    pd.name,
                    pd.description,
                    pc.category_id,
                    cd.name AS category_name
                FROM `" . DB_PREFIX . "product` p
                LEFT JOIN `" . DB_PREFIX . "product_description` pd
                    ON (p.product_id = pd.product_id AND pd.language_id = " . (int)$language_id . ")
                LEFT JOIN (
                    SELECT product_id, MIN(category_id) AS category_id
                    FROM `" . DB_PREFIX . "product_to_category`
                    GROUP BY product_id
                ) pc ON (p.product_id = pc.product_id)
                LEFT JOIN `" . DB_PREFIX . "category_description` cd
                    ON (pc.category_id = cd.category_id AND cd.language_id = " . (int)$language_id . ")"
                . $where . "
                ORDER BY p.product_id ASC
                LIMIT " . (int)$offset . ", " . (int)($limit + 1);

        $rows = $this->db->query($sql)->rows;
        $has_more = count($rows) > $limit;
        if ($has_more) {
            $rows = array_slice($rows, 0, $limit);
        }

        $items = array();
        foreach ($rows as $row) {
            $items[] = array(
                'external_id' => (string)$row['product_id'],
                'sku' => $row['sku'] !== '' ? (string)$row['sku'] : (string)$row['model'],
                'model' => (string)$row['model'],
                'name' => isset($row['name']) ? (string)$row['name'] : '',
                'description' => isset($row['description']) ? (string)$row['description'] : '',
                'price' => isset($row['price']) ? (float)$row['price'] : null,
                'special_price' => isset($row['special_price']) && $row['special_price'] !== null ? (float)$row['special_price'] : null,
                'quantity' => isset($row['quantity']) ? (int)$row['quantity'] : null,
                'category_id' => isset($row['category_id']) ? (string)$row['category_id'] : null,
                'category_name' => isset($row['category_name']) ? (string)$row['category_name'] : null,
                'image_urls' => $this->productImageUrls((int)$row['product_id'], isset($row['image']) ? (string)$row['image'] : '')
            );
        }

        return array(
            'items' => $items,
            'has_more' => $has_more,
            'next_cursor' => $has_more ? (string)($offset + $limit) : null
        );
    }

    // Rows beyond this per-request ceiling fail row-level with too_many_rows
    // while earlier rows still apply. The value is published as the
    // bulk.max_rows capability detail so clients can chunk correctly.
    private const BULK_MAX_ROWS = 500;

    private function bulkPrices($payload) {
        $items = isset($payload['items']) && is_array($payload['items']) ? array_values($payload['items']) : array();
        $errors = array();
        $results = array();
        $success = 0;

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $item = array();
            }
            $verbatim = $this->rowVerbatimIdentity($item);

            if ($index >= self::BULK_MAX_ROWS) {
                $results[] = $this->bulkRowFailure($index, $verbatim, 'too_many_rows',
                    'Row exceeds the per-request ceiling of ' . self::BULK_MAX_ROWS . ' rows (bulk.max_rows); chunk the request and replay the tail');
                continue;
            }

            $has_price = array_key_exists('price', $item) && $item['price'] !== null;
            $has_special = array_key_exists('special_price', $item) && $item['special_price'] !== null;

            if (($has_price && (float)$item['price'] < 0) || ($has_special && (float)$item['special_price'] < 0)) {
                $results[] = $this->bulkRowFailure($index, $verbatim, 'invalid_price', 'Price values cannot be negative');
                continue;
            }

            list($product_id, $resolve_error, $resolve_message) = $this->resolveBulkRowProductId($item);
            if ($product_id <= 0) {
                $results[] = $this->bulkRowFailure($index, $verbatim, $resolve_error, $resolve_message);
                continue;
            }

            // The base price write and the special delete/insert are one
            // logical row write: they commit atomically or not at all, so a
            // mid-row failure can never leave a base price without its
            // intended special (or vice versa) behind.
            try {
                $this->db->query("START TRANSACTION");
                try {
                    if ($has_price) {
                        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET price = '" . (float)$item['price'] . "', date_modified = NOW() WHERE product_id = " . (int)$product_id);
                    }

                    if ($has_special) {
                        $this->replaceSpecialPrice($product_id, (float)$item['special_price']);
                    }

                    $this->db->query("COMMIT");
                } catch (\Throwable $write_error) {
                    $this->rollbackQuietly();
                    throw $write_error;
                }

                $success++;
                $results[] = array(
                    'index' => $index,
                    'external_product_id' => $verbatim,
                    'resolved_product_id' => $product_id,
                    'success' => true
                );
            } catch (\Throwable $e) {
                $results[] = $this->bulkRowFailure($index, $verbatim, 'write_failed',
                    'Price row write failed atomically: ' . $e->getMessage(), $product_id);
            }
        }

        foreach ($results as $row) {
            if (!$row['success']) {
                $errors[] = array('external_id' => (string)$row['external_product_id'], 'message' => $row['error_message']);
            }
        }

        return array(
            'total_count' => count($items),
            'success_count' => $success,
            'failed_count' => count($items) - $success,
            'errors' => $errors,
            'items' => $results
        );
    }

    private function bulkStocks($payload) {
        $items = isset($payload['items']) && is_array($payload['items']) ? array_values($payload['items']) : array();
        $errors = array();
        $results = array();
        $success = 0;

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $item = array();
            }
            $verbatim = $this->rowVerbatimIdentity($item);

            if ($index >= self::BULK_MAX_ROWS) {
                $results[] = $this->bulkRowFailure($index, $verbatim, 'too_many_rows',
                    'Row exceeds the per-request ceiling of ' . self::BULK_MAX_ROWS . ' rows (bulk.max_rows); chunk the request and replay the tail');
                continue;
            }

            // A warehouse-scoped row must never degrade into a global quantity
            // write: until the bridge implements real warehouse stock, the row
            // is rejected where the operator can see it instead of silently
            // mis-writing the sellable total.
            if (array_key_exists('warehouse_id', $item) && $item['warehouse_id'] !== null && trim((string)$item['warehouse_id']) !== '') {
                $results[] = $this->bulkRowFailure($index, $verbatim, 'warehouse_unsupported',
                    'OpenCart bridge does not support warehouse-scoped stock; the row was not written rather than applied to global quantity');
                continue;
            }

            // A missing quantity must never default to zero: silently zeroing
            // stock is a data-corruption write, not a validation failure the
            // sender can see. Negative quantities are rejected the same way.
            if (!array_key_exists('quantity', $item) || $item['quantity'] === null || !is_numeric($item['quantity'])) {
                $results[] = $this->bulkRowFailure($index, $verbatim, 'invalid_quantity', 'Stock row requires an explicit non-negative numeric quantity');
                continue;
            }
            $quantity = (int)$item['quantity'];
            if ($quantity < 0 || (float)$item['quantity'] != (float)$quantity) {
                $results[] = $this->bulkRowFailure($index, $verbatim, 'invalid_quantity', 'Stock row requires a non-negative integer quantity');
                continue;
            }

            list($product_id, $resolve_error, $resolve_message) = $this->resolveBulkRowProductId($item);
            if ($product_id <= 0) {
                $results[] = $this->bulkRowFailure($index, $verbatim, $resolve_error, $resolve_message);
                continue;
            }

            try {
                // Single-statement row write: inherently atomic, no explicit
                // transaction needed (unlike price/card graph rows).
                $this->db->query("UPDATE `" . DB_PREFIX . "product` SET quantity = " . (int)$quantity . ", date_modified = NOW() WHERE product_id = " . (int)$product_id);

                $success++;
                $results[] = array(
                    'index' => $index,
                    'external_product_id' => $verbatim,
                    'resolved_product_id' => $product_id,
                    'success' => true
                );
            } catch (\Throwable $e) {
                $results[] = $this->bulkRowFailure($index, $verbatim, 'write_failed',
                    'Stock row write failed: ' . $e->getMessage(), $product_id);
            }
        }

        foreach ($results as $row) {
            if (!$row['success']) {
                $errors[] = array('external_id' => (string)$row['external_product_id'], 'message' => $row['error_message']);
            }
        }

        return array(
            'total_count' => count($items),
            'success_count' => $success,
            'failed_count' => count($items) - $success,
            'errors' => $errors,
            'items' => $results
        );
    }

    // The identity string the client actually sent, before any casting: rows
    // are attributed by input index first, but echoing the verbatim identity
    // keeps legacy error entries and operator logs meaningful for alias rows
    // (the v1 int cast destroyed non-numeric identities here).
    private function rowVerbatimIdentity($item) {
        foreach (array('external_product_id', 'product_id', 'sku', 'model') as $key) {
            if (isset($item[$key]) && (string)$item[$key] !== '') {
                return (string)$item[$key];
            }
        }
        return '';
    }

    private function bulkRowFailure($index, $verbatim, $error_code, $error_message, $resolved_product_id = null) {
        return array(
            'index' => $index,
            'external_product_id' => $verbatim,
            'resolved_product_id' => $resolved_product_id !== null ? (int)$resolved_product_id : null,
            'success' => false,
            'error_code' => $error_code,
            'error_message' => $error_message
        );
    }

    // Resolves one price/stock row identity to exactly one product id.
    // Returns array(product_id, error_code, error_message): product_id > 0 on
    // success, otherwise 0 with a row-level failure reason.
    //
    // Identity forms, in precedence order:
    // 1. Distinct fields: product_id (numeric exact row), sku or model
    //    (exact-match alias).
    // 2. external_product_id with an explicit id_kind of product_id/sku/model.
    // 3. Bare external_product_id (legacy v1-compatible default): numeric
    //    values are product ids exactly as the v1 int cast treated them;
    //    non-numeric values resolve by exact sku/model match.
    // Alias resolution counts distinct product ids across exact matches only:
    // zero matches is product_not_found, several is product_ambiguous - never
    // an arbitrary LIMIT-1 pick, and a numeric sku is never confused with a
    // product id when id_kind says sku.
    private function resolveBulkRowProductId($item) {
        $id_kind = '';
        $identity = '';

        if (isset($item['product_id']) && (string)$item['product_id'] !== '') {
            $id_kind = 'product_id';
            $identity = (string)$item['product_id'];
        } elseif (isset($item['sku']) && trim((string)$item['sku']) !== '') {
            $id_kind = 'sku';
            $identity = (string)$item['sku'];
        } elseif (isset($item['model']) && trim((string)$item['model']) !== '') {
            $id_kind = 'model';
            $identity = (string)$item['model'];
        } elseif (isset($item['external_product_id']) && trim((string)$item['external_product_id']) !== '') {
            $identity = trim((string)$item['external_product_id']);
            $raw_kind = isset($item['id_kind']) ? strtolower(trim((string)$item['id_kind'])) : '';
            if (in_array($raw_kind, array('product_id', 'sku', 'model'), true)) {
                $id_kind = $raw_kind;
            } elseif ($raw_kind !== '') {
                // An explicit but unknown id_kind must not silently degrade into
                // the legacy numeric-id interpretation: a numeric SKU would then
                // write the product with that numeric id instead of failing.
                return array(0, 'invalid_id_kind', 'Unknown id_kind: ' . $raw_kind . ' (expected product_id, sku, or model)');
            }
        } else {
            $identity = trim($identity);
        }

        if ($identity === '') {
            return array(0, 'identity_missing', 'Row carries no product identity (product_id, sku, model, or external_product_id)');
        }

        if ($id_kind === 'product_id') {
            if (!ctype_digit($identity) || (int)$identity <= 0) {
                return array(0, 'invalid_product_id', 'product_id identity must be a positive integer, got: ' . $identity);
            }
            $product_id = (int)$identity;
            if (!$this->productExists($product_id)) {
                return array(0, 'product_not_found', 'Product not found: ' . $identity);
            }
            return array($product_id, null, null);
        }

        if ($id_kind === 'sku' || $id_kind === 'model') {
            return $this->resolveProductIdByExactAlias($id_kind, $identity);
        }

        // Legacy default for a bare external_product_id.
        if (ctype_digit($identity) && (int)$identity > 0) {
            $product_id = (int)$identity;
            if (!$this->productExists($product_id)) {
                return array(0, 'product_not_found', 'Product not found: ' . $identity);
            }
            return array($product_id, null, null);
        }

        return $this->resolveProductIdByExactAlias('', $identity);
    }

    // Exact-match alias resolution over distinct product ids. With an empty
    // kind both sku and model are probed and a single distinct match across
    // either resolves; ambiguity in any combination fails the row.
    private function resolveProductIdByExactAlias($kind, $identity) {
        $columns = array();
        if ($kind === 'sku' || $kind === '') {
            if ($this->tableColumnExists('product', 'sku')) {
                $columns[] = 'sku';
            }
        }
        if ($kind === 'model' || $kind === '') {
            $columns[] = 'model';
        }

        if (!count($columns)) {
            return array(0, 'product_not_found', 'Product not found by ' . ($kind !== '' ? $kind : 'sku/model') . ': ' . $identity);
        }

        $matched = array();
        foreach ($columns as $column) {
            // BINARY forces the exact-match semantics the contract promises:
            // MySQL default collations are case-insensitive, which would either
            // merge distinct products differing only by case into a false
            // ambiguity or update a near-match casing variant.
            $rows = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product` WHERE BINARY `" . $column . "` = '" . $this->db->escape($identity) . "'")->rows;
            foreach ($rows as $row) {
                $matched[(int)$row['product_id']] = true;
            }
        }

        if (count($matched) === 1) {
            return array((int)array_key_first($matched), null, null);
        }
        if (count($matched) === 0) {
            return array(0, 'product_not_found', 'Product not found by exact ' . ($kind !== '' ? $kind : 'sku/model') . ' match: ' . $identity);
        }
        return array(0, 'product_ambiguous', 'More than one product matches ' . ($kind !== '' ? $kind : 'sku/model') . ' exactly: ' . $identity);
    }

    private function createProduct($product) {
        $name = isset($product['name']) ? trim((string)$product['name']) : '';
        $model = isset($product['model']) ? trim((string)$product['model']) : '';

        if ($model === '' && isset($product['sku'])) {
            $model = trim((string)$product['sku']);
        }

        if ($name === '') {
            throw new \Exception('Product name is required for new products');
        }

        if ($model === '') {
            throw new \Exception('Product model or SKU is required for new products');
        }

        $fields = array(
            "model = '" . $this->db->escape($model) . "'",
            "sku = '" . $this->db->escape(isset($product['sku']) ? (string)$product['sku'] : '') . "'",
            "quantity = " . (int)$this->value($product, 'quantity', 0),
            "stock_status_id = " . (int)$this->value($product, 'stock_status_id', $this->config->get('config_stock_status_id')),
            "manufacturer_id = " . (int)$this->resolveManufacturerId($product),
            "shipping = " . (int)$this->value($product, 'shipping', 1),
            "price = '" . (float)$this->value($product, 'price', 0) . "'",
            "points = 0",
            "tax_class_id = " . (int)$this->value($product, 'tax_class_id', 0),
            "date_available = CURDATE()",
            "weight = '" . (float)$this->value($product, 'weight', 0) . "'",
            "weight_class_id = " . (int)$this->value($product, 'weight_class_id', $this->config->get('config_weight_class_id')),
            "length = '" . (float)$this->value($product, 'length', 0) . "'",
            "width = '" . (float)$this->value($product, 'width', 0) . "'",
            "height = '" . (float)$this->value($product, 'height', 0) . "'",
            "length_class_id = " . (int)$this->value($product, 'length_class_id', $this->config->get('config_length_class_id')),
            "subtract = 1",
            "minimum = " . (int)$this->value($product, 'minimum', 1),
            "sort_order = " . (int)$this->value($product, 'sort_order', 0),
            "status = " . (int)$this->value($product, 'status', 1),
            "date_added = NOW()",
            "date_modified = NOW()"
        );

        if ($this->tableColumnExists('product', 'variant')) {
            $fields[] = "`variant` = '{}'";
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "product` SET " . implode(', ', $fields));
        $product_id = (int)$this->db->getLastId();
        $this->normalizeProductCartJsonFields($product_id);

        return $product_id;
    }

    private function updateProduct($product_id, $product) {
        if (!$this->productExists($product_id)) {
            throw new \Exception('Product not found: ' . $product_id);
        }

        $updates = array();
        $map = array(
            'model' => 'string',
            'sku' => 'string',
            'quantity' => 'int',
            'stock_status_id' => 'int',
            'shipping' => 'bool',
            'price' => 'float',
            'tax_class_id' => 'int',
            'weight' => 'float',
            'weight_class_id' => 'int',
            'length' => 'float',
            'width' => 'float',
            'height' => 'float',
            'length_class_id' => 'int',
            'minimum' => 'int',
            'sort_order' => 'int',
            'status' => 'bool'
        );

        foreach ($map as $key => $type) {
            if (!array_key_exists($key, $product) || $product[$key] === null) {
                continue;
            }

            $column = $key;
            if ($type === 'string') {
                $updates[] = "`" . $column . "` = '" . $this->db->escape((string)$product[$key]) . "'";
            } elseif ($type === 'float') {
                $updates[] = "`" . $column . "` = '" . (float)$product[$key] . "'";
            } elseif ($type === 'bool') {
                $updates[] = "`" . $column . "` = " . (int)$product[$key];
            } else {
                $updates[] = "`" . $column . "` = " . (int)$product[$key];
            }
        }

        if (array_key_exists('manufacturer', $product) && $product['manufacturer'] !== null) {
            $updates[] = "manufacturer_id = " . (int)$this->resolveManufacturerId($product);
        }

        if ($updates) {
            $updates[] = "date_modified = NOW()";
            $this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . implode(', ', $updates) . " WHERE product_id = " . (int)$product_id);
        }

        $this->normalizeProductCartJsonFields($product_id);
    }

    private function normalizeProductCartJsonFields($product_id) {
        if ($this->tableColumnExists('product', 'variant')) {
            $this->db->query("UPDATE `" . DB_PREFIX . "product`
                SET `variant` = '{}'
                WHERE product_id = " . (int)$product_id . "
                    AND (`variant` IS NULL OR `variant` = '')");
        }
    }

    private function saveProductDescriptions($product_id, $product) {
        $has_description_data = false;
        foreach (array('name', 'description', 'tag', 'meta_title', 'meta_description', 'meta_keyword') as $key) {
            if (array_key_exists($key, $product) && $product[$key] !== null) {
                $has_description_data = true;
                break;
            }
        }

        if (!$has_description_data) {
            return;
        }

        $source_language_id = isset($product['language_id']) && $product['language_id'] !== null
            ? (int)$product['language_id']
            : $this->getDefaultLanguageId();

        $source_existing = $this->db->query("SELECT * FROM `" . DB_PREFIX . "product_description` WHERE product_id = " . (int)$product_id . " AND language_id = " . (int)$source_language_id)->row;
        $base_name = $this->value($product, 'name', isset($source_existing['name']) ? $source_existing['name'] : '');
        $base_description = $this->value($product, 'description', isset($source_existing['description']) ? $source_existing['description'] : '');
        $base_tag = $this->value($product, 'tag', isset($source_existing['tag']) ? $source_existing['tag'] : '');
        $base_meta_title = $this->value($product, 'meta_title', isset($source_existing['meta_title']) ? $source_existing['meta_title'] : $base_name);
        $base_meta_description = $this->value($product, 'meta_description', isset($source_existing['meta_description']) ? $source_existing['meta_description'] : '');
        $base_meta_keyword = $this->value($product, 'meta_keyword', isset($source_existing['meta_keyword']) ? $source_existing['meta_keyword'] : '');

        foreach ($this->getProductDescriptionLanguageIds($source_language_id) as $language_id) {
            $existing = $this->db->query("SELECT * FROM `" . DB_PREFIX . "product_description` WHERE product_id = " . (int)$product_id . " AND language_id = " . (int)$language_id)->row;

            $name = $this->value($product, 'name', isset($existing['name']) ? $existing['name'] : $base_name);
            $description = $this->value($product, 'description', isset($existing['description']) ? $existing['description'] : $base_description);
            $tag = $this->value($product, 'tag', isset($existing['tag']) ? $existing['tag'] : $base_tag);
            $meta_title = $this->value($product, 'meta_title', isset($existing['meta_title']) ? $existing['meta_title'] : $base_meta_title);
            $meta_description = $this->value($product, 'meta_description', isset($existing['meta_description']) ? $existing['meta_description'] : $base_meta_description);
            $meta_keyword = $this->value($product, 'meta_keyword', isset($existing['meta_keyword']) ? $existing['meta_keyword'] : $base_meta_keyword);

            if ($existing) {
                $this->db->query("UPDATE `" . DB_PREFIX . "product_description` SET
                    name = '" . $this->db->escape((string)$name) . "',
                    description = '" . $this->db->escape((string)$description) . "',
                    tag = '" . $this->db->escape((string)$tag) . "',
                    meta_title = '" . $this->db->escape((string)$meta_title) . "',
                    meta_description = '" . $this->db->escape((string)$meta_description) . "',
                    meta_keyword = '" . $this->db->escape((string)$meta_keyword) . "'
                    WHERE product_id = " . (int)$product_id . " AND language_id = " . (int)$language_id);
            } else {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "product_description` SET
                    product_id = " . (int)$product_id . ",
                    language_id = " . (int)$language_id . ",
                    name = '" . $this->db->escape((string)$name) . "',
                    description = '" . $this->db->escape((string)$description) . "',
                    tag = '" . $this->db->escape((string)$tag) . "',
                    meta_title = '" . $this->db->escape((string)$meta_title) . "',
                    meta_description = '" . $this->db->escape((string)$meta_description) . "',
                    meta_keyword = '" . $this->db->escape((string)$meta_keyword) . "'");
            }
        }
    }

    private function getProductDescriptionLanguageIds($source_language_id) {
        $ids = array((int)$source_language_id);

        $sql = "SELECT language_id FROM `" . DB_PREFIX . "language`";
        if ($this->tableColumnExists('language', 'status')) {
            $sql .= " WHERE status = 1";
        }
        $sql .= " ORDER BY sort_order ASC, name ASC";

        foreach ($this->db->query($sql)->rows as $row) {
            $language_id = (int)$row['language_id'];
            if ($language_id > 0) {
                $ids[] = $language_id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function saveProductStore($product_id, $product) {
        $store_id = isset($product['store_id']) && $product['store_id'] !== null
            ? (int)$product['store_id']
            : $this->getDefaultStoreId();

        $exists = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product_to_store` WHERE product_id = " . (int)$product_id . " AND store_id = " . (int)$store_id)->num_rows;
        if (!$exists) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_store` SET product_id = " . (int)$product_id . ", store_id = " . (int)$store_id);
        }
    }

    private function saveProductCategories($product_id, $product) {
        if (!isset($product['category_ids']) || !is_array($product['category_ids']) || !count($product['category_ids'])) {
            return;
        }

        $this->db->query("DELETE FROM `" . DB_PREFIX . "product_to_category` WHERE product_id = " . (int)$product_id);
        foreach ($product['category_ids'] as $category_id) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_category` SET product_id = " . (int)$product_id . ", category_id = " . (int)$category_id);
        }
    }

    private function saveProductAttributes($product_id, $product) {
        if (!isset($product['attributes']) || !is_array($product['attributes']) || !count($product['attributes'])) {
            return;
        }

        $language_id = isset($product['language_id']) && $product['language_id'] !== null
            ? (int)$product['language_id']
            : $this->getDefaultLanguageId();

        foreach ($product['attributes'] as $attribute_key => $value) {
            $attribute_id = (int)$attribute_key;
            if ($attribute_id <= 0) {
                $attribute_id = $this->resolveAttributeId((string)$attribute_key);
            }

            if ($attribute_id <= 0) {
                continue;
            }

            $text = is_array($value) ? implode(', ', $value) : (string)$value;
            $this->db->query("DELETE FROM `" . DB_PREFIX . "product_attribute` WHERE product_id = " . (int)$product_id . " AND attribute_id = " . (int)$attribute_id . " AND language_id = " . (int)$language_id);
            $this->db->query("INSERT INTO `" . DB_PREFIX . "product_attribute` SET
                product_id = " . (int)$product_id . ",
                attribute_id = " . (int)$attribute_id . ",
                language_id = " . (int)$language_id . ",
                text = '" . $this->db->escape($text) . "'");
        }
    }

    private function saveProductOptions($product_id, $product) {
        if (!isset($product['options']) || !is_array($product['options']) || !count($product['options'])) {
            return;
        }

        foreach ($product['options'] as $option_key => $value) {
            $option_id = (int)$option_key;
            if ($option_id <= 0) {
                continue;
            }

            $this->db->query("DELETE pov FROM `" . DB_PREFIX . "product_option_value` pov
                LEFT JOIN `" . DB_PREFIX . "product_option` po ON (pov.product_option_id = po.product_option_id)
                WHERE po.product_id = " . (int)$product_id . " AND po.option_id = " . (int)$option_id);
            $this->db->query("DELETE FROM `" . DB_PREFIX . "product_option` WHERE product_id = " . (int)$product_id . " AND option_id = " . (int)$option_id);

            $this->db->query("INSERT INTO `" . DB_PREFIX . "product_option` SET
                product_id = " . (int)$product_id . ",
                option_id = " . (int)$option_id . ",
                value = '',
                required = 0");

            $product_option_id = (int)$this->db->getLastId();
            $values = is_array($value) ? $value : array($value);

            foreach ($values as $option_value_id) {
                if ((int)$option_value_id <= 0) {
                    continue;
                }

                $this->db->query("INSERT INTO `" . DB_PREFIX . "product_option_value` SET
                    product_option_id = " . (int)$product_option_id . ",
                    product_id = " . (int)$product_id . ",
                    option_id = " . (int)$option_id . ",
                    option_value_id = " . (int)$option_value_id . ",
                    quantity = " . (int)$this->value($product, 'quantity', 0) . ",
                    subtract = 1,
                    price = '0.0000',
                    price_prefix = '+',
                    points = 0,
                    points_prefix = '+',
                    weight = '0.0000',
                    weight_prefix = '+'");
            }
        }
    }

    // Network/fs phase of image handling: downloads every reachable URL and
    // writes the image files, without touching the database. Runs outside the
    // product-graph transaction so remote latency never holds row locks.
    private function preDownloadProductImages($image_urls) {
        $result = array('requested' => count($image_urls), 'paths' => array(), 'errors' => array());
        if (!count($image_urls)) {
            return $result;
        }

        foreach ($image_urls as $url) {
            $error = '';
            $downloaded = $this->downloadImage((string)$url, $error);
            if ($downloaded) {
                $result['paths'][] = $downloaded;
            } elseif ($error !== '') {
                $result['errors'][] = array(
                    'url' => (string)$url,
                    'message' => $error
                );
            }
        }

        return $result;
    }

    // Database phase of image handling: links already-downloaded image files
    // into the product graph. Runs inside the row transaction so the primary
    // image, gallery rows and the rest of the graph commit atomically.
    private function saveProductImages($product_id, $product, $preloaded = null) {
        if (!isset($product['image_urls']) || !is_array($product['image_urls']) || !count($product['image_urls'])) {
            return array('requested' => 0, 'downloaded' => 0, 'errors' => array());
        }

        if ($preloaded === null) {
            $preloaded = $this->preDownloadProductImages($product['image_urls']);
        }
        $relative_images = $preloaded['paths'];
        $errors = $preloaded['errors'];

        if (!count($relative_images)) {
            if (count($errors)) {
                $this->writeLog('Delegat Bridge image download failed for product ' . (int)$product_id . ': ' . json_encode($errors));
            }
            return array(
                'requested' => count($product['image_urls']),
                'downloaded' => 0,
                'errors' => $errors
            );
        }

        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET image = '" . $this->db->escape($relative_images[0]) . "', date_modified = NOW() WHERE product_id = " . (int)$product_id);
        $this->db->query("DELETE FROM `" . DB_PREFIX . "product_image` WHERE product_id = " . (int)$product_id);

        $sort_order = 0;
        foreach (array_slice($relative_images, 1) as $image) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "product_image` SET
                product_id = " . (int)$product_id . ",
                image = '" . $this->db->escape($image) . "',
                sort_order = " . (int)$sort_order++);
        }

        if (count($errors)) {
            $this->writeLog('Delegat Bridge partial image download failure for product ' . (int)$product_id . ': ' . json_encode($errors));
        }

        return array(
            'requested' => count($product['image_urls']),
            'downloaded' => count($relative_images),
            'errors' => $errors
        );
    }

    private function saveProductSpecial($product_id, $product) {
        if (!array_key_exists('special_price', $product) || $product['special_price'] === null || $product['special_price'] === '') {
            return;
        }

        $this->replaceSpecialPrice($product_id, (float)$product['special_price']);
    }

    // The customer group special prices are written for - and read back
    // against. One source of truth shared by the write and the readback so
    // they can never drift into a false mismatch.
    private function getSpecialCustomerGroupId() {
        $customer_group_id = (int)$this->config->get('config_customer_group_id');
        return $customer_group_id > 0 ? $customer_group_id : 1;
    }

    private function replaceSpecialPrice($product_id, $price) {
        $customer_group_id = $this->getSpecialCustomerGroupId();

        $this->db->query("DELETE FROM `" . DB_PREFIX . "product_special` WHERE product_id = " . (int)$product_id . " AND customer_group_id = " . (int)$customer_group_id);
        $this->db->query("INSERT INTO `" . DB_PREFIX . "product_special` SET
            product_id = " . (int)$product_id . ",
            customer_group_id = " . (int)$customer_group_id . ",
            priority = 1,
            price = '" . (float)$price . "',
            date_start = '0000-00-00',
            date_end = '0000-00-00'");
    }

    private function resolveProductId($product) {
        if (isset($product['product_id']) && (int)$product['product_id'] > 0) {
            return (int)$product['product_id'];
        }

        if (isset($product['sku']) && trim((string)$product['sku']) !== '') {
            $query = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product` WHERE sku = '" . $this->db->escape((string)$product['sku']) . "' LIMIT 1");
            if ($query->num_rows) {
                return (int)$query->row['product_id'];
            }
        }

        if (isset($product['model']) && trim((string)$product['model']) !== '') {
            $query = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product` WHERE model = '" . $this->db->escape((string)$product['model']) . "' LIMIT 1");
            if ($query->num_rows) {
                return (int)$query->row['product_id'];
            }
        }

        return 0;
    }

    private function productExists($product_id) {
        return $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product` WHERE product_id = " . (int)$product_id)->num_rows > 0;
    }

    private function resolveManufacturerId($product) {
        if (isset($product['manufacturer_id']) && (int)$product['manufacturer_id'] > 0) {
            return (int)$product['manufacturer_id'];
        }

        if (!isset($product['manufacturer']) || trim((string)$product['manufacturer']) === '') {
            return 0;
        }

        $name = trim((string)$product['manufacturer']);
        $query = $this->db->query("SELECT manufacturer_id FROM `" . DB_PREFIX . "manufacturer` WHERE name = '" . $this->db->escape($name) . "' LIMIT 1");
        if ($query->num_rows) {
            return (int)$query->row['manufacturer_id'];
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "manufacturer` SET name = '" . $this->db->escape($name) . "', sort_order = 0");
        $manufacturer_id = (int)$this->db->getLastId();
        $this->db->query("INSERT INTO `" . DB_PREFIX . "manufacturer_to_store` SET manufacturer_id = " . (int)$manufacturer_id . ", store_id = " . (int)$this->getDefaultStoreId());

        return $manufacturer_id;
    }

    private function resolveCategoryIdByName($name, $parent_id, $language_id) {
        $query = $this->db->query("SELECT c.category_id
            FROM `" . DB_PREFIX . "category` c
            INNER JOIN `" . DB_PREFIX . "category_description` cd
                ON (c.category_id = cd.category_id AND cd.language_id = " . (int)$language_id . ")
            WHERE c.parent_id = " . (int)$parent_id . "
                AND cd.name = '" . $this->db->escape($name) . "'
            LIMIT 1");

        return $query->num_rows ? (int)$query->row['category_id'] : 0;
    }

    private function saveCategoryDescription($category_id, $language_id, $name, $description) {
        $meta_title = $name;
        $exists = $this->db->query("SELECT category_id FROM `" . DB_PREFIX . "category_description`
            WHERE category_id = " . (int)$category_id . " AND language_id = " . (int)$language_id)->num_rows;

        if ($exists) {
            $this->db->query("UPDATE `" . DB_PREFIX . "category_description` SET
                name = '" . $this->db->escape($name) . "',
                description = '" . $this->db->escape($description) . "',
                meta_title = '" . $this->db->escape($meta_title) . "',
                meta_description = '',
                meta_keyword = ''
                WHERE category_id = " . (int)$category_id . " AND language_id = " . (int)$language_id);
        } else {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "category_description` SET
                category_id = " . (int)$category_id . ",
                language_id = " . (int)$language_id . ",
                name = '" . $this->db->escape($name) . "',
                description = '" . $this->db->escape($description) . "',
                meta_title = '" . $this->db->escape($meta_title) . "',
                meta_description = '',
                meta_keyword = ''");
        }
    }

    private function saveCategoryStore($category_id, $store_id) {
        $exists = $this->db->query("SELECT category_id FROM `" . DB_PREFIX . "category_to_store`
            WHERE category_id = " . (int)$category_id . " AND store_id = " . (int)$store_id)->num_rows;

        if (!$exists) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "category_to_store` SET
                category_id = " . (int)$category_id . ",
                store_id = " . (int)$store_id);
        }
    }

    private function saveCategoryPath($category_id, $parent_id) {
        $level = 0;
        $this->db->query("DELETE FROM `" . DB_PREFIX . "category_path` WHERE category_id = " . (int)$category_id);

        if ($parent_id > 0) {
            $paths = $this->db->query("SELECT path_id, level FROM `" . DB_PREFIX . "category_path`
                WHERE category_id = " . (int)$parent_id . "
                ORDER BY level ASC")->rows;

            foreach ($paths as $path) {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "category_path` SET
                    category_id = " . (int)$category_id . ",
                    path_id = " . (int)$path['path_id'] . ",
                    level = " . (int)$level++);
            }
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "category_path` SET
            category_id = " . (int)$category_id . ",
            path_id = " . (int)$category_id . ",
            level = " . (int)$level);
    }

    private function buildCategoryAssignments($parent_id, $sort_order, $is_insert) {
        $fields = array();

        if ($this->tableColumnExists('category', 'parent_id')) {
            $fields[] = "parent_id = " . (int)$parent_id;
        }

        if ($this->tableColumnExists('category', 'sort_order')) {
            $fields[] = "sort_order = " . (int)$sort_order;
        }

        if ($this->tableColumnExists('category', 'status')) {
            $fields[] = "status = 1";
        }

        if ($this->tableColumnExists('category', 'top')) {
            $fields[] = "top = " . ($parent_id === 0 ? 1 : 0);
        }

        if ($this->tableColumnExists('category', 'column')) {
            $fields[] = "`column` = 1";
        }

        if ($is_insert && $this->tableColumnExists('category', 'date_added')) {
            $fields[] = "date_added = NOW()";
        }

        if ($this->tableColumnExists('category', 'date_modified')) {
            $fields[] = "date_modified = NOW()";
        }

        return $fields;
    }

    private function ensureCategorySchema() {
        $this->ensureTableColumn('category', 'parent_id', '`parent_id` int(11) NOT NULL DEFAULT 0');
        $this->ensureTableColumn('category', 'sort_order', '`sort_order` int(3) NOT NULL DEFAULT 0');
        $this->ensureTableColumn('category', 'status', '`status` tinyint(1) NOT NULL DEFAULT 1');
        $this->ensureTableColumn('category', 'date_added', '`date_added` datetime DEFAULT NULL');
        $this->ensureTableColumn('category', 'date_modified', '`date_modified` datetime DEFAULT NULL');
        $this->ensureTableColumn('category', 'top', '`top` tinyint(1) NOT NULL DEFAULT 0');
        $this->ensureTableColumn('category', 'column', '`column` int(3) NOT NULL DEFAULT 1');
    }

    private function ensureTableColumn($table, $column, $definition) {
        if (!$this->tableColumnExists($table, $column)) {
            $this->db->query("ALTER TABLE `" . DB_PREFIX . $this->db->escape($table) . "` ADD COLUMN " . $definition);
        }
    }

    private function tableExists($table) {
        static $cache = array();
        if (!array_key_exists($table, $cache)) {
            $cache[$table] = count($this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . $table) . "'")->rows) > 0;
        }
        return $cache[$table];
    }

    private function tableColumnExists($table, $column) {
        $query = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . $this->db->escape($table) . "` LIKE '" . $this->db->escape($column) . "'");
        return $query->num_rows > 0;
    }

    private function resolveAttributeId($name) {
        $language_id = $this->getDefaultLanguageId();
        $query = $this->db->query("SELECT attribute_id FROM `" . DB_PREFIX . "attribute_description` WHERE language_id = " . (int)$language_id . " AND name = '" . $this->db->escape($name) . "' LIMIT 1");
        return $query->num_rows ? (int)$query->row['attribute_id'] : 0;
    }

    private function ensureAttributeGroup($name, $language_id) {
        $query = $this->db->query("SELECT attribute_group_id FROM `" . DB_PREFIX . "attribute_group_description`
            WHERE language_id = " . (int)$language_id . "
                AND name = '" . $this->db->escape($name) . "'
            LIMIT 1");

        if ($query->num_rows) {
            return (int)$query->row['attribute_group_id'];
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_group` SET sort_order = 0");
        $attribute_group_id = (int)$this->db->getLastId();
        $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_group_description` SET
            attribute_group_id = " . (int)$attribute_group_id . ",
            language_id = " . (int)$language_id . ",
            name = '" . $this->db->escape($name) . "'");

        return $attribute_group_id;
    }

    private function ensureAttribute($name, $attribute_group_id, $language_id, $sort_order) {
        $query = $this->db->query("SELECT attribute_id FROM `" . DB_PREFIX . "attribute_description`
            WHERE language_id = " . (int)$language_id . "
                AND name = '" . $this->db->escape($name) . "'
            LIMIT 1");

        if ($query->num_rows) {
            $attribute_id = (int)$query->row['attribute_id'];
            $this->db->query("UPDATE `" . DB_PREFIX . "attribute` SET
                attribute_group_id = " . (int)$attribute_group_id . ",
                sort_order = " . (int)$sort_order . "
                WHERE attribute_id = " . (int)$attribute_id);
            return $attribute_id;
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute` SET
            attribute_group_id = " . (int)$attribute_group_id . ",
            sort_order = " . (int)$sort_order);
        $attribute_id = (int)$this->db->getLastId();
        $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_description` SET
            attribute_id = " . (int)$attribute_id . ",
            language_id = " . (int)$language_id . ",
            name = '" . $this->db->escape($name) . "'");

        return $attribute_id;
    }

    private function downloadImage($url, &$error = null) {
        $error = '';
        if (!preg_match('#^https?://#i', $url)) {
            $error = 'Invalid image URL';
            return '';
        }

        $body = false;
        $content_type = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Delegat-OpenCart-Bridge/1.0');
            $body = curl_exec($ch);
            if ($body === false) {
                $error = 'cURL error: ' . curl_error($ch);
                curl_close($ch);
                return '';
            }

            $content_type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($status < 200 || $status >= 300) {
                $error = 'HTTP status ' . $status;
                return '';
            }
        } else {
            $body = @file_get_contents($url);
            if ($body === false) {
                $last_error = error_get_last();
                $error = isset($last_error['message']) ? $last_error['message'] : 'file_get_contents failed';
            }
        }

        if ($body === false || strlen($body) === 0) {
            if ($error === '') {
                $error = 'Empty image response';
            }
            return '';
        }

        $extension = $this->guessImageExtension($url, $content_type);
        $dir = DIR_IMAGE . 'catalog/delegat/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $relative = 'catalog/delegat/' . md5($url) . '.' . $extension;
        file_put_contents(DIR_IMAGE . $relative, $body);

        return $relative;
    }

    private function productImageUrls($product_id, $primary_image) {
        $images = array();
        if ($primary_image !== '') {
            $images[] = $this->imageUrl($primary_image);
        }

        $query = $this->db->query("SELECT image FROM `" . DB_PREFIX . "product_image` WHERE product_id = " . (int)$product_id . " ORDER BY sort_order ASC, product_image_id ASC");
        foreach ($query->rows as $row) {
            if (!isset($row['image']) || trim((string)$row['image']) === '') {
                continue;
            }
            $images[] = $this->imageUrl((string)$row['image']);
        }

        return array_values(array_unique($images));
    }

    private function imageUrl($relative) {
        $relative = ltrim((string)$relative, '/');
        if (defined('HTTP_CATALOG') && HTTP_CATALOG) {
            return rtrim(HTTP_CATALOG, '/') . '/image/' . $relative;
        }

        $server = $this->config->get('config_url');
        return rtrim((string)$server, '/') . '/image/' . $relative;
    }

    private function writeLog($message) {
        if (isset($this->log)) {
            $this->log->write($message);
        }
    }

    private function guessImageExtension($url, $content_type) {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
        $allowed = array('jpg', 'jpeg', 'png', 'gif', 'webp');

        if (in_array($extension, $allowed, true)) {
            return $extension === 'jpeg' ? 'jpg' : $extension;
        }

        if (stripos($content_type, 'png') !== false) return 'png';
        if (stripos($content_type, 'gif') !== false) return 'gif';
        if (stripos($content_type, 'webp') !== false) return 'webp';

        return 'jpg';
    }

    private function baseProductFields() {
        return array(
            array('key' => 'sku', 'name' => 'SKU', 'is_required' => true, 'type' => 'string', 'group' => 'Basic', 'sort_order' => 10),
            array('key' => 'model', 'name' => 'Model', 'is_required' => true, 'type' => 'string', 'group' => 'Basic', 'sort_order' => 20),
            array('key' => 'name', 'name' => 'Name', 'is_required' => true, 'type' => 'string', 'group' => 'Basic', 'sort_order' => 30),
            array('key' => 'description', 'name' => 'Description', 'is_required' => false, 'type' => 'string', 'group' => 'Basic', 'sort_order' => 40),
            array('key' => 'price', 'name' => 'Price', 'is_required' => true, 'type' => 'number', 'group' => 'Commerce', 'sort_order' => 50),
            array('key' => 'special_price', 'name' => 'Special price', 'is_required' => false, 'type' => 'number', 'group' => 'Commerce', 'sort_order' => 55),
            array('key' => 'quantity', 'name' => 'Quantity', 'is_required' => false, 'type' => 'integer', 'group' => 'Commerce', 'sort_order' => 60),
            array('key' => 'images', 'name' => 'Images', 'is_required' => false, 'type' => 'multiselect', 'group' => 'Media', 'sort_order' => 70),
            array('key' => 'manufacturer', 'name' => 'Manufacturer', 'is_required' => false, 'type' => 'string', 'group' => 'Basic', 'sort_order' => 80),
            array('key' => 'meta_title', 'name' => 'Meta title', 'is_required' => false, 'type' => 'string', 'group' => 'SEO', 'sort_order' => 90),
            array('key' => 'meta_description', 'name' => 'Meta description', 'is_required' => false, 'type' => 'string', 'group' => 'SEO', 'sort_order' => 100),
            array('key' => 'meta_keyword', 'name' => 'Meta keyword', 'is_required' => false, 'type' => 'string', 'group' => 'SEO', 'sort_order' => 105),
            array('key' => 'tag', 'name' => 'Tags', 'is_required' => false, 'type' => 'string', 'group' => 'SEO', 'sort_order' => 110),
            array('key' => 'status', 'name' => 'Status', 'is_required' => false, 'type' => 'boolean', 'group' => 'Basic', 'sort_order' => 115),
            array('key' => 'weight', 'name' => 'Weight', 'is_required' => false, 'type' => 'number', 'group' => 'Shipping', 'sort_order' => 120),
            array('key' => 'length', 'name' => 'Length', 'is_required' => false, 'type' => 'number', 'group' => 'Shipping', 'sort_order' => 130),
            array('key' => 'width', 'name' => 'Width', 'is_required' => false, 'type' => 'number', 'group' => 'Shipping', 'sort_order' => 140),
            array('key' => 'height', 'name' => 'Height', 'is_required' => false, 'type' => 'number', 'group' => 'Shipping', 'sort_order' => 150)
        );
    }

    private function categoryResponseFields($attribute_ids, $language_id) {
        $fields = $this->baseProductFields();
        $attribute_ids = array_values(array_unique(array_map('intval', $attribute_ids)));

        if (!count($attribute_ids)) {
            return $fields;
        }

        $sql_ids = implode(',', $attribute_ids);
        $attribute_sql = "SELECT a.attribute_id, ad.name, agd.name AS group_name
                          FROM `" . DB_PREFIX . "attribute` a
                          LEFT JOIN `" . DB_PREFIX . "attribute_description` ad
                              ON (a.attribute_id = ad.attribute_id AND ad.language_id = " . (int)$language_id . ")
                          LEFT JOIN `" . DB_PREFIX . "attribute_group_description` agd
                              ON (a.attribute_group_id = agd.attribute_group_id AND agd.language_id = " . (int)$language_id . ")
                          WHERE a.attribute_id IN (" . $sql_ids . ")
                          ORDER BY a.sort_order ASC, ad.name ASC";

        foreach ($this->db->query($attribute_sql)->rows as $row) {
            $fields[] = array(
                'key' => 'attr:' . $row['attribute_id'],
                'name' => $row['name'],
                'is_required' => false,
                'type' => 'string',
                'group' => $row['group_name'] ? 'Attributes: ' . $row['group_name'] : 'Attributes',
                'sort_order' => 1000 + (int)$row['attribute_id']
            );
        }

        return $fields;
    }

    private function getCategoryNameMap($language_id) {
        $rows = $this->db->query("SELECT c.category_id, c.parent_id, cd.name
            FROM `" . DB_PREFIX . "category` c
            LEFT JOIN `" . DB_PREFIX . "category_description` cd
                ON (c.category_id = cd.category_id AND cd.language_id = " . (int)$language_id . ")")->rows;

        $map = array();
        foreach ($rows as $row) {
            $map[(int)$row['category_id']] = array(
                'name' => $row['name'],
                'parent_id' => (int)$row['parent_id']
            );
        }

        return $map;
    }

    private function buildCategoryPath($category_id, $map) {
        $parts = array();
        $guard = 0;

        while ($category_id > 0 && isset($map[$category_id]) && $guard++ < 50) {
            array_unshift($parts, $map[$category_id]['name']);
            $category_id = (int)$map[$category_id]['parent_id'];
        }

        return implode(' / ', $parts);
    }

    private function countCategoryChildren($category_id) {
        $query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "category` WHERE status = 1 AND parent_id = " . (int)$category_id);
        return (int)$query->row['total'];
    }

    private function readJsonBody() {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return array();
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \Exception('Invalid JSON body');
        }

        return $data;
    }

    private function json($data, $status = 200) {
        $this->setStatus($status);
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function jsonError($message, $status = 400, $details = array()) {
        $this->json(array(
            'success' => false,
            'error' => $message,
            'message' => $message,
            'details' => $details
        ), $status);
    }

    private function setStatus($status) {
        $messages = array(
            200 => 'OK',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Internal Server Error'
        );

        $protocol = isset($this->request->server['SERVER_PROTOCOL']) ? $this->request->server['SERVER_PROTOCOL'] : 'HTTP/1.1';
        $text = isset($messages[$status]) ? $messages[$status] : 'Error';
        $this->response->addHeader($protocol . ' ' . (int)$status . ' ' . $text);
    }

    private function getProvidedApiKey() {
        $server = $this->request->server;

        if (isset($server['HTTP_X_DELEGAT_API_KEY'])) {
            return trim($server['HTTP_X_DELEGAT_API_KEY']);
        }

        if (isset($server['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(.+)/i', $server['HTTP_AUTHORIZATION'], $matches)) {
            return trim($matches[1]);
        }

        if (isset($server['REDIRECT_HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(.+)/i', $server['REDIRECT_HTTP_AUTHORIZATION'], $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    private function parseAllowedIps($value) {
        $lines = preg_split('/\r\n|\r|\n/', $value);
        $ips = array();

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $ips[] = $line;
            }
        }

        return $ips;
    }

    private function getDefaultStoreId() {
        $value = $this->config->get('module_delegat_bridge_default_store_id');
        return $value === null || $value === '' ? 0 : (int)$value;
    }

    private function getDefaultLanguageId() {
        $value = $this->config->get('module_delegat_bridge_default_language_id');
        return $value === null || $value === '' ? (int)$this->config->get('config_language_id') : (int)$value;
    }

    private function orders() {
        $page_size = isset($this->request->get['page_size']) ? (int)$this->request->get['page_size'] : 50;
        if ($page_size < 1) {
            $page_size = 50;
        }
        if ($page_size > 100) {
            $page_size = 100;
        }

        $cursor = isset($this->request->get['cursor']) && $this->request->get['cursor'] !== ''
            ? (int)$this->request->get['cursor']
            : 0;
        $status_filter = isset($this->request->get['status']) ? strtolower(trim((string)$this->request->get['status'])) : '';
        $language_id = $this->getDefaultLanguageId();

        $sql = "SELECT o.*, os.name AS status_name
                FROM `" . DB_PREFIX . "order` o
                LEFT JOIN `" . DB_PREFIX . "order_status` os
                    ON (o.order_status_id = os.order_status_id AND os.language_id = " . (int)$language_id . ")
                WHERE o.order_status_id > 0";

        if ($cursor > 0) {
            $sql .= " AND o.order_id < " . (int)$cursor;
        }

        $sql .= " ORDER BY o.order_id DESC LIMIT " . (int)($page_size + 1);
        $rows = $this->db->query($sql)->rows;

        $has_more = count($rows) > $page_size;
        if ($has_more) {
            $rows = array_slice($rows, 0, $page_size);
        }

        $items = array();
        $last_id = null;
        foreach ($rows as $row) {
            $last_id = (int)$row['order_id'];
            $mapped = $this->mapOrderRow($row);
            if ($status_filter !== '' && $mapped['status'] !== $status_filter) {
                continue;
            }
            $items[] = $mapped;
        }

        return array(
            'items' => $items,
            'has_more' => $has_more,
            'next_cursor' => $has_more && $last_id !== null ? (string)$last_id : null
        );
    }

    private function order() {
        $order_id = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
        if ($order_id <= 0) {
            $this->jsonError('order_id is required', 400);
            exit;
        }

        $row = $this->loadOrderRow($order_id);
        if (!$row) {
            $this->jsonError('Order not found', 404);
            exit;
        }

        return $this->mapOrderRow($row);
    }

    private function updateOrderStatus($payload) {
        $order_id = isset($payload['order_id']) ? (int)$payload['order_id'] : (isset($payload['orderId']) ? (int)$payload['orderId'] : 0);
        $action = isset($payload['action']) ? trim((string)$payload['action']) : '';
        $reason = isset($payload['reason']) ? trim((string)$payload['reason']) : '';

        if ($order_id <= 0 || $action === '') {
            return array(
                'success' => false,
                'order' => null,
                'error' => 'order_id and action are required'
            );
        }

        $existing = $this->loadOrderRow($order_id);
        if (!$existing) {
            return array(
                'success' => false,
                'order' => null,
                'error' => 'Order not found: ' . $order_id
            );
        }

        $status_id = $this->resolveOrderStatusId($action);
        if ($status_id <= 0) {
            return array(
                'success' => false,
                'order' => null,
                'error' => 'Unsupported order status action: ' . $action
            );
        }

        $this->db->query("UPDATE `" . DB_PREFIX . "order`
            SET order_status_id = " . (int)$status_id . ", date_modified = NOW()
            WHERE order_id = " . (int)$order_id);

        $this->db->query("INSERT INTO `" . DB_PREFIX . "order_history`
            SET order_id = " . (int)$order_id . ",
                order_status_id = " . (int)$status_id . ",
                notify = 0,
                comment = '" . $this->db->escape($reason) . "',
                date_added = NOW()");

        $updated = $this->loadOrderRow($order_id);

        return array(
            'success' => true,
            'order' => $updated ? $this->mapOrderRow($updated) : null,
            'error' => null
        );
    }

    private function promotions() {
        $rows = $this->db->query("SELECT * FROM `" . DB_PREFIX . "coupon` ORDER BY coupon_id DESC")->rows;
        $items = array();
        foreach ($rows as $row) {
            $items[] = $this->mapPromotionRow($row);
        }

        return array('items' => $items);
    }

    private function promotion() {
        $promotion_id = isset($this->request->get['promotion_id']) ? (int)$this->request->get['promotion_id'] : 0;
        if ($promotion_id <= 0) {
            $this->jsonError('promotion_id is required', 400);
            exit;
        }

        $row = $this->loadCouponRow($promotion_id);
        if (!$row) {
            $this->jsonError('Promotion not found', 404);
            exit;
        }

        return $this->mapPromotionRow($row);
    }

    private function updatePromotionProducts($payload) {
        $coupon_id = isset($payload['promotion_id'])
            ? (int)$payload['promotion_id']
            : (isset($payload['promotionId']) ? (int)$payload['promotionId'] : 0);

        if ($coupon_id <= 0) {
            return array(
                'success' => false,
                'added' => 0,
                'removed' => 0,
                'promotion' => null,
                'error' => 'promotion_id is required'
            );
        }

        $coupon = $this->loadCouponRow($coupon_id);
        if (!$coupon) {
            return array(
                'success' => false,
                'added' => 0,
                'removed' => 0,
                'promotion' => null,
                'error' => 'Promotion not found: ' . $coupon_id
            );
        }

        $remove_all = !empty($payload['remove_all']) || !empty($payload['removeAll']);
        $remove_ids = array();
        $raw_remove = isset($payload['remove_external_product_ids'])
            ? $payload['remove_external_product_ids']
            : (isset($payload['removeExternalProductIds']) ? $payload['removeExternalProductIds'] : array());
        if (is_array($raw_remove)) {
            foreach ($raw_remove as $id) {
                $pid = (int)$id;
                if ($pid > 0) {
                    $remove_ids[$pid] = $pid;
                }
            }
        }

        $add_ids = array();
        $raw_add = isset($payload['add']) && is_array($payload['add']) ? $payload['add'] : array();
        foreach ($raw_add as $entry) {
            if (is_array($entry)) {
                $val = isset($entry['external_product_id'])
                    ? $entry['external_product_id']
                    : (isset($entry['externalProductId']) ? $entry['externalProductId'] : (isset($entry['product_id']) ? $entry['product_id'] : 0));
                $pid = (int)$val;
            } else {
                $pid = (int)$entry;
            }
            if ($pid > 0) {
                $add_ids[$pid] = $pid;
            }
        }

        $existing_rows = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "coupon_product` WHERE coupon_id = " . (int)$coupon_id)->rows;
        $existing_ids = array();
        foreach ($existing_rows as $r) {
            $existing_ids[(int)$r['product_id']] = true;
        }

        $removed = 0;
        $added = 0;

        if ($remove_all) {
            $removed = count($existing_ids);
            $this->db->query("DELETE FROM `" . DB_PREFIX . "coupon_product` WHERE coupon_id = " . (int)$coupon_id);
            $existing_ids = array();
        } elseif (!empty($remove_ids)) {
            foreach ($remove_ids as $pid) {
                if (isset($existing_ids[$pid])) {
                    $this->db->query("DELETE FROM `" . DB_PREFIX . "coupon_product` WHERE coupon_id = " . (int)$coupon_id . " AND product_id = " . (int)$pid);
                    unset($existing_ids[$pid]);
                    $removed++;
                }
            }
        }

        foreach ($add_ids as $pid) {
            if (!isset($existing_ids[$pid])) {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "coupon_product` SET coupon_id = " . (int)$coupon_id . ", product_id = " . (int)$pid);
                $existing_ids[$pid] = true;
                $added++;
            }
        }

        $updated = $this->loadCouponRow($coupon_id);

        return array(
            'success' => true,
            'added' => $added,
            'removed' => $removed,
            'promotion' => $updated ? $this->mapPromotionRow($updated) : null,
            'error' => null
        );
    }

    private function loadOrderRow($order_id) {
        $language_id = $this->getDefaultLanguageId();
        $query = $this->db->query("SELECT o.*, os.name AS status_name
            FROM `" . DB_PREFIX . "order` o
            LEFT JOIN `" . DB_PREFIX . "order_status` os
                ON (o.order_status_id = os.order_status_id AND os.language_id = " . (int)$language_id . ")
            WHERE o.order_id = " . (int)$order_id . "
            LIMIT 1");

        return $query->num_rows ? $query->row : null;
    }

    private function mapOrderRow($row) {
        $order_id = (int)$row['order_id'];
        $raw_status = isset($row['status_name']) && $row['status_name'] !== ''
            ? (string)$row['status_name']
            : (string)$row['order_status_id'];

        $item_rows = $this->db->query("SELECT op.product_id, op.name, op.model, op.quantity, op.price, p.sku
            FROM `" . DB_PREFIX . "order_product` op
            LEFT JOIN `" . DB_PREFIX . "product` p ON (op.product_id = p.product_id)
            WHERE op.order_id = " . (int)$order_id . "
            ORDER BY op.order_product_id ASC")->rows;

        $items = array();
        foreach ($item_rows as $ir) {
            $sku = isset($ir['sku']) && $ir['sku'] !== '' ? (string)$ir['sku'] : (string)$ir['model'];
            $items[] = array(
                'external_product_id' => (string)$ir['product_id'],
                'sku' => $sku,
                'name' => (string)$ir['name'],
                'quantity' => (int)$ir['quantity'],
                'price' => (float)$ir['price'],
                'discounted_price' => null
            );
        }

        $customer_name = trim((isset($row['firstname']) ? $row['firstname'] : '') . ' ' . (isset($row['lastname']) ? $row['lastname'] : ''));
        $address_parts = array();
        foreach (array('shipping_country', 'shipping_zone', 'shipping_postcode', 'shipping_city', 'shipping_address_1', 'shipping_address_2') as $field) {
            if (!empty($row[$field])) {
                $address_parts[] = trim((string)$row[$field]);
            }
        }

        return array(
            'external_id' => (string)$order_id,
            'display_id' => '#' . $order_id,
            'status' => $this->normalizeOrderStatus($raw_status),
            'raw_status' => $raw_status,
            'channel' => 'shop',
            'created_at' => !empty($row['date_added']) ? (string)$row['date_added'] : null,
            'updated_at' => !empty($row['date_modified']) ? (string)$row['date_modified'] : null,
            'currency' => !empty($row['currency_code']) ? (string)$row['currency_code'] : 'RUB',
            'total' => isset($row['total']) ? (float)$row['total'] : 0.0,
            'customer_name' => $customer_name !== '' ? $customer_name : null,
            'customer_phone' => !empty($row['telephone']) ? (string)$row['telephone'] : null,
            'customer_email' => !empty($row['email']) ? (string)$row['email'] : null,
            'shipping_address' => !empty($address_parts) ? implode(', ', $address_parts) : null,
            'comment' => !empty($row['comment']) ? (string)$row['comment'] : null,
            'items' => $items
        );
    }

    private function normalizeOrderStatus($raw_status) {
        $s = strtolower(trim((string)$raw_status));
        if ($s === '' || $s === 'pending' || $s === 'new') {
            return 'new';
        }
        if (strpos($s, 'process') !== false || strpos($s, 'confirm') !== false || strpos($s, 'pack') !== false) {
            return 'processing';
        }
        if (strpos($s, 'ship') !== false || strpos($s, 'delivering') !== false) {
            return 'shipped';
        }
        if (strpos($s, 'complete') !== false || strpos($s, 'deliver') !== false) {
            return 'delivered';
        }
        if (strpos($s, 'cancel') !== false || strpos($s, 'void') !== false || strpos($s, 'denied') !== false || strpos($s, 'fail') !== false || strpos($s, 'expired') !== false) {
            return 'cancelled';
        }
        if (strpos($s, 'refund') !== false || strpos($s, 'return') !== false || strpos($s, 'reversed') !== false || strpos($s, 'chargeback') !== false) {
            return 'returned';
        }
        return 'unknown';
    }

    private function resolveOrderStatusId($action) {
        if (ctype_digit($action)) {
            $id = (int)$action;
            $check = $this->db->query("SELECT order_status_id FROM `" . DB_PREFIX . "order_status` WHERE order_status_id = " . $id . " LIMIT 1");
            return $check->num_rows ? $id : 0;
        }

        $normalized = strtolower(trim($action));
        $candidates = array();
        if (in_array($normalized, array('confirm', 'processing', 'pack', 'assemble', 'accept'), true)) {
            $candidates = array('Processing', 'Processed');
        } elseif (in_array($normalized, array('ship', 'shipped', 'delivering'), true)) {
            $candidates = array('Shipped');
        } elseif (in_array($normalized, array('complete', 'completed', 'deliver', 'delivered'), true)) {
            $candidates = array('Complete', 'Delivered');
        } elseif (in_array($normalized, array('cancel', 'canceled', 'cancelled'), true)) {
            $candidates = array('Canceled', 'Cancelled', 'Voided');
        } elseif (in_array($normalized, array('refund', 'refunded', 'return', 'returned'), true)) {
            $candidates = array('Refunded', 'Reversed');
        } elseif (in_array($normalized, array('pending', 'new'), true)) {
            $candidates = array('Pending');
        } else {
            $candidates = array($action);
        }

        $language_id = $this->getDefaultLanguageId();
        foreach ($candidates as $name) {
            $q = $this->db->query("SELECT order_status_id FROM `" . DB_PREFIX . "order_status`
                WHERE language_id = " . (int)$language_id . " AND LOWER(name) = '" . $this->db->escape(strtolower($name)) . "'
                LIMIT 1");
            if ($q->num_rows) {
                return (int)$q->row['order_status_id'];
            }
        }

        foreach ($candidates as $name) {
            $q = $this->db->query("SELECT order_status_id FROM `" . DB_PREFIX . "order_status`
                WHERE LOWER(name) LIKE '%" . $this->db->escape(strtolower($name)) . "%'
                LIMIT 1");
            if ($q->num_rows) {
                return (int)$q->row['order_status_id'];
            }
        }

        return 0;
    }

    private function loadCouponRow($coupon_id) {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "coupon` WHERE coupon_id = " . (int)$coupon_id . " LIMIT 1");
        return $query->num_rows ? $query->row : null;
    }

    private function mapPromotionRow($row) {
        $coupon_id = (int)$row['coupon_id'];
        $prod_rows = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "coupon_product` WHERE coupon_id = " . (int)$coupon_id . " ORDER BY product_id ASC")->rows;
        $product_ids = array();
        foreach ($prod_rows as $pr) {
            $product_ids[] = (string)$pr['product_id'];
        }

        $starts_at = !empty($row['date_start']) && $row['date_start'] !== '0000-00-00' ? (string)$row['date_start'] : null;
        $ends_at = !empty($row['date_end']) && $row['date_end'] !== '0000-00-00' ? (string)$row['date_end'] : null;
        $is_percent = isset($row['type']) && strtoupper((string)$row['type']) === 'P';

        return array(
            'external_id' => (string)$coupon_id,
            'name' => !empty($row['name']) ? (string)$row['name'] : (string)$row['code'],
            'type' => 'coupon',
            'status' => !empty($row['status']) ? 'active' : 'inactive',
            'starts_at' => $starts_at,
            'ends_at' => $ends_at,
            'discount_percent' => $is_percent ? (float)$row['discount'] : null,
            'discount_amount' => !$is_percent ? (float)$row['discount'] : null,
            'product_ids' => $product_ids,
            'description' => !empty($row['code']) ? 'Code: ' . (string)$row['code'] : null
        );
    }

    private function value($array, $key, $default = null) {
        return array_key_exists($key, $array) && $array[$key] !== null ? $array[$key] : $default;
    }
}
