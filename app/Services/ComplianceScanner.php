<?php
namespace App\Services;
use App\Models\{ComplianceRule,Product,ProductComplianceCheck,ProductComplianceCheckMatch,ProductViolation};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class ComplianceScanner {
 public function scan(Product $product,string $trigger='create'):ProductComplianceCheck { return DB::transaction(function()use($product,$trigger){
  $check=ProductComplianceCheck::create(['product_id'=>$product->id,'trigger'=>$trigger,'status'=>'running','name_snapshot'=>$product->name,'description_snapshot'=>$product->description,'category_id_snapshot'=>$product->category_id,'started_at'=>now()]);
  $text=Str::lower($product->name.' '.$product->description); $result='clear';
  foreach(ComplianceRule::where('is_active',true)->get() as $rule){ $matched=false;$excerpt=null;
   if(in_array($rule->rule_type,['keyword','phrase'],true)&&$rule->pattern){foreach(array_filter(array_map('trim',explode('|',Str::lower($rule->pattern)))) as $term){if(Str::contains($text,$term)){$matched=true;$excerpt=$term;break;}}}
   if($rule->rule_type==='restricted_category'&&$rule->category_id===$product->category_id)$matched=true;
   if($rule->rule_type==='category_mismatch'&&$product->store?->sellerProfile?->approved_category_id&&$product->store->sellerProfile->approved_category_id!==$product->category_id)$matched=true;
   if(!$matched)continue;
   ProductComplianceCheckMatch::create(['check_id'=>$check->id,'rule_id'=>$rule->id,'matched_field'=>$rule->target_field,'matched_excerpt'=>$excerpt,'severity'=>$rule->severity,'recommended_action'=>$rule->default_action]);
   if(in_array($rule->default_action,['flag','hold','block'],true)){ProductViolation::create(['violation_no'=>'VIO-'.Str::upper(Str::random(10)),'product_id'=>$product->id,'seller_profile_id'=>$product->store->seller_profile_id,'check_id'=>$check->id,'rule_id'=>$rule->id,'source'=>'automated','violation_type'=>$rule->code,'severity'=>$rule->severity,'reason'=>$rule->name,'detected_excerpt'=>$excerpt,'status'=>'flagged']);$result=$rule->default_action==='block'?'blocked':'review_required';}
  }
  $check->update(['status'=>'completed','result'=>$result,'completed_at'=>now()]);
  $product->update(['compliance_status'=>$result==='clear'?'clear':($result==='blocked'?'blocked':'flagged'),'product_status'=>$result==='blocked'?'hidden':$product->product_status]); return $check->load('matches');
 }); }
}
