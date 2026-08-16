<?php

namespace App\Traits;

use App\Models\Slug;
use Illuminate\Support\Str;

trait HasSlug
{
    public static function bootHasSlug()
    {
        static::saved(function ($model) {
            $model->generateSlug();
        });
    }

    public function slugs()
    {
        return $this->morphMany(Slug::class, 'sluggable');
    }

    public function generateSlug()
    {
        $baseSlug = Str::slug($this->{$this->getSluggableField()});
        $slugString = $baseSlug;
        $counter = 1;

        // Check if this exact slug exists for another model
        while (
            Slug::where('slug', $slugString)->where(function ($query) {
                $query->where('sluggable_type', '!=', get_class($this))
                    ->orWhere('sluggable_id', '!=', $this->id);
            })->exists()
        ) {
            $slugString = $baseSlug . '-' . $counter;
            $counter++;
        }

        // If it exists for THIS model as canonical, do nothing
        $currentCanonical = $this->slugs()->where('is_canonical', true)->first();
        if ($currentCanonical && $currentCanonical->slug === $slugString) {
            return;
        }

        // Demote old canonicals
        $this->slugs()->update(['is_canonical' => false]);

        // MUL-128: updateOrCreate evita slugs_slug_unique ao re-sync
        $this->slugs()->updateOrCreate(
            ['slug' => $slugString],
            ['is_canonical' => true]
        );
    }

    protected function getSluggableField()
    {
        return 'name'; // Default field to generate slug from
    }
}
