<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;
    protected $table = 'wp_posts';
    protected $primaryKey = 'ID';
    public function meta(){
        return $this->hasMany(ProductMeta::class,'post_id','ID');
    }
    public function categories(){
        return $this->belongsToMany(Category::class, 'wp_term_relationships', 'object_id', 'term_taxonomy_id');
    }
}
