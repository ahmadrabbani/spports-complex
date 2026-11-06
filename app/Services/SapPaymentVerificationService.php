<?php

namespace App\Services;

use App\Models\Challan;
use App\Models\PaymentVerification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SapPaymentVerificationService
{
    public function verify(Challan $challan, ?int $actor = null): string
    {
        $lock = Cache::lock('sap-verify:'.$challan->id, max(120, (int) config('services.sap.timeout', 30) + 60));
        abort_unless($lock->get(), 409, 'Verification is already in progress for this challan.');
        try {
            return $this->verifyLocked($challan->fresh(), $actor);
        } finally {
            $lock->release();
        }
    }

    private function verifyLocked(Challan $challan, ?int $actor): string
    {
        abort_if(in_array($challan->status, ['cancelled', 'superseded'], true), 409, 'Reconcile the original challan in SAP; it cannot settle a replacement.');
        $recent = PaymentVerification::where('challan_id', $challan->id)->where('provider', 'sap')->where('created_at', '>=', now()->subSeconds(30))->latest('id')->first();
        if ($recent) {
            return $recent->verification_result;
        }
        abort_unless($challan->environment === 'live', 409, 'SAP verification is restricted to real SAP challans.');
        abort_unless(preg_match('/^\d{6,30}$/D', (string) $challan->sap_document_no), 422, 'A numeric SAP challan number is required.');
        $number = $challan->sap_verification_number;
        if (! $number) {
            $document = (string) $challan->sap_document_no;
            $number = strlen($document) === 18 ? $document : SapChallanNumber::make($document, (string) $challan->company_code, $challan->issued_on->format('Y'));
            $challan->update(['sap_verification_number' => $number]);
        }
        abort_unless(preg_match('/^\d{18}$/D', $number), 422, 'An 18-digit SAP verification number is required.');
        $body = [];
        $code = null;
        $result = 'verification_error';
        try {
            $response = Http::connectTimeout(config('services.sap.connect_timeout'))->timeout(config('services.sap.timeout'))->get(config('services.sap.verification_url'), [config('services.sap.verification_parameter', 'challan') => $number]);
            $code = $response->status();
            $body = ['raw' => mb_substr($response->body(), 0, 50000)];
            // No authoritative response contract has been supplied. Unknown formats fail closed.
            $result = $response->successful() ? 'verification_unknown' : 'verification_error';
        } catch (ConnectionException $e) {
            $body = ['error' => 'SAP connection unavailable'];
        }
        PaymentVerification::create(['challan_id' => $challan->id, 'provider' => 'sap', 'verification_result' => $result, 'response_code' => $code, 'response_payload' => $body, 'created_by' => $actor]);

        return $result;
    }
}
