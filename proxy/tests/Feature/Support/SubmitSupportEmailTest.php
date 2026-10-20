<?php

use App\Actions\Support\SubmitSupportEmail;
use App\Jobs\SendSupportEmail;
use App\Models\User;
use App\Services\Support\SupportContactManager;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['queue.default' => 'database']);
});

function supportSubmissionData(array $overrides = []): array
{
    return array_replace([
        'subject' => 'Private support subject',
        'description' => 'Private diagnostic description',
        'email' => 'reply@example.org',
        'attachments' => [],
    ], $overrides);
}

test('support acceptance queues one encrypted job with private files and a seven day deadline', function (string $acceptedAt, string $expectedExpiry) {
    $this->travelTo(CarbonImmutable::parse($acceptedAt));
    Storage::fake('local');
    $account = User::factory()->create(['email' => 'account@example.org']);
    app(SupportContactManager::class)->assign(User::factory()->admin()->create()->id);

    app(SubmitSupportEmail::class)->handle(supportSubmissionData([
        'attachments' => [UploadedFile::fake()->createWithContent('trace.log', "trace\n")],
    ]), $account);

    $this->assertDatabaseCount('jobs', 1);
    $row = DB::table('jobs')->first();
    expect($row->queue)->toBe('support-mail');
    expect($row->payload)->not->toContain('Private support subject', 'Private diagnostic description', 'reply@example.org', 'account@example.org');
    $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
    $job = unserialize(Crypt::decrypt($payload['data']['command']), ['allowed_classes' => true]);
    expect($job)->toBeInstanceOf(SendSupportEmail::class);
    expect($job->data->acceptedAt)->toBe(CarbonImmutable::parse($acceptedAt)->timestamp);
    expect($job->data->expiresAt)->toBe(CarbonImmutable::parse($expectedExpiry)->timestamp);
    expect($job->data->account)->toBe(['id' => $account->id, 'name' => $account->name, 'email' => 'account@example.org']);
    expect($payload['support_mail'])->toBe(['id' => $job->data->id, 'expires_at' => $job->data->expiresAt]);
    expect($job->data->attachments[0]['path'])->toStartWith('support-mail/'.$job->data->id.'/')->not->toContain('trace.log');
    Storage::disk('local')->assertExists($job->data->attachments[0]['path']);
    $manifest = Storage::disk('local')->get('support-mail/'.$job->data->id.'/manifest.json');
    expect($manifest)->not->toContain('reply@example.org', 'Private support subject', 'Private diagnostic description');
})->with([
    'ordinary week' => ['2026-02-10T10:00:00+01:00', '2026-02-17T10:00:00+01:00'],
    'daylight saving starts' => ['2026-03-25T10:00:00+01:00', '2026-04-01T11:00:00+02:00'],
    'daylight saving ends' => ['2026-10-20T10:00:00+02:00', '2026-10-27T09:00:00+01:00'],
]);

test('a request without attachments still has expiring operational metadata', function () {
    Storage::fake('local');
    Queue::fake([SendSupportEmail::class]);
    app(SupportContactManager::class)->assign(User::factory()->admin()->create()->id);
    app(SubmitSupportEmail::class)->handle(supportSubmissionData(), null);
    Queue::assertPushedOn('support-mail', SendSupportEmail::class, function (SendSupportEmail $job): bool {
        Storage::disk('local')->assertExists('support-mail/'.$job->data->id.'/manifest.json');

        return $job->data->account === null && $job->data->attachments === [];
    });
});

test('unavailable support rejects acceptance before any storage or queue effects', function () {
    Storage::fake('local');
    Queue::fake();
    expect(fn () => app(SubmitSupportEmail::class)->handle(supportSubmissionData(), null))
        ->toThrow(ValidationException::class);
    expect(Storage::disk('local')->allFiles('support-mail'))->toBe([]);
    Queue::assertNothingPushed();
});

test('a failed attachment write removes already created metadata', function () {
    $disk = Storage::fake('local');
    $failingDisk = Mockery::mock($disk);
    $failingDisk->shouldReceive('putFileAs')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('local')->andReturn($failingDisk);
    Queue::fake();
    app(SupportContactManager::class)->assign(User::factory()->admin()->create()->id);
    expect(fn () => app(SubmitSupportEmail::class)->handle(supportSubmissionData([
        'attachments' => [UploadedFile::fake()->createWithContent('trace.log', 'trace')],
    ]), null))->toThrow(ValidationException::class);
    expect($disk->allFiles('support-mail'))->toBe([]);
    Queue::assertNothingPushed();
});

test('a definite encryption failure removes the temporary files', function () {
    Storage::fake('local');
    app(SupportContactManager::class)->assign(User::factory()->admin()->create()->id);
    Queue::shouldReceive('pushOn')->once()->andThrow(new EncryptException('Synthetic encryption failure'));
    expect(fn () => app(SubmitSupportEmail::class)->handle(supportSubmissionData(), null))
        ->toThrow(ValidationException::class);
    expect(Storage::disk('local')->allFiles('support-mail'))->toBe([]);
    $this->assertDatabaseCount('jobs', 0);
});

test('an ambiguous enqueue failure reports an error but keeps files for an accepted job', function () {
    Storage::fake('local');
    app(SupportContactManager::class)->assign(User::factory()->admin()->create()->id);
    $connection = Queue::connection('database');
    Queue::shouldReceive('pushOn')->once()->andReturnUsing(function ($queue, $job) use ($connection): void {
        $connection->pushOn($queue, $job);
        throw new RuntimeException('Synthetic lost queue acknowledgement');
    });
    expect(fn () => app(SubmitSupportEmail::class)->handle(supportSubmissionData(), null))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('jobs', 1);
    expect(Storage::disk('local')->allFiles('support-mail'))->toHaveCount(1);
});

test('acceptance rechecks the authenticated account against the current contact before side effects', function () {
    Queue::fake();
    Storage::fake('local');
    $account = User::factory()->admin()->create();
    $action = app(SubmitSupportEmail::class);
    app(SupportContactManager::class)->assign($account->id);
    expect(fn () => $action->handle(supportSubmissionData([
        'email' => 'different@example.org',
        'attachments' => [UploadedFile::fake()->createWithContent('trace.log', 'trace')],
    ]), $account))->toThrow(HttpException::class);
    Queue::assertNothingPushed();
    expect(Storage::disk('local')->allFiles('support-mail'))->toBe([]);
});
