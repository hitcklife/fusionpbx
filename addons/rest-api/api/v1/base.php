<?php
/**
 * FusionPBX REST API Base Controller
 * Provides common functionality for all API endpoints
 *
 * This file contains reusable helper functions for API endpoints including:
 * - Pagination
 * - Validation
 * - Record lookup
 * - Cache management
 * - XML regeneration
 * - Query parameter handling
 */

// Locate the FusionPBX bootstrap no matter if the API is symlinked or copied
$bootstrap_path = null;
$search_dir = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $candidate = $search_dir . '/resources/require.php';
    if (file_exists($candidate)) {
        $bootstrap_path = $candidate;
        break;
    }
    $parent = dirname($search_dir);
    if ($parent === $search_dir) {
        break; // reached filesystem root
    }
    $search_dir = $parent;
}

if ($bootstrap_path === null) {
    http_response_code(500);
    echo 'FusionPBX bootstrap (resources/require.php) not found.';
    exit;
}

require_once $bootstrap_path;
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/response.php';
$api_config = require __DIR__ . '/config.php';

/**
 * Paginate database query results
 *
 * @param string $sql Main query SQL
 * @param string $count_sql Count query SQL
 * @param array $parameters Query parameters
 * @param int $page Page number (1-indexed)
 * @param int $per_page Items per page (max 100)
 * @return array ['items' => array, 'pagination' => array]
 */
function api_paginate($sql, $count_sql, $parameters, $page = 1, $per_page = 50) {
    global $domain_uuid;

    $page = max(1, intval($page));
    $per_page = min(100, max(1, intval($per_page)));
    $offset = ($page - 1) * $per_page;

    $database = new database;

    // Get total count
    $total = $database->select($count_sql, $parameters, 'column');

    // Add pagination to query
    $parameters['limit'] = $per_page;
    $parameters['offset'] = $offset;

    $items = $database->select($sql . " LIMIT :limit OFFSET :offset", $parameters, 'all');

    $pagination = [
        'page' => $page,
        'per_page' => $per_page,
        'total' => (int)$total,
        'total_pages' => ceil($total / $per_page)
    ];

    return ['items' => $items ?? [], 'pagination' => $pagination];
}

/**
 * Validate required fields in request data
 *
 * @param array $data Request data to validate
 * @param array $required_fields List of required field names
 * @return array List of validation errors (empty if valid)
 */
function api_validate($data, $required_fields) {
    $errors = [];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            $errors[] = ['field' => $field, 'message' => "{$field} is required"];
        }
    }
    return $errors;
}

/**
 * Validate UUID format and return it or exit with error
 *
 * @param string $uuid UUID to validate
 * @param string $field_name Field name for error message
 * @return string Validated UUID
 */
function api_validate_uuid($uuid, $field_name = 'uuid') {
    if (empty($uuid) || !is_uuid($uuid)) {
        api_error('VALIDATION_ERROR', "Invalid {$field_name}", $field_name, 400);
    }
    return $uuid;
}

/**
 * Check if a record exists in the specified table
 *
 * @param string $table Table name
 * @param string $uuid_field UUID field name
 * @param string $uuid UUID value
 * @param string|null $domain_uuid Domain UUID (uses global if null)
 * @return bool True if record exists
 */
function api_record_exists($table, $uuid_field, $uuid, $check_domain_uuid = null) {
    global $domain_uuid;
    $check_domain_uuid = $check_domain_uuid ?? $domain_uuid;

    $database = new database;
    $sql = "SELECT COUNT(*) FROM {$table} WHERE {$uuid_field} = :uuid AND domain_uuid = :domain_uuid";
    $parameters = ['uuid' => $uuid, 'domain_uuid' => $check_domain_uuid];
    return $database->select($sql, $parameters, 'column') > 0;
}

/**
 * Get a single record by UUID
 *
 * @param string $table Table name
 * @param string $uuid_field UUID field name
 * @param string $uuid UUID value
 * @param string $fields Fields to select (default: *)
 * @return array|false Record data or false if not found
 */
function api_get_record($table, $uuid_field, $uuid, $fields = '*') {
    global $domain_uuid;

    $database = new database;
    $sql = "SELECT {$fields} FROM {$table} WHERE {$uuid_field} = :uuid AND domain_uuid = :domain_uuid";
    $parameters = ['uuid' => $uuid, 'domain_uuid' => $domain_uuid];
    return $database->select($sql, $parameters, 'row');
}

/**
 * Clear FusionPBX cache entry
 *
 * @param string $key Cache key to clear
 */
function api_clear_cache($key) {
    $cache = new cache;
    $cache->delete($key);
}

/**
 * Clear dialplan cache for domain
 *
 * @param string|null $context Context name (uses domain_name if null)
 */
