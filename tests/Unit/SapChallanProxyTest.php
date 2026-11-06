<?php

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../integrations/sapchallan/index.php';

class SapChallanProxyTest extends TestCase
{
    private function payload(): array
    {
        return ['fname' => 'Synthetic', 'lname' => 'Applicant', 'address' => 'A+B %20 Road', 'phone' => '03000000000', 'cnic' => '3520200000000', 'amount' => '100.00,25.50', 'desc' => 'Monthly Fee,Card Fee', 'area' => '1', 'type' => '1', 'glSAP' => '0000610400,0000610338', 'reacon' => '260108', 'center' => '0021010003', 'pcenter' => '002101003', 'file' => 'QA-JT-001'];
    }

    public function test_forwards_dynamic_fields_without_double_decoding_or_removing_spaces(): void
    {
        $input = $this->payload();
        $result = sportsSapParameters($input + ['url' => 'http://untrusted.test']);
        $this->assertSame($input, $result);
        parse_str(http_build_query($result, '', '&', PHP_QUERY_RFC3986), $decoded);
        $this->assertSame($input, $decoded);
    }

    public function test_missing_mapping_is_rejected_instead_of_using_a_hardcoded_default(): void
    {
        $input = $this->payload();
        unset($input['center']);
        $this->expectException(InvalidArgumentException::class);
        sportsSapParameters($input);
    }

    public function test_mismatched_fee_lines_are_rejected(): void
    {
        $input = $this->payload();
        $input['glSAP'] = '0000610400';
        $this->expectException(InvalidArgumentException::class);
        sportsSapParameters($input);
    }

    public function test_array_injection_is_rejected(): void
    {
        $input = $this->payload();
        $input['fname'] = ['unexpected'];
        $this->expectException(InvalidArgumentException::class);
        sportsSapParameters($input);
    }

    public function test_only_valid_upstream_success_is_accepted(): void
    {
        $this->assertSame('1700160703', sportsSapDocument('{"challan_no":"1700160703"}'));
        $this->assertSame('170016070320002026', sportsSapDocument('{"challan_no":"170016070320002026"}'));
        foreach (['', 'error', '<html>error</html>', '{}', '{"challan_no":"error"}', '{"success":false,"challan_no":"1700160703"}'] as $body) {
            $this->assertNull(sportsSapDocument($body));
        }
    }
}
