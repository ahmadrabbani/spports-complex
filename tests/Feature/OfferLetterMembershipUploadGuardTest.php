<?php

namespace Tests\Feature;

use App\Jobs\ExtractOfferLetterFamily;
use App\Models\Member;
use App\Models\SportsComplex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OfferLetterMembershipUploadGuardTest extends TestCase
{
    use RefreshDatabase;

    private function actors(?string $number = 'Ind#208'): array
    {
        Storage::fake('local');
        Queue::fake();
        config(['offer_letters.background' => true]);
        $c = SportsComplex::create(['name' => 'Synthetic', 'code' => 'TT']);
        $m = Member::create(['sports_complex_id' => $c->id, 'customer_no' => 'S1', 'full_name' => 'Synthetic Member', 'membership_no' => $number, 'status' => 'active']);
        $u = User::factory()->create(['role' => 'admin', 'sports_complex_id' => $c->id, 'is_complex_admin' => true, 'must_change_password' => false]);

        return [$u, $m];
    }

    private function payload(): array
    {
        return ['category' => 'offer_letter', 'title' => 'Offer letter', 'file' => UploadedFile::fake()->image('offer.png', 400, 400)];
    }

    public function test_missing_or_wrong_confirmation_never_saves_a_document_or_file(): void
    {
        [$u,$m] = $this->actors();
        $before = $m->fresh()->getRawOriginal();
        $this->actingAs($u);
        $url = '/api/members/'.$m->id.'/documents';
        $this->postJson($url, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('membership_number');
        foreach (['Ind#209', '208', 'Family#208', 'wrong'] as $number) {
            $this->postJson($url, [...$this->payload(), 'membership_number' => $number])->assertUnprocessable()->assertJsonValidationErrors('membership_number');
        }
        $this->assertDatabaseCount('member_documents', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        Queue::assertNothingPushed();
        $this->assertSame($before, $m->fresh()->getRawOriginal());
    }

    public function test_matching_confirmation_uploads_and_queues_extraction_without_changing_member(): void
    {
        [$u,$m] = $this->actors();
        $before = $m->fresh()->getRawOriginal();
        $this->actingAs($u)->postJson('/api/members/'.$m->id.'/documents', [...$this->payload(), 'membership_number' => ' ind#208 '])->assertCreated()->assertJsonPath('extraction_status', 'queued');
        $this->assertDatabaseCount('member_documents', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        Queue::assertPushed(ExtractOfferLetterFamily::class);
        $this->assertSame($before, $m->fresh()->getRawOriginal());
    }

    public function test_member_without_membership_number_cannot_receive_offer_letter(): void
    {
        [$u,$m] = $this->actors(null);
        $this->actingAs($u)->postJson('/api/members/'.$m->id.'/documents', [...$this->payload(), 'membership_number' => 'S1'])->assertConflict();
        $this->assertDatabaseCount('member_documents', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        Queue::assertNothingPushed();
    }
}
