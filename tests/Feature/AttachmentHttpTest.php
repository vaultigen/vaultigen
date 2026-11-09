<?php

use App\Filament\Pages\EntryDetail;
use App\Models\FileAttachment;
use App\Services\AuditWriter;
use App\Services\VaultAdministration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    vaultigen_fake_attachment_disk();
});

test('the upload endpoint stores an encrypted attachment and redirects to the entry', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $this->actingAs($alice);

    $response = $this->post(
        route('attachments.store', ['vault' => $vault->id, 'entry' => $entry->id]),
        ['attachment' => UploadedFile::fake()->createWithContent('contract.txt', 'synthetic contract body')],
    );

    $attachment = FileAttachment::query()->sole();
    $response->assertRedirect(EntryDetail::getUrl(['entry' => $entry->id]));

    expect(vaultigen_decrypt_attachment($attachment))->toBe('synthetic contract body')
        ->and(Storage::disk('attachments')->exists($attachment->random_storage_path))->toBeTrue();
    $this->assertDatabaseHas('audit_events', ['action' => 'attachment.uploaded']);
});

test('the upload endpoint reports missing and invalid files without storing anything', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $this->actingAs($alice);

    $this->post(route('attachments.store', ['vault' => $vault->id, 'entry' => $entry->id]))
        ->assertRedirect(EntryDetail::getUrl(['entry' => $entry->id]))
        ->assertSessionHasErrors('attachment');

    config(['vaultigen.attachments.max_bytes_per_file' => 4]);
    $this->post(
        route('attachments.store', ['vault' => $vault->id, 'entry' => $entry->id]),
        ['attachment' => UploadedFile::fake()->createWithContent('big.txt', 'too many bytes')],
    )->assertSessionHasErrors('attachment');

    expect(FileAttachment::query()->count())->toBe(0);
});

test('the download endpoint streams the decrypted bytes as an attachment with no-store headers', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachment = vaultigen_upload_attachment($alice, $entry, 'download me exactly', 'report.txt');

    $this->actingAs($bob);
    $response = $this->get(route('attachments.download', ['attachment' => $attachment->id]));
    $response->assertOk();

    expect($response->streamedContent())->toBe('download me exactly')
        ->and((string) $response->headers->get('content-disposition'))->toContain('attachment')
        ->and((string) $response->headers->get('content-disposition'))->toContain('report.txt')
        ->and((string) $response->headers->get('cache-control'))->toContain('no-store')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');

    $this->assertDatabaseHas('audit_events', ['action' => 'attachment.download_started', 'actor_id' => $bob->id]);
    $this->assertDatabaseHas('audit_events', ['action' => 'attachment.download_completed']);
    $this->assertDatabaseHas('secret_reveals', ['user_id' => $bob->id, 'entry_id' => $entry->id]);

    $this->actingAs($carol);
    $this->get(route('attachments.download', ['attachment' => $attachment->id]))->assertNotFound();
});

test('the download endpoint fails closed when the delivery audit cannot be written', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachment = vaultigen_upload_attachment($alice, $entry, 'secret bytes', 'x.txt');

    $this->mock(AuditWriter::class, function ($mock): void {
        $mock->shouldReceive('record')->andThrow(new RuntimeException('audit unavailable'));
        $mock->shouldReceive('transaction')->andReturnUsing(fn (Closure $callback) => $callback());
    });

    $this->actingAs($alice);
    $this->withoutExceptionHandling();

    expect(fn () => $this->get(route('attachments.download', ['attachment' => $attachment->id])))
        ->toThrow(RuntimeException::class);
});

test('a corrupted object fails the download and records a completed-but-failed event', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachment = vaultigen_upload_attachment($alice, $entry, 'body', 'x.txt');
    file_put_contents(Storage::disk('attachments')->path($attachment->random_storage_path), 'garbage');

    $this->actingAs($alice);
    $response = $this->get(route('attachments.download', ['attachment' => $attachment->id]));
    $response->assertOk();

    // Streaming throws part-way through; close the buffer the test helper left open.
    $level = ob_get_level();
    $threw = false;
    try {
        $response->streamedContent();
    } catch (RuntimeException) {
        $threw = true;
    } finally {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }

    expect($threw)->toBeTrue();

    $this->assertDatabaseHas('audit_events', ['action' => 'attachment.download_started', 'actor_id' => $alice->id]);
    $this->assertDatabaseHas('audit_events', ['action' => 'attachment.download_completed', 'outcome' => 'failed']);
});

test('the remove endpoint deletes an attachment for an editor and rejects a viewer', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachment = vaultigen_upload_attachment($alice, $entry, 'body', 'x.txt');

    $this->actingAs($bob);
    $this->delete(route('attachments.destroy', ['vault' => $vault->id, 'entry' => $entry->id, 'attachment' => $attachment->id]))
        ->assertForbidden();

    $this->actingAs($alice);
    $this->delete(route('attachments.destroy', ['vault' => $vault->id, 'entry' => $entry->id, 'attachment' => $attachment->id]))
        ->assertRedirect(EntryDetail::getUrl(['entry' => $entry->id]));

    expect(DB::table('file_attachments')->where('id', $attachment->id)->value('state'))->toBe('deleted');
    $this->assertDatabaseHas('audit_events', ['action' => 'attachment.deleted']);
});

test('the remove endpoint surfaces frozen-vault validation failures', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachment = vaultigen_upload_attachment($alice, $entry, 'body', 'x.txt');
    app(VaultAdministration::class)->setState($admin, $vault->id, 'read_only');

    $this->actingAs($alice);
    $this->delete(route('attachments.destroy', ['vault' => $vault->id, 'entry' => $entry->id, 'attachment' => $attachment->id]))
        ->assertRedirect(EntryDetail::getUrl(['entry' => $entry->id]))
        ->assertSessionHasErrors('attachment');
});
