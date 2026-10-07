<?php

namespace Database\Seeders;

use App\Support\DemoMode;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /** 既定の Seeder はデモデータのみ。本番では実行できない。 */
    public function run(): void
    {
        if (! DemoMode::seedingAllowed()) {
            throw new RuntimeException('デモデータは local / testing 環境でのみ投入できます。');
        }

        $this->call(DemoSeeder::class);
    }
}
