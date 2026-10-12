<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {Schema::create('place_shared_archives',function(Blueprint $t){
  $t->string('place_id',30)->primary();$t->json('response');$t->json('cached_images');$t->json('section_times');
  $t->unsignedBigInteger('source_app_id')->nullable();$t->timestamps();
 });}
 public function down(): void {Schema::dropIfExists('place_shared_archives');}
};
