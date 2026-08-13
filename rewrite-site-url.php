<?php
declare(strict_types=1);

$raw = getenv('SITE_URL') ?: 'http://localhost:8089/';
$site = rtrim($raw, '/') . '/';
$escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $site);

function patch_file(string $path, array $replacements): void
{
    if (!is_file($path)) {
        fwrite(STDERR, "Skip missing {$path}\n");
        return;
    }
    $text = file_get_contents($path);
    if ($text === false) {
        fwrite(STDERR, "Cannot read {$path}\n");
        exit(1);
    }
    foreach ($replacements as $const => $value) {
        $updated = preg_replace(
            "/define\\s*\\(\\s*'" . preg_quote($const, '/') . "'\\s*,\\s*'[^']*'\\s*\\)/",
            "define('" . $const . "', '" . $value . "')",
            $text,
            1
        );
        if ($updated === null) {
            fwrite(STDERR, "Regex failed for {$const} in {$path}\n");
            exit(1);
        }
        $text = $updated;
    }
    if (file_put_contents($path, $text) === false) {
        fwrite(STDERR, "Cannot write {$path}\n");
        exit(1);
    }
}

patch_file('/var/www/html/config.php', [
    'HTTP_SERVER' => $escaped,
    'HTTPS_SERVER' => $escaped,
]);
patch_file('/var/www/html/admin/config.php', [
    'HTTP_SERVER' => $escaped . 'admin/',
    'HTTPS_SERVER' => $escaped . 'admin/',
    'HTTP_CATALOG' => $escaped,
    'HTTPS_CATALOG' => $escaped,
]);

echo "SITE_URL patched to {$site}\n";
