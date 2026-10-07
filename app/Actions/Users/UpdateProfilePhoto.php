<?php

namespace App\Actions\Users;

use App\Exceptions\WorkflowRuleBroken;
use App\Models\User;
use App\Support\Audit;
use App\Support\FileVault;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A person adds, replaces or removes their own profile photo. The photo is cropped to
 * a centred square, resized to 256×256 (enough for the largest place it is shown) and
 * kept as a JPEG on the private disk. The original upload is not kept.
 */
final class UpdateProfilePhoto
{
    private const SIZE = 256;

    public function handle(User $user, string $sourcePath): User
    {
        $image = @imagecreatefromstring((string) file_get_contents($sourcePath));
        if ($image === false) {
            throw new WorkflowRuleBroken('This image could not be read. Use a JPG or PNG photo.');
        }
        $image = $this->upright($image, $sourcePath);

        // A centred square, so a face in the middle of a portrait or landscape photo stays in.
        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);
        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($square, 0, 0, (int) imagecolorallocate($square, 255, 255, 255));
        imagecopyresampled($square, $image, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);

        $tmp = (string) tempnam(sys_get_temp_dir(), 'avatar');
        imagejpeg($square, $tmp, 85);
        $stored = FileVault::store($tmp, "avatars/{$user->id}", 'jpg');
        @unlink($tmp);

        return $this->replace($user, $stored['disk'], $stored['path'], 'user.photo_changed');
    }

    public function remove(User $user): User
    {
        return $this->replace($user, null, null, 'user.photo_removed');
    }

    private function replace(User $user, ?string $disk, ?string $path, string $action): User
    {
        [$oldDisk, $oldPath] = [$user->avatar_disk, $user->avatar_path];

        DB::transaction(function () use ($user, $disk, $path, $action): void {
            $user->update(['avatar_disk' => $disk, 'avatar_path' => $path]);
            Audit::record($user, $action, $user);
        });

        if ($oldPath !== null && $oldPath !== $path) {
            Storage::disk((string) $oldDisk)->delete($oldPath);
        }

        return $user;
    }

    /** Phone photos are often stored sideways with a note to turn them; turn them. */
    private function upright(\GdImage $image, string $sourcePath): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = (int) (@exif_read_data($sourcePath)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;

        return $angle !== 0 ? (imagerotate($image, $angle, 0) ?: $image) : $image;
    }
}
