<?php

namespace App\Console\Commands;

use App\Models\Presswise\QueueOrder;
use Illuminate\Console\Command;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

use App\Jobs\ZohoImportInvoice;
use App\Services\ZohoService;
use Carbon\Carbon;


class PresswiseImportNewInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'presswise:import-new-invoices {orderID?} {--noqueue}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Polls for invoices that were created as "new" since the last run from the queue_order table.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        DB::listen(function ($query) {
            File::append(
                storage_path('/logs/query.log'),
                $query->sql . ' [' . implode(', ', $query->bindings) . ']' . PHP_EOL
            );
        });

        // Test invoice is orderID=317180
        $orderID = $this->argument('orderID');

        $last_run_data = $this->getLastRunData();

        $this->line("Last run: " . $last_run_data['maxCreated']);

        if ($orderID) {
            $invoices = QueueOrder::where('orderID', $orderID)->firstRevision()->get();
        } else {
            // grab all "new" invoices created since
            $invoices = QueueOrder::createdSince($last_run_data['maxCreated'])->where('status', 'new')->where('subTotal', '>', 0)->get();
        }

        $this->line(count($invoices) . " to process\n");
        foreach ($invoices as $invoice) {

            if ($this->option("noqueue")) {
                try {
                    $record = ZohoService::findInvoiceByPW_Order_ID($invoice->orderID);
                    $this->line("Invoice with PW orderID={$invoice->orderID} found. ignoring.");
                    // record found; ignore the import request
                } catch (\App\Services\ZohoRecordNotFoundException) {
                    // not found! record a new record
                    $this->line("Importing PW orderID={$invoice->orderID}.");
                    $record = $invoice->toZoho();
                    ZohoService::saveRecord("Invoices", $record);
                }
            } else {
                ZohoImportInvoice::dispatch($invoice);
            }

            if (!$orderID) {
                // updated max created, if needed
                if ($invoice->createDate > $last_run_data['maxCreated']) {
                    $last_run_data['maxCreated'] = $invoice->createDate;
                }
            }


            $record = $invoice->toZoho();
        }

        $this->saveLastRunData($last_run_data);

        return 0;
    }

    protected function getLastRunData()
    {
        $defaults = [
            'maxCreated' => Carbon::now()->subtract(1, 'day')
        ];
        // look for the json file
        if (Storage::disk('local')->exists('invoice.data')) {
            return unserialize(Storage::disk('local')->get('invoice.data')) + $defaults;
        } else {
            // not found; make new data
            return $defaults;
        }
    }

    protected function saveLastRunData($data)
    {
        return Storage::disk('local')->put('invoice.data', serialize($data));
    }
}
