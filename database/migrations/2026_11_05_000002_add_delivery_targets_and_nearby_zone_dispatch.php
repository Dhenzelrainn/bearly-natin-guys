<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'delivery_latitude')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->decimal('delivery_latitude', 10, 7)->nullable();
            });
        }

        if (! Schema::hasColumn('orders', 'delivery_longitude')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->decimal('delivery_longitude', 10, 7)->nullable();
            });
        }

        if (! Schema::hasColumn('dispatch_batches', 'assignment_mode')) {
            Schema::table('dispatch_batches', function (Blueprint $table): void {
                $table->string('assignment_mode', 24)->nullable();
            });
        }

        if (! Schema::hasColumn('dispatch_batches', 'rider_zone_id')) {
            Schema::table('dispatch_batches', function (Blueprint $table): void {
                $table->foreignId('rider_zone_id')->nullable();
            });
        }

        if (! Schema::hasColumn('dispatch_batches', 'assignment_reason')) {
            Schema::table('dispatch_batches', function (Blueprint $table): void {
                $table->text('assignment_reason')->nullable();
            });
        }

        $this->ensureIndex(
            'dispatch_batches',
            'rider_zone_id',
            'dispatch_batches_rider_zone_idx',
        );

        $this->ensureForeignKey(
            'dispatch_batches',
            'rider_zone_id',
            'sorting_zones',
            'dispatch_batches_rider_zone_fk',
        );
    }

    public function down(): void
    {
        $hasOverflowAssignment = false;
        if (Schema::hasColumn('dispatch_batches', 'assignment_mode')
            || Schema::hasColumn('dispatch_batches', 'rider_zone_id')
            || Schema::hasColumn('dispatch_batches', 'assignment_reason')) {
            $hasOverflowAssignment = DB::table('dispatch_batches')->where(function ($query): void {
                foreach (['assignment_mode', 'rider_zone_id', 'assignment_reason'] as $column) {
                    if (Schema::hasColumn('dispatch_batches', $column)) {
                        $query->orWhereNotNull($column);
                    }
                }
            })->exists();
        }

        $hasDeliverySnapshot = false;
        if (Schema::hasColumn('orders', 'delivery_latitude')
            || Schema::hasColumn('orders', 'delivery_longitude')) {
            $hasDeliverySnapshot = DB::table('orders')->where(function ($query): void {
                if (Schema::hasColumn('orders', 'delivery_latitude')) {
                    $query->orWhereNotNull('delivery_latitude');
                }
                if (Schema::hasColumn('orders', 'delivery_longitude')) {
                    $query->orWhereNotNull('delivery_longitude');
                }
            })->exists();
        }

        if ($hasOverflowAssignment || $hasDeliverySnapshot) {
            throw new RuntimeException(
                'Delivery coordinate snapshots or dispatch assignment history exist; preserve them before rolling back this migration.',
            );
        }

        $this->dropForeignKeyForColumn('dispatch_batches', 'rider_zone_id');

        Schema::table('dispatch_batches', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['assignment_mode', 'rider_zone_id', 'assignment_reason'],
                fn (string $column): bool => Schema::hasColumn('dispatch_batches', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['delivery_latitude', 'delivery_longitude'],
                fn (string $column): bool => Schema::hasColumn('orders', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function ensureForeignKey(
        string $tableName,
        string $column,
        string $referencedTable,
        string $foreignKeyName,
    ): void {
        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) !== [$column]) {
                continue;
            }

            if (($foreignKey['foreign_table'] ?? null) !== $referencedTable
                || ($foreignKey['foreign_columns'] ?? []) !== ['id']) {
                throw new RuntimeException("The existing {$tableName}.{$column} foreign key targets an unexpected table or column.");
            }

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $referencedTable, $foreignKeyName): void {
            $table->foreign($column, $foreignKeyName)
                ->references('id')
                ->on($referencedTable)
                ->nullOnDelete();
        });
    }

    private function ensureIndex(
        string $tableName,
        string $column,
        string $indexName,
    ): void {
        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['columns'] ?? []) === [$column]) {
                return;
            }
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $indexName): void {
            $table->index($column, $indexName);
        });
    }

    private function dropForeignKeyForColumn(string $tableName, string $column): void
    {
        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) !== [$column]) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($foreignKey): void {
                $table->dropForeign($foreignKey['name']);
            });

            return;
        }
    }
};
