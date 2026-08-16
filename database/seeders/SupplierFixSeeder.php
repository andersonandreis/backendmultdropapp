<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Supplier;
use App\Models\User;

class SupplierFixSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Supplier::truncate();

        $user = User::first();
        $userId = $user ? $user->id : 1;

        Supplier::create([
            'id' => 53,
            'user_id' => $userId,
            'company_name' => 'JTDrop',
            'document' => '11111111111111',
            'is_active' => true,
            'type' => 'warehouse'
        ]);

        Supplier::create([
            'id' => 61,
            'user_id' => $userId,
            'company_name' => 'Plug Lar',
            'document' => '22222222222222',
            'is_active' => true,
            'type' => 'warehouse'
        ]);

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        echo "Suppliers fixed!\n";
    }
}
