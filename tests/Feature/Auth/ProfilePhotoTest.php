<?php

use App\Models\Movement;
use App\Models\User;
use Database\Seeders\MovementsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Each person may add a profile photo. It is cropped square, kept privately, and shown
 * instead of their initials wherever their name appears.
 */

beforeEach(function () {
    Storage::fake((string) config('oha.disk'));
    $this->seed(MovementsSeeder::class);
    $this->user = User::factory()->create(['name' => 'Tendai Moyo']);
    $this->disk = fn () => Storage::disk((string) config('oha.disk'));
});

it('saves a photo cropped to a 256-pixel square, and shows it in place of the initials', function () {
    $this->actingAs($this->user)->post(route('profile.photo'), ['photo' => UploadedFile::fake()->image('me.png', 600, 400)])
        ->assertSessionHas('status', 'Your profile photo is saved. It now shows wherever your name appears.');

    $this->user->refresh();
    [$width, $height, $type] = getimagesizefromstring(($this->disk)()->get($this->user->avatar_path));
    expect([$width, $height, $type])->toBe([256, 256, IMAGETYPE_JPEG]);

    // The top bar, for them.
    $this->actingAs($this->user)->get(route('dashboard'))->assertSee($this->user->avatarUrl(), escape: false)->assertDontSee('>TM<', escape: false);

    // Anyone signed in can load it; nobody else.
    $this->actingAs(User::factory()->create())->get($this->user->avatarUrl())->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    auth()->logout();
    $this->get($this->user->avatarUrl())->assertRedirect(route('login'));
});

it('shows it beside their name on the movements they assess and in Users & Roles', function () {
    $zambia = Movement::query()->where('slug', 'zambia')->firstOrFail();
    $this->user->assignedMovements()->attach($zambia);
    $this->actingAs($this->user)->post(route('profile.photo'), ['photo' => UploadedFile::fake()->image('me.jpg', 300, 300)]);
    $url = $this->user->refresh()->avatarUrl();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('movements.index'))->assertSee($url, escape: false);
    $this->actingAs($admin)->get(route('movements.show', $zambia))->assertSee($url, escape: false);
    $this->actingAs($admin)->get(route('admin.users.index'))->assertSee($url, escape: false);
});

/** A real, single-colour PNG, so two photos differ. */
function colouredPhoto(string $name, int $red, int $green, int $blue): UploadedFile
{
    $image = imagecreatetruecolor(300, 300);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, $red, $green, $blue));
    $path = tempnam(sys_get_temp_dir(), 'photo').'.png';
    imagepng($image, $path);

    return new UploadedFile($path, $name, 'image/png', null, true);
}

it('replaces and removes the photo, deleting the old file', function () {
    $this->actingAs($this->user)->post(route('profile.photo'), ['photo' => colouredPhoto('one.png', 200, 30, 40)]);
    $first = $this->user->refresh()->avatar_path;

    $this->actingAs($this->user)->post(route('profile.photo'), ['photo' => colouredPhoto('two.png', 20, 90, 200)]);
    $second = $this->user->refresh()->avatar_path;

    expect($second)->not->toBe($first)
        ->and(($this->disk)()->exists($first))->toBeFalse()
        ->and(($this->disk)()->exists($second))->toBeTrue();

    $this->actingAs($this->user)->delete(route('profile.photo.remove'))->assertSessionHas('status', 'Your profile photo is removed. Your initials show instead.');

    expect($this->user->refresh()->avatar_path)->toBeNull()
        ->and(($this->disk)()->exists($second))->toBeFalse()
        ->and($this->user->avatarUrl())->toBeNull();
});

it('refuses files that are not a usable photo', function () {
    $this->actingAs($this->user)->post(route('profile.photo'), ['photo' => UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf')])
        ->assertSessionHasErrors(['photo' => 'The photo must be a JPG, PNG or WebP image.']);
    $this->actingAs($this->user)->post(route('profile.photo'), ['photo' => UploadedFile::fake()->image('tiny.png', 20, 20)])
        ->assertSessionHasErrors(['photo' => 'The photo is too small: use one at least 64 pixels wide and high.']);

    expect($this->user->refresh()->avatar_path)->toBeNull();
});

it('shows initials for people without a photo', function () {
    expect(User::factory()->make(['name' => 'Naledi  van Wyk'])->initials())->toBe('NV');
});
