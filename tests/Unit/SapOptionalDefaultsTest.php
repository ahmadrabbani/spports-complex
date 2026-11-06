<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
class SapOptionalDefaultsTest extends TestCase {
 private function parameters(array $extra=[]): array {
  require_once __DIR__.'/../../integrations/sapchallan/optional-defaults/index.php';
  return sportsSapDefaultParameters(array_merge(['fname'=>'Synthetic Applicant','lname'=>'Synthetic Father','cnic'=>'3520200000000','amount'=>'100'], $extra));
 }
 public function test_omitted_parameters_keep_the_exact_original_defaults(): void {
  $p=$this->parameters();
  foreach(['company_code'=>'1000','glSAP'=>'0000610338','reacon'=>'260100','center'=>'0011050015','pcenter'=>'001105001','file'=>'DAM-BALLOT-APPL-2024','baseline'=>'2026-11-18'] as $key=>$value)$this->assertSame($value,$p[$key]);
 }
 public function test_supplied_values_override_defaults_without_losing_zeroes(): void {
  $values=['company_code'=>'2000','glSAP'=>'0000610400,0000610402','amount'=>'100,50','reacon'=>'260110','center'=>'0021040001','pcenter'=>'002104001','file'=>'Ind#208'];
  $p=$this->parameters($values);foreach($values as $key=>$value)$this->assertSame($value,$p[$key]);
  $url='http://172.16.70.8:8080/SAPVERIFICATION/Challan?'.http_build_query($p,'','&',PHP_QUERY_RFC3986);
  $this->assertStringContainsString('company_code=2000',$url);$this->assertStringContainsString('file=Ind%23208',$url);
 }
 public function test_invalid_company_array_is_rejected(): void {$this->expectException(\InvalidArgumentException::class);$this->parameters(['company_code'=>['2000']]);}
 public function test_empty_explicit_override_is_rejected(): void {$this->expectException(\InvalidArgumentException::class);$this->parameters(['glSAP'=>'']);}
}
