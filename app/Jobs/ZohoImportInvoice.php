<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use App\Models\Presswise\QueueOrder;
use App\Services\ZohoService;


class ZohoImportInvoice implements ShouldQueue //, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $invoice;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(QueueOrder $invoice)
    {
        $this->invoice = $invoice;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $record = ZohoService::findInvoiceByPW_Order_ID($this->invoice->orderID);
            // record found; ignore the import request
        } catch (\App\Services\ZohoRecordNotFoundException) {
            // not found! record a new record
            $record = $this->invoice->toZoho();
            ZohoService::saveRecord("Invoices", $record);
        }
    }

    /**
     * The unique ID of the job.
     *
     * @return string
     */
    public function uniqueId()
    {
        return $this->invoice->orderID;
    }
}
