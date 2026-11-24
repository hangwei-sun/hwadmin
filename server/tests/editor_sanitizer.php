<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use plugins\editor\HtmlSanitizer;

$html = '<p style="color:red">Hello<script>alert(1)</script><a href="javascript:alert(1)" onclick="alert(1)">link</a><img src="/uploads/a.png" onerror="alert(1)"></p><video src="/uploads/a.mp4" controls></video>';
$result = HtmlSanitizer::clean($html);
foreach (['script', 'javascript:', 'onclick', 'onerror'] as $unsafe) {
    if (stripos($result, $unsafe) !== false) { throw new RuntimeException('Unsafe HTML retained: ' . $unsafe); }
}
foreach (['Hello', '/uploads/a.png', '<video', '/uploads/a.mp4'] as $expected) {
    if (!str_contains($result, $expected)) { throw new RuntimeException('Editor content lost: ' . $expected . ' in ' . $result); }
}
$manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/plugins/editor/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$nodes = array_column($manifest['menus'][0]['children'], 'name');
foreach (['drafts', 'upload', 'savedraft', 'deletedraft'] as $action) {
    if (!in_array('plugin/editor/index/' . $action, $nodes, true)) {
        throw new RuntimeException('Missing editor permission for ' . $action);
    }
}
echo "Editor sanitizer and permission assertions passed\n";
