<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instruments', function (Blueprint $table): void {
            $table->string('industry', 120)->nullable();
            $table->char('country', 2)->nullable();
        });

        // Backfill only the new attributes. Running the full S&P 500 seeder on
        // every deploy would also overwrite Admin changes to membership,
        // company, sector, exchange and active state.
        $path = base_path('database/data/sp500.csv');
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("S&P 500 classification dataset not found at {$path}.");
        }
        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if ($header !== ['ticker', 'company', 'sector', 'industry', 'country', 'exchange']) {
                throw new RuntimeException('Unexpected S&P 500 classification dataset header.');
            }
            while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }
                if (count($cells) !== count($header)) {
                    throw new RuntimeException('Malformed S&P 500 classification dataset row.');
                }
                DB::table('instruments')->where('ticker', $cells[0])->update([
                    'industry' => $cells[3] === '' ? null : $cells[3],
                    'country' => $cells[4] === '' ? null : $cells[4],
                ]);
            }
        } finally {
            fclose($handle);
        }
    }

    public function down(): void
    {
        Schema::table('instruments', function (Blueprint $table): void {
            $table->dropColumn(['industry', 'country']);
        });
    }
};
