<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\AuthService;
use App\Utils\CacheKey;
use Firebase\JWT\JWT;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class SessionRevocationTest extends TestCase
{
    public function test_revocation_rejects_hot_and_late_repopulated_jwt_cache(): void
    {
        $previous = Facade::getFacadeApplication();
        $oldContainer = Container::getInstance();
        $app = new Container();
        $app->instance('config', new ConfigRepository(['app' => ['key' => str_repeat('k', 32)]]));
        Container::setInstance($app);
        Facade::setFacadeApplication($app);
        $cache = new Repository(new ArrayStore());
        Cache::swap($cache);

        try {
            $user = new User();
            $user->id = 7;
            $jwt = JWT::encode(['id' => 7, 'session' => 'other'], config('app.key'), 'HS256');
            $current = JWT::encode(['id' => 7, 'session' => 'current'], config('app.key'), 'HS256');
            $identity = ['id' => 7, 'email' => 'fixture@example.test', 'is_admin' => 0, 'is_staff' => 0];
            $cache->put(CacheKey::get('USER_SESSIONS', 7), ['other' => ['auth_data' => $jwt], 'current' => ['auth_data' => $current]]);
            $cache->put($jwt, $identity, 3600);
            $cache->put($current, $identity, 3600);
            $this->assertSame($identity, AuthService::decryptAuthData($jwt));
            $this->assertTrue((new AuthService($user))->removeSession('other'));
            $this->assertFalse($cache->has($jwt));
            $this->assertFalse(AuthService::decryptAuthData($jwt));
            $cache->put($jwt, $identity, 3600);
            $this->assertFalse(AuthService::decryptAuthData($jwt));
            $this->assertSame($identity, AuthService::decryptAuthData($current));
        } finally {
            Cache::clearResolvedInstance('cache');
            Facade::setFacadeApplication($previous);
            Container::setInstance($oldContainer);
        }
    }
}
