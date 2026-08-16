<?php

use App\Models\Product;
use App\Jobs\AutoFillProductAttributesJob;

$count = Product::where('is_active', true)->count();
echo 'Active products: ' . $count . PHP_EOL;

$dispatched = 0;
Product::where('is_active', true)->each(function($p) use (&$dispatched) {
    AutoFillProductAttributesJob::dispatch($p->id);
    $dispatched++;
});
echo 'Dispatched: ' . $dispatched . ' jobs' . PHP_EOL;