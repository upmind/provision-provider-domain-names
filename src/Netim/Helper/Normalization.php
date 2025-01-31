<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Netim\Helper;

class Normalization
{
    use NormalizationConstantsTrait;

    public function stripAccent($str)
    {
        $patterns = [
            '/à/', '/á/', '/â/', '/ã/', '/ä/', '/å/', '/ā/', '/ă/', '/ą/', '/æ/', '/ç/', '/ć/', '/ĉ/', '/ċ/', '/č/',
            '/ď/', '/đ/', '/è/', '/é/', '/ê/', '/ë/', '/ē/', '/ĕ/', '/ė/', '/ę/', '/ě/', '/ĝ/', '/ġ/', '/ģ/', '/ĥ/',
            '/ħ/', '/ì/', '/í/', '/î/', '/ï/', '/ĩ/', '/ī/', '/ĭ/', '/į/', '/ı/', '/ĵ/', '/ķ/', '/ĺ/', '/ļ/', '/ľ/',
            '/ŀ/', '/ł/', '/ñ/', '/ń/', '/ņ/', '/ň/', '/ŉ/', '/ò/', '/ó/', '/ô/', '/õ/', '/ö/', '/ō/', '/ŏ/', '/ő/',
            '/ø/', '/ð/', '/œ/', '/ŕ/', '/ŗ/', '/ř/', '/ś/', '/ŝ/', '/š/', '/ș/', '/ť/', '/ŧ/', '/ț/', '/ù/', '/ú/',
            '/û/', '/ü/', '/ũ/', '/ū/', '/ŭ/', '/ů/', '/ű/', '/ų/', '/ŵ/', '/ý/', '/ÿ/', '/ŷ/', '/ź/', '/ż/', '/ž/',
            '/ß/', '/À/', '/Á/', '/Â/', '/Ã/', '/Ä/', '/Å/', '/Ā/', '/Ă/', '/Ą/', '/Æ/', '/Ç/', '/Ć/', '/Ĉ/', '/Ċ/',
            '/Č/', '/Ď/', '/Đ/', '/È/', '/É/', '/Ê/', '/Ë/', '/Ē/', '/Ĕ/', '/Ė/', '/Ę/', '/Ě/', '/Ĝ/', '/Ġ/', '/Ģ/',
            '/Ĥ/', '/Ħ/', '/İ/', '/Ì/', '/Í/', '/Î/', '/Ï/', '/Ĩ/', '/Ī/', '/Ĭ/', '/Į/', '/I/', '/Ĵ/', '/Ķ/', '/Ĺ/',
            '/Ļ/', '/Ľ/', '/Ŀ/', '/Ł/', '/Ñ/', '/Ń/', '/Ņ/', '/Ň/', '/Ò/', '/Ó/', '/Ô/', '/Õ/', '/Ö/', '/Ō/', '/Ŏ/',
            '/Ő/', '/Ø/', '/Œ/', '/Ŕ/', '/Ŗ/', '/Ř/', '/Ś/', '/Ŝ/', '/Š/', '/Ș/', '/Ť/', '/Ŧ/', '/Ț/', '/Ù/', '/Ú/',
            '/Û/', '/Ü/', '/Ũ/', '/Ū/', '/Ŭ/', '/Ů/', '/Ű/', '/Ų/', '/Ŵ/', '/Ý/', '/Ÿ/', '/Ŷ/', '/Ź/', '/Ż/', '/Ž/'
        ];

        $replacements = [
            'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'ae', 'c', 'c', 'c', 'c', 'c', 'd', 'd', 'e', 'e', 'e', 'e',
            'e', 'e', 'e', 'e', 'e', 'g', 'g', 'g', 'h', 'h', 'i', 'i', 'i', 'i', 'i', 'i', 'i', 'i', 'i', 'j', 'k',
            'l', 'l', 'l', 'l', 'l', 'n', 'n', 'n', 'n', 'n', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'oe',
            'r', 'r', 'r', 's', 's', 's', 's', 't', 't', 't', 'u', 'u', 'u', 'u', 'u', 'u', 'u', 'u', 'u', 'u', 'w',
            'y', 'y', 'y', 'z', 'z', 'z', 'ss', 'A', 'A', 'A', 'A', 'A', 'A', 'A', 'A', 'A', 'AE', 'C', 'C', 'C', 'C',
            'C', 'C', 'D', 'E', 'E', 'E', 'E', 'E', 'E', 'E', 'E', 'E', 'G', 'G', 'G', 'G', 'H', 'I', 'I', 'I', 'I',
            'I', 'I', 'I', 'I', 'I', 'I', 'J', 'K', 'L', 'L', 'L', 'L', 'L', 'N', 'N', 'N', 'N', 'O', 'O', 'O', 'O',
            'O', 'O', 'O', 'O', 'O', 'OE', 'R', 'R', 'R', 'S', 'S', 'S', 'S', 'T', 'T', 'T', 'U', 'U', 'U', 'U', 'U',
            'U', 'U', 'U', 'U', 'U', 'W', 'Y', 'Y', 'Y', 'Z', 'Z', 'Z'
        ];

        return preg_replace($patterns, $replacements, $str);
    }

