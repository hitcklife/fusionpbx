<?php
require_once __DIR__ . '/../../base.php';
validate_api_key();
api_require_method('DELETE');

$queue_uuid = get_uuid_from_path();
api_validate_uuid($queue_uuid, 'call_center_queue_uuid');

$database = new database;
$sql = "SELECT call_center_queue_uuid, dialplan_uuid, queue_context FROM v_call_center_queues
        WHERE domain_uuid = :domain_uuid AND call_center_queue_uuid = :queue_uuid";
$existing = $database->select($sql, [
    'domain_uuid' => $domain_uuid,
    'queue_uuid' => $queue_uuid,
], 'row');
if (empty($existing)) {
    api_not_found('Call Center Queue');
}

$app_uuid = '95788e50-9500-079e-2807-fd530b0ea370';

$array['call_center_tiers'][0]['domain_uuid'] = $domain_uuid;
$array['call_center_tiers'][0]['call_center_queue_uuid'] = $queue_uuid;
$array['call_center_queues'][0]['domain_uuid'] = $domain_uuid;
$array['call_center_queues'][0]['call_center_queue_uuid'] = $queue_uuid;
if (!empty($existing['dialplan_uuid']) && is_uuid($existing['dialplan_uuid'])) {
    $array['dialplans'][0]['dialplan_uuid'] = $existing['dialplan_uuid'];
}

$p = permissions::new();
$p->add('call_center_queue_delete', 'temp');
$p->add('call_center_tier_delete', 'temp');
$p->add('dialplan_delete', 'temp');

$database->app_name = 'call_centers';
$database->app_uuid = $app_uuid;
$database->delete($array);

$p->delete('call_center_queue_delete', 'temp');
$p->delete('call_center_tier_delete', 'temp');
$p->delete('dialplan_delete', 'temp');

if (function_exists('save_call_center_xml')) {
    save_call_center_xml();
}
$cache = new cache;
$cache->delete(gethostname() . ':' . 'configuration:callcenter.conf');
$cache->delete('configuration:callcenter.conf');
api_clear_dialplan_cache($existing['queue_context'] ?? $domain_name);
api_reloadxml();

api_no_content();
