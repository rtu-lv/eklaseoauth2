<?php

defined('MOODLE_INTERNAL') || die();

require_once $CFG->dirroot . '/auth/eklaseoauth2/lib/EklaseOAuth.php';
require_once $CFG->dirroot . '/auth/eklaseoauth2/locallib.php';
require_once $CFG->libdir . '/authlib.php';
require_once $CFG->dirroot . '/cohort/lib.php';

class auth_plugin_eklaseoauth2 extends auth_plugin_base
{
    /** @var EklaseOAuth */
    private $eklaseoauth;
    const ROLE_STUDENT = 'Student';
    const ROLE_TEACHER = 'Teacher';
    const TEACHER = 1;
    const STUDENT = 2;

    public function __construct()
    {
        $this->authtype = 'eklaseoauth2';
        $this->config = get_config('auth/' . $this->authtype);
    }

    public function loginpage_hook()
    {
        global $CFG, $DB, $SESSION;

        $provider = optional_param('provider', '', PARAM_TEXT);
        if (empty($provider) || $provider !== $this->authtype) {
            return;
        }

        $code = optional_param('code', '', PARAM_TEXT);
        if (empty($code)) {
            if ($this->config->debug) {
                error_log('[Eklase OAuth2] Missing authorization code.');
            }
            print_error('error_missingcode', 'auth_' . $this->authtype, null);
        }

        $this->eklaseoauth = new EklaseOAuth($this->config->client_id, $this->config->client_secret, $this->config->redirect_url);
        $token = $this->get_access_token($code);
        $userinfo = $this->get_user_info($token);
        $username = $this->get_username($userinfo);
        $user_role = $this->get_role($userinfo);

        $user = $DB->get_record('user', array('username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'idnumber' => $userinfo['person_code']));

        if (!$user) {
            $user = create_user_record($username, null, $this->authtype);
        }

        $SESSION->username = $username;
        if (!authenticate_user_login($username, null)) {
            print_error('error_failedauth', 'auth_' . $this->authtype);
        }

        $user->firstname = $userinfo['firstname'];
        $user->lastname = $userinfo['lastname'];
        $user->idnumber = $userinfo['person_code'];
        $user->email = (!empty($userinfo['emailaddress'])) ? $userinfo['emailaddress'] : $user->email;
        $user->school = $userinfo['school'];
        $user->school_code = $userinfo['schoolviisid'];
        $user->role = $userinfo['persontype'];

        if (!empty($userinfo['classlevel']) && !empty($userinfo['classname'])) {
            $user->classlevel = $userinfo['classlevel'];
            $user->classname = $userinfo['classname'];
        }

        try {
            $DB->update_record('user', $user);
        } catch (moodle_exception $e) {
            if ($this->config->debug) {
                error_log('[Eklase OAuth2] Failed to update user.' . $e->getMessage() . '\n Serialized user data:  (' . serialize($user) . ')');
            }
            print_error('error_failedupdateuser', 'auth_' . $this->authtype);
        }

        complete_user_login($user);

        $goto = $CFG->wwwroot . '/';
        if ((isset($this->config->forcecomplete) && $this->config->forcecomplete)/* && !$completed*/) {
            $goto = $CFG->wwwroot . '/local/eu/complete.php';
        } else {
            if (user_not_fully_set_up($user)) {
                $goto = $CFG->wwwroot . '/user/edit.php';
            } else if (isset($SESSION->wantsurl) && (strpos($SESSION->wantsurl, $CFG->wwwroot) === 0)) {
                $goto = $SESSION->wantsurl;
                unset($SESSION->wantsurl);
            }
        }

        redirect($goto);
    }

    /**
     * @param string $username
     * @param null $password
     * @return bool
     */
    public function user_login($username, $password)
    {
        global $CFG, $DB, $SESSION;

        if (isset($SESSION->username) && $SESSION->username == $username) {
            if ($user = $DB->get_record("user", array('username' => $username, 'auth' => $this->authtype, 'mnethostid' => $CFG->mnet_localhost_id))) {
                unset($SESSION->username);
                return true;
            }
        }
        return false;
    }

    /**
     * @param object $config
     * @param object $err
     * @param array $user_fields
     */
    public function config_form($config, $err, $user_fields)
    {
        $config->client_id = (isset($config->client_id) ? $config->client_id : '');
        $config->client_secret = (isset($config->client_secret) ? $config->client_secret : '');
        $config->redirect_url = (isset($config->redirect_url) ? $config->redirect_url : '');
        $config->usernameprefix = (isset($config->usernameprefix) ? $config->usernameprefix : 'ek_');
        $config->debug = (isset($config->debug) ? $config->debug : '0');

        include 'config.html';
    }

    /**
     * Saglabā autentifikācijas moduļa konfigurāciju. Ja konfigurācijas atribūta vērtība netika norādīta,
     * tad šim atribūtam saglabā sākumu vērtību.
     *
     * @param $config
     * @return bool
     */
    public function process_config($config)
    {
        set_config('client_id', $config->client_id, "auth/{$this->authtype}");
        if ($config->client_secret !== 'password') {
            set_config('client_secret', $config->client_secret, "auth/{$this->authtype}");
        }
        set_config('redirect_url', $config->redirect_url, "auth/{$this->authtype}");
        set_config('usernameprefix', (!empty($config->usernameprefix) ? $config->usernameprefix : 'ek_'), "auth/{$this->authtype}");
        set_config('debug', (isset($config->debug) ? 1 : 0), "auth/{$this->authtype}");

        return true;
    }

    /**
     * Ja autentifikācijas modulis aktivizēts, tad ārējais provaiders pieejams uz lappuses ar login formu.
     *
     * @param string $wantsurl
     * @return array
     */
    public function loginpage_idp_list($wantsurl)
    {
        $config = $this->config;
        if (empty($config->client_id) || empty($config->redirect_url)) {
            if ($config->debug) {
                error_log('[Eklase OAuth2] Auth plugin not configured.');
            }
            return parent::loginpage_idp_list($wantsurl);
        }

        $idpurl = EklaseOAuth::getAuthorizeUrl($config->client_id, $config->redirect_url);
        return array(
            array(
                'url' => new eklase_moodle_url($idpurl),
                'icon' => new pix_icon('eklase', 'Eklase', 'auth_' . $this->authtype, array("class" => "eklaseoauth2"))
            )
        );
    }

    public function can_signup()
    {
        return false;
    }

    public function can_confirm()
    {
        return false;
    }

    public function can_edit_profile()
    {
        return true;
    }

    public function can_change_password()
    {
        return false;
    }

    public function can_reset_password()
    {
        return false;
    }

    public function is_internal()
    {
        return false;
    }

    public function prevent_local_passwords()
    {
        return true;
    }

    public function is_synchronised_with_external()
    {
        return false;
    }

    private function get_access_token($code)
    {
        $token = $this->eklaseoauth->getAccessToken($code);
        if (!$token) {
            if ($this->config->debug) {
                error_log('[Eklase OAuth2] Get access token request failed');
            }
            print_error('error_failedgettoken', 'auth_' . $this->authtype);
        }
        return $token;
    }

    private function get_user_info($access_token)
    {
        $userinfo = array();
        $userinfo['person_code'] = '';

        $response = $this->eklaseoauth->getResource("https://my.e-klase.lv/Auth/OAuth/API/Me", $access_token);

        if (!$xmldata = simplexml_load_string($response)) {
            if ($this->config->debug) {
                error_log('[auth/eklase] Can\'t connect to authentication API');
            }
            print_error(get_string('auth_internalerror', 'auth_eklase'));
        }

        if (!empty($xmldata->AdditionalData)) {
            $dataitem = $xmldata->xpath("//DataItem[@id='2']");
            $pattern = '/^(\d{6})-(\d{5})$/';
            if (!empty($dataitem)) {
                $person_code = (string)$dataitem[0];
                if (!empty($person_code) && preg_match($pattern, $person_code))
                    $userinfo['person_code'] = $person_code;
                unset($xmldata->AdditionalData);
            }
        }

        foreach ((array)$xmldata as $field => $value) {
            $field = strtolower($field);
            $userinfo[$field] = empty($value) ? '' : $value;
        }

        return $userinfo;
    }

    private function get_username($userinfo)
    {

        if (!isset($userinfo['person_code']) || empty($userinfo['person_code'])) {
            if ($this->config->debug) {
                error_log('[Eklase OAuth2] User info not contain person_code.');
            }
            print_error('error_missinguserperson_code', 'auth_' . $this->authtype);
        }
        return $this->config->usernameprefix . $userinfo['person_code'];
    }

    private function get_role($userinfo)
    {
        $result = false;
        switch ($userinfo['persontype']) {
            case self::ROLE_STUDENT:
                $result = self::STUDENT;
                break;
            case self::ROLE_TEACHER:
                $result = self::TEACHER;
                break;
        }
        return $result;
    }

}
