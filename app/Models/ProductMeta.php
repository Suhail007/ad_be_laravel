<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductMeta extends Model
{
    use HasFactory;
    protected $table = 'wp_postmeta';
    protected $primaryKey = 'meta_id';
    protected $guarded = [];
    public $timestamps = false;
    public function product(){
        return $this->belongsTo(Product::class, 'post_id');
    }
}
