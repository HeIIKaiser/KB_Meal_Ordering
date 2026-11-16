<?php

use App\Domain\Attendance\AttendanceSync;
use App\Models\AttendanceDay;
use App\Models\AttendanceSync as SyncLog;
use App\Support\Settings;

beforeEach(function () {
    travelToLocal('2026-11-05 07:00:00');
    $this->token = 'test-token-'.str_repeat('x', 30);
    app(Settings::class)->set(Settings::ATTENDANCE_TOKEN_HASH, hash('sha256', $this->token));
    employee(['osobne_cislo' => '003']);
    $this->payload = attendancePayload('2026-10', ['3' => ['2026-10-01' => ['8:00', '8:00']], '77' => ['2026-10-01' => ['8:00', '8:00']]]);
});

function bridge(string $token): array
{
    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

it('rejects requests without the right token', function () {
    $this->postJson('/api/attendance-sync', $this->payload)->assertUnauthorized();
    $this->postJson('/api/attendance-sync', $this->payload, bridge('wrong'))->assertUnauthorized();
    $this->getJson('/api/attendance-sync/pending', bridge('wrong'))->assertUnauthorized();
    expect(AttendanceDay::query()->count())->toBe(0);
});

it('rejects everything while no token is configured', function () {
    app(Settings::class)->set(Settings::ATTENDANCE_TOKEN_HASH, null);
    $this->postJson('/api/attendance-sync', $this->payload, bridge(''))->assertUnauthorized();
});

it('stores a month and reports unknown personal numbers', function () {
    $this->postJson('/api/attendance-sync', $this->payload, bridge($this->token))
        ->assertOk()
        ->assertJson(['ok' => true, 'month' => '2026-10', 'employees' => 2, 'days' => 2, 'unmatched' => ['77'], 'closed' => false]);

    expect(AttendanceDay::query()->count())->toBe(2)->and(SyncLog::query()->sole()->ip)->toBe('127.0.0.1');
});

it('accepts a gzip body', function () {
    $this->call('POST', '/api/attendance-sync', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'HTTP_CONTENT_ENCODING' => 'gzip', 'CONTENT_TYPE' => 'application/json',
    ], gzencode(json_encode($this->payload)))->assertOk()->assertJson(['days' => 2]);
});

it('answers 422 for invalid data and 400 for broken JSON, and logs the failure', function () {
    $this->postJson('/api/attendance-sync', ['month' => 'x'], bridge($this->token))->assertStatus(422);
    $this->call('POST', '/api/attendance-sync', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json'], '{nope')
        ->assertStatus(400);

    expect(SyncLog::query()->where('ok', false)->count())->toBe(1);
});

it('enforces the IP allowlist when set', function () {
    app(Settings::class)->set(Settings::ATTENDANCE_IP_ALLOWLIST, '192.168.0.66, 10.0.0.0/8');
    $this->getJson('/api/attendance-sync/pending', bridge($this->token))->assertForbidden();

    app(Settings::class)->set(Settings::ATTENDANCE_IP_ALLOWLIST, '127.0.0.1');
    $this->getJson('/api/attendance-sync/pending', bridge($this->token))->assertOk()->assertJson(['pending' => false]);
});

it('requires HTTPS in production', function () {
    app()->instance('env', 'production');
    $this->getJson('/api/attendance-sync/pending', bridge($this->token))->assertForbidden()->assertJson(['error' => 'HTTPS required.']);
});

it('shows a pending request and records bridge status reports', function () {
    app(AttendanceSync::class)->request(['2026-10']);
    $this->getJson('/api/attendance-sync/pending', bridge($this->token))->assertJson(['pending' => true, 'months' => ['2026-10']]);

    $this->postJson('/api/attendance-sync/status', ['ok' => false, 'message' => 'Tabuľka vis_attdaypart chýba'], bridge($this->token))->assertOk();
    expect(SyncLog::query()->where('kind', 'status')->sole()->message)->toBe('Tabuľka vis_attdaypart chýba');
});

it('is rate limited', function () {
    foreach (range(1, 30) as $i) {
        $this->getJson('/api/attendance-sync/pending', bridge($this->token))->assertOk();
    }
    $this->getJson('/api/attendance-sync/pending', bridge($this->token))->assertStatus(429);
});
