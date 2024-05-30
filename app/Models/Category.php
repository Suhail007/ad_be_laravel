<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;
    protected $table = 'wp_terms';
    protected $primaryKey = 'term_id';
    public function products()
    {
        return $this->belongsToMany(Product::class, 'wp_term_relationships', 'term_taxonomy_id', 'object_id');
    }
    public function categorymeta(){
        return $this->hasMany(CategoryMeta::class,'term_id','term_id');
    }

    public function taxonomies(){
        return $this->hasOne(CategoryTaxonomy::class,'term_id','term_id');
    }
    public function taxonomy()
    {
        return $this->hasOne(CategoryTaxonomy::class, 'term_id', 'term_id');
    }

    public function children()
    {
        return $this->hasManyThrough(
            Category::class,
            CategoryTaxonomy::class,
            'parent', // Foreign key on CategoryTaxonomy table
            'term_id', // Foreign key on Category table
            'term_id', // Local key on Category table
            'term_id' // Local key on CategoryTaxonomy table
        );
    }
}
