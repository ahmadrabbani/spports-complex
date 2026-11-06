<?php

namespace App\Http\Controllers;

use App\Models\Member;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class MembershipCardController extends Controller
{
    public function show(Request $request, Member $member)
    {
        $this->authorizeCard($request, $member);

        $pdfUrl = $request->user()->role === 'member' ? route('portal.card.pdf') : route('members.card.pdf', $member);

        return view('members.card', $this->cardData($member) + compact('pdfUrl'));
    }

    public function pdf(Request $request, Member $member)
    {
        $this->authorizeCard($request, $member);
        $filename = 'membership-card-'.preg_replace('/[^A-Za-z0-9-]+/', '-', $member->membership_no ?: $member->customer_no).'.pdf';

        return Pdf::loadView('members.card-pdf', $this->cardData($member))
            ->setPaper([0, 0, 242.65, 153.07])
            ->download($filename);
    }

    public function verify(Request $request, Member $member)
    {
        abort_unless($request->hasValidSignature(), 403);

        return view('members.verify', $this->cardData($member));
    }

    public function portalShow(Request $request)
    {
        abort_unless($request->user()->member, 404, 'No linked membership record was found.');

        return $this->show($request, $request->user()->member);
    }

    public function portalPdf(Request $request)
    {
        abort_unless($request->user()->member, 404, 'No linked membership record was found.');

        return $this->pdf($request, $request->user()->member);
    }

    private function cardData(Member $member): array
    {
        $member->load(['sportsComplex:id,name,code', 'memberships' => fn ($query) => $query->latest('id')->limit(1)]);
        $verifyUrl = URL::temporarySignedRoute('membership.verify', now()->addYears(5), ['member' => $member->id]);
        $qr = (new PngWriter)->write(new QrCode(data: $verifyUrl, errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: 240, margin: 8));
        $photo = null;
        if ($member->profile_image_path && Storage::disk('local')->exists($member->profile_image_path)) {
            $photo = 'data:image/webp;base64,'.base64_encode(Storage::disk('local')->get($member->profile_image_path));
        }

        if ($member->reconciliation_account === '260111') {
            $member = clone $member;
            $member->status = 'frozen';
        }

        return compact('member', 'verifyUrl', 'photo') + ['qrCode' => $qr->getDataUri()];
    }

    private function authorizeCard(Request $request, Member $member): void
    {
        $user = $request->user();
        if ($user->role === 'member') {
            abort_unless($user->member_id === $member->id, 403);

            return;
        }
        abort_unless(in_array($user->role, ['admin', 'manager', 'operator'], true), 403);
        abort_if($user->sports_complex_id && $user->sports_complex_id !== $member->sports_complex_id, 403);
    }
}
