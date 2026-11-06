<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  DB::transaction(function(){
   foreach(DB::table('sports_complexes')->get() as $row){
    $updates=[];
    foreach(['cost_center'=>10,'profit_center'=>9] as $field=>$width){
     $value=trim((string)$row->$field);
     if($value!=='' && ctype_digit($value) && strlen($value)<=$width)$updates[$field]=str_pad($value,$width,'0',STR_PAD_LEFT);
    }
    if($updates)DB::table('sports_complexes')->where('id',$row->id)->update($updates);
   }
  });
 }
 public function down(): void { /* Preserve significant leading zeros; restore from backup if necessary. */ }
};
