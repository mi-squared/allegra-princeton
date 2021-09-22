<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

use Illuminate\Console\Command;
use App\Models\Presswise\QueueOrder;
use App\Services\ZohoService;
use App\Jobs\ZohoUpdateInvoice;

use Carbon\Carbon;

class PresswisePollInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'presswise:poll-invoices {orderID?} {--noqueue}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Polls for invoices that changed from "new" since the last run and updates the Zoho status.';

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

        $orderID = $this->argument('orderID');

        $last_run_data = $this->getLastRunData();

        $this->line("Last run: " . $last_run_data['maxUpdated']);
        // test orderID = 317180

        if ($orderID) {
            $invoices = QueueOrder::where('orderID', $orderID)->get();
        } else {
            // grab all quotes updated since the last run
            $invoices = QueueOrder::updatedSince($last_run_data['maxUpdated'])->where('status', '<>', 'new')->get();
        }


        $this->line(count($invoices) . " to process\n");
        foreach ($invoices as $invoice) {
            if ($this->option("noqueue")) {
                // try to find the existing record
                try {
                    $record = ZohoService::findInvoiceByPW_Order_ID($invoice->orderID);

                    $invoice->updateZohoRecord($record);
                    ZohoService::updateRecord("Invoices", $record);
                } catch (\App\Services\ZohoRecordNotFoundException) {
                    // TODO
                }
            } else {
                ZohoUpdateInvoice::dispatch($invoice);
            }

            if (!$orderID) {
                // updated max updated, if needed
                if ($invoice->lastModified > $last_run_data['maxUpdated']) {
                    $last_run_data['maxUpdated'] = $invoice->lastModified;
                }
            }
        }

        $this->saveLastRunData($last_run_data);

        return 0;
    }

    protected function getLastRunData()
    {
        // TODO abstract this into a common class to share with
        // the other tasks
        $defaults = [
            'maxUpdated' => Carbon::now()->subtract(1, 'day')
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
