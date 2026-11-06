<?php

namespace App\Http\Controllers;

use App\Models\FamilyMember;
use App\Models\Member;
use App\Services\MemberPhotoService;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

class FamilyMemberController extends Controller
{
    private function authorizeMember(Request $request, Member $member): void
    {
        $user = $request->user();
        if ($user->role === 'member') {
            abort_unless((int) $user->member_id === $member->id, 403);
        } else {
            abort_unless(in_array($user->role, ['admin', 'manager', 'operator'], true), 403);
            abort_if($user->sports_complex_id && (int) $user->sports_complex_id !== $member->sports_complex_id, 403);
        }
    }

    public function index(Request $request, Member $member)
    {
        $this->authorizeMember($request, $member);

        $members = $member->familyMembers()->orderBy('id')->get();
        if ($request->boolean('include_eligibility')) {
            $allowed = $member->allowsFamilyMembers();

            return response()->json(['members' => $members, 'can_add_family_members' => $allowed,
                'restriction' => $allowed ? null : 'Individual memberships cannot add family members.']);
        }

        return $members;
    }

    public function store(Request $request, Member $member)
    {
        $this->authorizeMember($request, $member);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'relationship' => ['required', Rule::in(['Son', 'Wife', 'Daughter', 'Son-in-law', 'Daughter-in-law'])],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'parent_family_member_id' => ['prohibited'],
        ]);

        return DB::transaction(function () use ($request, $member, $data) {
            $locked = Member::lockForUpdate()->findOrFail($member->id);
            $this->authorizeMember($request, $locked);
            if (! $locked->allowsFamilyMembers()) {
                return response()->json(['message' => 'Individual memberships cannot add family members.',
                    'code' => 'FAMILY_MEMBERS_NOT_ALLOWED'], 422);
            }

            return response()->json($locked->familyMembers()->create(array_merge($data, ['parent_family_member_id' => null])), 201);
        });
    }

    public function uploadPhoto(Request $request, FamilyMember $familyMember, MemberPhotoService $photos)
    {
        $this->authorizeMember($request, $familyMember->member);
        abort_unless($request->user()->role === 'admin', 403);
        abort_if($request->user()->isRestrictedComplexAdmin() && (! $request->user()->sports_complex_id || ! $request->user()->sportsComplex?->is_active), 403, 'An active sports complex assignment is required.');
        $request->validate(['photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:min_width=240,min_height=240,max_width=6000,max_height=6000']]);
        $path = $photos->store($request->file('photo'), 'family-photos/'.$familyMember->id);
        try {
            DB::transaction(function () use ($request, $familyMember, $path) {
                $locked = FamilyMember::lockForUpdate()->findOrFail($familyMember->id);
                $old = $locked->profile_image_path;
                $locked->forceFill(['profile_image_path' => $path, 'photo_updated_at' => now(), 'photo_updated_by' => $request->user()->id])->save();
                DB::table('family_photo_audits')->insert(['family_member_id' => $locked->id, 'actor_user_id' => $request->user()->id, 'action' => $old ? 'replaced' : 'uploaded', 'old_path' => $old, 'new_path' => $path, 'created_at' => now(), 'updated_at' => now()]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return ['message' => 'Family member photograph saved.', 'family_member' => $familyMember->fresh()];
    }

    public function photo(Request $request, FamilyMember $familyMember)
    {
        $this->authorizeMember($request, $familyMember->member);
        $path = $familyMember->profile_image_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function card(Request $request, FamilyMember $familyMember)
    {
        $this->authorizeMember($request, $familyMember->member);
        $data = $this->cardData($familyMember);
        if ($request->routeIs('family.card.pdf')) {
            return Pdf::loadView('members.card-pdf', $data)->setPaper([0, 0, 242.65, 153.07])->download('family-card-'.$familyMember->id.'.pdf');
        }

        return view('members.card', $data + ['pdfUrl' => route('family.card.pdf', $familyMember)]);
    }

    public function verify(Request $request, FamilyMember $familyMember)
    {
        abort_unless($request->hasValidSignature(), 403);

        return view('members.verify', $this->cardData($familyMember));
    }

    private function cardData(FamilyMember $familyMember): array
    {
        $owner = $familyMember->member()->with('sportsComplex')->firstOrFail();
        // Present the dependent's identity while retaining the sponsor's current status and complex.
        $member = new Member([
            'full_name' => $familyMember->full_name,
            'membership_no' => ($owner->membership_no ?: $owner->customer_no).'-F'.$familyMember->id,
            'status' => $owner->reconciliation_account === '260111' ? 'frozen' : $owner->status,
        ]);
        $member->setRelation('sportsComplex', $owner->sportsComplex);
        $verifyUrl = URL::temporarySignedRoute('family.verify', now()->addYears(5), ['familyMember' => $familyMember->id]);
        $qrCode = (new PngWriter)->write(new QrCode(data: $verifyUrl, size: 240, margin: 8))->getDataUri();

        $photo = null;
        if ($familyMember->profile_image_path && Storage::disk('local')->exists($familyMember->profile_image_path)) {
            $photo = 'data:image/webp;base64,'.base64_encode(Storage::disk('local')->get($familyMember->profile_image_path));
        }

        return compact('member', 'verifyUrl', 'qrCode', 'photo') + ['familyMember' => $familyMember, 'sponsor' => $owner];
    }
}
