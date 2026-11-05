<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shipping_settlements', 'settlement_mode')) {
            Schema::table('shipping_settlements', function (Blueprint $table): void {
                $table->string('settlement_mode', 24)->nullable();
            });
        }

        if (! Schema::hasColumn('shipping_settlements', 'origin_logistics_profile_id')) {
            Schema::table('shipping_settlements', function (Blueprint $table): void {
                $table->foreignId('origin_logistics_profile_id')->nullable();
            });
        }

        if (! Schema::hasColumn('shipping_settlements', 'origin_logistics_share_minor')) {
            Schema::table('shipping_settlements', function (Blueprint $table): void {
                $table->unsignedBigInteger('origin_logistics_share_minor')->default(0);
            });
        }

        if (! Schema::hasTable('shipping_settlement_origin_remittances')) {
            Schema::create('shipping_settlement_origin_remittances', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('shipping_settlement_id');
                $table->foreignId('logistics_profile_id');
                $table->unsignedBigInteger('amount_minor');
                $table->string('status', 24)->default('pending');
                $table->foreignId('logistics_payout_account_id')->nullable();
                $table->string('remittance_method', 30)->nullable();
                $table->string('remittance_reference', 150)->nullable();
                $table->date('remitted_on')->nullable();
                $table->timestamp('remitted_at')->nullable();
                $table->foreignId('remitted_by')->nullable();
                $table->text('remittance_reason')->nullable();
                $table->string('remitted_to_provider', 120)->nullable();
                $table->string('remitted_to_account_name', 160)->nullable();
                $table->char('remitted_to_last4', 4)->nullable();
                $table->timestamps();
            });
        }

        $this->ensureIndex(
            'shipping_settlement_origin_remittances',
            ['shipping_settlement_id'],
            'ss_origin_remit_settlement_unique',
            unique: true,
        );
        $this->ensureIndex(
            'shipping_settlement_origin_remittances',
            ['logistics_profile_id', 'status'],
            'ss_origin_remittances_provider_status_idx',
        );

        $this->ensureForeignKey(
            'shipping_settlement_origin_remittances',
            'shipping_settlement_id',
            'shipping_settlements',
            'ss_origin_remit_settlement_fk',
        );
        $this->ensureForeignKey(
            'shipping_settlement_origin_remittances',
            'logistics_profile_id',
            'logistics_profiles',
            'ss_origin_remit_logistics_fk',
        );
        $this->ensureForeignKey(
            'shipping_settlement_origin_remittances',
            'logistics_payout_account_id',
            'logistics_payout_accounts',
            'ss_origin_remit_payout_account_fk',
        );
        $this->ensureForeignKey(
            'shipping_settlement_origin_remittances',
            'remitted_by',
            'users',
            'ss_origin_remit_remitted_by_fk',
            nullOnDelete: true,
        );
        $this->ensureForeignKey(
            'shipping_settlements',
            'origin_logistics_profile_id',
            'logistics_profiles',
            'ss_origin_logistics_profile_fk',
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('shipping_settlement_origin_remittances')
            && DB::table('shipping_settlement_origin_remittances')->exists()) {
            throw new RuntimeException('Origin Logistics remittance history exists; preserve it before rolling back this financial migration.');
        }

        if (Schema::hasTable('shipping_settlements')
            && DB::table('shipping_settlements')->where('settlement_mode', 'inter_hub')->exists()) {
            throw new RuntimeException('Inter-hub settlement snapshots exist; preserve them before rolling back this financial migration.');
        }

        Schema::dropIfExists('shipping_settlement_origin_remittances');
        $this->dropForeignKeyForColumn('shipping_settlements', 'origin_logistics_profile_id');

        Schema::table('shipping_settlements', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['settlement_mode', 'origin_logistics_profile_id', 'origin_logistics_share_minor'],
                fn (string $column): bool => Schema::hasColumn('shipping_settlements', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function ensureIndex(string $tableName, array $columns, string $indexName, bool $unique = false): void
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            if ($index['columns'] === $columns && (bool) $index['unique'] === $unique) {
                return;
            }
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName, $unique): void {
            if ($unique) {
                $table->unique($columns, $indexName);

                return;
            }

            $table->index($columns, $indexName);
        });
    }

    private function ensureForeignKey(
        string $tableName,
        string $column,
        string $referencedTable,
        string $foreignKeyName,
        bool $nullOnDelete = false,
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

        Schema::table($tableName, function (Blueprint $table) use ($column, $referencedTable, $foreignKeyName, $nullOnDelete): void {
            $foreign = $table->foreign($column, $foreignKeyName)
                ->references('id')
                ->on($referencedTable);

            if ($nullOnDelete) {
                $foreign->nullOnDelete();

                return;
            }

            $foreign->restrictOnDelete();
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
