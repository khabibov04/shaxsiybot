<?php

namespace App\Console\Commands;

use App\Models\CurrencyRate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class UpdateCurrencyRates extends Command
{
    protected $signature = 'telegram:update-currency-rates';
    protected $description = 'Update currency exchange rates from external API';

    public function handle(): int
    {
        $this->info('Updating currency rates...');

        try {
            // Using free exchange rate API
            $response = Http::get('https://api.exchangerate-api.com/v4/latest/USD');

            if (!$response->successful()) {
                $this->error('Failed to fetch rates from API');
                return Command::FAILURE;
            }

            $data = $response->json();
            $rates = $data['rates'] ?? [];

            $currencies = ['EUR', 'RUB', 'UZS', 'GBP', 'CNY', 'JPY', 'KRW'];
            $updated = 0;

            foreach ($currencies as $currency) {
                if (isset($rates[$currency])) {
                    CurrencyRate::updateOrCreate(
                        [
                            'from_currency' => 'USD',
                            'to_currency' => $currency,
                            'date' => today(),
                        ],
                        [
                            'rate' => $rates[$currency],
                        ]
                    );
                    $updated++;
                    $this->line("USD -> {$currency}: {$rates[$currency]}");
                }
            }

            // Also store reverse rates for convenience
            foreach ($currencies as $currency) {
                if (isset($rates[$currency]) && $rates[$currency] > 0) {
                    CurrencyRate::updateOrCreate(
                        [
                            'from_currency' => $currency,
                            'to_currency' => 'USD',
                            'date' => today(),
                        ],
                        [
                            'rate' => 1 / $rates[$currency],
                        ]
                    );
                }
            }

            $this->info("Updated {$updated} currency rates");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}

