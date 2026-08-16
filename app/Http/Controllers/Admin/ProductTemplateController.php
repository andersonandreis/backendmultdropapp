<?php

namespace App\Http\Controllers\Admin;

use App\Exports\ProductsTemplateExport;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;

class ProductTemplateController extends Controller
{
    public function download()
    {
        if (!auth()->check()) {
            return redirect()->route('filament.admin.auth.login');
        }

        $user = auth()->user();

        if (!in_array($user->role, ['super_admin', 'supplier'])) {
            abort(403);
        }

        return Excel::download(new ProductsTemplateExport(), 'template-produtos.csv', \Maatwebsite\Excel\Excel::CSV);
    }
}
