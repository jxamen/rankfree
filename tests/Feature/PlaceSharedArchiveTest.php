<?php
namespace Tests\Feature;
use App\Domain\Place\PlaceSharedArchive;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class PlaceSharedArchiveTest extends TestCase {
 use RefreshDatabase;
 private function headers(): array {$u=User::factory()->create(['api_scopes'=>['rank']]);[, $key]=ApiKey::issue($u,'shared',['rank'],null,null,null);return ['Authorization'=>'Bearer '.$key];}
 private function data(string $name='Shared'): array {return ['place'=>['place_id'=>'123456','name'=>$name,'menus'=>[['name'=>'Bread']],'recent_reviews'=>[['id'=>'r1'],['id'=>'r2']]],'status'=>['business'=>'ok','menus'=>'ok','recent_reviews'=>'ok'],'partial'=>false];}
 public function test_api_reuses_shared_data_without_http_and_respects_review_limit(): void {
  Http::preventStrayRequests();app(PlaceSharedArchive::class)->put($this->data(),['photos'=>['https://image.j-curve.co.kr/example.webp']],'2026-01-01T00:00:00Z',36);
  $this->getJson('/api/v1/rank/place?place=123456&cache_only=1&review_limit=1',$this->headers())->assertOk()->assertJsonPath('cache.hit',true)->assertJsonPath('place.menus.0.name','Bread')->assertJsonCount(1,'place.recent_reviews')->assertJsonCount(1,'cached_images.photos');Http::assertNothingSent();
 }
 public function test_cache_miss_and_short_url_never_fetch_in_cache_only_mode(): void {
  Http::preventStrayRequests();$h=$this->headers();$this->getJson('/api/v1/rank/place?place=123456&cache_only=1',$h)->assertNotFound();
  $this->getJson('/api/v1/rank/place?place=https%3A%2F%2Fnaver.me%2Fexample&cache_only=1',$h)->assertStatus(422);Http::assertNothingSent();
 }
 public function test_partial_and_older_import_cannot_erase_newer_success(): void {
  $s=app(PlaceSharedArchive::class);$s->put($this->data('New'),[],'2026-10-10T00:00:00Z',36);
  $s->put($this->data('Old'),[],'2026-10-01T00:00:00Z',33);
  $p=$this->data('Latest');$p['place']['menus']=[];$p['status']['menus']='blocked';$s->put($p,[],'2026-10-11T00:00:00Z',33);
  $r=$s->get('123456');$this->assertSame('Latest',$r['place']['name']);$this->assertSame('Bread',$r['place']['menus'][0]['name']);$this->assertFalse($r['partial']);$this->assertDatabaseCount('place_shared_archives',1);
 }
 public function test_refresh_fetches_and_stores_new_data(): void {
  $s=app(PlaceSharedArchive::class);$s->put($this->data(),[],'2020-01-01T00:00:00Z');Http::preventStrayRequests();
  Http::fake(['*'=>Http::response('<script>window.__APOLLO_STATE__={"PlaceDetailBase:123456":{"name":"Fresh"}};</script>')]);
  $this->getJson('/api/v1/rank/place?place=123456&refresh=1',$this->headers())->assertOk()->assertJsonPath('cache.hit',false)->assertJsonPath('place.name','Fresh');Http::assertSentCount(3);$this->assertSame('Fresh',$s->get('123456')['place']['name']);
 }
}
