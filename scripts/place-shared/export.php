<?php
require '/www/jcurve/jcurve-api/vendor/autoload.php';
$app=require '/www/jcurve/jcurve-api/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$since=$argv[1]??'1970-01-01 00:00:00';
foreach(App\Models\ManagedApp::whereIn('slug',['dessertnow','kfoodmap'])->get(['id','slug','db_name']) as $a){
 App\Support\AppDb::on($a,function()use($a,$since){
  if(!Illuminate\Support\Facades\Schema::hasTable('sm_place_details'))return;
  Illuminate\Support\Facades\DB::table('sm_place_details')->where('app_id',$a->id)->where('updated_at','>=',$since)->orderBy('id')->chunkById(100,function($rows)use($a){foreach($rows as $r){
   $response=json_decode($r->last_response,true)?:[];$response['place']=json_decode($r->payload,true)?:[];
   echo json_encode(['response'=>$response,'images'=>json_decode($r->cached_images,true)?:[],'at'=>$r->updated_at,'app_id'=>$a->id],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
  }});
 });
}
