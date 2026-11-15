<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = DB::getDatabaseName();
$docs = __DIR__.'/../docs/database';
$tables = collect(DB::select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME', [$database, 'BASE TABLE']))
    ->pluck('TABLE_NAME')->reject(fn (string $table) => $table === 'migrations')->values();
$columns = collect(DB::select('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$database]))->groupBy('TABLE_NAME');
$foreignKeys = collect(DB::select('SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.COLUMN_NAME', [$database]));
$indexes = collect(DB::select('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME, INDEX_NAME', [$database]))->groupBy('TABLE_NAME');

$dbmlType = static function (object $column): string {
    return match ($column->DATA_TYPE) {
        'enum' => 'varchar',
        'tinyint' => 'boolean',
        'bigint', 'int', 'smallint', 'mediumint' => $column->DATA_TYPE,
        default => preg_replace('/\s+unsigned$/', '', $column->COLUMN_TYPE),
    };
};

$dbml = ['// Generated from the validated Bearly MySQL schema', ''];
foreach ($tables as $table) {
    $dbml[] = "Table {$table} {";
    foreach ($columns->get($table, collect()) as $column) {
        $attributes = [];
        if ($column->COLUMN_KEY === 'PRI') $attributes[] = 'pk';
        if ($column->EXTRA === 'auto_increment') $attributes[] = 'increment';
        if ($column->IS_NULLABLE === 'NO') $attributes[] = 'not null';
        if ($column->COLUMN_KEY === 'UNI') $attributes[] = 'unique';
        $suffix = $attributes ? ' ['.implode(', ', $attributes).']' : '';
        $dbml[] = "  {$column->COLUMN_NAME} {$dbmlType($column)}{$suffix}";
    }
    $dbml[] = '}';
    $dbml[] = '';
}
foreach ($foreignKeys as $foreignKey) {
    $dbml[] = "Ref: {$foreignKey->TABLE_NAME}.{$foreignKey->COLUMN_NAME} > {$foreignKey->REFERENCED_TABLE_NAME}.{$foreignKey->REFERENCED_COLUMN_NAME}";
}
file_put_contents($docs.'/BEARLY_ERD.dbml', implode(PHP_EOL, $dbml).PHP_EOL);

$mermaid = ['erDiagram'];
foreach ($tables as $table) {
    $mermaid[] = "    {$table} {";
    foreach ($columns->get($table, collect()) as $column) {
        $type = preg_replace('/[^a-zA-Z0-9_]/', '_', $column->DATA_TYPE);
        $key = $column->COLUMN_KEY === 'PRI' ? ' PK' : ($column->COLUMN_KEY === 'UNI' ? ' UK' : '');
        $mermaid[] = "        {$type} {$column->COLUMN_NAME}{$key}";
    }
    $mermaid[] = '    }';
}
foreach ($foreignKeys as $foreignKey) {
    $nullable = $columns->get($foreignKey->TABLE_NAME)->firstWhere('COLUMN_NAME', $foreignKey->COLUMN_NAME)->IS_NULLABLE === 'YES';
    $child = $nullable ? 'o{' : '|{';
    $mermaid[] = "    {$foreignKey->REFERENCED_TABLE_NAME} ||--{$child} {$foreignKey->TABLE_NAME} : {$foreignKey->COLUMN_NAME}";
}
file_put_contents($docs.'/BEARLY_ERD.mmd', implode(PHP_EOL, $mermaid).PHP_EOL);

$markdown = ['# Bearly Database Schema Dictionary', '', '> Generated from a clean MySQL migration. Laravel migrations remain the executable source of truth.', '', 'Conventions: money integers are centavos; timestamps are UTC at storage boundaries; workflow statuses use application strings rather than MySQL enums; nullable foreign keys represent optional actors or later assignments.', ''];
foreach ($tables as $table) {
    $markdown[] = "## `{$table}`";
    $markdown[] = '';
    $markdown[] = '| Column | MySQL type | Null | Default | Key |';
    $markdown[] = '|---|---|---:|---|---|';
    foreach ($columns->get($table, collect()) as $column) {
        $default = $column->COLUMN_DEFAULT === null ? '—' : str_replace('|', '\\|', (string) $column->COLUMN_DEFAULT);
        $markdown[] = "| `{$column->COLUMN_NAME}` | `{$column->COLUMN_TYPE}` | {$column->IS_NULLABLE} | {$default} | {$column->COLUMN_KEY} |";
    }
    $markdown[] = '';
    $tableForeignKeys = $foreignKeys->where('TABLE_NAME', $table);
    if ($tableForeignKeys->isNotEmpty()) {
        $markdown[] = '**Foreign keys**';
        $markdown[] = '';
        foreach ($tableForeignKeys as $foreignKey) {
            $markdown[] = "- `{$foreignKey->COLUMN_NAME}` → `{$foreignKey->REFERENCED_TABLE_NAME}.{$foreignKey->REFERENCED_COLUMN_NAME}`; update {$foreignKey->UPDATE_RULE}, delete {$foreignKey->DELETE_RULE}.";
        }
        $markdown[] = '';
    }
    $tableIndexes = $indexes->get($table, collect())->reject(fn ($index) => $index->INDEX_NAME === 'PRIMARY');
    if ($tableIndexes->isNotEmpty()) {
        $markdown[] = '**Indexes**';
        $markdown[] = '';
        foreach ($tableIndexes as $index) {
            $kind = (int) $index->NON_UNIQUE === 0 ? 'unique' : 'index';
            $markdown[] = "- `{$index->INDEX_NAME}` ({$index->columns_list}) — {$kind}.";
        }
        $markdown[] = '';
    }
}
file_put_contents($docs.'/BEARLY_DATABASE_SCHEMA.md', implode(PHP_EOL, $markdown).PHP_EOL);
echo 'Generated '.$tables->count()." tables into {$docs}".PHP_EOL;
