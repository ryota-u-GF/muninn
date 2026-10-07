<?php

namespace Tests\Feature;

use App\Exceptions\RevisionConflictException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_errors_are_logged_without_bound_values(): void
    {
        Log::spy();
        $e = new QueryException('mysql', 'insert into tasks (title, description) values (?, ?)', ['社外秘タイトル', '秘密の本文'], new \PDOException('boom'));

        report($e);

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) {
            $dump = $message.json_encode($context, JSON_UNESCAPED_UNICODE);

            return ! str_contains($dump, '社外秘タイトル') && ! str_contains($dump, '秘密の本文')
                && $context['sql'] === 'insert into tasks (title, description) values (?, ?)';
        })->once();
    }

    public function test_conflicts_are_not_reported(): void
    {
        Log::spy();
        report(new RevisionConflictException(2));
        Log::shouldNotHaveReceived('error');
    }
}
