<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_bundles', function (Blueprint $table) {
            if (! Schema::hasColumn('product_bundles', 'name')) {
                $table->string('name')->nullable()->after('supplier_id');
            }
            if (! Schema::hasColumn('product_bundles', 'sku')) {
                $table->string('sku', 60)->nullable()->after('name');
            }
            if (! Schema::hasColumn('product_bundles', 'ean')) {
                $table->string('ean', 25)->nullable()->after('sku');
            }
            if (! Schema::hasColumn('product_bundles', 'price')) {
                $table->decimal('price', 18, 2)->nullable()->after('ean');
            }
            if (! Schema::hasColumn('product_bundles', 'stock')) {
                $table->integer('stock')->default(0)->after('price');
            }
            if (! Schema::hasColumn('product_bundles', 'weight')) {
                $table->decimal('weight', 10, 2)->nullable()->after('stock');
            }
            if (! Schema::hasColumn('product_bundles', 'description')) {
                $table->text('description')->nullable()->after('weight');
            }
            if (! Schema::hasColumn('product_bundles', 'legacy_kit_id')) {
                $table->unsignedBigInteger('legacy_kit_id')->nullable()->after('description');
                $table->index('legacy_kit_id');
            }
            if (! Schema::hasColumn('product_bundles', 'cover_image_url')) {
                $table->text('cover_image_url')->nullable()->after('legacy_kit_id');
            }
            if (! Schema::hasColumn('product_bundles', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('cover_image_url');
            }
        });

        if (! Schema::hasTable('product_bundle_media')) {
            Schema::create('product_bundle_media', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_bundle_id')
                    ->constrained('product_bundles')
                    ->cascadeOnDelete();
                $table->text('url');
                $table->unsignedSmallInteger('ordem')->default(0);
                $table->timestamps();
                $table->index('product_bundle_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('product_bundles', function (Blueprint $table) {
            foreach (['name', 'sku', 'ean', 'price', 'stock', 'weight', 'description',
                      'legacy_kit_id', 'cover_image_url', 'is_active'] as $col) {
                if (Schema::hasColumn('product_bundles', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::dropIfExists('product_bundle_media');
    }
};
