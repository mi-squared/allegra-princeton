<?php

namespace App\Models\Presswise;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ListCustomer extends Model
{
    use HasFactory;

    protected $connection = 'presswise_order';
    protected $table = 'list_customer';
    protected $primaryKey = 'customerID';

    const CREATED_AT = 'created';
    const UPDATED_AT = 'modified';
}
