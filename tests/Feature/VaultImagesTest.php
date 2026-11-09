<?php

use App\Filament\FavoriteVaultNavigation;
use App\Filament\Pages\MyVaults;
use App\Filament\Pages\VaultEntries;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultImage;
use App\Services\Favorites;
use App\Services\PersonalVaults;
use App\Services\VaultAdministration;
use App\Services\VaultImages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Encode a GD image of the given size, filled with one colour.
 */
function vault_image_bytes(int $width, int $height, string $format = 'jpeg'): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
    ob_start();
    $format === 'png' ? imagepng($image) : imagejpeg($image, null, 95);

    return (string) ob_get_clean();
}

/**
 * Store an uploaded image on the vault through the update path.
 */
function vault_image_upload(User $actor, Vault $vault): void
{
    app(VaultAdministration::class)->update($actor, $vault->id, [
        'name' => $vault->name,
        'description' => $vault->description,
        'icon' => $vault->icon,
        'image' => UploadedFile::fake()->createWithContent('logo.jpg', vault_image_bytes(800, 400)),
    ]);
}

test('uploads are re-encoded to a small square image', function () {
    $normalized = app(VaultImages::class)->normalize(vault_image_bytes(1600, 900));

    $info = getimagesizefromstring($normalized['bytes']);

    expect($normalized['mime'])->toBe('image/webp')
        ->and($info[0])->toBe(VaultImages::TARGET_SIZE)
        ->and($info[1])->toBe(VaultImages::TARGET_SIZE)
        ->and($info['mime'])->toBe('image/webp')
        ->and(strlen($normalized['bytes']))->toBeLessThan(2048);
});

test('re-encoding keeps transparency', function () {
    $source = imagecreatetruecolor(100, 100);
    imagealphablending($source, false);
    imagesavealpha($source, true);
    imagefill($source, 0, 0, (int) imagecolorallocatealpha($source, 0, 0, 0, 127));
    ob_start();
    imagepng($source);
    $png = (string) ob_get_clean();

    $output = imagecreatefromstring(app(VaultImages::class)->normalize($png)['bytes']);

    expect(imagecolorat($output, 0, 0) >> 24)->toBe(127);
});

test('unacceptable uploads are rejected', function (Closure $bytes) {
    expect(fn () => app(VaultImages::class)->normalize($bytes()))
        ->toThrow(ValidationException::class);
})->with([
    'svg' => fn () => '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><script>alert(1)</script></svg>',
    'not an image' => fn () => 'plain text',
    'truncated image' => fn () => "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 10, 10, 8, 6, 0, 0, 0).pack('N', 0),
    'too many pixels' => fn () => "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 5000, 5000, 8, 6, 0, 0, 0).pack('N', 0),
    'too many bytes' => fn () => vault_image_bytes(10, 10).str_repeat("\0", VaultImages::MAX_UPLOAD_KILOBYTES * 1024),
]);

test('managers upload an image that replaces the icon until it is removed', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $vault->forceFill(['icon' => 'server-stack'])->save();

    vault_image_upload($alice, $vault);

    $image = VaultImage::query()->findOrFail($vault->id);
    expect($image->mime)->toBe('image/webp')
        ->and($vault->fresh()->icon)->toBe('server-stack')
        ->and(app(VaultImages::class)->avatarUrl($vault->fresh()))
        ->toBe(route('vaults.image', ['vault' => $vault->id, 'v' => substr($image->checksum, 0, 12)]));
    $this->assertDatabaseHas('audit_events', ['actor_id' => $alice->id, 'action' => 'vault.updated']);

    app(VaultAdministration::class)->update($alice, $vault->id, ['name' => $vault->name, 'icon' => 'server-stack', 'remove_image' => true]);

    expect(VaultImage::query()->find($vault->id))->toBeNull()
        ->and(base64_decode(substr(app(VaultImages::class)->avatarUrl($vault->fresh()), strlen('data:image/svg+xml;base64,'))))
        ->toContain('stroke="#fff"');
});

test('a vault without image or icon gets a letter avatar', function () {
    ['vault' => $vault] = vaultigen_entry_fixture();

    $svg = base64_decode(substr(app(VaultImages::class)->avatarUrl($vault), strlen('data:image/svg+xml;base64,')));

    expect($svg)->toContain('>O</text>');
});

test('an image that is not an upload is rejected', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();

    expect(fn () => app(VaultAdministration::class)->update($alice, $vault->id, ['name' => $vault->name, 'image' => '/etc/passwd']))
        ->toThrow(ValidationException::class);
});

test('viewers cannot change the vault image', function () {
    ['bob' => $bob, 'vault' => $vault] = vaultigen_entry_fixture();

    expect(fn () => vault_image_upload($bob, $vault))->toThrow(AuthorizationException::class)
        ->and(VaultImage::query()->find($vault->id))->toBeNull();
});

test('a vault can be created with an image', function () {
    $user = User::factory()->vaultCreator()->withTwoFactor()->create();

    $id = app(VaultAdministration::class)->createOwn($user, [
        'name' => 'Clients',
        'image' => UploadedFile::fake()->createWithContent('logo.png', vault_image_bytes(120, 120, 'png')),
    ]);

    expect(VaultImage::query()->find($id))->not->toBeNull();
});

test('the edit action stores an uploaded image', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $this->actingAs($alice);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->callAction('edit', [
            'name' => $vault->name,
            'image' => UploadedFile::fake()->createWithContent('logo.jpg', vault_image_bytes(300, 300)),
        ])
        ->assertHasNoActionErrors();

    expect(VaultImage::query()->find($vault->id)?->mime)->toBe('image/webp');
});

test('the image is served only to users who can reach the vault', function () {
    ['admin' => $admin, 'alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    vault_image_upload($alice, $vault);
    $image = VaultImage::query()->findOrFail($vault->id);
    $url = route('vaults.image', ['vault' => $vault->id]);

    $this->actingAs($bob)->get($url)->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('Cache-Control', 'max-age=86400, private')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    $this->actingAs($bob)->get($url, ['If-None-Match' => '"'.$image->checksum.'"'])->assertStatus(304);
    $this->actingAs($admin)->get($url)->assertOk();
    $this->actingAs($carol)->get($url)->assertNotFound();
});

test('a vault without an image has no image to serve', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();

    $this->actingAs($alice)->get(route('vaults.image', ['vault' => $vault->id]))->assertNotFound();
});

test('new personal work vaults use the person icon', function () {
    $owner = User::factory()->withTwoFactor()->create();

    $id = app(PersonalVaults::class)->provisionFor($owner);

    expect(Vault::query()->findOrFail($id)->icon)->toBe('user');
});

test('the vault list and favorite navigation show the vault avatar', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    vault_image_upload($alice, $vault);
    app(Favorites::class)->toggleVault($alice, $vault->id);
    $avatar = app(VaultImages::class)->avatarUrl($vault);
    $this->actingAs($alice);

    expect(Livewire::test(MyVaults::class)->html())->toContain(e($avatar))
        ->and(FavoriteVaultNavigation::items($alice)[0]->getIcon()->toHtml())->toContain(e($avatar));
});
