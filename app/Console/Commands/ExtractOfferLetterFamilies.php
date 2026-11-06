<?php

namespace App\Console\Commands;

use App\Models\MemberDocument;
use App\Services\OfferLetterFamilyImporter;
use Illuminate\Console\Command;

class ExtractOfferLetterFamilies extends Command
{
    protected $signature = 'offers:extract-family {--document=} {--limit=25}';

    protected $description = 'Read saved offer letters and import only high-confidence matching family tables';

    public function handle(OfferLetterFamilyImporter $importer): int
    {
        $q = MemberDocument::where('category', 'offer_letter')->whereNull('family_member_id');
        if ($this->option('document')) {
            $q->whereKey((int) $this->option('document'));
        } else {
            $q->whereNull('extraction_status');
        }
        foreach ($q->orderBy('id')->limit(max(1, min(100, (int) $this->option('limit'))))->get() as $d) {
            $result = $importer->process($d);
            $this->line('Document '.$d->id.': '.$result->extraction_status.' — '.$result->extraction_message);
        }

        return self::SUCCESS;
    }
}
