<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$target = $argv[1] ?? null;
if (! $target) {
    fwrite(STDERR, "Usage: php scripts/backup_database.php <output.sql>\n");
    exit(1);
}

$pdo = DB::connection()->getPdo();
$database = DB::getDatabaseName();
$tables = DB::select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME', [$database, 'BASE TABLE']);
$handle = fopen($target, 'wb');
if (! $handle) {
    throw new RuntimeException("Unable to open backup target: {$target}");
}

fwrite($handle, "-- Bearly database backup\nSET FOREIGN_KEY_CHECKS=0;\n\n");
foreach ($tables as $record) {
    $table = $record->TABLE_NAME;
    $quotedTable = '`'.str_replace('`', '``', $table).'`';
    $create = DB::selectOne("SHOW CREATE TABLE {$quotedTable}");
    $createSql = array_values((array) $create)[1];
    fwrite($handle, "DROP TABLE IF EXISTS {$quotedTable};\n{$createSql};\n\n");

    foreach (DB::table($table)->orderByRaw('1')->cursor() as $row) {
        $values = array_map(static fn ($value) => $value === null ? 'NULL' : $pdo->quote((string) $value), array_values((array) $row));
        fwrite($handle, "INSERT INTO {$quotedTable} VALUES (".implode(', ', $values).");\n");
    }
    fwrite($handle, "\n");
}
fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($handle);

echo realpath($target).' ('.filesize($target)." bytes)\n";
