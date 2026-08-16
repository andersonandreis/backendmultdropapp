<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use \App\Traits\HasSlug;

    protected $fillable = [
        'supplier_id',
        'name',
        'parent_id',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}
