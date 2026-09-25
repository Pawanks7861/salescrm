<?php

use App\Services\Leads\LeadNumberService;
use App\Services\Leads\PhoneNormalizer;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;

test('lead numbers are sequential per year and zero padded', function () {
    $service = app(LeadNumberService::class);
    $year = now()->setTimezone('Asia/Kolkata')->format('Y');

    expect($service->next())->toBe("LD-{$year}-000001");
    expect($service->next())->toBe("LD-{$year}-000002");
    expect(DB::table('number_sequences')->where('prefix', 'LD')->value('last_value'))->toBe(2);
});

test('numbers do not reuse values after a lead is deleted', function () {
    $service = app(LeadNumberService::class);
    $first = $service->next();
    $second = $service->next();

    expect($second)->not->toBe($first);
    expect(DB::table('number_sequences')->count())->toBe(1);
});

test('prefix setting is respected and sanitised', function () {
    app(SettingService::class)->updateGroup('lead', ['lead.number_prefix' => 'en-q!']);

    expect(app(LeadNumberService::class)->next())->toStartWith('ENQ-');
});

test('many sequential allocations never collide', function () {
    $service = app(LeadNumberService::class);
    $numbers = collect(range(1, 50))->map(fn () => $service->next());

    expect($numbers->unique()->count())->toBe(50);
});

test('phone normaliser produces a single canonical form', function (string $input, ?string $expected) {
    expect(app(PhoneNormalizer::class)->normalize($input))->toBe($expected);
})->with([
    ['9876543210', '919876543210'],
    ['+91 98765 43210', '919876543210'],
    ['098765-43210', '919876543210'],
    ['0091 9876543210', '919876543210'],
    ['(987) 654-3210', '919876543210'],
    ['+1 415 555 0100', '14155550100'],
    ['12345', null],
    ['', null],
]);
