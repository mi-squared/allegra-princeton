<?php

namespace App\Models\Presswise;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuoteQuantities extends Model
{
    use HasFactory;

    protected $connection = 'presswise';
    protected $table = 'quote_quantities';
    protected $primaryKey = 'quoteQuantID';

    const CREATED_AT = 'created';
    const UPDATED_AT = 'modified';
}
