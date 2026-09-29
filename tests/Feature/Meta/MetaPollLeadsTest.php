<?php

use App\Jobs\SyncMetaFormLeads;
use Illuminate\Support\Facades\Queue;

test('meta:poll-leads queues a one-day pull for each enabled form on a selected page', function () {
    Queue::fake();
    $meta = metaSetup();

    $this->artisan('meta:poll-leads')->assertSuccessful();

    Queue::assertPushed(SyncMetaFormLeads::class, 1);
    Queue::assertPushed(SyncMetaFormLeads::class, fn (SyncMetaFormLeads $job) => $job->formId === $meta->form->id && $job->days === 1);
});

test('meta:poll-leads skips forms that are disabled or on a page that is not receiving leads', function () {
    Queue::fake();
    metaSetup(['is_enabled' => false]);
    metaSetup(['form_id' => '333'], ['page_id' => '444', 'is_selected' => false]);

    $this->artisan('meta:poll-leads')->assertSuccessful();

    Queue::assertNothingPushed();
});
