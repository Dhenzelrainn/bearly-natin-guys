<?php
// Isolated checks: in-memory SQLite, array cache, fake SMTP transport, generated test images.
// Run from the project root: php tests/registration-smoke.php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->instance('env', 'testing');
config(['app.key'=>'base64:'.base64_encode(str_repeat('a',32)), 'database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:', 'cache.default'=>'array','session.driver'=>'array','email-verification.enabled'=>true]);
Illuminate\Support\Facades\DB::purge();
Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);
$request=Illuminate\Http\Request::create('/register','POST');
$session=app('session')->driver();$session->start();$request->setLaravelSession($session);
$app->instance('request',$request);
$passed=0;
function check($condition,$message){global $passed;if(!$condition)throw new RuntimeException($message);$passed++;echo "PASS: $message\n";}
function rejected($fn,$message){try{$fn();}catch(Illuminate\Validation\ValidationException $e){check(true,$message);return;}throw new RuntimeException($message);}
$phone=app(App\Services\InternationalPhone::class);
check($phone->normalize('09171234567','PH')==='+639171234567','PH national number normalized');
check($phone->normalize('4155552671','US')==='+14155552671','US national number normalized');
rejected(fn()=>$phone->normalize('+819012345678','PH'),'Wrong country rejected');
$service=app(App\Services\EmailVerificationService::class);
$mail = new class {
 public string $code=''; public bool $fail=false;
 public function mailer($name){if($name!=='smtp')throw new RuntimeException('Wrong mailer');return $this;}
 public function to($email){return $this;}
 public function send($mailable){if($this->fail)throw new RuntimeException('SMTP failure');$this->code=$mailable->code;}
};
Illuminate\Support\Facades\Mail::swap($mail);
$service->send($request,'test@example.com');
$code=$mail->code;
rejected(fn()=>$service->check($request,'test@example.com','000000'),'Wrong OTP rejected');
$service->check($request,'test@example.com',$code);
check((bool)$service->verifiedAt($request,'test@example.com'),'Correct OTP creates proof');
rejected(fn()=>$service->verifiedAt($request,'other@example.com'),'Proof bound to email');
$oldId=$session->getId();$session->setId(str_repeat('x',40));
rejected(fn()=>$service->verifiedAt($request,'test@example.com'),'Proof bound to session');
$session->setId($oldId);
try{$service->send($request,'test@example.com');throw new RuntimeException('Cooldown missing');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){check($e->getStatusCode()===429,'Resend cooldown enforced');}
$state=Illuminate\Support\Facades\Cache::get($service->key($request));$state['verified_until']=time()-1;Illuminate\Support\Facades\Cache::put($service->key($request),$state,100);
rejected(fn()=>$service->verifiedAt($request,'test@example.com'),'Expired proof rejected');
Illuminate\Support\Facades\Cache::flush();
$service->send($request,'test@example.com');$code=$mail->code;
for($i=0;$i<5;$i++){try{$service->check($request,'test@example.com','000000');}catch(Illuminate\Validation\ValidationException){}}
rejected(fn()=>$service->check($request,'test@example.com',$code),'Attempt limit blocks correct code after five failures');
config(['email-verification.enabled'=>false]);rejected(fn()=>$service->send($request,'test@example.com'),'Disabled SMTP fails closed');config(['email-verification.enabled'=>true]);
$postal=app(App\Services\PostalCodeLookup::class);check($postal->find('0403422000')['options'][0]['code']==='4010','Pila postal code is 4010');
check(!$postal->find(null,'Unknown','Pila')['options'],'Unknown province does not match another Pila');
Illuminate\Support\Facades\Cache::flush();
$mail->fail=true;
rejected(fn()=>$service->send($request,'test@example.com'),'SMTP failure reports error');
check(!Illuminate\Support\Facades\Cache::has($service->key($request)), 'SMTP failure creates no OTP proof');
$mail->fail=false;
Illuminate\Support\Facades\Cache::flush();
$result=$service->send($request,'test@example.com');
check(!isset($result['code']),'Send response contains no OTP');
$state=Illuminate\Support\Facades\Cache::get($service->key($request));
check($state['code_hash'] !== $mail->code, 'Cached code is hashed');
$service->check($request,'test@example.com',$mail->code);
check(str_contains(view('emails.registration-code',['code'=>'123456'])->render(),'123456'),'Email template renders code');
// Exercise registration controller with real validation, storage and a temporary DB.
$data=['role'=>'buyer','first_name'=>'Test','last_name'=>'Buyer','middle_initial'=>'r','sex'=>'prefer_not_to_say','birthday'=>'2000-01-01','email'=>'test@example.com','contact_number'=>'09171234567','phone_country'=>'PH','province'=>'Laguna','city'=>'Pila','city_code'=>'0403422000','barangay'=>'Bulilan Norte','street_name'=>'Test Street','house_number'=>'1','postal_code'=>'4010','password'=>'Testing123','password_confirmation'=>'Testing123','terms'=>'1'];
$files=['valid_id'=>Illuminate\Http\UploadedFile::fake()->image('id.jpg')];
$reg=Illuminate\Http\Request::create('/register','POST',$data,[],$files);$reg->setLaravelSession($session);
$controller=app(App\Http\Controllers\auth\BearlyAuthController::class);
$response=$controller->register($reg);$user=App\Models\User::first();
check($user && $user->status==='pending' && $user->middle_initial==='R.' && $user->email_verified_at && !$user->phone_verified_at && $user->contact_number==='+639171234567','Verified buyer saved pending with normalized phone and initial');
Illuminate\Support\Facades\Storage::disk('local')->delete($user->valid_id_path);
check(!Illuminate\Support\Facades\Cache::has($service->key($reg)),'Successful registration consumes proof');
$data['email']='second@example.com';$reg=Illuminate\Http\Request::create('/register','POST',$data,[],$files);$reg->setLaravelSession($session);
rejected(fn()=>$controller->register($reg),'Registration cannot bypass email proof');
$data['role']='seller';$data['business_name']='Test Store';$data['business_category']='Books and Media';$files['business_permit']=Illuminate\Http\UploadedFile::fake()->image('permit.jpg');
$reg=Illuminate\Http\Request::create('/register','POST',$data,[],$files);$reg->setLaravelSession($session);
$controller->register($reg);$seller=App\Models\User::where('role','seller')->first();
check($seller && !$seller->phone_verified_at,'Seller registration does not require buyer OTP');
Illuminate\Support\Facades\Storage::disk('local')->delete([$seller->valid_id_path,$seller->business_permit_path]);
view()->share('errors',new Illuminate\Support\ViewErrorBag());
$session->put('marketplace_application',['role'=>'buyer','name'=>'Alex R. Cruz','status'=>'pending']);
$html=view('auth.pending')->render();check(substr_count($html,'Return to sign in')===1,'Buyer receipt has one sign-in action');
check(str_contains(view('auth.register')->render(),'data-email-verification'),'Registration form renders');
check(str_contains(view('legal.privacy')->render(),'Privacy Policy'),'Privacy page renders');
check(str_contains(view('legal.terms')->render(),'Terms of Service'),'Terms page renders');
$session->put('marketplace_application',['role'=>'seller','name'=>'Test Seller','status'=>'pending']);
$html=view('auth.pending')->render();check(!str_contains($html,'class="buyer-pending"'),'Seller receipt remains separate');
echo "TOTAL: $passed checks passed\n";
