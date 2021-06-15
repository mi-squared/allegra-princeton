<?php

namespace App\Models\Presswise;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuoteItems extends Model
{
    use HasFactory;

    protected $connection = 'presswise';
    protected $table = 'quote_items';
    protected $primaryKey = 'itemRow';

    const CREATED_AT = 'created';
    const UPDATED_AT = 'modified';
}
