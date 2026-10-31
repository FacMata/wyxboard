<?php

namespace Tests\Feature;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Payments\Epusdt;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentCallbackBindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('v2board.telegram_bot_enable', 0);
        DB::purge('sqlite');
        Queue::fake();
        Schema::create('v2_payment', function ($t) {
            $t->increments('id'); $t->string('uuid'); $t->string('payment');
            $t->text('config'); $t->integer('enable'); $t->string('notify_domain')->nullable();
        });
        Schema::create('v2_order', function ($t) {
            $t->increments('id'); $t->string('trade_no'); $t->integer('payment_id')->nullable();
            $t->integer('status'); $t->integer('total_amount'); $t->string('callback_no')->nullable();
            $t->integer('paid_at')->nullable(); $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        DB::table('v2_payment')->insert([
            ['id' => 1, 'uuid' => 'epusdt-config', 'payment' => 'Epusdt', 'config' => json_encode(['epusdt_token' => 'test-secret']), 'enable' => 1],
            ['id' => 2, 'uuid' => 'other-config', 'payment' => 'Paytaro', 'config' => json_encode(['key' => 'another-secret']), 'enable' => 1],
        ]);
        DB::table('v2_order')->insert(['id' => 1, 'trade_no' => 'fixture-order', 'payment_id' => 1, 'status' => 0, 'total_amount' => 1000]);
    }

    private function signedCallback(string $key): array
    {
        return ['order_id' => 'fixture-order', 'status' => 2, 'trade_id' => 'fixture-paid',
            'signature' => md5('order_id=fixture-order&status=2&trade_id=fixture-paid' . $key)];
    }

    public function testMismatchedAdapterAndEmptyKeyCannotMarkAnOrderPaid(): void
    {
        $this->postJson('/api/v1/guest/payment/notify/Epusdt/other-config', $this->signedCallback(''))->assertStatus(500);
        $this->postJson('/api/v1/guest/payment/notify/Epusdt/missing-config', $this->signedCallback(''))->assertStatus(500);
        foreach (['', '  '] as $key) {
            $this->assertFalse((new Epusdt(['epusdt_token' => $key]))->notify($this->signedCallback('')));
        }
        $this->assertSame(0, (int) Order::find(1)->status);
        Queue::assertNothingPushed();
    }

    public function testVerifiedCallbackMustMatchTheOrdersPaymentConfiguration(): void
    {
        DB::table('v2_order')->where('id', 1)->update(['payment_id' => 2]);
        $this->postJson('/api/v1/guest/payment/notify/Epusdt/epusdt-config', $this->signedCallback('test-secret'))->assertStatus(500);
        $this->assertSame(0, (int) Order::find(1)->status);
        Queue::assertNothingPushed();
    }

    public function testValidGetAndPostCallbacksRemainIdempotent(): void
    {
        $uri = '/api/v1/guest/payment/notify/Epusdt/epusdt-config';
        $this->get($uri . '?' . http_build_query($this->signedCallback('test-secret')))->assertOk()->assertSee('ok');
        $this->postJson($uri, $this->signedCallback('test-secret'))->assertOk()->assertSee('ok');
        $this->assertSame(1, (int) Order::find(1)->status);
        Queue::assertPushed(OrderHandleJob::class, 1);
    }

    public function testPaymentBindingIsRecheckedAfterReloadingTheLockedOrder(): void
    {
        $stale = Order::find(1);
        DB::table('v2_order')->where('id', 1)->update(['payment_id' => 2]);
        try {
            (new OrderService($stale))->paid('callback', 1);
            $this->fail('Changed payment configuration was accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertSame(0, (int) Order::find(1)->status);
        Queue::assertNothingPushed();
    }
}
