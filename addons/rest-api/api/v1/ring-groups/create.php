<?php
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/base.php';
validate_api_key();

$request = get_request_data();

if (empty($request['ring_group_name'])) {
    api_error('VALIDATION_ERROR', 'Ring group name is required', 'ring_group_name');
}
if (empty($request['ring_group_extension'])) {
    api_error('VALIDATION_ERROR', 'Ring group extension is required', 'ring_group_extension');
}

$database = new database;
$sql = "SELECT COUNT(*) FROM v_ring_groups WHERE domain_uuid = :domain_uuid AND ring_group_extension = :extension";
if ($database->select($sql, ['domain_uuid' => $domain_uuid, 'extension' => $request['ring_group_extension']], 'column') > 0) {
    api_conflict('ring_group_extension', 'Extension already exists');
}

$ring_group_uuid = uuid();
$dialplan_uuid = uuid();
$ring_group_context = $request['ring_group_context'] ?? $domain_name;
$ring_group_name = $request['ring_group_name'];
$ring_group_extension = $request['ring_group_extension'];
$ring_group_enabled = $request['ring_group_enabled'] ?? 'true';
$ring_group_description = $request['ring_group_description'] ?? '';
$app_uuid = '1d61fb65-1eec-bc73-a6ee-a6203b4fe6f2';

$array['ring_groups'][0]['domain_uuid'] = $domain_uuid;
$array['ring_groups'][0]['ring_group_uuid'] = $ring_group_uuid;
$array['ring_groups'][0]['dialplan_uuid'] = $dialplan_uuid;
$array['ring_groups'][0]['ring_group_name'] = $ring_group_name;
$array['ring_groups'][0]['ring_group_extension'] = $ring_group_extension;
$array['ring_groups'][0]['ring_group_context'] = $ring_group_context;
$array['ring_groups'][0]['ring_group_strategy'] = $request['ring_group_strategy'] ?? 'simultaneous';
$array['ring_groups'][0]['ring_group_timeout_app'] = $request['ring_group_timeout_app'] ?? 'transfer';
$array['ring_groups'][0]['ring_group_timeout_data'] = $request['ring_group_timeout_data'] ?? '';
$array['ring_groups'][0]['ring_group_cid_name_prefix'] = $request['ring_group_cid_name_prefix'] ?? '';
$array['ring_groups'][0]['ring_group_cid_number_prefix'] = $request['ring_group_cid_number_prefix'] ?? '';
$array['ring_groups'][0]['ring_group_enabled'] = $ring_group_enabled;
$array['ring_groups'][0]['ring_group_description'] = $ring_group_description;

if (!empty($request['destinations']) && is_array($request['destinations'])) {
    $y = 0;
    foreach ($request['destinations'] as $dest) {
        if (empty($dest['destination_number'])) {
            continue;
        }
        $array['ring_groups'][0]['ring_group_destinations'][$y]['ring_group_destination_uuid'] = uuid();
        $array['ring_groups'][0]['ring_group_destinations'][$y]['domain_uuid'] = $domain_uuid;
        $array['ring_groups'][0]['ring_group_destinations'][$y]['ring_group_uuid'] = $ring_group_uuid;
        $array['ring_groups'][0]['ring_group_destinations'][$y]['destination_number'] = $dest['destination_number'];
        $array['ring_groups'][0]['ring_group_destinations'][$y]['destination_delay'] = $dest['destination_delay'] ?? '0';
        $array['ring_groups'][0]['ring_group_destinations'][$y]['destination_timeout'] = $dest['destination_timeout'] ?? '30';
        $array['ring_groups'][0]['ring_group_destinations'][$y]['destination_prompt'] = $dest['destination_prompt'] ?? '';
        $array['ring_groups'][0]['ring_group_destinations'][$y]['destination_enabled'] = $dest['destination_enabled'] ?? 'true';
        $y++;
    }
}

$array['dialplans'][0]['domain_uuid'] = $domain_uuid;
$array['dialplans'][0]['dialplan_uuid'] = $dialplan_uuid;
$array['dialplans'][0]['dialplan_name'] = $ring_group_name;
$array['dialplans'][0]['dialplan_number'] = $ring_group_extension;
$array['dialplans'][0]['dialplan_context'] = $ring_group_context;
$array['dialplans'][0]['dialplan_continue'] = 'false';
$array['dialplans'][0]['dialplan_xml'] = api_ring_group_dialplan_xml($ring_group_name, $ring_group_extension, $dialplan_uuid, $ring_group_uuid);
$array['dialplans'][0]['dialplan_order'] = '101';
$array['dialplans'][0]['dialplan_enabled'] = $ring_group_enabled;
$array['dialplans'][0]['dialplan_description'] = $ring_group_description;
$array['dialplans'][0]['app_uuid'] = $app_uuid;

$p = permissions::new();
$p->add('ring_group_add', 'temp');
$p->add('ring_group_destination_add', 'temp');
$p->add('dialplan_add', 'temp');

$database = new database;
$database->app_name = 'ring_groups';
$database->app_uuid = $app_uuid;
$database->save($array);

$p->delete('ring_group_add', 'temp');
$p->delete('ring_group_destination_add', 'temp');
$p->delete('dialplan_add', 'temp');

api_clear_dialplan_cache($ring_group_context);
api_reloadxml();

api_created([
    'ring_group_uuid' => $ring_group_uuid,
    'dialplan_uuid' => $dialplan_uuid,
], 'Ring group created successfully');
