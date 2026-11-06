<?php

namespace App\Jobs;

use App\Models\MemberDocument;
use App\Services\OfferLetterFamilyImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExtractOfferLetterFamily implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 1;

    public int $timeout = 210;

    public function __construct(public int $documentId)
    {
        $this->onConnection('offer_letters');
        $this->onQueue('offer-letters');
    }

    public function handle(OfferLetterFamilyImporter $importer): void
    {
        if ($d = MemberDocument::find($this->documentId)) {
            $importer->process($d);
        }
    }

    public function failed(?Throwable $e): void
    {
        MemberDocument::whereKey($this->documentId)->whereIn('extraction_status', ['queued', 'processing'])->update(['extraction_status' => 'needs_review', 'extraction_message' => 'Background extraction stopped. Retry reading the saved letter.', 'extracted_at' => now()]);
    }
}
