<?php
namespace App\Http\Controllers;
use App\Models\{Shipment,ShipmentEvent};
use App\Services\AuditService;
use Illuminate\Http\{RedirectResponse,Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
class AdminFulfillmentController extends Controller {
 public function index(Request $request):View{$shipments=Shipment::with(['sellerOrder.order.buyer','sellerOrder.store','waybill.parcels','events'])->latest()->paginate(15);$admin=$request->user();return view('admin.fulfillment.index',['admin'=>['name'=>$admin->name,'role'=>'Admin','email'=>$admin->email,'initials'=>collect(explode(' ',$admin->name))->map(fn($x)=>strtoupper(substr($x,0,1)))->take(2)->implode('')],'topNotifications'=>[],'shipments'=>$shipments,'metrics'=>['pending_pickup'=>Shipment::whereIn('status',['pending_waybill','ready_for_pickup'])->count(),'intake'=>Shipment::where('status','at_sorting_center')->count(),'transit'=>Shipment::whereIn('status',['in_transit','out_for_delivery'])->count(),'delivered'=>Shipment::where('status','delivered')->count(),'failed'=>Shipment::whereIn('status',['delivery_failed','exception'])->count()]]);}
 public function override(Request $request,Shipment $shipment,AuditService $audit):RedirectResponse{$data=$request->validate(['status'=>['required',Rule::in(['pending_waybill','ready_for_pickup','at_sorting_center','in_transit','out_for_delivery','delivered','delivery_failed','exception'])],'note'=>['required','string','max:1000']]);DB::transaction(function()use($request,$shipment,$data,$audit){$old=$shipment->status;$shipment->update(['status'=>$data['status'],'delivered_at'=>$data['status']==='delivered'?now():$shipment->delivered_at]);ShipmentEvent::create(['shipment_id'=>$shipment->id,'event_type'=>'admin_override','from_status'=>$old,'to_status'=>$data['status'],'actor_user_id'=>$request->user()->id,'source'=>'admin','notes'=>$data['note'],'occurred_at'=>now()]);$audit->record('shipment.status_overridden','fulfillment',$shipment,'Administrator overrode shipment status.',['status'=>$old],['status'=>$data['status'],'note'=>$data['note']],'warning',$request);},3);return back()->with('success','Shipment status updated.');}
}
