<?php
namespace App\Domain\Place;

use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

/** Public place data only; app-specific verification, rankings and tags stay in each app. */
class PlaceSharedArchive
{
    public function put(array $response, array $images = [], ?string $at = null, ?int $sourceApp = null): bool
    {
        $place = $response['place'] ?? [];
        $id = (string) ($place['place_id'] ?? '');
        if (!preg_match('/^\d{5,30}$/', $id) || empty($place['name']) || ($response['status']['business'] ?? '') !== 'ok') return false;
        $time = CarbonImmutable::parse($at ?? $response['fetched_at'] ?? 'now', 'UTC')->utc()->format('Y-m-d H:i:s');
        DB::transaction(function () use ($id, $response, $place, $images, $time, $sourceApp) {
            DB::table('place_shared_archives')->insertOrIgnore(['place_id'=>$id,'response'=>'{}','cached_images'=>'{}','section_times'=>'{}','created_at'=>now(),'updated_at'=>now()]);
            $row = DB::table('place_shared_archives')->where('place_id',$id)->lockForUpdate()->first();
            $saved = json_decode($row->response,true) ?: ['place'=>['place_id'=>$id],'status'=>[]];
            $times = json_decode($row->section_times,true) ?: [];
            $fields = ['place_id','place_url','name','category','phone','description','business_hours','conveniences','payment_info','transportation','additional_info','links','images','location','review_count','blog_review_count','menus','recent_reviews'];
            foreach (['business','menus','recent_reviews'] as $section) {
                if (($response['status'][$section] ?? '') !== 'ok' || ($times[$section] ?? '') > $time) continue;
                foreach ($place as $key=>$value) {
                    $owner = in_array($key,['menus','recent_reviews'],true) ? $key : 'business';
                    if ($owner === $section && in_array($key,$fields,true)) $saved['place'][$key] = $value;
                }
                $saved['status'][$section] = 'ok';
                $times[$section] = $time;
            }
            foreach (['business','menus','recent_reviews'] as $section) $saved['status'][$section] ??= 'unavailable';
            $saved['partial'] = in_array('unavailable',$saved['status'],true);
            $saved['fetched_at'] = CarbonImmutable::parse(max($times),'UTC')->toIso8601String();
            $cached = json_decode($row->cached_images,true) ?: [];
            $cached['photo_url'] = $cached['photo_url'] ?? $images['photo_url'] ?? null;
            $cached['photos'] = array_values(array_unique(array_merge($cached['photos'] ?? [],$images['photos'] ?? [])));
            $json = fn($x)=>json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            DB::table('place_shared_archives')->where('place_id',$id)->update(['response'=>$json($saved),'cached_images'=>$json($cached),'section_times'=>$json($times),'source_app_id'=>$sourceApp ?? $row->source_app_id,'updated_at'=>now()]);
        },3);
        return true;
    }

    public function get(string $id, int $reviewLimit = 10): ?array
    {
        $row = DB::table('place_shared_archives')->where('place_id',$id)->first();
        if (!$row) return null;
        $result = json_decode($row->response,true);
        if (empty($result['place']['name'])) return null;
        $result['place']['recent_reviews'] = array_slice($result['place']['recent_reviews'] ?? [],0,$reviewLimit);
        $times = json_decode($row->section_times,true) ?: [];
        $age = max(0,time()-CarbonImmutable::parse(min($times),'UTC')->timestamp);
        $result['cached_images'] = json_decode($row->cached_images,true) ?: [];
        $result['cache'] = ['hit'=>true,'source'=>'shared_archive','collected_at'=>$result['fetched_at'],'section_collected_at'=>$times,'age_seconds'=>$age,'stale'=>$age>86400];
        return $result;
    }
}
