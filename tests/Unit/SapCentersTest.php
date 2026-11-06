<?php
namespace Tests\Unit;
use App\Models\SportsComplex;
use PHPUnit\Framework\TestCase;
class SapCentersTest extends TestCase {
 public function test_center_widths_preserve_supplied_zeroes(): void {
  $c=new SportsComplex(['cost_center'=>'0011050020','profit_center'=>'001105001']);
  $this->assertSame('0011050020',$c->cost_center);$this->assertSame('001105001',$c->profit_center);$this->assertTrue($c->hasSapCenters());
  $c->cost_center='21070001';$c->profit_center='21070001';
  $this->assertSame('0021070001',$c->cost_center);$this->assertSame('021070001',$c->profit_center);
  $c->profit_center='1234567890';$this->assertFalse($c->hasSapCenters());
 }
}
