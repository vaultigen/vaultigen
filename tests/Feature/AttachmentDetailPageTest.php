<?php

use App\Filament\Pages\EntryDetail;
use Livewire\Livewire;

beforeEach(function (): void {
    vaultigen_fake_attachment_disk();
});

test('the entry detail page lists decrypted attachment metadata for an editor', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachment = vaultigen_upload_attachment($alice, $entry, str_repeat('a', 2048), 'report.txt');

    $this->actingAs($alice);

    Livewire::test(EntryDetail::class, ['entryId' => $entry->id])
        ->assertSee('report.txt')
        ->assertSee('KB')
        ->assertSee('Add a file')
        ->assertSee(route('attachments.download', ['attachment' => $attachment->id]), escape: false);
});

test('a viewer can download but does not see the upload control', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    vaultigen_upload_attachment($alice, $entry, 'viewer bytes', 'shared.txt');

    $this->actingAs($bob);

    Livewire::test(EntryDetail::class, ['entryId' => $entry->id])
        ->assertSee('shared.txt')
        ->assertDontSee('Add a file')
        ->assertDontSee('Upload and encrypt');
});
