<?php
require '/www/jcurve/rankfree/vendor/autoload.php';$app=require '/www/jcurve/rankfree/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$service=new App\Domain\Place\PlaceSharedArchive;$n=0;$skipped=0;
while(($line=fgets(STDIN))!==false){$r=json_decode($line,true,512,JSON_THROW_ON_ERROR);if($service->put($r['response'],$r['images'],$r['at'],$r['app_id']))$n++;else $skipped++;}
echo json_encode(['imported'=>$n,'skipped'=>$skipped,'shared_places'=>Illuminate\Support\Facades\DB::table('place_shared_archives')->count()])."\n";
