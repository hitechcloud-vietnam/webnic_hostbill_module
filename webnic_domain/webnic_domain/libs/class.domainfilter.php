<?php
namespace Domain\webnic_domain;

/**
 * Standalone helper for sensitive-keyword and Vietnamese registrant-name
 * validation. The main module carries its own copies of these checks; this
 * class is provided for reuse/testing and reads keywords.json from this dir.
 *
 * Class Domainfilter
 * @package Domain\webnic_domain
 */
class Domainfilter
{
    public function __construct()
    {
    }

    /**
     * Check a domain name against the sensitive-keyword list.
     * @param string $domainName
     * @return string|false "keyword - description" or false when clean.
     */
    public function checkKeywordSensitive($domainName)
    {
        $file = __DIR__ . DS . 'keywords.json';
        if (!is_file($file)) {
            return false;
        }
        $keywords   = json_decode(file_get_contents($file), true) ?: [];
        $domainName = $this->convertAccentsAndSpecialToNormal($domainName);

        foreach ($keywords as $keyword => $description) {
            if (stripos($domainName, $keyword) !== false) {
                return "$keyword - $description";
            }
        }
        return false;
    }

    /**
     * Normalise Vietnamese accents and collapse whitespace.
     * @param string $str
     * @return string
     */
    public function convertAccentsAndSpecialToNormal($str)
    {
        $str = preg_replace("/(à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ)/", 'a', $str);
        $str = preg_replace("/(è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ)/", 'e', $str);
        $str = preg_replace("/(ì|í|ị|ỉ|ĩ)/", 'i', $str);
        $str = preg_replace("/(ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ)/", 'o', $str);
        $str = preg_replace("/(ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ)/", 'u', $str);
        $str = preg_replace("/(ỳ|ý|ỵ|ỷ|ỹ)/", 'y', $str);
        $str = preg_replace("/(đ)/", 'd', $str);
        $str = preg_replace("/(À|Á|Ạ|Ả|Ã|Â|Ầ|Ấ|Ậ|Ẩ|Ẫ|Ă|Ằ|Ắ|Ặ|Ẳ|Ẵ)/", 'A', $str);
        $str = preg_replace("/(È|É|Ẹ|Ẻ|Ẽ|Ê|Ề|Ế|Ệ|Ể|Ễ)/", 'E', $str);
        $str = preg_replace("/(Ì|Í|Ị|Ỉ|Ĩ)/", 'I', $str);
        $str = preg_replace("/(Ò|Ó|Ọ|Ỏ|Õ|Ô|Ồ|Ố|Ộ|Ổ|Ỗ|Ơ|Ờ|Ớ|Ợ|Ở|Ỡ)/", 'O', $str);
        $str = preg_replace("/(Ù|Ú|Ụ|Ủ|Ũ|Ư|Ừ|Ứ|Ự|Ử|Ữ)/", 'U', $str);
        $str = preg_replace("/(Ỳ|Ý|Ỵ|Ỷ|Ỹ)/", 'Y', $str);
        $str = preg_replace("/(Đ)/", 'D', $str);

        $str = preg_replace('/[\t\n\r\0\x0B]/', '', $str);
        $str = preg_replace('/([\s])\1+/', ' ', $str);
        return trim($str);
    }

    /**
     * Validate that a value looks like a diacritic-bearing Vietnamese full name.
     * @param string $value
     * @return bool
     */
    public function check_str_vietnamese($value)
    {
        $name_arr = explode(' ', $value);
        if (count($name_arr) < 3) {
            return false;
        }
        return (bool) preg_match('/[^a-zA-Z0-9[:space:]\@\.\#\*\!\$\^\&\(\)]/', $value);
    }

    /**
     * Returns true when an individual registrant name looks invalid.
     * @param string $str
     * @return bool
     */
    public function checkRegistrantName($str)
    {
        $str   = preg_replace('/\s+/', ' ', $str);
        $words = explode(' ', $str);
        if (count($words) < 2) {
            return true;
        }
        if (count($words) > 4) {
            return true;
        }
        if (preg_match('/[^\x00-\x7F\x20-\x7E]/', $str)) {
            return false;
        }
        return false;
    }

    /**
     * Returns true when an organization registrant name looks invalid.
     * @param string $str
     * @return bool
     */
    public function checkRegistrantCompanyName($str)
    {
        if (preg_match('/\bcong ty\b/i', $str)) {
            return false;
        }
        if (preg_match('/[^\x00-\x7F\x20-\x7E]/', $str)) {
            return false;
        }
        return true;
    }
}
