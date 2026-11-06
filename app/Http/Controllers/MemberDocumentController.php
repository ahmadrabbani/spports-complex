<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MemberDocument;
use App\Services\OfferLetterFamilyImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MemberDocumentController extends Controller
{
    private function authorizeMember(Request $r, Member $m): void
    {
        abort_unless(in_array($r->user()->role, ['admin', 'manager', 'operator'], true), 403);
        abort_if($r->user()->sports_complex_id && (int) $r->user()->sports_complex_id !== $m->sports_complex_id, 403);
    }

    public function index(Request $r, Member $member)
    {
        $this->authorizeMember($r, $member);
        $data = $r->validate(['family_member_id' => ['nullable', 'integer', Rule::exists('family_members', 'id')->where('member_id', $member->id)]]);

        return MemberDocument::where('member_id', $member->id)->where('family_member_id', $data['family_member_id'] ?? null)->latest()->get();
    }

    public function store(Request $r, Member $member)
    {
        $this->authorizeMember($r, $member);
        abort_unless($r->user()->role === 'admin', 403);
        $data = $r->validate(['family_member_id' => ['nullable', 'integer', Rule::exists('family_members', 'id')->where('member_id', $member->id)], 'category' => ['required', Rule::in(['supporting_document', 'offer_letter'])], 'title' => 'required|string|max:150', 'membership_number' => [Rule::requiredIf($r->input('category') === 'offer_letter'), 'nullable', 'string', 'max:80'], 'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240']]);
        $restricted = $r->user()->isRestrictedComplexAdmin();
        if ($restricted) {
            abort_unless($data['category'] === 'offer_letter' && empty($data['family_member_id']), 403, 'This account can upload only the primary member offer letter.');
        }
        if ($data['category'] === 'offer_letter') {
            $this->verifyMembershipNumber($member, $data['membership_number']);
        }
        $file = $r->file('file');
        $path = $file->storeAs('member-documents/'.$member->id, Str::uuid().'.'.$file->extension(), 'local');
        abort_unless($path, 500, 'Unable to store document.');
        try {
            $document = DB::transaction(function () use ($r, $member, $data, $file, $path, $restricted) {
                $lockedMember = Member::lockForUpdate()->findOrFail($member->id);
                if ($data['category'] === 'offer_letter') {
                    $this->verifyMembershipNumber($lockedMember, $data['membership_number']);
                }
                if ($restricted) {
                    abort_if(MemberDocument::where('member_id', $member->id)->whereNull('family_member_id')->where('category', 'offer_letter')->exists(), 409, 'An offer letter has already been uploaded for this member. It cannot be uploaded again.');
                }

                return MemberDocument::create(['offer_letter_slot' => $restricted ? $member->id : null, 'member_id' => $member->id, 'family_member_id' => $data['family_member_id'] ?? null, 'category' => $data['category'], 'title' => $data['title'], 'original_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())), 'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => $r->user()->id]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        if ($document->category === 'offer_letter' && ! $document->family_member_id) {
            $document = app(OfferLetterFamilyImporter::class)->enqueue($document);
        }

        return response()->json($document, 201);
    }

    private function verifyMembershipNumber(Member $member, string $number): void
    {
        $normalize = fn (string $value) => mb_strtoupper(preg_replace('/\s+/u', '', trim($value)));
        $expected = $normalize((string) $member->membership_no);
        if ($expected === '') {
            abort(409, 'This member has no recorded membership number. Central administration must confirm it before an offer letter can be uploaded.');
        }
        if (! hash_equals($expected, $normalize($number))) {
            throw ValidationException::withMessages(['membership_number' => 'The membership number does not match the selected member. The offer letter was not saved.']);
        }
    }

    public function extractFamily(Request $r, MemberDocument $document, OfferLetterFamilyImporter $importer)
    {
        $this->authorizeMember($r, $document->member);
        abort_unless($r->user()->role === 'admin', 403);

        return $importer->enqueue($document);
    }

    public function confirmFamily(Request $r, MemberDocument $document, OfferLetterFamilyImporter $importer)
    {
        $this->authorizeMember($r, $document->member);
        abort_unless($r->user()->role === 'admin', 403);
        abort_if(in_array($document->extraction_status, ['processing', 'queued'], true), 409, 'Extraction is in progress.');
        abort_unless(in_array($document->extraction_status, ['needs_review', 'imported'], true), 409, 'Read the saved offer letter before confirming its family list.');
        $data = $r->validate([
            'confirmed' => ['required', 'accepted'],
            'sponsor_name' => ['required', 'string', 'max:255'],
            'membership_number' => ['required', 'string', 'max:80'],
            'rows' => ['required', 'array', 'min:1', 'max:25'],
            'rows.*.full_name' => ['required', 'string', 'max:255'],
            'rows.*.relationship' => ['required', Rule::in(['Wife', 'Son', 'Daughter', 'Son-in-law', 'Daughter-in-law'])],
            'rows.*.membership_validity' => ['required', Rule::in(['lifetime', 'unmarried_under_25'])],
            'rows.*.membership_valid_until' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $importer->import($document, $data, $r->user()->id);
    }

    public function download(Request $r, MemberDocument $document)
    {
        $this->authorizeMember($r, $document->member);
        abort_unless(Storage::disk('local')->exists($document->path), 404);
        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$document->mime_type] ?? 'bin';

        return Storage::disk('local')->download($document->path, 'member-document-'.$document->id.'.'.$extension, ['Content-Type' => $document->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
