<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\MembershipCategory;
use App\Models\SportsComplex;
use App\Models\User;
use Database\Seeders\ReconciliationAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RestrictedComplexUploadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_complex_account_is_read_only_except_family_photo_and_one_offer_letter(): void
    {
        Storage::fake('local');
        $this->seed(ReconciliationAccountSeeder::class);
        $c = SportsComplex::create(['name' => 'Synthetic', 'code' => 'SY']);
        $other = SportsComplex::create(['name' => 'Other', 'code' => 'OT']);
        $attrs = ['full_name' => 'Synthetic Member', 'membership_no' => '3048', 'status' => 'active', 'membership_category_id' => MembershipCategory::where('name', 'General Public Family Membership')->value('id')];
        $m = Member::create($attrs + ['sports_complex_id' => $c->id, 'customer_no' => 'S1']);
        $foreign = Member::create($attrs + ['sports_complex_id' => $other->id, 'customer_no' => 'O1']);
        $u = User::factory()->create(['role' => 'admin', 'is_complex_admin' => true, 'sports_complex_id' => $c->id, 'must_change_password' => false]);
        $before = $m->fresh()->getRawOriginal();
        $this->actingAs($u);
        $this->putJson('/api/members/'.$m->id, ['full_name' => 'Tampered'])->assertForbidden();
        $this->deleteJson('/api/members/'.$m->id)->assertForbidden();
        $this->postJson('/api/members', [])->assertForbidden();
        foreach (['category', 'freeze', 'unfreeze', 'freeze-fee-approvals', 'memberships', 'payments'] as $action) {
            $method = $action === 'category' ? 'putJson' : 'postJson';
            $this->$method('/api/members/'.$m->id.'/'.$action, [])->assertForbidden();
        }
        $this->postJson('/api/challans', [])->assertForbidden();
        $family = $this->postJson('/api/members/'.$m->id.'/family-members', ['full_name' => 'Synthetic Child', 'relationship' => 'Son'])->assertCreated()->json('id');
        $this->postJson('/family-members/'.$family.'/photo', ['photo' => UploadedFile::fake()->image('family.jpg', 400, 400)])->assertOk();
        $payload = ['membership_number' => '3048', 'title' => 'Offer letter', 'category' => 'offer_letter', 'file' => UploadedFile::fake()->image('offer.png', 400, 400)];
        $url = '/api/members/'.$m->id.'/documents';
        $this->postJson($url, [...$payload, 'category' => 'supporting_document'])->assertForbidden();
        $this->postJson($url, [...$payload, 'family_member_id' => $family])->assertForbidden();
        $doc = $this->postJson($url, $payload)->assertCreated()->json('id');
        $this->postJson($url, $payload)->assertConflict();
        $this->assertDatabaseCount('member_documents', 1);
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->postJson('/api/members/'.$foreign->id.'/documents', $payload)->assertForbidden();
        $this->postJson('/api/members/'.$foreign->id.'/photo', ['photo' => UploadedFile::fake()->image('member.jpg', 400, 400)])->assertForbidden();
        $this->postJson('/api/members/'.$foreign->id.'/family-members', ['full_name' => 'Other child', 'relationship' => 'Son'])->assertForbidden();
        $this->assertSame($before, $m->fresh()->getRawOriginal());
        $this->postJson('/api/members/'.$m->id.'/photo', ['photo' => UploadedFile::fake()->image('member.jpg', 400, 400), 'full_name' => 'Tampered'])->assertOk();
        $after = $m->fresh()->getRawOriginal();
        foreach (['profile_image_path', 'photo_updated_at', 'photo_updated_by', 'updated_at'] as $k) {
            unset($before[$k],$after[$k]);
        }$this->assertSame($before, $after);
        $central = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $this->actingAs($central)->postJson($url, [...$payload, 'category' => 'supporting_document'])->assertCreated();
    }

    public function test_existing_central_offer_letter_prevents_first_complex_upload(): void
    {
        Storage::fake('local');
        $c = SportsComplex::create(['name' => 'Test', 'code' => 'TT']);
        $m = Member::create(['sports_complex_id' => $c->id, 'customer_no' => 'TEST', 'membership_no' => '3048', 'full_name' => 'Synthetic', 'status' => 'active']);
        $u = User::factory()->create(['role' => 'admin', 'sports_complex_id' => $c->id, 'must_change_password' => false]);
        MemberDocument::create(['member_id' => $m->id, 'category' => 'offer_letter', 'title' => 'Existing', 'original_name' => 'existing.pdf', 'path' => 'existing.pdf', 'mime_type' => 'application/pdf', 'size' => 10, 'uploaded_by' => $u->id]);
        $this->actingAs($u)->postJson('/api/members/'.$m->id.'/documents', ['title' => 'Second', 'category' => 'offer_letter', 'membership_number' => '3048', 'file' => UploadedFile::fake()->image('offer.png', 400, 400)])->assertConflict();
        $this->assertDatabaseCount('member_documents', 1);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }
}
