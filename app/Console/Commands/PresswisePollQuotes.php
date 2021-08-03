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
    protected $signature = 'presswise:poll-quotes {quoteID?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Polls for new quotes from the list_quote table and inserts them into the job queue for processing.';

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

        $this->line("Last run: " . $last_run_data->maxCreated);

        // test quote id = 132181.1

        if ($quoteID) {
            $new_quotes = ListQuote::where('quoteID', $quoteID)->firstRevision()->get();
        } else {
            $new_quotes = ListQuote::createdSince(new Carbon($last_run_data->maxCreated))->delayed()->get();
        }


        $this->line(count($new_quotes) . " to process\n");
        foreach ($new_quotes as $quote) {
            ZohoImportQuote::dispatch($quote);
            if (!$quoteID) {
                // updated max created, if needed
                if ($quote->created > $last_run_data->maxCreated) {
                    $last_run_data->maxCreated = $quote->created;
                }
            }
        }

        $this->saveLastRunData($last_run_data);

        return 0;
    }

    protected function getLastRunData()
    {
        // look for the json file
        if (Storage::disk('local')->exists('presswise.json')) {
            return json_decode(Storage::disk('local')->get('presswise.json'));
        } else {
            // not found; make new data
            return [
                'maxCreated' => Carbon::now()->subtract(1, 'day')
            ];
        }
    }

    protected function saveLastRunData($data)
    {
        return Storage::disk('local')->put('presswise.json', json_encode($data));
    }
}
