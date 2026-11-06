<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
class SapProxyErrorUrlTest extends TestCase {
 public function test_invalid_data_response_includes_exact_internal_url(): void {
  require_once __DIR__.'/../../integrations/sapchallan/index.php';
  $url='http://172.16.70.8:8080/SAPVERIFICATION/Challan?fname=Synthetic%20Applicant&center=0021070001';
  $this->assertSame(['success'=>false,'error'=>'SAP rejected the request: Invalid Data','sap_url'=>$url],sportsSapErrorPayload('SAP rejected the request: Invalid Data',$url));
  $this->assertArrayNotHasKey('sap_url',sportsSapErrorPayload('Missing parameter',null));
 }
}
