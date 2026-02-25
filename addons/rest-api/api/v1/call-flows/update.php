<?php
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/base.php';
validate_api_key();

$call_flow_uuid = get_uuid_from_path();
if (empty($call_flow_uuid)) {
    api_error('MISSING_UUID', 'Call flow UUID is required');
}

// Fetch all existing fields
$database = new database;
$sql = "SELECT * FROM v_call_flows WHERE domain_uuid = :domain_uuid AND call_flow_uuid = :call_flow_uuid";
$existing = $database->select($sql, ['domain_uuid' => $domain_uuid, 'call_flow_uuid' => $call_flow_uuid], 'row');

if (empty($existing)) {
    api_not_found('Call flow');
}

$request = get_request_data();

// Check extension uniqueness if changed
if (isset($request['call_flow_extension']) && $request['call_flow_extension'] !== $existing['call_flow_extension']) {
    $check_sql = "SELECT COUNT(*) FROM v_call_flows WHERE domain_uuid = :domain_uuid AND call_flow_extension = :extension AND call_flow_uuid != :call_flow_uuid";
    if ($database->select($check_sql, ['domain_uuid' => $domain_uuid, 'extension' => $request['call_flow_extension'], 'call_flow_uuid' => $call_flow_uuid], 'column') > 0) {
        api_conflict('call_flow_extension', 'Extension already exists');
    }
}

// Merge request over existing values
$call_flow_name = $request['call_flow_name'] ?? $existing['call_flow_name'];
$call_flow_extension = $request['call_flow_extension'] ?? $existing['call_flow_extension'];
$call_flow_feature_code = $request['call_flow_feature_code'] ?? $existing['call_flow_feature_code'];
$call_flow_status = $request['call_flow_status'] ?? $existing['call_flow_status'];
$call_flow_pin_number = $request['call_flow_pin_number'] ?? $existing['call_flow_pin_number'];
$call_flow_label = $request['call_flow_label'] ?? $existing['call_flow_label'];
$call_flow_sound = $request['call_flow_sound'] ?? $existing['call_flow_sound'];
$call_flow_alternate_label = $request['call_flow_alternate_label'] ?? $existing['call_flow_alternate_label'];
$call_flow_alternate_sound = $request['call_flow_alternate_sound'] ?? $existing['call_flow_alternate_sound'];
$call_flow_app = $request['call_flow_app'] ?? $existing['call_flow_app'];
$call_flow_data = $request['call_flow_data'] ?? $existing['call_flow_data'];
$call_flow_alternate_app = $request['call_flow_alternate_app'] ?? $existing['call_flow_alternate_app'];
$call_flow_alternate_data = $request['call_flow_alternate_data'] ?? $existing['call_flow_alternate_data'];
$call_flow_context = $request['call_flow_context'] ?? $existing['call_flow_context'] ?? $domain_name;
$call_flow_enabled = $request['call_flow_enabled'] ?? $existing['call_flow_enabled'];
$call_flow_description = $request['call_flow_description'] ?? $existing['call_flow_description'];

// Use existing dialplan_uuid or generate a new one
$dialplan_uuid = $existing['dialplan_uuid'] ?? uuid();

// Escape special characters for dialplan regex
$destination_extension = $call_flow_extension;
$destination_extension = str_replace("*", "\*", $destination_extension);
$destination_extension = str_replace("+", "\+", $destination_extension);

$destination_feature = $call_flow_feature_code;
if (!empty($destination_feature)) {
    // Allows dial feature code as `flow+<feature_code>`
    if (substr($destination_feature, 0, 5) != 'flow+') {
        $destination_feature = '(?:flow+)?' . $destination_feature;
    }
    $destination_feature = str_replace("*", "\*", $destination_feature);
    $destination_feature = str_replace("+", "\+", $destination_feature);
}

// Build the XML dialplan
$dialplan_xml = "<extension name=\"".xml::sanitize($call_flow_name)."\" continue=\"\" uuid=\"".xml::sanitize($dialplan_uuid)."\">\n";
if (!empty($call_flow_feature_code)) {
    $dialplan_xml .= "	<condition field=\"destination_number\" expression=\"^".xml::sanitize($destination_feature)."$\" break=\"on-true\">\n";
    $dialplan_xml .= "		<action application=\"answer\" data=\"\"/>\n";
    $dialplan_xml .= "		<action application=\"sleep\" data=\"200\"/>\n";
    $dialplan_xml .= "		<action application=\"set\" data=\"feature_code=true\"/>\n";
    $dialplan_xml .= "		<action application=\"set\" data=\"call_flow_uuid=".xml::sanitize($call_flow_uuid)."\"/>\n";
    $dialplan_xml .= "		<action application=\"lua\" data=\"call_flow.lua\"/>\n";
    $dialplan_xml .= "	</condition>\n";
}
$dialplan_xml .= "	<condition field=\"destination_number\" expression=\"^".xml::sanitize($destination_extension)."$\">\n";
$dialplan_xml .= "		<action application=\"set\" data=\"call_flow_uuid=".xml::sanitize($call_flow_uuid)."\"/>\n";
$dialplan_xml .= "		<action application=\"lua\" data=\"call_flow.lua\"/>\n";
$dialplan_xml .= "	</condition>\n";
$dialplan_xml .= "</extension>\n";

