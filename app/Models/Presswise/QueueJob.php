<?php

namespace App\Models\Presswise;

use Illuminate\Database\Eloquent\Model;


class QueueJob extends Model
{
    protected $connection = 'presswise_order';
    protected $table = 'queue_job';
    protected $primaryKey = 'jobID';

    const CREATED_AT = 'created';
    const UPDATED_AT = 'modified';

    public function queueJobs()
    {
        return $this->hasOne(QuoteQuantities::class, "quoteQuantItemRow")->ofMany("quoteQuantID", "min");
    }
}
