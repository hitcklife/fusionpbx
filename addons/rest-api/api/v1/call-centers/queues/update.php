<?php
require_once __DIR__ . '/../../base.php';
validate_api_key();
api_require_method('PUT');

$queue_uuid = get_uuid_from_path();
api_validate_uuid($queue_uuid, 'call_center_queue_uuid');

$database = new database;
$sql = "SELECT * FROM v_call_center_queues
        WHERE domain_uuid = :domain_uuid AND call_center_queue_uuid = :queue_uuid";
$existing = $database->select($sql, [
    'domain_uuid' => $domain_uuid,
    'queue_uuid' => $queue_uuid,
], 'row');

if (empty($existing)) {
    api_not_found('Call Center Queue');
}

$data = get_request_data();
$app_uuid = '95788e50-9500-079e-2807-fd530b0ea370';

if (isset($data['queue_extension']) && $data['queue_extension'] !== $existing['queue_extension']) {
    $exists_sql = "SELECT COUNT(*) FROM v_call_center_queues
                   WHERE domain_uuid = :domain_uuid
                   AND queue_extension = :queue_extension
                   AND call_center_queue_uuid != :queue_uuid";
    $exists = $database->select($exists_sql, [
        'domain_uuid' => $domain_uuid,
        'queue_extension' => $data['queue_extension'],
        'queue_uuid' => $queue_uuid
    ], 'column');
    if ($exists > 0) {
        api_conflict('queue_extension', 'Queue extension already exists');
    }
}

$dialplan_uuid = $existing['dialplan_uuid'] ?? null;
$dialplan_is_new = false;
if (empty($dialplan_uuid) || !is_uuid($dialplan_uuid)) {
    $dialplan_uuid = uuid();
    $dialplan_is_new = true;
}

$queue_name = $data['queue_name'] ?? $existing['queue_name'];
$queue_extension = $data['queue_extension'] ?? $existing['queue_extension'];
$queue_context = $data['queue_context'] ?? $existing['queue_context'] ?? $domain_name;
$queue_description = $data['queue_description'] ?? $existing['queue_description'] ?? '';
$queue_language = $data['queue_language'] ?? $existing['queue_language'] ?? 'en';
$queue_dialect = $data['queue_dialect'] ?? $existing['queue_dialect'] ?? 'us';
$queue_voice = $data['queue_voice'] ?? $existing['queue_voice'] ?? 'callie';

$timeout_app = $data['queue_timeout_app'] ?? null;
$timeout_data = $data['queue_timeout_data'] ?? '';
if (!empty($data['queue_timeout_action']) && strpos($data['queue_timeout_action'], ':') !== false) {
    [$timeout_app, $timeout_data] = explode(':', $data['queue_timeout_action'], 2);
} elseif (empty($timeout_app) && !empty($existing['queue_timeout_action']) && strpos($existing['queue_timeout_action'], ':') !== false) {
    [$timeout_app, $timeout_data] = explode(':', $existing['queue_timeout_action'], 2);
}

$array['call_center_queues'][0]['domain_uuid'] = $domain_uuid;
$array['call_center_queues'][0]['call_center_queue_uuid'] = $queue_uuid;
$array['call_center_queues'][0]['dialplan_uuid'] = $dialplan_uuid;
$array['call_center_queues'][0]['queue_name'] = $queue_name;
$array['call_center_queues'][0]['queue_extension'] = $queue_extension;
$array['call_center_queues'][0]['queue_context'] = $queue_context;

$allowed_fields = [
    'queue_strategy', 'queue_moh_sound', 'queue_record_template', 'queue_time_base_score',
    'queue_max_wait_time', 'queue_max_wait_time_with_no_agent', 'queue_tier_rules_apply',
    'queue_tier_rule_wait_second', 'queue_tier_rule_wait_multiply_level',
    'queue_tier_rule_no_agent_no_wait', 'queue_discard_abandoned_after',
    'queue_abandoned_resume_allowed', 'queue_announce_sound', 'queue_announce_frequency',
    'queue_description', 'queue_cid_prefix', 'queue_cc_exit_keys',
    'queue_greeting', 'queue_limit', 'queue_time_base_score_sec',
];
foreach ($allowed_fields as $field) {
    if (array_key_exists($field, $data)) {
        $array['call_center_queues'][0][$field] = $data[$field];
    }
}
if ($timeout_app !== null) {
    $array['call_center_queues'][0]['queue_timeout_action'] = $timeout_app . ':' . $timeout_data;
}

$array['dialplans'][0]['domain_uuid'] = $domain_uuid;
$array['dialplans'][0]['dialplan_uuid'] = $dialplan_uuid;
$array['dialplans'][0]['dialplan_name'] = $queue_name;
$array['dialplans'][0]['dialplan_number'] = $queue_extension;
$array['dialplans'][0]['dialplan_context'] = $queue_context;
$array['dialplans'][0]['dialplan_continue'] = 'false';
$array['dialplans'][0]['dialplan_xml'] = api_call_center_queue_dialplan_xml([
    'name' => $queue_name,
    'extension' => $queue_extension,
    'dialplan_uuid' => $dialplan_uuid,
    'queue_uuid' => $queue_uuid,
    'domain_name' => $domain_name,
    'language' => $queue_language,
    'dialect' => $queue_dialect,
    'voice' => $queue_voice,
    'limit' => $data['queue_limit'] ?? $existing['queue_limit'] ?? null,
    'greeting' => $data['queue_greeting'] ?? $existing['queue_greeting'] ?? null,
    'cid_prefix' => $data['queue_cid_prefix'] ?? $existing['queue_cid_prefix'] ?? null,
    'exit_keys' => $data['queue_cc_exit_keys'] ?? $existing['queue_cc_exit_keys'] ?? null,
    'timeout_app' => $timeout_app,
    'timeout_data' => $timeout_data,
    'time_base_score_sec' => $data['queue_time_base_score_sec'] ?? $existing['queue_time_base_score_sec'] ?? null,
]);
$array['dialplans'][0]['dialplan_order'] = '230';
$array['dialplans'][0]['dialplan_enabled'] = true;
$array['dialplans'][0]['dialplan_description'] = $queue_description;
$array['dialplans'][0]['app_uuid'] = $app_uuid;

$p = permissions::new();
$p->add('call_center_queue_edit', 'temp');
$p->add('dialplan_edit', 'temp');
if ($dialplan_is_new) {
    $p->add('dialplan_add', 'temp');
}

$database->app_name = 'call_centers';
$database->app_uuid = $app_uuid;
$database->save($array);
api_require_saved($database);

$p->delete('call_center_queue_edit', 'temp');
$p->delete('dialplan_edit', 'temp');
$p->delete('dialplan_add', 'temp');

if (function_exists('save_call_center_xml')) {
    save_call_center_xml();
}
$cache = new cache;
$cache->delete(gethostname() . ':' . 'configuration:callcenter.conf');
$cache->delete('configuration:callcenter.conf');
api_clear_dialplan_cache($queue_context);
api_reloadxml();

api_success([
    'call_center_queue_uuid' => $queue_uuid,
    'dialplan_uuid' => $dialplan_uuid,
], 'Call center queue updated successfully');
