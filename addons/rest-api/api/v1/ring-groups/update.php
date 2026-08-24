<?php
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/base.php';
validate_api_key();

$ring_group_uuid = get_uuid_from_path();
api_validate_uuid($ring_group_uuid, 'ring_group_uuid');

$database = new database;
$sql = "SELECT ring_group_name, ring_group_extension, ring_group_context, ring_group_strategy,
        ring_group_timeout_app, ring_group_timeout_data, ring_group_cid_name_prefix,
        ring_group_cid_number_prefix, ring_group_enabled, ring_group_description, dialplan_uuid
        FROM v_ring_groups WHERE domain_uuid = :domain_uuid AND ring_group_uuid = :ring_group_uuid";
$existing = $database->select($sql, ['domain_uuid' => $domain_uuid, 'ring_group_uuid' => $ring_group_uuid], 'row');

if (empty($existing)) {
    api_not_found('Ring group');
}

$request = get_request_data();
$app_uuid = '1d61fb65-1eec-bc73-a6ee-a6203b4fe6f2';

$dialplan_uuid = $existing['dialplan_uuid'];
$dialplan_is_new = false;
if (empty($dialplan_uuid) || !is_uuid($dialplan_uuid)) {
    $dialplan_uuid = uuid();
    $dialplan_is_new = true;
}

$ring_group_name = $request['ring_group_name'] ?? $existing['ring_group_name'];
$ring_group_extension = $request['ring_group_extension'] ?? $existing['ring_group_extension'];
$ring_group_context = $request['ring_group_context'] ?? $existing['ring_group_context'] ?? $domain_name;
$ring_group_enabled = $request['ring_group_enabled'] ?? $existing['ring_group_enabled'] ?? 'true';
$ring_group_description = $request['ring_group_description'] ?? $existing['ring_group_description'] ?? '';

if (isset($request['ring_group_extension']) && $request['ring_group_extension'] !== $existing['ring_group_extension']) {
    $check_sql = "SELECT COUNT(*) FROM v_ring_groups WHERE domain_uuid = :domain_uuid AND ring_group_extension = :extension AND ring_group_uuid != :ring_group_uuid";
    if ($database->select($check_sql, ['domain_uuid' => $domain_uuid, 'extension' => $request['ring_group_extension'], 'ring_group_uuid' => $ring_group_uuid], 'column') > 0) {
        api_conflict('ring_group_extension', 'Extension already exists');
    }
}

$array['ring_groups'][0]['ring_group_uuid'] = $ring_group_uuid;
$array['ring_groups'][0]['domain_uuid'] = $domain_uuid;
$array['ring_groups'][0]['dialplan_uuid'] = $dialplan_uuid;
$array['ring_groups'][0]['ring_group_name'] = $ring_group_name;
$array['ring_groups'][0]['ring_group_extension'] = $ring_group_extension;
$array['ring_groups'][0]['ring_group_context'] = $ring_group_context;
$array['ring_groups'][0]['ring_group_enabled'] = $ring_group_enabled;
$array['ring_groups'][0]['ring_group_description'] = $ring_group_description;
if (isset($request['ring_group_strategy'])) {
    $array['ring_groups'][0]['ring_group_strategy'] = $request['ring_group_strategy'];
}
if (isset($request['ring_group_timeout_app'])) {
    $array['ring_groups'][0]['ring_group_timeout_app'] = $request['ring_group_timeout_app'];
}
if (isset($request['ring_group_timeout_data'])) {
    $array['ring_groups'][0]['ring_group_timeout_data'] = $request['ring_group_timeout_data'];
}
if (isset($request['ring_group_cid_name_prefix'])) {
    $array['ring_groups'][0]['ring_group_cid_name_prefix'] = $request['ring_group_cid_name_prefix'];
}
if (isset($request['ring_group_cid_number_prefix'])) {
    $array['ring_groups'][0]['ring_group_cid_number_prefix'] = $request['ring_group_cid_number_prefix'];
}

if (isset($request['destinations']) && is_array($request['destinations'])) {
    $p_del = permissions::new();
    $p_del->add('ring_group_destination_delete', 'temp');
    $delete_array['ring_group_destinations'][0]['domain_uuid'] = $domain_uuid;
    $delete_array['ring_group_destinations'][0]['ring_group_uuid'] = $ring_group_uuid;
    $database->delete($delete_array);
    unset($delete_array);
    $p_del->delete('ring_group_destination_delete', 'temp');

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
$p->add('ring_group_edit', 'temp');
$p->add('ring_group_destination_add', 'temp');
$p->add('dialplan_edit', 'temp');
if ($dialplan_is_new) {
    $p->add('dialplan_add', 'temp');
}

$database = new database;
$database->app_name = 'ring_groups';
$database->app_uuid = $app_uuid;
$database->save($array);

$p->delete('ring_group_edit', 'temp');
$p->delete('ring_group_destination_add', 'temp');
$p->delete('dialplan_edit', 'temp');
$p->delete('dialplan_add', 'temp');

api_clear_dialplan_cache($ring_group_context);
api_reloadxml();

api_success([
    'ring_group_uuid' => $ring_group_uuid,
    'dialplan_uuid' => $dialplan_uuid,
], 'Ring group updated successfully');
