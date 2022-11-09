<?php defined('MOODLE_INTERNAL') || die();

function eklaseoauth_convert_to_eu_data($data)
{
    $eudata = array();

    if (isset($data['persontype'])) {
        $role = $data['persontype'];
        switch ($role) {
            case ROLE_STUDENT:
            case ROLE_TEACHER:
                $school = (isset($data['school']) && !empty($data['school'])) ? $data['school'] : '';
                $convertedschools = eu_convert_to_schools($school);
                if (ROLE_STUDENT === $role && !empty($convertedschools['schools'])) {
                    $classlevel = isset($data['classlevel']) ? $data['classlevel'] : '';
                    if (!$classname = eu_convert_to_classname($classlevel)) {
                        error_log('[auth/eklase] Failed to get class number. Userdata: ' . serialize($data));
                    }
                    $convertedschools['schools'][0]->entities = array($classname);
                }
                break;
            case ROLE_PARENT:
                break;
            default:
        }
        $eudata[$role] = isset($convertedschools) ? $convertedschools : array();
    }
    return $eudata;
}

class eklase_moodle_url extends moodle_url
{
    private $eklaseurl;

    public function __construct($url, array $params = null)
    {
        parent::__construct($url);
        $this->eklaseurl = $url;
    }

    /**
     * @param bool $escaped
     * @param array $overrideparams
     * @return string
     */
    public function get_query_string($escaped = true, array $overrideparams = null)
    {
        $arr = array();
        if ($overrideparams !== null) {
            $params = $this->merge_overrideparams($overrideparams);
        } else {
            $params = $this->params;
        }

        foreach ($params as $key => $val) {
            if (!empty($val)) {
                if (is_array($val)) {
                    foreach ($val as $index => $value) {
                        $arr[] = rawurlencode($key . '[' . $index . ']') . "=" . rawurlencode($value);
                    }
                } else {
                    $arr[] = rawurlencode($key) . "=" . rawurlencode($val);
                }
                // ja ir atribūta nosaukums, bet nav vērtības
            } else {
                // pārbauda vai atribūta nosaukums beidzas uz '/'
                if (strpos(substr($key, 0, -1), '/') !== 0) {
                    $arr[] = $key;
                }
            }
        }

        if ($escaped) {
            return implode('&amp;', $arr);
        } else {
            return implode('&', $arr);
        }
    }
}