<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderCancellationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        app('db')->purge('sqlite');

        Schema::create('v2_user', function ($table) {
            $table->increments('id');
            $table->integer('balance')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_order', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->unsignedTinyInteger('status')->default(0);
            $table->integer('balance_amount')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        DB::table('v2_user')->insert([
            'id' => 42,
            'balance' => 0,
        ]);
        DB::table('v2_order')->insert([
            'id' => 7,
            'user_id' => 42,
            'status' => 0,
            'balance_amount' => 1,
        ]);
    }

    public function testStaleCancellationCannotRefundBalanceTwice(): void
    {
        $firstRequestOrder = Order::findOrFail(7);
        $concurrentRequestOrder = Order::findOrFail(7);

        $this->assertTrue((new OrderService($firstRequestOrder))->cancel());
        $this->assertFalse((new OrderService($concurrentRequestOrder))->cancel());

        $this->assertSame(1, DB::table('v2_user')->find(42)->balance);
        $this->assertSame(2, DB::table('v2_order')->find(7)->status);
    }
}
