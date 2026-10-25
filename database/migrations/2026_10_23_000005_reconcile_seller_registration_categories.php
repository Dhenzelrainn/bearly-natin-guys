<?php

use Database\Seeders\SellerRegistrationCategorySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'categories',
            'marketplace_categories',
            'marketplace_subcategories',
            'marketplace_category_business_categories',
        ] as $requiredTable) {
            if (! Schema::hasTable($requiredTable)) {
                throw new RuntimeException("Run the existing [{$requiredTable}] taxonomy migration before Seller registration reconciliation.");
            }
        }

        $registrationCategories = app(SellerRegistrationCategorySeeder::class);
        $registrationCategories->preflight();

        if (! Schema::hasColumn('categories', 'is_registration_option')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->boolean('is_registration_option')->default(false)->after('is_active');
            });
        }

        $registrationCategories->run();
    }

    public function down(): void
    {
        throw new RuntimeException('Seller registration category migration is forward-only to preserve registration options, mappings, and taxonomy history.');
    }
};
