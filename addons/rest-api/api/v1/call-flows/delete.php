<?php
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/base.php';
validate_api_key();

$call_flow_uuid = get_uuid_from_path();
if (empty($call_flow_uuid)) {
    api_error('MISSING_UUID', 'Call flow UUID is required');
}

// Fetch dialplan_uuid and call_flow_context before deletion
$database = new database;
$sql = "SELECT call_flow_uuid, dialplan_uuid, call_flow_context FROM v_call_flows WHERE domain_uuid = :domain_uuid AND call_flow_uuid = :call_flow_uuid";
$existing = $database->select($sql, ['domain_uuid' => $domain_uuid, 'call_flow_uuid' => $call_flow_uuid], 'row');

if (empty($existing)) {
    api_not_found('Call flow');
}

$dialplan_uuid = $existing['dialplan_uuid'];
$call_flow_context = $existing['call_flow_context'] ?? $domain_name;

// Build delete array for all three tables
$array['call_flows'][0]['domain_uuid'] = $domain_uuid;
$array['call_flows'][0]['call_flow_uuid'] = $call_flow_uuid;

if (!empty($dialplan_uuid)) {
    $array['dialplans'][0]['domain_uuid'] = $domain_uuid;
    $array['dialplans'][0]['dialplan_uuid'] = $dialplan_uuid;

    $array['dialplan_details'][0]['domain_uuid'] = $domain_uuid;
    $array['dialplan_details'][0]['dialplan_uuid'] = $dialplan_uuid;
}

// Grant permissions
$p = permissions::new();
$p->add('call_flow_delete', 'temp');
$p->add('dialplan_delete', 'temp');
$p->add('dialplan_detail_delete', 'temp');

$database = new database;
$database->app_name = 'call_flows';
$database->app_uuid = 'b1b70f85-6b42-429b-8c5a-60c8b02b7d14';
$database->delete($array);

// Revoke permissions
$p->delete('call_flow_delete', 'temp');
$p->delete('dialplan_delete', 'temp');
$p->delete('dialplan_detail_delete', 'temp');

// Clear the cache using the call flow's actual context
api_clear_dialplan_cache($call_flow_context);

api_no_content();
