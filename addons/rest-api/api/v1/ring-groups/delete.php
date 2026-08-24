<?php
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/base.php';
validate_api_key();

$ring_group_uuid = get_uuid_from_path();
api_validate_uuid($ring_group_uuid, 'ring_group_uuid');

$database = new database;
$sql = "SELECT ring_group_uuid, dialplan_uuid, ring_group_context FROM v_ring_groups
        WHERE domain_uuid = :domain_uuid AND ring_group_uuid = :ring_group_uuid";
$existing = $database->select($sql, ['domain_uuid' => $domain_uuid, 'ring_group_uuid' => $ring_group_uuid], 'row');
if (empty($existing)) {
    api_not_found('Ring group');
}

$array['ring_group_destinations'][0]['domain_uuid'] = $domain_uuid;
$array['ring_group_destinations'][0]['ring_group_uuid'] = $ring_group_uuid;

$array['ring_groups'][0]['domain_uuid'] = $domain_uuid;
$array['ring_groups'][0]['ring_group_uuid'] = $ring_group_uuid;

if (!empty($existing['dialplan_uuid']) && is_uuid($existing['dialplan_uuid'])) {
    $array['dialplans'][0]['dialplan_uuid'] = $existing['dialplan_uuid'];
}

$p = permissions::new();
$p->add('ring_group_delete', 'temp');
$p->add('ring_group_destination_delete', 'temp');
$p->add('dialplan_delete', 'temp');

$database = new database;
$database->app_name = 'ring_groups';
$database->app_uuid = '1d61fb65-1eec-bc73-a6ee-a6203b4fe6f2';
$database->delete($array);

$p->delete('ring_group_delete', 'temp');
$p->delete('ring_group_destination_delete', 'temp');
$p->delete('dialplan_delete', 'temp');

api_clear_dialplan_cache($existing['ring_group_context'] ?? $domain_name);
api_reloadxml();

api_no_content();
