<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\User\OrderController;
use App\Http\Requests\User\OrderSave;
use App\Models\Order;
use App\Services\CouponService;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrderConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Opt-in uses only the disposable internal-network database, never application credentials.
        $mysql = getenv('SECURITY_TEST_MYSQL') === '1';
        config()->set('database.default', 'security_test');
        config()->set('database.connections.security_test', $mysql ? [
            'driver' => 'mysql', 'host' => 'security-db', 'port' => 3306,
            'database' => 'security_test', 'username' => 'root', 'password' => 'test-only',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ] : ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('security_test');
        config()->set(['education.enabled' => false, 'risk.enabled' => false, 'risk.hmac_key' => '',
            'v2board.new_order_event_id' => 0, 'v2board.change_order_event_id' => 0]);
        Queue::fake();
        foreach (['v2_order', 'v2_user', 'v2_plan', 'v2_coupon'] as $table) Schema::dropIfExists($table);
        Schema::create('v2_user', function ($t) {
            $t->increments('id'); $t->string('email')->nullable();
            foreach (['plan_id', 'group_id', 'expired_at', 'device_limit', 'speed_limit', 'invite_user_id', 'discount', 'created_at', 'updated_at'] as $key) $t->bigInteger($key)->nullable();
            foreach (['balance', 'transfer_enable', 'u', 'd'] as $key) $t->bigInteger($key)->default(0);
        });
        Schema::create('v2_plan', function ($t) {
            $t->increments('id'); $t->string('system_key')->nullable();
            foreach (['capacity_limit', 'device_limit', 'speed_limit', 'created_at', 'updated_at'] as $key) $t->integer($key)->nullable();
            $t->integer('group_id')->default(1); $t->integer('transfer_enable')->default(1);
            $t->integer('billing_type')->default(0); $t->integer('show')->default(1); $t->integer('renew')->default(1);
            $t->integer('onetime_price')->default(1000);
        });
        Schema::create('v2_order', function ($t) {
            $t->increments('id'); $t->integer('user_id'); $t->integer('plan_id');
            $t->string('trade_no')->unique(); $t->string('period'); $t->integer('type')->default(1); $t->integer('status')->default(0);
            foreach (['total_amount', 'balance_amount', 'surplus_amount', 'refund_amount', 'discount_amount', 'education_discount_amount', 'invite_risk_flags'] as $key) $t->bigInteger($key)->default(0);
            foreach (['coupon_id', 'payment_id', 'paid_at', 'invite_user_id', 'created_at', 'updated_at'] as $key) $t->integer($key)->nullable();
            foreach (['callback_no', 'order_ip_hmac', 'order_ip_validation'] as $key) $t->string($key)->nullable();
            $t->text('surplus_order_ids')->nullable();
        });
        Schema::create('v2_coupon', function ($t) {
            $t->increments('id'); $t->string('code'); $t->integer('type'); $t->integer('value');
            $t->integer('show')->default(1); $t->integer('limit_use')->nullable(); $t->integer('limit_use_with_user')->nullable();
            $t->text('limit_plan_ids')->nullable(); $t->text('limit_period')->nullable();
            $t->integer('started_at'); $t->integer('ended_at'); $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        DB::table('v2_user')->insert(['id' => 1, 'email' => 'fixture@example.test', 'plan_id' => 1, 'group_id' => 1, 'transfer_enable' => 1073741824]);
        DB::table('v2_plan')->insert([['id' => 1], ['id' => 2]]);
        DB::table('v2_coupon')->insert(['id' => 1, 'code' => 'ONCE', 'type' => 2, 'value' => 100,
            'limit_use' => 10, 'limit_use_with_user' => 1, 'started_at' => time() - 60, 'ended_at' => time() + 3600]);
    }

    private function saveOrder(array $params = [])
    {
        return (new OrderController())->save(OrderSave::create('/order/save', 'POST', $params + [
            'user' => ['id' => 1], 'plan_id' => 1, 'period' => 'onetime_price',
        ]));
    }

    public function testPendingCouponUseCountsAndCancellationReleasesPerUserQuota(): void
    {
        $this->saveOrder(['coupon_code' => 'ONCE']);
        $coupon = new CouponService('ONCE'); $coupon->setUserId(1);
        $this->assertFalse($coupon->checkLimitUseWithUser());
        $this->assertSame(0, (int) Order::first()->total_amount);
        $this->assertTrue((new OrderService(Order::first()))->cancel());
        $this->assertTrue($coupon->checkLimitUseWithUser());
        $this->saveOrder(['coupon_code' => 'ONCE']);
        $this->assertSame(1, Order::where('status', 0)->count());
    }

    public function testFailedCreationRollsBackAndReleasesItsTransaction(): void
    {
        try {
            $this->saveOrder(['coupon_code' => 'missing']);
            $this->fail('Missing coupon accepted');
        } catch (HttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, Order::count());
        $this->saveOrder();
        $this->assertSame(1, Order::count());
    }

    public function testCompletedDepositCannotBeCreditedTwice(): void
    {
        config()->set('v2board.deposit_bounus', []);
        DB::table('v2_order')->insert(['id' => 30, 'user_id' => 1, 'plan_id' => 0,
            'trade_no' => 'deposit-once', 'period' => 'deposit', 'type' => 9, 'status' => 1, 'total_amount' => 1000]);
        $stale = Order::find(30);
        (new OrderService(Order::find(30)))->open();
        (new OrderService($stale))->open();
        $this->assertSame(1000, (int) DB::table('v2_user')->where('id', 1)->value('balance'));
        $this->assertSame(3, (int) Order::find(30)->status);
    }

    private function duplicateSurplusOrders(): void
    {
        DB::table('v2_user')->where('id', 1)->update(['plan_id' => 2]);
        DB::table('v2_order')->insert(['id' => 10, 'user_id' => 1, 'plan_id' => 1,
            'trade_no' => 'source', 'period' => 'onetime_price', 'status' => 3, 'total_amount' => 1000]);
        foreach ([11, 12] as $id) {
            DB::table('v2_order')->insert(['id' => $id, 'user_id' => 1, 'plan_id' => 2,
                'trade_no' => 'replacement-' . $id, 'period' => 'onetime_price', 'type' => 3,
                'status' => 0, 'total_amount' => 0, 'surplus_amount' => 1000,
                'refund_amount' => 900, 'surplus_order_ids' => json_encode([10])]);
            (new OrderController())->checkout(Request::create('/checkout', 'POST', [
                'user' => ['id' => 1], 'trade_no' => 'replacement-' . $id,
            ]));
        }
    }

    public function testPreexistingDuplicateZeroPriceOrdersCannotRefundTheSameSurplusTwice(): void
    {
        $this->duplicateSurplusOrders();
        (new OrderService(Order::find(11)))->open();
        (new OrderService(Order::find(11)))->open();
        try {
            (new OrderService(Order::find(12)))->open();
            $this->fail('Consumed surplus was credited again');
        } catch (HttpException $e) {
            $this->assertSame('SURPLUS_ALREADY_CONSUMED', $e->getMessage());
        }
        $this->assertSame(900, (int) DB::table('v2_user')->where('id', 1)->value('balance'));
        $this->assertSame(4, (int) Order::find(10)->status);
        $this->assertSame(3, (int) Order::find(11)->status);
        $this->assertSame(1, (int) Order::find(12)->status);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testMixedValidAndForeignSourcesRollBackAllClaims(): void
    {
        $this->duplicateSurplusOrders();
        DB::table('v2_order')->insert(['id' => 20, 'user_id' => 2, 'plan_id' => 1,
            'trade_no' => 'foreign-source', 'period' => 'onetime_price', 'status' => 3]);
        DB::table('v2_order')->where('id', 11)->update(['surplus_order_ids' => json_encode([10, 20])]);
        try {
            (new OrderService(Order::find(11)))->open();
            $this->fail('Foreign surplus was accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(3, (int) Order::find(10)->status);
        $this->assertSame(3, (int) Order::find(20)->status);
        $this->assertSame(0, (int) DB::table('v2_user')->where('id', 1)->value('balance'));
    }

    public function testConcurrentCreationWaitsForTheUserLockAndRechecksPendingOrders(): void
    {
        if (getenv('SECURITY_TEST_MYSQL') !== '1') $this->markTestSkipped('Requires isolated MariaDB row locks.');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($pair[0]); DB::purge('security_test');
            fwrite($pair[1], 'R'); fread($pair[1], 1); fwrite($pair[1], 'A');
            try {
                $this->saveOrder();
                exit(1);
            } catch (HttpException $e) {
                exit($e->getMessage() === __('You have an unpaid or pending order, please try again later or cancel it') ? 0 : 2);
            } catch (\Throwable $e) {
                fwrite(STDERR, $e->getMessage()); exit(3);
            }
        }
        fclose($pair[1]); DB::purge('security_test');
        $this->assertSame('R', fread($pair[0], 1));
        DB::beginTransaction();
        DB::table('v2_user')->where('id', 1)->lockForUpdate()->first();
        fwrite($pair[0], 'G'); $this->assertSame('A', fread($pair[0], 1));
        usleep(200000);
        try { $this->saveOrder(); } finally { DB::commit(); }
        pcntl_waitpid($pid, $status); fclose($pair[0]);
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame(1, Order::count());
    }
}
