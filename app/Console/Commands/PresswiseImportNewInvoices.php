<?php

namespace App\Console\Commands;

use App\Models\Presswise\QueueOrder;
use Illuminate\Console\Command;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

use App\Services\ZohoService;


class PresswiseImportNewInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'presswise:import-new-invoices';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
        // ZohoService::dumpRecordsAsPhp('Invoices');
        $invoices = QueueOrder::where('orderID', '317180')->get();

        $this->line(count($invoices) . " to process\n");
        foreach ($invoices as $invoice) {

            $record = $invoice->toZoho();
            try {
                ZohoService::saveRecord("Invoices", $record);
            } catch (\com\zoho\crm\api\exception\SDKException $e) {
                $this->line($e);
            }
        }

        return 0;
    }
}
