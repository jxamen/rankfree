<?php

namespace Tests\Feature;

use App\Domain\Order\OrderInputException;
use App\Domain\Order\OrderPlacer;
use App\Domain\Order\PrepaidWallet;
use App\Models\MarketingProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Real parallel processes and independent connections. SQLite checks the race;
 * MySQL/InnoDB deployment additionally uses SELECT FOR UPDATE on the user row. */
class PrepaidConcurrencyTest extends TestCase
{
    public function test_two_parallel_orders_cannot_spend_one_balance_twice(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required');
        }
        $path = tempnam(sys_get_temp_dir(), 'rf-concurrency-');
        $previous = DB::getDefaultConnection();
        config(['database.connections.prepaid_race' => array_replace(config('database.connections.sqlite'), ['database' => $path, 'busy_timeout' => 10000])]);
        DB::setDefaultConnection('prepaid_race');
        try {
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            DB::statement('PRAGMA journal_mode=WAL');
            $user = User::create(['name' => 'Race', 'email' => 'race@test.invalid', 'password' => 'password']);
            $product = MarketingProduct::create(['product_type' => 'REWARD', 'title' => 'Race', 'quantity_mode' => 'total', 'base_cost' => 0, 'min_price' => 1000, 'min_quantity' => 1, 'max_quantity' => 10, 'is_active' => true]);
            app(PrepaidWallet::class)->change($user->id, 1100, 'fund', 'fund');
            DB::disconnect('prepaid_race');
            $children = [];
            $start = microtime(true) + 0.3;
            for ($i = 0; $i < 2; $i++) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    DB::purge('prepaid_race');
                    while (microtime(true) < $start) {
                        usleep(1000);
                    }
                    try {
                        app(OrderPlacer::class)->place(User::findOrFail($user->id), MarketingProduct::findOrFail($product->id), [], ['quantity' => 1, 'prepaid' => true]);
                        exit(0);
                    } catch (OrderInputException $e) {
                        exit($e->field === 'balance' ? 10 : 20);
                    } catch (\Throwable $e) {
                        exit(30);
                    }
                }
                $this->assertGreaterThan(0, $pid);
                $children[] = $pid;
            }
            $codes = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $codes[] = pcntl_wexitstatus($status);
            }
            sort($codes);
            $this->assertSame([0, 10], $codes);
            DB::purge('prepaid_race');
            $this->assertSame(0, (int) User::findOrFail($user->id)->prepaid_balance);
            $this->assertSame(1, DB::table('marketing_orders')->count());
            $this->assertSame(1, DB::table('wallet_entries')->where('amount', '<', 0)->count());
        } finally {
            DB::purge('prepaid_race');
            DB::setDefaultConnection($previous);
            @unlink($path);
            @unlink($path.'-wal');
            @unlink($path.'-shm');
        }
    }
}