// Build the dialplan array
$array["dialplans"][0]["domain_uuid"] = $domain_uuid;
$array["dialplans"][0]["dialplan_uuid"] = $dialplan_uuid;
$array["dialplans"][0]["dialplan_name"] = $call_flow_name;
$array["dialplans"][0]["dialplan_number"] = $call_flow_extension;
$array["dialplans"][0]["dialplan_context"] = $call_flow_context;
$array["dialplans"][0]["dialplan_continue"] = "false";
$array["dialplans"][0]["dialplan_xml"] = $dialplan_xml;
$array["dialplans"][0]["dialplan_order"] = "333";
$array["dialplans"][0]["dialplan_enabled"] = $call_flow_enabled;
$array["dialplans"][0]["dialplan_description"] = $call_flow_description;
$array["dialplans"][0]["app_uuid"] = "b1b70f85-6b42-429b-8c5a-60c8b02b7d14";

// Build the call_flows array
$array["call_flows"][0]["call_flow_uuid"] = $call_flow_uuid;
$array["call_flows"][0]["domain_uuid"] = $domain_uuid;
$array["call_flows"][0]["dialplan_uuid"] = $dialplan_uuid;
$array["call_flows"][0]["call_flow_name"] = $call_flow_name;
$array["call_flows"][0]["call_flow_extension"] = $call_flow_extension;
$array["call_flows"][0]["call_flow_feature_code"] = $call_flow_feature_code;
$array["call_flows"][0]["call_flow_status"] = $call_flow_status;
$array["call_flows"][0]["call_flow_pin_number"] = $call_flow_pin_number;
$array["call_flows"][0]["call_flow_label"] = $call_flow_label;
$array["call_flows"][0]["call_flow_sound"] = $call_flow_sound;
$array["call_flows"][0]["call_flow_alternate_label"] = $call_flow_alternate_label;
$array["call_flows"][0]["call_flow_alternate_sound"] = $call_flow_alternate_sound;
$array["call_flows"][0]["call_flow_app"] = $call_flow_app;
$array["call_flows"][0]["call_flow_data"] = $call_flow_data;
$array["call_flows"][0]["call_flow_alternate_app"] = $call_flow_alternate_app;
$array["call_flows"][0]["call_flow_alternate_data"] = $call_flow_alternate_data;
$array["call_flows"][0]["call_flow_context"] = $call_flow_context;
$array["call_flows"][0]["call_flow_enabled"] = $call_flow_enabled;
$array["call_flows"][0]["call_flow_description"] = $call_flow_description;

// Grant permissions
$p = permissions::new();
$p->add('call_flow_edit', 'temp');
$p->add('dialplan_add', 'temp');
$p->add('dialplan_edit', 'temp');

$database = new database;
$database->app_name = 'call_flows';
$database->app_uuid = 'b1b70f85-6b42-429b-8c5a-60c8b02b7d14';
$database->save($array);

// Revoke permissions
$p->delete('call_flow_edit', 'temp');
$p->delete('dialplan_add', 'temp');
$p->delete('dialplan_edit', 'temp');

// Send PRESENCE_IN event if feature code is set
if (!empty($call_flow_feature_code)) {
    $esl = event_socket::create();
    if ($esl->is_connected()) {
        $event = "sendevent PRESENCE_IN\n";
        $event .= "proto: flow\n";
        $event .= "event_type: presence\n";
        $event .= "alt_event_type: dialog\n";
        $event .= "Presence-Call-Direction: outbound\n";
        $event .= "state: Active (1 waiting)\n";
        $event .= "from: flow+".$call_flow_feature_code."@".$domain_name."\n";
        $event .= "login: flow+".$call_flow_feature_code."@".$domain_name."\n";
        $event .= "unique-id: ".$call_flow_uuid."\n";
        if ($call_flow_status == "true") {
            $event .= "answer-state: confirmed\n";
        } else {
            $event .= "answer-state: terminated\n";
        }
        event_socket::command($event);
    }
}

// Clear the cache
api_clear_dialplan_cache($call_flow_context);

api_success(['call_flow_uuid' => $call_flow_uuid], 'Call flow updated successfully');
