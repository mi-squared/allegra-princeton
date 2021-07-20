<?php

namespace App\Models\Presswise;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ListSubscriber extends Model
{
    use HasFactory;

    protected $connection = 'presswise_login';
    protected $table = 'list_subscriber';
    protected $primaryKey = 'userID';

    const CREATED_AT = null;
    const UPDATED_AT = 'modified';
}
