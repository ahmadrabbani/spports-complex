<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\SportsComplex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FamilyPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_upload_is_private_audited_and_included_on_card(): void
    {
        Storage::fake('local');
        $c = SportsComplex::create(['name' => 'Test', 'code' => 'TT']);
        $m = Member::create(['sports_complex_id' => $c->id, 'customer_no' => 'TT', 'full_name' => 'Sponsor', 'status' => 'active']);
        $f = $m->familyMembers()->create(['full_name' => 'Dependent', 'relationship' => 'Daughter']);
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $url = '/family-members/'.$f->id.'/photo';
        $this->actingAs($admin)->postJson($url, ['photo' => UploadedFile::fake()->image('test.jpg', 480, 600)])->assertOk()->assertJsonMissingPath('family_member.profile_image_path');
        $f->refresh();
        Storage::disk('local')->assertExists($f->profile_image_path);
        $this->assertDatabaseHas('family_photo_audits', ['family_member_id' => $f->id, 'actor_user_id' => $admin->id]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get('/family-members/'.$f->id.'/card')->assertOk()->assertSee('data:image/webp;base64,', false);
        $this->get('/family-members/'.$f->id.'/card.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson($url, ['photo' => UploadedFile::fake()->image('small.jpg', 100, 100)])->assertUnprocessable();
        $other = User::factory()->create(['role' => 'member', 'must_change_password' => false]);
        $this->actingAs($other)->get($url)->assertForbidden();
        $owner = User::factory()->create(['role' => 'member', 'member_id' => $m->id, 'must_change_password' => false]);
        $this->actingAs($owner)->get($url)->assertOk();
        $this->postJson($url, ['photo' => UploadedFile::fake()->image('test.jpg', 400, 400)])->assertForbidden();
    }

    public function test_scoped_admin_can_upload_only_own_family_photos_with_active_assignment(): void
    {
        Storage::fake('local');
        $c = SportsComplex::create(['name' => 'Synthetic', 'code' => 'SY']);
        $other = SportsComplex::create(['name' => 'Other', 'code' => 'OT']);
        $m = Member::create(['sports_complex_id' => $c->id, 'customer_no' => 'S1', 'full_name' => 'Sponsor', 'status' => 'active']);
        $foreign = Member::create(['sports_complex_id' => $other->id, 'customer_no' => 'O1', 'full_name' => 'Other sponsor', 'status' => 'active']);
        $f = $m->familyMembers()->create(['full_name' => 'Child', 'relationship' => 'Son']);
        $ff = $foreign->familyMembers()->create(['full_name' => 'Other Child', 'relationship' => 'Son']);
        $u = User::factory()->create(['role' => 'admin', 'is_complex_admin' => true, 'sports_complex_id' => $c->id, 'must_change_password' => false]);
        $before = $f->fresh()->only(['member_id', 'full_name', 'relationship', 'date_of_birth']);
        $this->actingAs($u)->postJson('/family-members/'.$f->id.'/photo', ['photo' => UploadedFile::fake()->image('child.png', 400, 400), 'full_name' => 'Tampered'])->assertOk();
        $this->assertSame($before, $f->fresh()->only(['member_id', 'full_name', 'relationship', 'date_of_birth']));
        $this->assertDatabaseHas('family_photo_audits', ['family_member_id' => $f->id, 'actor_user_id' => $u->id]);
        $this->get('/family-members/'.$f->id.'/card')->assertOk()->assertSee('data:image/webp;base64,', false);
        $this->postJson('/family-members/'.$ff->id.'/photo', ['photo' => UploadedFile::fake()->image('foreign.png', 400, 400)])->assertForbidden();
        $c->update(['is_active' => false]);
        $u->unsetRelation('sportsComplex');
        $this->postJson('/family-members/'.$f->id.'/photo', ['photo' => UploadedFile::fake()->image('disabled.png', 400, 400)])->assertForbidden();
        $u->update(['sports_complex_id' => null]);
        $u->unsetRelation('sportsComplex');
        $this->actingAs($u)->postJson('/family-members/'.$f->id.'/photo', ['photo' => UploadedFile::fake()->image('unassigned.png', 400, 400)])->assertForbidden();
    }
}
