<?php

namespace Tests\Feature;

use App\Jobs\ExtractOfferLetterFamily;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\SportsComplex;
use App\Models\User;
use App\Services\OfferLetterFamilyImporter;
use App\Services\OfferLetterFamilyParser;
use App\Services\OfferLetterTextReader;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OfferLetterFamilyExtractionTest extends TestCase
{
    use RefreshDatabase;

    private function source(int $confidence = 100): array
    {
        return ['method' => 'pdf_text', 'lines' => array_map(fn ($text) => ['text' => $text, 'confidence' => $confidence], [
            'OFFER LETTER OF MEMBERSHIP # 3048',
            '01. Test Sponsor Self Life Time',
            '02. Test Spouse Wife Life Time',
            '03. Test Daughter Daughter Unmarried & Below 25 years (28-05-2033)',
            '04. Test Son Son Unmarried & Below 25 years (02-02-2041)',
        ])];
    }

    private function actors(): array
    {
        config(['offer_letters.background' => false]);
        Storage::fake('local');
        $c = SportsComplex::create(['name' => 'Synthetic', 'code' => 'TT']);
        $m = Member::create(['sports_complex_id' => $c->id, 'customer_no' => 'SYN', 'membership_no' => '3048', 'full_name' => 'Test Sponsor', 'status' => 'active', 'reconciliation_account' => '260108', 'cnic' => '3520200000000']);
        $u = User::factory()->create(['role' => 'admin', 'sports_complex_id' => $c->id, 'is_complex_admin' => true, 'must_change_password' => false]);

        return [$u, $m];
    }

    private function upload(User $u, Member $m)
    {
        return $this->actingAs($u)->postJson('/api/members/'.$m->id.'/documents', ['title' => 'Offer letter', 'category' => 'offer_letter', 'membership_number' => $m->membership_no, 'file' => UploadedFile::fake()->image('offer.png', 1000, 1500)]);
    }

    public function test_upload_auto_imports_matching_family_without_updating_member_and_is_idempotent(): void
    {
        [$u,$m] = $this->actors();
        $before = $m->fresh()->getRawOriginal();
        $this->mock(OfferLetterTextReader::class)->shouldReceive('read')->once()->andReturn($this->source());
        $response = $this->upload($u, $m)->assertCreated()->assertJsonPath('extraction_status', 'imported')->assertJsonMissingPath('extraction_payload');
        $id = $response->json('id');
        $this->assertDatabaseCount('family_members', 3);
        $this->assertSame($before, $m->fresh()->getRawOriginal());
        $this->assertDatabaseHas('family_members', ['full_name' => 'Test Daughter', 'membership_valid_until' => '2033-05-28', 'date_of_birth' => null, 'offer_letter_document_id' => $id]);
        $this->assertDatabaseMissing('family_members', ['full_name' => 'Test Sponsor']);
        $this->postJson('/api/member-documents/'.$id.'/extract-family')->assertOk()->assertJsonPath('extraction_status', 'imported');
        $this->assertDatabaseCount('family_members', 3);
        $this->postJson('/api/members/'.$m->id.'/documents', [])->assertUnprocessable();
    }

    public function test_blurred_scan_requires_review_and_restricted_account_can_confirm_once(): void
    {
        [$u,$m] = $this->actors();
        $this->mock(OfferLetterTextReader::class)->shouldReceive('read')->andReturn($this->source(60));
        $id = $this->upload($u, $m)->assertCreated()->assertJsonPath('extraction_status', 'needs_review')->json('id');
        $this->assertDatabaseCount('family_members', 0);
        $data = (new OfferLetterFamilyParser)->parse($this->source());
        $data['confirmed'] = true;
        $this->postJson('/api/member-documents/'.$id.'/confirm-family', [...$data, 'sponsor_name' => 'Wrong Person'])->assertConflict();
        $this->assertDatabaseCount('family_members', 0);
        $this->postJson('/api/member-documents/'.$id.'/confirm-family', $data)->assertOk()->assertJsonPath('extraction_status', 'imported');
        $this->postJson('/api/member-documents/'.$id.'/confirm-family', $data)->assertOk();
        $this->assertDatabaseCount('family_members', 3);
        $this->assertDatabaseHas('member_documents', ['id' => $id, 'extraction_reviewed_by' => $u->id]);
    }

    public function test_existing_dependant_is_not_duplicated_or_overwritten(): void
    {
        [$u,$m] = $this->actors();
        $f = $m->familyMembers()->create(['full_name' => 'TEST SPOUSE', 'relationship' => 'Wife', 'date_of_birth' => '1980-01-01']);
        $this->mock(OfferLetterTextReader::class)->shouldReceive('read')->andReturn($this->source());
        $this->upload($u, $m)->assertCreated()->assertJsonPath('family_extraction.skipped', 1);
        $this->assertDatabaseCount('family_members', 3);
        $this->assertSame('1980-01-01', $f->fresh()->date_of_birth);
    }

    public function test_individual_category_and_foreign_account_cannot_import(): void
    {
        [$u,$m] = $this->actors();
        $m->update(['reconciliation_account' => '260110']);
        $this->mock(OfferLetterTextReader::class)->shouldReceive('read')->andReturn($this->source());
        $id = $this->upload($u, $m)->assertCreated()->assertJsonPath('extraction_status', 'needs_review')->json('id');
        $data = (new OfferLetterFamilyParser)->parse($this->source());
        $data['confirmed'] = true;
        $this->postJson('/api/member-documents/'.$id.'/confirm-family', $data)->assertConflict();
        $c = SportsComplex::create(['name' => 'Foreign', 'code' => 'OT']);
        $other = User::factory()->create(['role' => 'admin', 'is_complex_admin' => true, 'sports_complex_id' => $c->id, 'must_change_password' => false]);
        $this->actingAs($other)->postJson('/api/member-documents/'.$id.'/extract-family')->assertForbidden();
        $this->postJson('/api/member-documents/'.$id.'/confirm-family', $data)->assertForbidden();
        $this->assertDatabaseCount('family_members', 0);
    }

    public function test_missing_ocr_leaves_original_upload_saved_for_retry(): void
    {
        [$u,$m] = $this->actors();
        $this->mock(OfferLetterTextReader::class)->shouldReceive('read')->andThrow(new \RuntimeException('OCR missing'));
        $this->upload($u, $m)->assertCreated()->assertJsonPath('extraction_status', 'needs_review');
        $this->assertDatabaseCount('member_documents', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('family_members', 0);
    }

    public function test_upload_queues_private_background_job_and_worker_imports(): void
    {
        [$u,$m] = $this->actors();
        config(['offer_letters.background' => true]);
        Queue::fake();
        $this->mock(OfferLetterTextReader::class)->shouldReceive('read')->once()->andReturn($this->source());
        $id = $this->upload($u, $m)->assertCreated()->assertJsonPath('extraction_status', 'queued')->json('id');
        Queue::assertPushed(ExtractOfferLetterFamily::class, fn ($job) => $job->documentId === $id && $job->connection === 'offer_letters' && $job->queue === 'offer-letters');
        $this->assertDatabaseCount('family_members', 0);
        (new ExtractOfferLetterFamily($id))->handle(app(OfferLetterFamilyImporter::class));
        $this->assertDatabaseCount('family_members', 3);
    }

    public function test_text_pdf_is_read_with_real_poppler(): void
    {
        [$u,$m] = $this->actors();
        $probe = new Process([config('offer_letters.pdftotext'), '-v']);
        try {
            $probe->run();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Poppler is not installed.');
        }
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('Poppler is not installed.');
        }
        $html = '<h3>OFFER LETTER OF MEMBERSHIP # 3048</h3><table><tr><td>01.</td><td>Test Sponsor</td><td>Self</td><td>Life Time</td></tr><tr><td>02.</td><td>Test Spouse</td><td>Wife</td><td>Life Time</td></tr><tr><td>03.</td><td>Test Daughter</td><td>Daughter</td><td>Unmarried &amp; Below 25 years (28-05-2033)</td></tr></table>';
        $bytes = Pdf::loadHTML($html)->output();
        Storage::disk('local')->put('test-letter.pdf', $bytes);
        $document = new MemberDocument(['path' => 'test-letter.pdf', 'mime_type' => 'application/pdf']);
        $source = app(OfferLetterTextReader::class)->read($document);
        $this->assertSame('pdf_text', $source['method']);
        $parsed = (new OfferLetterFamilyParser)->parse($source);
        $this->assertSame('3048', $parsed['membership_number']);
        $this->assertCount(2, $parsed['rows']);
        $this->assertSame([], $parsed['issues']);
    }

    public function test_incomplete_table_is_never_auto_imported(): void
    {
        $source = $this->source();
        $source['lines'][] = ['text' => '05. Unreadable Child Unknown relation', 'confidence' => 100];
        $result = (new OfferLetterFamilyParser)->parse($source);
        $this->assertContains('A numbered family row could not be read.', $result['issues']);
    }
}
