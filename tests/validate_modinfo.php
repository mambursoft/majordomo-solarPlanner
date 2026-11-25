<?php
$d = json_decode(file_get_contents(__DIR__ . '/../modinfo.json'), true);
if (!$d || !isset($d['name'], $d['version'], $d['title'])) {
    fwrite(STDERR, "invalid modinfo\n");
    exit(1);
}
echo 'modinfo ok: ' . $d['name'] . ' ' . $d['version'] . "\n";