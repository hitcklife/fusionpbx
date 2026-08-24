<?php
require_once __DIR__ . '/../../base.php';
validate_api_key();
api_require_method('POST');

$data = get_request_data();

$errors = api_validate($data, ['queue_name', 'queue_extension']);
if (!empty($errors)) {
    api_validation_error($errors);
}

$database = new database;
$exists_sql = "SELECT COUNT(*) FROM v_call_center_queues
               WHERE domain_uuid = :domain_uuid AND queue_extension = :queue_extension";
$exists = $database->select($exists_sql, [
    'domain_uuid' => $domain_uuid,
    'queue_extension' => $data['queue_extension']
], 'column');

if ($exists > 0) {
    api_conflict('queue_extension', 'Queue extension already exists');
}

$queue_uuid = uuid();
$dialplan_uuid = uuid();
$app_uuid = '95788e50-9500-079e-2807-fd530b0ea370';
$queue_name = $data['queue_name'];
$queue_extension = $data['queue_extension'];
$queue_context = $data['queue_context'] ?? $domain_name;
$queue_description = $data['queue_description'] ?? '';
$queue_language = $data['queue_language'] ?? 'en';
$queue_dialect = $data['queue_dialect'] ?? 'us';
$queue_voice = $data['queue_voice'] ?? 'callie';
$timeout_app = $data['queue_timeout_app'] ?? null;
$timeout_data = $data['queue_timeout_data'] ?? '';
if (!empty($data['queue_timeout_action']) && strpos($data['queue_timeout_action'], ':') !== false) {
    [$timeout_app, $timeout_data] = explode(':', $data['queue_timeout_action'], 2);
}

$array['call_center_queues'][0]['domain_uuid'] = $domain_uuid;
$array['call_center_queues'][0]['call_center_queue_uuid'] = $queue_uuid;
$array['call_center_queues'][0]['dialplan_uuid'] = $dialplan_uuid;
$array['call_center_queues'][0]['queue_name'] = $queue_name;
$array['call_center_queues'][0]['queue_extension'] = $queue_extension;
$array['call_center_queues'][0]['queue_context'] = $queue_context;
$array['call_center_queues'][0]['queue_strategy'] = $data['queue_strategy'] ?? 'round-robin';
$array['call_center_queues'][0]['queue_moh_sound'] = $data['queue_moh_sound'] ?? null;
$array['call_center_queues'][0]['queue_record_template'] = $data['queue_record_template'] ?? null;
$array['call_center_queues'][0]['queue_time_base_score'] = $data['queue_time_base_score'] ?? 'system';
$array['call_center_queues'][0]['queue_max_wait_time'] = $data['queue_max_wait_time'] ?? 0;
$array['call_center_queues'][0]['queue_max_wait_time_with_no_agent'] = $data['queue_max_wait_time_with_no_agent'] ?? 90;
$array['call_center_queues'][0]['queue_tier_rules_apply'] = $data['queue_tier_rules_apply'] ?? 'false';
$array['call_center_queues'][0]['queue_tier_rule_wait_second'] = $data['queue_tier_rule_wait_second'] ?? 300;
$array['call_center_queues'][0]['queue_tier_rule_wait_multiply_level'] = $data['queue_tier_rule_wait_multiply_level'] ?? 'true';
$array['call_center_queues'][0]['queue_tier_rule_no_agent_no_wait'] = $data['queue_tier_rule_no_agent_no_wait'] ?? 'true';
$array['call_center_queues'][0]['queue_discard_abandoned_after'] = $data['queue_discard_abandoned_after'] ?? 900;
$array['call_center_queues'][0]['queue_abandoned_resume_allowed'] = $data['queue_abandoned_resume_allowed'] ?? 'false';
$array['call_center_queues'][0]['queue_announce_sound'] = $data['queue_announce_sound'] ?? null;
$array['call_center_queues'][0]['queue_announce_frequency'] = $data['queue_announce_frequency'] ?? 0;
$array['call_center_queues'][0]['queue_description'] = $queue_description;
$array['call_center_queues'][0]['queue_language'] = $queue_language;
$array['call_center_queues'][0]['queue_dialect'] = $queue_dialect;
$array['call_center_queues'][0]['queue_voice'] = $queue_voice;
if (!empty($data['queue_cid_prefix'])) {
    $array['call_center_queues'][0]['queue_cid_prefix'] = $data['queue_cid_prefix'];
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
    'limit' => $data['queue_limit'] ?? null,
    'greeting' => $data['queue_greeting'] ?? null,
    'cid_prefix' => $data['queue_cid_prefix'] ?? null,
    'exit_keys' => $data['queue_cc_exit_keys'] ?? null,
    'timeout_app' => $timeout_app,
    'timeout_data' => $timeout_data,
    'time_base_score_sec' => $data['queue_time_base_score_sec'] ?? null,
]);
$array['dialplans'][0]['dialplan_order'] = '230';
$array['dialplans'][0]['dialplan_enabled'] = true;
$array['dialplans'][0]['dialplan_description'] = $queue_description;
$array['dialplans'][0]['app_uuid'] = $app_uuid;

$p = permissions::new();
$p->add('call_center_queue_add', 'temp');
$p->add('dialplan_add', 'temp');

$database->app_name = 'call_centers';
$database->app_uuid = $app_uuid;
$database->save($array);
api_require_saved($database);

$p->delete('call_center_queue_add', 'temp');
$p->delete('dialplan_add', 'temp');

if (function_exists('save_call_center_xml')) {
    save_call_center_xml();
}
$cache = new cache;
$cache->delete(gethostname() . ':' . 'configuration:callcenter.conf');
$cache->delete('configuration:callcenter.conf');
api_clear_dialplan_cache($queue_context);
api_reloadxml();

api_created([
    'call_center_queue_uuid' => $queue_uuid,
    'dialplan_uuid' => $dialplan_uuid,
], 'Call center queue created successfully');