function api_clear_dialplan_cache($context = null) {
    global $domain_name;
    $context = $context ?? $domain_name;
    $cache = new cache;
    $cache->delete("dialplan:" . $context);
}

/**
 * XML-escape a value the same way native FusionPBX apps do.
 */
function api_xml_esc($value) {
    if (class_exists('xml') && method_exists('xml', 'sanitize')) {
        return xml::sanitize($value);
    }
    return htmlspecialchars((string)($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Native IVR option conversion: a numeric param is transfer {ext} XML {context}.
 *
 * @param array $opt
 * @param string $context Domain/context name
 * @return array{digits:mixed,action:string,param:string,order:mixed,enabled:string,description:string}
 */
function api_normalize_ivr_option($opt, $context) {
    $digits = $opt['ivr_menu_option_digits'] ?? $opt['digit'] ?? null;
    $param = $opt['ivr_menu_option_param'] ?? $opt['destination'] ?? '';
    $action = $opt['ivr_menu_option_action'] ?? $opt['action'] ?? 'menu-exec-app';
    if (isset($param) && $param !== '' && is_numeric($param)) {
        $action = 'menu-exec-app';
        $param = 'transfer ' . $param . ' XML ' . $context;
    }
    return [
        'digits' => $digits,
        'action' => $action,
        'param' => $param,
        'order' => $opt['ivr_menu_option_order'] ?? $opt['order'] ?? null,
        'enabled' => $opt['ivr_menu_option_enabled'] ?? $opt['enabled'] ?? 'true',
        'description' => $opt['ivr_menu_option_description'] ?? $opt['description'] ?? '',
    ];
}

/**
 * Ring-group dialplan XML matching app/ring_groups/ring_group_edit.php
 */
function api_ring_group_dialplan_xml($name, $extension, $dialplan_uuid, $ring_group_uuid) {
    $xml = "<extension name=\"".api_xml_esc($name)."\" continue=\"\" uuid=\"".api_xml_esc($dialplan_uuid)."\">\n";
    $xml .= "	<condition field=\"destination_number\" expression=\"^".api_xml_esc($extension)."$\">\n";
    $xml .= "		<action application=\"ring_ready\" data=\"\"/>\n";
    $xml .= "		<action application=\"set\" data=\"ring_group_uuid=".api_xml_esc($ring_group_uuid)."\"/>\n";
    $xml .= "		<action application=\"set\" data=\"record_stereo=true\"/>\n";
    $xml .= "		<action application=\"lua\" data=\"app.lua ring_groups\"/>\n";
    $xml .= "	</condition>\n";
    $xml .= "</extension>\n";
    return $xml;
}

/**
 * Call-center queue dialplan XML matching app/call_centers/call_center_queue_edit.php
 *
 * @param array $q name, extension, dialplan_uuid, queue_uuid, domain_name, language, dialect, voice, limit, greeting, cid_prefix, exit_keys, timeout_app, timeout_data, time_base_score_sec
 */
function api_call_center_queue_dialplan_xml(array $q) {
    $name = $q['name'];
    $extension = $q['extension'];
    $dialplan_uuid = $q['dialplan_uuid'];
    $queue_uuid = $q['queue_uuid'];
    $domain_name = $q['domain_name'];
    $language = $q['language'] ?? 'en';
    $dialect = $q['dialect'] ?? 'us';
    $voice = $q['voice'] ?? 'callie';
    $sounds = '';
    if (class_exists('settings')) {
        $settings = new settings();
        $sounds = $settings->get('switch', 'sounds', '');
    }

    $xml = "<extension name=\"".api_xml_esc($name)."\" continue=\"\" uuid=\"".api_xml_esc($dialplan_uuid)."\">\n";
    if (!empty($q['limit'])) {
        $xml .= "	<condition field=\"destination_number\" expression=\"^(callcenter\+)?".api_xml_esc($extension)."$\" break=\"on-false\">\n";
        $xml .= "		<action application=\"limit\" data=\"hash inbound \${destination_number} ".api_xml_esc($q['limit'])." !NORMAL_CIRCUIT_CONGESTION\"/>\n";
        $xml .= "	</condition>\n";
    }
    $xml .= "	<condition field=\"destination_number\" expression=\"^([^#]+#)(.*)\$\" break=\"never\">\n";
    $xml .= "		<action application=\"set\" data=\"caller_id_name=\$2\"/>\n";
    $xml .= "	</condition>\n";
    $xml .= "	<condition field=\"destination_number\" expression=\"^(callcenter\+)?".api_xml_esc($extension)."$\">\n";
    $xml .= "		<action application=\"answer\" data=\"\"/>\n";
    $xml .= "		<action application=\"set\" data=\"sound_prefix=".api_xml_esc($sounds).'/'.api_xml_esc($language).'/'.api_xml_esc($dialect).'/'.api_xml_esc($voice)."\"/>\n";
    if (!empty($queue_uuid)) {
        $xml .= "		<action application=\"set\" data=\"call_center_queue_uuid=".api_xml_esc($queue_uuid)."\"/>\n";
    }
    if (!empty($extension) && is_numeric($extension)) {
        $xml .= "		<action application=\"set\" data=\"queue_extension=".api_xml_esc($extension)."\"/>\n";
    }
    $xml .= "		<action application=\"set\" data=\"cc_export_vars=\${cc_export_vars},call_center_queue_uuid,sip_h_Alert-Info,sound_prefix\"/>\n";
    $xml .= "		<action application=\"set\" data=\"hangup_after_bridge=true\"/>\n";
    if (!empty($q['time_base_score_sec'])) {
        $xml .= "		<action application=\"set\" data=\"cc_base_score=".api_xml_esc($q['time_base_score_sec'])."\"/>\n";
    }
    if (!empty($q['greeting'])) {
        $xml .= "		<action application=\"sleep\" data=\"1000\"/>\n";
        $greeting_array = explode(':', $q['greeting']);
        if (count($greeting_array) == 1) {
            $xml .= "		<action application=\"playback\" data=\"".api_xml_esc($q['greeting'])."\"/>\n";
        } elseif (in_array($greeting_array[0], ['say', 'tone_stream', 'phrase'], true)) {
            $xml .= "		<action application=\"".api_xml_esc($greeting_array[0])."\" data=\"".api_xml_esc($greeting_array[1] ?? '')."\"/>\n";
        }
    }
    if (!empty($q['cid_prefix'])) {
        $xml .= "		<action application=\"set\" data=\"effective_caller_id_name=".api_xml_esc($q['cid_prefix'])."#\${caller_id_name}\"/>\n";
    }
    if (isset($q['exit_keys']) && $q['exit_keys'] !== null && $q['exit_keys'] !== '') {
        $xml .= "		<action application=\"set\" data=\"cc_exit_keys=".api_xml_esc($q['exit_keys'])."\"/>\n";
    }
    $xml .= "		<action application=\"callcenter\" data=\"".api_xml_esc($extension)."@".api_xml_esc($domain_name)."\"/>\n";
    if (!empty($q['timeout_app'])) {
        $xml .= "		<action application=\"".api_xml_esc($q['timeout_app'])."\" data=\"".api_xml_esc($q['timeout_data'] ?? '')."\"/>\n";
    }
    $xml .= "	</condition>\n";
    $xml .= "</extension>\n";
    return $xml;
}

function api_reloadxml() {
    if (class_exists('event_socket')) {
        event_socket::api('reloadxml');
    }
}

/**
 * Log API action for audit trail
 *
 * @param string $action Action performed
 * @param string $entity_type Entity type (extension, gateway, etc.)
 * @param string $entity_uuid Entity UUID
 * @param array $details Additional details
 */
function api_log($action, $entity_type, $entity_uuid, $details = []) {
    // Optional: Log API actions for audit trail
    // Can be extended to write to v_database_transactions
    // For now, this is a placeholder for future implementation
}

/**
 * Trigger FreeSWITCH XML regeneration
 *
 * @param string $type Type of XML to regenerate (extension, dialplan, gateway)
 * @param string|null $domain_uuid Domain UUID
 * @param string|null $domain_name Domain name
 */
function api_regenerate_xml($type, $regen_domain_uuid = null, $regen_domain_name = null) {
    global $domain_uuid;
    global $domain_name;

    $regen_domain_uuid = $regen_domain_uuid ?? $domain_uuid;
    $regen_domain_name = $regen_domain_name ?? $domain_name;

    switch ($type) {
        case 'extension':
            if (class_exists('extension')) {
                $ext = new extension;
                $ext->domain_uuid = $regen_domain_uuid;
                $ext->domain_name = $regen_domain_name;
                $ext->xml();
            }
            break;
        case 'dialplan':
            api_clear_dialplan_cache();
            break;
        case 'gateway':
            if (function_exists('save_gateway_xml')) {
                save_gateway_xml();
            }
            $cache = new cache;
            $cache->delete(gethostname() . ":configuration:sofia.conf");
            break;
    }
}

/**
 * Get query parameters with default values
 *
 * @param array $defaults Associative array of parameter => default value
 * @return array Query parameters
 */
function api_get_query_params($defaults = []) {
    $params = [];
    foreach ($defaults as $key => $default) {
        $params[$key] = $_GET[$key] ?? $default;
    }
    return $params;
}

/**
 * Build WHERE clause and parameters from filters
 *
 * @param array $filters Filter values (field => value)
 * @param array $allowed_fields List of allowed filter fields
 * @return array ['where' => string, 'parameters' => array]
 */
function api_build_filters($filters, $allowed_fields) {
    $where = [];
    $parameters = [];

    foreach ($filters as $field => $value) {
        if (in_array($field, $allowed_fields) && !empty($value)) {
            $where[] = "{$field} = :{$field}";
            $parameters[$field] = $value;
        }
    }

    return [
        'where' => count($where) > 0 ? ' AND ' . implode(' AND ', $where) : '',
        'parameters' => $parameters
    ];
}

/**
 * Generate dialplan XML from details and save to database
 *
 * @param string $dialplan_uuid Dialplan UUID to generate XML for
 */
function api_generate_dialplan_xml($dialplan_uuid) {
    $database = new database;

    // Get dialplan info
    $sql = "SELECT dialplan_uuid, dialplan_name, dialplan_continue FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid";
    $dialplan = $database->select($sql, ['dialplan_uuid' => $dialplan_uuid], 'row');
    if (empty($dialplan)) return;

    // Get enabled details ordered by group then order
    $sql = "SELECT dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
                   dialplan_detail_break, dialplan_detail_inline, dialplan_detail_group
            FROM v_dialplan_details
            WHERE dialplan_uuid = :dialplan_uuid AND dialplan_detail_enabled = 'true'
            ORDER BY CAST(dialplan_detail_group AS INTEGER) ASC, CAST(dialplan_detail_order AS INTEGER) ASC";
    $details = $database->select($sql, ['dialplan_uuid' => $dialplan_uuid], 'all');
    if (empty($details)) return;

    // Group details
    $groups = [];
    foreach ($details as $detail) {
        $group = $detail['dialplan_detail_group'] ?? '0';
        $groups[$group][] = $detail;
    }

    $continue = ($dialplan['dialplan_continue'] === 'true' || $dialplan['dialplan_continue'] === true) ? 'true' : 'false';
    $esc = function($v) { return htmlspecialchars($v ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'); };

    $xml = '<extension name="' . $esc($dialplan['dialplan_name']) . '" continue="' . $continue . '" uuid="' . $dialplan_uuid . '">' . "\n";

    foreach ($groups as $group_details) {
        $conditions = [];
        $actions = [];
        $anti_actions = [];

        foreach ($group_details as $d) {
            if ($d['dialplan_detail_tag'] === 'condition') {
                $conditions[] = $d;
            } elseif ($d['dialplan_detail_tag'] === 'action') {
                $actions[] = $d;
            } elseif ($d['dialplan_detail_tag'] === 'anti-action') {
                $anti_actions[] = $d;
            }
        }

        $last_idx = count($conditions) - 1;
        foreach ($conditions as $idx => $cond) {
            $field = $esc($cond['dialplan_detail_type']);
            $expr = $esc($cond['dialplan_detail_data']);
            $brk = !empty($cond['dialplan_detail_break']) ? ' break="' . $esc($cond['dialplan_detail_break']) . '"' : '';

            if ($idx < $last_idx || (empty($actions) && empty($anti_actions))) {
                $xml .= "\t<condition field=\"{$field}\" expression=\"{$expr}\"{$brk}/>\n";
            } else {
                $xml .= "\t<condition field=\"{$field}\" expression=\"{$expr}\"{$brk}>\n";
                foreach ($actions as $a) {
                    $inline = (!empty($a['dialplan_detail_inline']) && $a['dialplan_detail_inline'] === 'true') ? ' inline="true"' : '';
                    $xml .= "\t\t<action application=\"" . $esc($a['dialplan_detail_type']) . '" data="' . $esc($a['dialplan_detail_data']) . '"' . $inline . "/>\n";
                }
                foreach ($anti_actions as $a) {
                    $inline = (!empty($a['dialplan_detail_inline']) && $a['dialplan_detail_inline'] === 'true') ? ' inline="true"' : '';
                    $xml .= "\t\t<anti-action application=\"" . $esc($a['dialplan_detail_type']) . '" data="' . $esc($a['dialplan_detail_data']) . '"' . $inline . "/>\n";
                }
                $xml .= "\t</condition>\n";
            }
        }
    }

    $xml .= '</extension>';

    // Save XML to database
    $update_array['dialplans'][0]['dialplan_uuid'] = $dialplan_uuid;
    $update_array['dialplans'][0]['dialplan_xml'] = $xml;

    $p = permissions::new();
    $p->add('dialplan_edit', 'temp');

    $db = new database;
    $db->app_name = 'dialplans';
    $db->app_uuid = '742714e5-8cdf-32fd-462c-cbe7e3d655db';
    $db->save($update_array);

    $p->delete('dialplan_edit', 'temp');
}

// Auto-authenticate when base.php is loaded
validate_api_key();
