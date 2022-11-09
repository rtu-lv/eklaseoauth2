<?php

require_once dirname(__FILE__) . "/../../config.php";

$authtype = 'eklaseoauth2';
$debug = get_config('auth/' . $authtype, 'debug');

$goto = get_login_url();
if (!is_enabled_auth($authtype)) {
    if ($debug) {
        error_log('[Eklase OAuth2] Trying login with disabled auth plugin.');
    }
    print_error('error_plugindisabled', 'auth_' . $authtype, $goto);
}

$code = optional_param('code', '', PARAM_TEXT);
if (empty($code)) {
    $error = optional_param('error', '', PARAM_TEXT);
    $errordesc = optional_param('error_description', '', PARAM_TEXT);
    if (!empty($error)) {
        if ($debug) {
            error_log('[Eklase OAuth2] Authentication failed. Error: "' . $error . '"/"' . $errordesc . '"');
        }
    }
    print_error('error_failedauth', 'auth_' . $authtype, $goto);
}

redirect(new moodle_url($goto, array('provider' => $authtype, 'code' => $code)));