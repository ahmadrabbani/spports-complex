<?php

namespace App\Services;

use App\Models\MemberDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class OfferLetterTextReader
{
    public function read(MemberDocument $document): array
    {
        $path = Storage::disk('local')->path($document->path);
        if (! is_file($path)) {
            throw new RuntimeException('Offer letter file is unavailable.');
        }
        $dir = storage_path('app/private/offer-extraction/'.Str::uuid());
        File::makeDirectory($dir, 0700, true);
        try {
            if ($document->mime_type === 'application/pdf') {
                $p = new Process([config('offer_letters.pdftotext'), '-f', '1', '-l', (string) config('offer_letters.maximum_pages'), '-layout', $path, '-']);
                $p->setTimeout(20);
                $p->mustRun();
                $text = $p->getOutput();
                if (preg_match('/\bself\b/i', $text) && preg_match('/\b(wife|son|daughter)\b/i', $text)) {
                    return ['lines' => array_map(fn ($s) => ['text' => $s, 'confidence' => 100], preg_split('/\R/', $text)), 'method' => 'pdf_text'];
                }
                $p = new Process([config('offer_letters.pdftoppm'), '-f', '1', '-l', (string) config('offer_letters.maximum_pages'), '-scale-to', '2400', '-gray', '-png', $path, $dir.'/page']);
                $p->setTimeout(35);
                $p->mustRun();
                $images = glob($dir.'/page-*.png');
            } else {
                $size = @getimagesize($path);
                if (! $size || $size[0] * $size[1] > 40000000) {
                    throw new RuntimeException('Image is too large or unreadable for extraction.');
                }
                $images = [$path];
            }
            $lines = [];
            foreach ($images as $image) {
                $p = new Process([config('offer_letters.tesseract'), $image, 'stdout', '-l', 'eng', '--psm', '6', 'tsv']);
                $p->setTimeout(40);
                $p->mustRun();
                $groups = [];
                foreach (explode("\n", $p->getOutput()) as $line) {
                    $cols = explode("\t", $line, 12);
                    if (count($cols) !== 12 || $cols[0] !== '5' || trim($cols[11]) === '') {
                        continue;
                    }$key = implode(':', array_slice($cols, 1, 4));
                    $groups[$key]['words'][] = $cols[11];
                    $groups[$key]['scores'][] = (float) $cols[10];
                }
                foreach ($groups as $group) {
                    $lines[] = ['text' => implode(' ', $group['words']), 'confidence' => min($group['scores'])];
                }
            }

            return ['lines' => $lines, 'method' => 'ocr'];
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