    public function stripSpecialChar($str)
    {
        $patterns = array('/-/', '/_/', '/,/');

        $replacements = array(' ', ' ', '');

        return preg_replace($patterns, $replacements, $str);
    }

    /**
     * Strip accent, special char and make the $str lowercase
     *
     * @param string $str
     **/
    public function process($str): string
    {
        $res = $this->stripAccent($str);
        $res = $this->stripSpecialChar($res);

        return strtolower($res);
    }

    /**
     * Removes all null values from an array
     *
     * @param array $arr An array
     * @return array An array with all the key/value of $arr removing null values
     *
     * @timecomplexity O(n)
     */
    public function array_removeNull($arr)
    {
        return array_filter($arr, function ($var) {
            return !is_null($var);
        });
    }

    /**
     * Map $fn onto $data keys/values, and $fn takes an additional argument $text
     *
     * @param $data array An array to be processed
     * @param $arg mixed An arg to be given to $fn
     * @param $fn callable A function that takes a parameter, and return a function that takes 2 parameters
     *
     * @return array An arra containing the result of the mapping of $fn($arg) onto $data
     *
     * @timecomplexity O(n) * complexity of $fn($arg)
     */
    public function constructMatch($data, $arg, $fn = null)
    {
        if ($fn === null) {
            $fn = array($this, 'testMatch');
        }

        return array_map($fn($arg), array_keys($data), $data);
    }


    /**
     * Normalize a string to get conform phone number for api
     *
     * @see whmcs module, netim.php
     */
    public function phoneNumber($str, $country)
    {
        if (empty($str)) {
            return $str;
        }

        // $str is normalized as "+countrycode digits" to conform API values
        $country = strtoupper($country);
        $str2 = str_replace(" ", "", $str);
        $str2 = str_replace("-", "", $str2);
        $str2 = str_replace(".", "", $str2);
        $str2 = str_replace("(", "", $str2);
        $str2 = str_replace(")", "", $str2);

        if (preg_match("#^\+#", $str2)) {
            if (preg_match("#^\+" . $this->getCountryPhoneCodeMappings()[$country] . "#", $str2)) {
                //Number has the international syntax for the country
                $l = strlen((string) $this->getCountryPhoneCodeMappings()[$country]) + 1;
                $new_str = "+" . $this->getCountryPhoneCodeMappings()[$country] . " " . substr($str2, $l, strlen($str2));
            } else {
                //Number has the international syntax for another country, but we are unable to extract the country code
                $new_str = substr($str2, 0, 3) . " " . substr($str2, 3, strlen($str2));
            }
        } else if (preg_match("#^00#", $str2)) {
            if (preg_match("#^00" . $this->getCountryPhoneCodeMappings()[$country] . "#", $str2)) {
                //Number has the international syntax for the country
                $new_str = "+" . $this->getCountryPhoneCodeMappings()[$country] . " " . substr($str2, 2 + strlen($this->getCountryPhoneCodeMappings()[$country]), strlen($str2));
            } else {
                //Number has the international syntax for another country but we are unable to extract the country code
                $new_str = "+" . substr($str2, 2, 2) . " " . substr($str, 4, strlen($str2));
            }
        } else {
            //Number has not the international syntax
            $new_str = "+" . $this->getCountryPhoneCodeMappings()[$country] . " " . substr($str2, 1, strlen($str2));
        }

        return $new_str;
    }

