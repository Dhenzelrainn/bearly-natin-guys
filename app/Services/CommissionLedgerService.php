<?php
namespace App\Services;
use App\Models\{Payment,PlatformCommission,SellerTransaction,SystemSetting};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class CommissionLedgerService {
 public function finalize(Payment $payment):void { DB::transaction(function()use($payment){$payment=Payment::query()->with('order.sellerOrders.store.sellerProfile')->lockForUpdate()->findOrFail($payment->id); abort_unless($payment->status==='paid',422,'Only paid payments can be commissioned.');
  $rate=(float)(SystemSetting::where('key','commission_rate')->first()?->value??10);$bps=(int)round($rate*100);
  foreach($payment->order->sellerOrders as $sellerOrder){$commission=PlatformCommission::firstOrCreate(['seller_order_id'=>$sellerOrder->id,'refund_id'=>null,'type'=>'sale'],['base_amount_minor'=>$sellerOrder->total_minor,'rate_bps'=>$bps,'amount_minor'=>(int)round($sellerOrder->total_minor*$bps/10000),'status'=>'finalized','calculated_at'=>now(),'finalized_at'=>now()]);
   SellerTransaction::firstOrCreate(['seller_order_id'=>$sellerOrder->id,'payment_id'=>$payment->id,'type'=>'sale'],['transaction_no'=>'STX-'.Str::upper(Str::random(12)),'seller_profile_id'=>$sellerOrder->store->seller_profile_id,'commission_id'=>$commission->id,'amount_minor'=>$sellerOrder->total_minor-$commission->amount_minor,'status'=>'posted','available_at'=>now(),'posted_at'=>now(),'description'=>'Net proceeds for '.$sellerOrder->seller_order_no]); }
 },3); }
}
