<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SecurityIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::connection()->getPdo()->sqliteCreateCollation('email_identity_test', function ($a, $b) {
            return strcmp(str_replace('é', 'e', strtolower($a)), str_replace('é', 'e', strtolower($b)));
        });
        DB::statement('CREATE TABLE v2_user (
            id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT COLLATE email_identity_test,
            password TEXT, password_algo TEXT, password_salt TEXT, token TEXT, uuid TEXT,
            plan_id INTEGER, group_id INTEGER, transfer_enable INTEGER, device_limit INTEGER,
            expired_at INTEGER, invite_user_id INTEGER, remarks TEXT,
            is_admin INTEGER DEFAULT 0, is_staff INTEGER DEFAULT 0, banned INTEGER DEFAULT 0,
            created_at INTEGER, updated_at INTEGER
        )');
        config()->set(['app.key' => str_repeat('k', 32), 'v2board.app_url' => 'https://panel.example.test',
            'v2board.recaptcha_enable' => 0, 'v2board.email_whitelist_enable' => 0,
            'v2board.email_gmail_limit_enable' => 0]);
        Queue::fake();
    }

    private function seedAccount(): User
    {
        DB::table('v2_user')->insert(['id' => 1, 'email' => 'Admin@Example.test', 'password' => 'original-hash', 'is_admin' => 1]);
        return User::find(1);
    }

    public function testAccentEquivalentEmailAndOldGenericCodeCannotResetAnAccount(): void
    {
        $this->seedAccount(); $variant = 'admin@éxample.test';
        $this->assertSame(1, User::where('email', $variant)->first()->id);
        $this->postJson('/api/v1/passport/comm/sendEmailVerify', ['email' => $variant, 'isforget' => 1])->assertStatus(500);
        Queue::assertNothingPushed();
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $variant), '123456', 300);
        $this->postJson('/api/v1/passport/auth/forget', ['email' => $variant, 'email_code' => '123456', 'password' => 'changed-password'])->assertStatus(500);
        $this->assertSame('original-hash', User::find(1)->password);
    }

    public function testRecoveryUsesStoredEmailAndBindsAccountState(): void
    {
        $user = $this->seedAccount();
        $jwt = (new AuthService($user))->generateAuthData(Request::create('/login'))['auth_data'];
        $this->postJson('/api/v1/passport/comm/sendEmailVerify', ['email' => 'ADMIN@EXAMPLE.TEST', 'isforget' => 1])->assertOk();
        $job = Queue::pushed(SendEmailJob::class)->first();
        $params = (new \ReflectionProperty($job, 'params'))->getValue($job);
        $this->assertSame('Admin@Example.test', $params['email']);
        $payload = ['email' => 'admin@example.test', 'email_code' => $params['template_value']['code'], 'password' => 'changed-password'];
        $this->postJson('/api/v1/passport/auth/forget', $payload)->assertOk();
        $this->assertTrue(password_verify('changed-password', User::find(1)->password));
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        $this->postJson('/api/v1/passport/auth/forget', $payload)->assertStatus(500);
    }

    public function testQuickLoginRejectsOldTicketsAndPasswordChanges(): void
    {
        $user = $this->seedAccount();
        Cache::put(CacheKey::get('TEMP_TOKEN', 'legacy-ticket'), 1, 60);
        $this->getJson('/api/v1/passport/auth/token2Login?verify=legacy-ticket')->assertStatus(500);
        $jwt = (new AuthService($user))->generateAuthData(Request::create('/login'))['auth_data'];
        $r = Request::create('/quick'); $r->headers->set('Authorization', $jwt);
        $response = (new \App\Http\Controllers\V1\Passport\AuthController())->getQuickLoginUrl($r);
        $url = json_decode($response->getContent(), true)['data'];
        parse_str(explode('?', parse_url($url, PHP_URL_FRAGMENT), 2)[1], $query);
        DB::table('v2_user')->where('id', 1)->update(['password' => 'new-hash']);
        $this->getJson('/api/v1/passport/auth/token2Login?verify=' . $query['verify'])->assertStatus(500);
    }

    public function testSessionProjectionAndAdminRoleRevocation(): void
    {
        $user = $this->seedAccount(); $auth = new AuthService($user);
        $current = $auth->generateAuthData(Request::create('/login'))['auth_data'];
        $other = $auth->generateAuthData(Request::create('/login'))['auth_data'];
        $this->assertNotFalse(AuthService::decryptAuthData($other));
        $r = Request::create('/sessions', 'GET', ['user' => ['id' => 1]]); $r->headers->set('Authorization', $current);
        $response = (new \App\Http\Controllers\V1\User\UserController())->getActiveSession($r);
        $tokens = array_column(json_decode($response->getContent(), true)['data'], 'auth_data');
        $this->assertContains($current, $tokens); $this->assertContains('', $tokens); $this->assertNotContains($other, $tokens);
        $data = ['id' => 1, 'email' => $user->email, 'banned' => 0, 'is_admin' => 0, 'is_staff' => 0];
        $r = \App\Http\Requests\Admin\UserUpdate::create('/update', 'POST', $data);
        $r->setValidator(app('validator')->make($data, $r->rules()));
        (new \App\Http\Controllers\V1\Admin\UserController())->update($r);
        $this->assertFalse(AuthService::decryptAuthData($current)); $this->assertFalse(AuthService::decryptAuthData($other));
    }

    public function testBothQuickLoginIssuersPreserveValidSingleUseExchange(): void
    {
        $user = $this->seedAccount();
        $jwt = (new AuthService($user))->generateAuthData(Request::create('/login'))['auth_data'];
        foreach ([new \App\Http\Controllers\V1\Passport\AuthController(), new \App\Http\Controllers\V1\User\UserController()] as $controller) {
            $r = Request::create('/quick', 'GET', ['user' => ['id' => 1]]);
            $r->headers->set('Authorization', $jwt);
            $url = json_decode($controller->getQuickLoginUrl($r)->getContent(), true)['data'];
            parse_str(explode('?', parse_url($url, PHP_URL_FRAGMENT), 2)[1], $query);
            $uri = '/api/v1/passport/auth/token2Login?verify=' . $query['verify'];
            $this->getJson($uri)->assertOk()->assertJsonStructure(['data' => ['auth_data']]);
            $this->getJson($uri)->assertStatus(500);
        }
    }

    public function testRandomInitialPasswordsAreDeliveredForSingleAndBulkAccounts(): void
    {
        $passwords = [];
        foreach ([['email_prefix' => 'single'], ['generate_count' => 2], ['email_prefix' => 'explicit', 'password' => 'explicit-password']] as $data) {
            $r = \App\Http\Requests\Admin\UserGenerate::create('/generate', 'POST', $data + ['email_suffix' => 'example.test']);
            $response = (new \App\Http\Controllers\V1\Admin\UserController())->generate($r);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            if (isset($data['email_prefix'])) {
                $credentials = json_decode($response->getContent(), true)['credentials'];
                $rows = [[$credentials['email'], $credentials['password']]];
            } else {
                $lines = explode("\r\n", trim($response->getContent())); array_shift($lines);
                $rows = array_map('str_getcsv', $lines); $this->assertCount(2, $rows);
            }
            foreach ($rows as $row) {
                $this->assertTrue(password_verify($row[1], User::where('email', $row[0])->first()->password));
                if (isset($data['password'])) $this->assertSame($data['password'], $row[1]);
                else { $this->assertSame(20, strlen($row[1])); $passwords[] = $row[1]; }
            }
        }
        $this->assertCount(3, array_unique($passwords));
    }
}
