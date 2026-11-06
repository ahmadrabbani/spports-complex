<?php

namespace App\Services;

use App\Jobs\ExtractOfferLetterFamily;
use App\Models\Member;
use App\Models\MemberDocument;
use Illuminate\Support\Facades\DB;
use Throwable;

class OfferLetterFamilyImporter
{
    public function __construct(private OfferLetterTextReader $reader, private OfferLetterFamilyParser $parser) {}

    public function enqueue(MemberDocument $document): MemberDocument
    {
        if (! config('offer_letters.background')) {
            return $this->process($document);
        }
        abort_unless($document->category === 'offer_letter' && ! $document->family_member_id, 422, 'Only primary member offer letters can supply a family table.');
        DB::transaction(function () use ($document) {
            $d = MemberDocument::lockForUpdate()->findOrFail($document->id);
            if ($d->extraction_status === 'imported' || (in_array($d->extraction_status, ['queued', 'processing'], true) && $d->extracted_at?->gt(now()->subMinutes(5)))) {
                return;
            }
            $d->update(['extraction_status' => 'queued', 'extraction_message' => 'Queued for private family-table extraction.', 'extracted_at' => now()]);
            ExtractOfferLetterFamily::dispatch($d->id);
        });

        return $document->fresh();
    }

    public function process(MemberDocument $document): MemberDocument
    {
        abort_unless($document->category === 'offer_letter' && ! $document->family_member_id, 422, 'Only a primary member offer letter can supply family members.');
        $claimed = DB::transaction(function () use ($document) {
            $d = MemberDocument::lockForUpdate()->findOrFail($document->id);
            if ($d->extraction_status === 'imported' || ($d->extraction_status === 'processing' && $d->extracted_at?->gt(now()->subMinutes(5)))) {
                return false;
            }$d->update(['extraction_status' => 'processing', 'extraction_message' => 'Reading offer letter.', 'extracted_at' => now()]);

            return true;
        });
        if (! $claimed) {
            return $document->fresh();
        }
        try {
            $parsed = $this->parser->parse($this->reader->read($document));
        } catch (Throwable $e) {
            $document->update(['extraction_status' => 'needs_review', 'extraction_message' => 'The letter could not be read automatically. Check scan quality and OCR/PDF tools; retry the saved document without uploading another letter.', 'extracted_at' => now()]);

            return $document->fresh();
        }
        $member = $document->member;
        if (! $this->identityMatches($member, $parsed)) {
            $parsed['issues'][] = 'The offer membership number or Self name does not match the selected member.';
        }
        if (! $member->allowsFamilyMembers()) {
            $parsed['issues'][] = 'The stored membership category does not allow family members. Central administration must resolve this.';
        }
        $document->update(['extraction_payload' => $parsed, 'extraction_status' => 'needs_review', 'extraction_message' => implode(' ', array_unique($parsed['issues'])) ?: 'Review extracted rows.', 'extracted_at' => now()]);
        if (! $parsed['issues']) {
            try {
                return $this->import($document, $parsed, null);
            } catch (Throwable $e) {
                $document->update(['extraction_status' => 'needs_review', 'extraction_message' => 'The rows could not be imported safely. Review the letter and existing family list.']);
            }
        }

        return $document->fresh();
    }

    public function identityMatches(Member $member, array $data): bool
    {
        $stored = preg_replace('/[^0-9]/', '', (string) $member->membership_no);
        $fromLetter = preg_replace('/[^0-9]/', '', (string) ($data['membership_number'] ?? ''));

        return $stored !== '' && $fromLetter !== '' && ltrim($stored, '0') === ltrim($fromLetter, '0') && OfferLetterFamilyParser::normalize($member->full_name) === OfferLetterFamilyParser::normalize((string) ($data['sponsor_name'] ?? ''));
    }

    public function import(MemberDocument $document, array $data, ?int $reviewer): MemberDocument
    {
        return DB::transaction(function () use ($document, $data, $reviewer) {
            $member = Member::lockForUpdate()->findOrFail($document->member_id);
            $d = MemberDocument::lockForUpdate()->findOrFail($document->id);
            if ($d->extraction_status === 'imported') {
                return $d;
            }
            abort_unless($d->category === 'offer_letter' && ! $d->family_member_id, 422);
            abort_unless($this->identityMatches($member, $data), 409, 'The sponsor name and membership number must match this member. No family members were added.');
            abort_unless($member->allowsFamilyMembers(), 409, 'This membership category does not allow family members.');
            abort_unless(count($data['rows']) > 0 && count($data['rows']) <= 25, 422, 'A complete dependant list is required.');
            $created = 0;
            $skipped = 0;
            $seen = [];
            foreach ($data['rows'] as $row) {
                $name = trim(preg_replace('/\s+/', ' ', $row['full_name']));
                $normalized = OfferLetterFamilyParser::normalize($name);
                abort_if($normalized === '' || $normalized === OfferLetterFamilyParser::normalize($member->full_name), 422, 'Do not import the Self row as a dependant.');
                $key = hash('sha256', $member->id.'|'.$normalized.'|'.$row['relationship']);
                abort_if(isset($seen[$key]), 422, 'Duplicate names in the extracted table require review.');
                $seen[$key] = true;
                if ($row['membership_validity'] === 'lifetime') {
                    abort_if(! empty($row['membership_valid_until']), 422, 'Lifetime membership cannot have an expiry date.');
                } else {
                    abort_unless($row['membership_validity'] === 'unmarried_under_25' && ! empty($row['membership_valid_until']), 422, 'Child validity requires an expiry date from the offer letter.');
                }
                $existing = $member->familyMembers()->get()->first(fn ($f) => OfferLetterFamilyParser::normalize($f->full_name) === $normalized);
                if ($existing) {
                    abort_unless(strtolower($existing->relationship) === strtolower($row['relationship']), 409, 'An existing name has a different relationship. Review it before importing.');
                    $skipped++;

                    continue;
                }
                $f = $member->familyMembers()->make(['full_name' => $name, 'relationship' => $row['relationship']]);
                $f->forceFill(['membership_valid_until' => $row['membership_valid_until'] ?? null, 'membership_validity' => $row['membership_validity'], 'offer_letter_document_id' => $d->id, 'offer_row_key' => $key])->save();
                $created++;
            }
            $payload = $data;
            $payload['created'] = $created;
            $payload['skipped'] = $skipped;
            if ($reviewer) {
                $payload['original_extraction'] = $d->extraction_payload;
            }
            $d->update(['extraction_payload' => $payload, 'extraction_status' => 'imported', 'extraction_message' => "Added {$created} family members; {$skipped} already existed.", 'extracted_at' => now(), 'extraction_reviewed_by' => $reviewer]);

            return $d;
        });
    }
}
