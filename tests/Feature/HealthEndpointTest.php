<?php

namespace Tests\Feature;

use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * /up is the container health check on the VPS (docker/healthcheck-fpm.sh over
 * FastCGI, and the web container's wget). It must go red when the database is
 * unreachable: on tx-chatbot a mysqld restart left the containers with a stale
 * socket mount, and every request 500'd for 41 hours behind four containers
 * that all reported healthy, because the check never touched the database.
 */
class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_is_ok_while_the_database_answers(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_health_diagnostic_fails_when_the_database_is_unreachable(): void
    {
        DB::shouldReceive('connection->getPdo')
            ->andThrow(new QueryException('mysql', 'select 1', [], new Exception('No such file or directory')));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('database');

        Event::dispatch(new DiagnosingHealth);
    }

    public function test_health_endpoint_returns_an_error_when_the_database_is_unreachable(): void
    {
        DB::shouldReceive('connection->getPdo')
            ->andThrow(new QueryException('mysql', 'select 1', [], new Exception('No such file or directory')));

        // Production runs with debug off; the failure detail names internal
        // paths and must only reach the logs, never an anonymous response.
        config(['app.debug' => false]);

        $response = $this->get('/up');

        $response->assertServerError();
        $response->assertDontSee('No such file or directory');
    }

    public function test_health_diagnostic_fails_when_the_cache_is_unreachable(): void
    {
        Cache::shouldReceive('put')->andThrow(new Exception('cache store unreachable'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('cache');

        Event::dispatch(new DiagnosingHealth);
    }
}
