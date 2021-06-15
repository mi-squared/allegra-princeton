<?php

namespace App\Models\Presswise;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ListQuote extends Model
{
    use HasFactory;

    protected $connection = 'presswise';
    protected $table = 'list_quote';

    const CREATED_AT = 'created';
    const UPDATED_AT = 'modified';

    const STATUS_NEW = 'new';

    public function scopeCreatedSince($query, $ts)
    {
        return $query->where('status', self::STATUS_NEW)->where(self::CREATED_AT, '>', $ts);
    }

    public function toZoho()
    {
        throw new \RuntimeException("TODO");
    }
}
