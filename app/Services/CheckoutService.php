<?php
namespace App\Services;
use App\Models\{Cart,Order,OrderItem,Payment,SellerOrder,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class CheckoutService {
 public function place(User $buyer,Cart $cart,array $data):Order{return DB::transaction(function()use($buyer,$cart,$data){$cart=Cart::query()->with('items.variant.product.store')->lockForUpdate()->findOrFail($cart->id);if($cart->user_id!==$buyer->id||$cart->status!=='active'||$cart->items->isEmpty())throw ValidationException::withMessages(['cart'=>'The cart is empty or unavailable.']);
  foreach($cart->items as $item){if($item->quantity>$item->variant->available_stock)throw ValidationException::withMessages(['cart'=>"Insufficient stock for {$item->variant->product->name}."]);}
  $subtotal=$cart->items->sum(fn($i)=>$i->variant->price_minor*$i->quantity);$order=Order::create(['order_no'=>'ORD-'.Str::upper(Str::random(12)),'buyer_id'=>$buyer->id,'recipient_name'=>$data['recipient_name'],'recipient_phone'=>$data['recipient_phone'],'address_line'=>$data['address_line'],'barangay'=>$data['barangay'],'city_municipality'=>$data['city_municipality'],'province'=>$data['province'],'postal_code'=>$data['postal_code']??null,'subtotal_minor'=>$subtotal,'total_minor'=>$subtotal,'status'=>'placed','payment_status'=>'unpaid','placed_at'=>now()]);
  foreach($cart->items->groupBy(fn($i)=>$i->variant->product->store_id) as $storeId=>$items){$amount=$items->sum(fn($i)=>$i->variant->price_minor*$i->quantity);$sellerOrder=SellerOrder::create(['seller_order_no'=>'SOR-'.Str::upper(Str::random(12)),'order_id'=>$order->id,'store_id'=>$storeId,'status'=>'placed','subtotal_minor'=>$amount,'total_minor'=>$amount]);foreach($items as $item){$variant=$item->variant;OrderItem::create(['seller_order_id'=>$sellerOrder->id,'product_id'=>$variant->product_id,'product_variant_id'=>$variant->id,'product_name'=>$variant->product->name,'variant_name'=>$variant->name,'sku'=>$variant->sku,'options'=>$variant->options,'unit_price_minor'=>$variant->price_minor,'quantity'=>$item->quantity,'subtotal_minor'=>$variant->price_minor*$item->quantity]);$variant->decrement('stock_on_hand',$item->quantity);}}
  Payment::create(['payment_no'=>'PAY-'.Str::upper(Str::random(12)),'order_id'=>$order->id,'method'=>$data['payment_method'],'amount_minor'=>$order->total_minor,'status'=>'pending','initiated_at'=>now()]);$cart->update(['status'=>'converted']);return $order->load(['sellerOrders.items','payments']);},3);}
}