    /**
     * Normalize a string to get conform state code for api
     *
     * @see whmcs module, netim.php
     */
    public function state($str, $country)
    {
        $country = strtoupper($country);
        $str = $this->stripAccent($str); // Removal of accentued characters
        $str = $this->specialCharacter($str); // Removal of special characters

        $str = strtoupper($str); // Change to upper case
        if (isset($this->getStateCodeToRegexMappings()[$country])) {
            foreach ($this->getStateCodeToRegexMappings()[$country] as $key => $value) {
                $regex = $this->prepareRegex($value);
                if (preg_match($regex, $str)) {
                    return $key;
                }
            }

            //the value is not found, we use the first key of the array
            $keys = array_keys($this->getStateCodeToRegexMappings()[$country]);
            return $keys[0];
        }

        return "";
    }

    /**
     * Normalize a string to get conform country code for api
     *
     * @see whmcs module, netim.php
     */
    public function country($str)
    {
        $str = $this->stripAccent($str); // Removal of accented characters
        $str = $this->specialCharacter($str); // Removal of special characters

        foreach ($this->getCountryCodeToRegexMappings() as $key => $value) {
            $regex = $this->prepareRegex($value);

            if (preg_match($regex, $str)) {
                return $key;
            }
        }

        //the value is not found, we use the initial value
        return $str;
    }

    /**
     * Normalize a string to remove all special char
     *
     * @see whmcs module, netim.php
     *
     * @param string $str
     * @return string
     */
    public function specialCharacter($str)
    {
        // List of characters to replace by code point => replace value
        $car = [
            33 => ".", 34 => "'", 35 => "", 36 => "", 37 => "", 40 => "", 41 => "", 42 => "", 43 => "", 58 => "",
            59 => ".", 60 => "", 61 => "", 62 => "", 63 => "", 91 => "", 92 => "", 93 => "", 94 => "", 96 => "'",
            123 => "", 124 => "", 125 => "", 171 => "'", 180 => "'", 8222 => " ", 8220 => "'", 8221 => "'"
        ];

        $patterns = [];
        $replacements = [];
        foreach ($car as $code => $replace) {
            $patterns[] = "/" . "\\" . mb_chr($code) . "/";
            $replacements[] = $replace;
        }

        return preg_replace($patterns, $replacements, $str);
    }

    /**
     * Takes a pattern and adds delimiter, anchors and options if needed to make it a valid regex
     *
     * @param string $wannabeRegex A string to be used as a regex pattern
     * @param string $delimiter OPTIONAL The delimiter to be used in the regex, default is '/'
     * @param bool $isStartOfLine OPTIONAL If true, adds '^' anchor at the start of regex. Default is true
     * @param bool $isEndOfLine OPTIONAL If true, adds '$' anchor at the end of regex. Default is true
     * @param string $options OPTIONAL A string representing options to use for the regex as defined for PCRE. Default is 'i' (case insensitive regex)
     *
     * @return string
     */
    private function prepareRegex(
        $wannabeRegex,
        $delimiter = '/',
        $isStartOfLine = true,
        $isEndOfLine = true,
        $options = 'i'
    ) {
        $res = $delimiter;
        $res .= $isStartOfLine ? '^' : '';
        $res .= '(' . $wannabeRegex . ')';
        $res .= $isEndOfLine ? '$' : '';
        $res .= $delimiter . $options;
        return $res;
    }

    /**
     * Function that can be used in constructMatch as the callable parameter.
     *
     * @param $text string A string to be search in by a regex
     *
     * @return callable A closure that takes two parameter $key and $val, and use $text as a out-of-scope variable.
     *         The closure do a regex match between $val and $text, and return $key when there is a match.
     *
     * @see constructMatch
     */
    //closure
    private function testMatch($text)
    {
        return function ($key, $val) use ($text) {
            if (preg_match($this->prepareRegex($val), $text)) {
                return $key;
            }
        };
    }
}
