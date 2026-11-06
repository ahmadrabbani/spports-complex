<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\SportsComplex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MemberDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_uploads_private_documents_and_rejects_cross_member_family_links(): void
    {
        Storage::fake('local');
        $c = SportsComplex::create(['name' => 'Test', 'code' => 'T']);
        $m = Member::create(['sports_complex_id' => $c->id, 'customer_no' => '1', 'membership_no' => 'Ind#208', 'full_name' => 'Synthetic', 'status' => 'active']);
        $other = Member::create(['sports_complex_id' => $c->id, 'customer_no' => '2', 'full_name' => 'Other', 'status' => 'active']);
        $f = $m->familyMembers()->create(['full_name' => 'Dependent', 'relationship' => 'Son']);
        $foreign = $other->familyMembers()->create(['full_name' => 'Other dependent', 'relationship' => 'Son']);
        $u = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $base = '/api/members/'.$m->id.'/documents';
        $data = ['title' => 'Supporting record', 'category' => 'supporting_document', 'family_member_id' => $f->id, 'file' => UploadedFile::fake()->image('record.png', 400, 400)];
        $response = $this->actingAs($u)->postJson($base, $data)->assertCreated()->assertJsonMissingPath('path');
        $doc = MemberDocument::firstOrFail();
        Storage::disk('local')->assertExists($doc->path);
        $this->get($response->json('download_url'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->getJson($base.'?family_member_id='.$f->id)->assertOk()->assertJsonCount(1);
        $this->getJson($base)->assertOk()->assertJsonCount(0);
        $this->postJson($base, [...$data, 'family_member_id' => $foreign->id])->assertUnprocessable();
        $this->postJson($base, [...$data, 'file' => UploadedFile::fake()->create('bad.html', 1, 'text/html')])->assertUnprocessable();
        $this->postJson($base, [...$data, 'file' => UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf')])->assertUnprocessable();
        $this->postJson($base, ['title' => 'Offer letter', 'category' => 'offer_letter', 'membership_number' => 'Ind#208', 'file' => UploadedFile::fake()->image('offer.jpg')])->assertCreated();
        $this->assertSame($u->id, $doc->uploaded_by);
        $operator = User::factory()->create(['role' => 'operator', 'must_change_password' => false]);
        $this->actingAs($operator)->postJson($base, $data)->assertForbidden();
        $c2 = SportsComplex::create(['name' => 'Other', 'code' => 'O']);
        $operator->update(['sports_complex_id' => $c2->id]);
        $this->getJson($base)->assertForbidden();
        $this->getJson($response->json('download_url'))->assertForbidden();
        $owner = User::factory()->create(['role' => 'member', 'member_id' => $m->id, 'must_change_password' => false]);
        $this->actingAs($owner)->getJson($response->json('download_url'))->assertForbidden();
    }
}
