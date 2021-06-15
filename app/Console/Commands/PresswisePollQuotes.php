<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Presswise\ListQuote;

use Carbon\Carbon;

class PresswisePollQuotes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'presswise:poll-quotes';

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
        // TODO use highest $quote->created_at from the last run
        $new_quotes = ListQuote::createdSince(Carbon::now()->subtract(1, 'day'))->get();

        $this->line(count($new_quotes) . " to process\n");
        foreach ($new_quotes as $quote) {
            // TODO
            // ZohoImportQuote::dispatch($quote);
        }

        // TODO record max created_at
        return 0;
    }
}
