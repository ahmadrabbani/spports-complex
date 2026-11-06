<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MemberPhotoService
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const MIN_DIMENSION = 240;
    public const MAX_DIMENSION = 6000;
    public const OUTPUT_SIZE = 720;

    public function store(UploadedFile $file, string $directory): string
    {
        $this->validateActualImage($file);

        $bytes = file_get_contents($file->getRealPath());
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            throw ValidationException::withMessages(['photo' => 'The uploaded file is not a readable image.']);
        }

        $source = $this->orientJpeg($source, $file);
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $sourceX = (int) floor(($width - $side) / 2);
        $sourceY = (int) floor(($height - $side) / 2);
        $output = imagecreatetruecolor(self::OUTPUT_SIZE, self::OUTPUT_SIZE);
        $white = imagecolorallocate($output, 255, 255, 255);
        imagefill($output, 0, 0, $white);
        imagecopyresampled($output, $source, 0, 0, $sourceX, $sourceY, self::OUTPUT_SIZE, self::OUTPUT_SIZE, $side, $side);

        ob_start();
        imagewebp($output, null, 82);
        $optimized = ob_get_clean();
        imagedestroy($source);
        imagedestroy($output);

        if (! is_string($optimized) || $optimized === '') {
            throw ValidationException::withMessages(['photo' => 'The image could not be optimized.']);
        }

        $path = trim($directory, '/').'/'.Str::uuid().'.webp';
        Storage::disk('local')->put($path, $optimized);

        return $path;
    }

    private function validateActualImage(UploadedFile $file): void
    {
        if (! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['photo' => 'The photograph must be no larger than 8 MB.']);
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $dimensions = @getimagesize($file->getRealPath());
        if (! in_array($mime, $allowed, true) || ! $dimensions) {
            throw ValidationException::withMessages(['photo' => 'Upload a genuine JPG, PNG, or WebP image.']);
        }
        [$width, $height] = $dimensions;
        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw ValidationException::withMessages(['photo' => 'Image dimensions must be between 240 and 6000 pixels on each side.']);
        }
    }

    private function orientJpeg(\GdImage $image, UploadedFile $file): \GdImage
    {
        if ($file->getMimeType() !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = @exif_read_data($file->getRealPath())['Orientation'] ?? 1;
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if ($rotated !== $image) {
            imagedestroy($image);
        }

        return $rotated;
    }
}
