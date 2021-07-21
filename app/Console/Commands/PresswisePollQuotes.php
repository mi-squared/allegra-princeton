<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

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

        // test quote id = 132181.1

        if ($quoteID) {
            $new_quotes = ListQuote::where('quoteID', $quoteID)->firstRevision()->get();
        } else {
            // TODO use highest $quote->created_at from the last run
            // $new_quotes = ListQuote::createdSince(Carbon::now()->subtract(1, 'day'))->get();
            $new_quotes = [];
        }

        $this->line(count($new_quotes) . " to process\n");
        foreach ($new_quotes as $quote) {
            ZohoImportQuote::dispatch($quote);
            if (!$quoteID) {
                // TODO record max created_at
            }
        }


        return 0;
    }
}
