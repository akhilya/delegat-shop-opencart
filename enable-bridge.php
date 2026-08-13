<?php
declare(strict_types=1);

$host = getenv('DB_HOSTNAME') ?: 'db';
$user = getenv('DB_USERNAME') ?: 'opencart';
$pass = getenv('DB_PASSWORD') ?: 'opencart';
$name = getenv('DB_DATABASE') ?: 'opencart';
$port = (int) (getenv('DB_PORT') ?: 3306);
$prefix = getenv('DB_PREFIX') ?: 'oc_';
$apiKey = getenv('BRIDGE_API_KEY') ?: 'delegat-oc-sandbox-api-key-local';

$mysqli = new mysqli($host, $user, $pass, $name, $port);
if ($mysqli->connect_error) {
    fwrite(STDERR, "DB connect failed: {$mysqli->connect_error}\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

function q(mysqli $db, string $sql): mysqli_result|bool
{
    $res = $db->query($sql);
    if ($res === false) {
        fwrite(STDERR, "SQL error: {$db->error}\n{$sql}\n");
        exit(1);
    }
    return $res;
}

$extTable = $prefix . 'extension';
$exists = q($mysqli, "SELECT extension_id FROM `{$extTable}` WHERE `type` = 'module' AND `code` = 'delegat_bridge' LIMIT 1");
if ($exists->num_rows === 0) {
    q($mysqli, "INSERT INTO `{$extTable}` SET `extension` = 'delegat_bridge', `type` = 'module', `code` = 'delegat_bridge'");
}

$setting = $prefix . 'setting';
$pairs = [
    'module_delegat_bridge_status' => '1',
    'module_delegat_bridge_api_key' => $apiKey,
    'module_delegat_bridge_allowed_ips' => '',
    'module_delegat_bridge_default_store_id' => '0',
    'module_delegat_bridge_default_language_id' => '1',
];

foreach ($pairs as $key => $value) {
    $k = $mysqli->real_escape_string($key);
    $v = $mysqli->real_escape_string($value);
    $found = q($mysqli, "SELECT setting_id FROM `{$setting}` WHERE `code` = 'module_delegat_bridge' AND `key` = '{$k}' LIMIT 1");
    if ($found->num_rows === 0) {
        q($mysqli, "INSERT INTO `{$setting}` SET `store_id` = 0, `code` = 'module_delegat_bridge', `key` = '{$k}', `value` = '{$v}', `serialized` = 0");
    } else {
        q($mysqli, "UPDATE `{$setting}` SET `value` = '{$v}', `serialized` = 0 WHERE `code` = 'module_delegat_bridge' AND `key` = '{$k}'");
    }
}

$route = 'extension/delegat_bridge/module/delegat_bridge';
$ug = $prefix . 'user_group';
$res = q($mysqli, "SELECT user_group_id, permission FROM `{$ug}` WHERE user_group_id = 1 LIMIT 1");
$row = $res->fetch_assoc();
if ($row) {
    $perm = json_decode((string) $row['permission'], true);
    if (!is_array($perm)) {
        $perm = ['access' => [], 'modify' => []];
    }
    foreach (['access', 'modify'] as $kind) {
        if (!isset($perm[$kind]) || !is_array($perm[$kind])) {
            $perm[$kind] = [];
        }
        if (!in_array($route, $perm[$kind], true)) {
            $perm[$kind][] = $route;
        }
    }
    $json = $mysqli->real_escape_string((string) json_encode($perm));
    q($mysqli, "UPDATE `{$ug}` SET `permission` = '{$json}' WHERE user_group_id = 1");
}

echo "Delegat Bridge enabled.\n";
