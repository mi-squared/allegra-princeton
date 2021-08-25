<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

use Illuminate\Console\Command;
use App\Models\Presswise\ListQuote;
use App\Services\ZohoService;
use App\Jobs\ZohoImportQuote;

use Carbon\Carbon;

class PresswisePollQuotes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'presswise:poll-quotes {quoteID?} {--noqueue}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Polls for quotes that changed from "new" since the last run from the list_quote table and updates the Zoho status.';

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

        $quoteID = $this->argument('quoteID');

        $last_run_data = $this->getLastRunData();

        $this->line("Last run: " . $last_run_data['maxCreated']);
        // test quote id = 132181.1

        if ($quoteID) {
            $new_quotes = ListQuote::where('quoteID', $quoteID)->firstRevision()->get();
        } else {
            // grab all quotes updated since the last run
            $new_quotes = ListQuote::updatedSince($last_run_data['maxCreated'])->where('status', '<>', 'new')->firstRevision()->get();
        }


        $this->line(count($new_quotes) . " to process\n");
        foreach ($new_quotes as $quote) {
            if ($this->option("noqueue")) {
                // try to find the existing record
                $record = ZohoService::findQuoteByPW_QuoteNo($quote->quoteID);

                $quote->updateZohoRecord($record);
                ZohoService::updateRecord("Quotes", $record);
            } else {
                ZohoUpdateQuote::dispatch($quote);
            }

            if (!$quoteID) {
                // updated max created, if needed
                if ($quote->created > $last_run_data['maxCreated']) {
                    $last_run_data['maxCreated'] = $quote->created;
                }
            }
        }

        $this->saveLastRunData($last_run_data);

        return 0;
    }

    protected function getLastRunData()
    {
        // look for the json file
        if (Storage::disk('local')->exists('presswise.data')) {
            return unserialize(Storage::disk('local')->get('presswise.data'));
        } else {
            // not found; make new data
            return [
                'maxCreated' => Carbon::now()->subtract(1, 'day')
            ];
        }
    }

    protected function saveLastRunData($data)
    {
        return Storage::disk('local')->put('presswise.data', serialize($data));
    }
}
