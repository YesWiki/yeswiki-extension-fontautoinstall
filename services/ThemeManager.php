<?php

/*
 * This file is part of the YesWiki Extension fontautoinstall.
 *
 * Authors : see README.md file that was distributed with this source code.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace YesWiki\Fontautoinstall\Service;

use Exception;
use YesWiki\Core\Service\ThemeManager as CoreThemeManager;

class ThemeManager extends CoreThemeManager
{
    public const CUSTOM_FONT_PATH = 'custom/fonts';
    public const USER_AGENTS = [
        'eot' => 'Mozilla/2.0 (compatible; MSIE 3.01; Windows 98)',
        'woff' => 'Mozilla/5.0 (Windows; U; Windows NT 6.1; fr; rv:1.9.2) Gecko/20100115 Firefox/3.6',
        'woff2' => 'Mozilla/5.0 (Windows NT 6.3; Win64; x64; rv:105.0) Gecko/20100101 Firefox/105.0',
        'truetype' => '',
    ];

    /**
     * add a css custom preset (only admins can change a file)
     * @param string $filename
     * @param array $post
     * @return array ['status' => bool, 'message' => '...','errorCode'=>0]
     *   errorCode : 0 : not connected user
     *               1 : bad post data
     *               2 : file already existing but user not admin
     *               3 : custom/css-presets not existing and not possible to create it
     *               4 : file not created
     */
    public function addCustomCSSPreset(string $filename, array $post): array
    {
        $data = parent::addCustomCSSPreset($filename, $post);

        $filePath = self::CUSTOM_CSS_PRESETS_PATH.DIRECTORY_SEPARATOR.$filename;
        if ($data['status'] && file_exists($filePath)) {
            // append font data

            $mainTextFontFamily = (empty($post['main-text-fontfamily']) || !is_string($post['main-text-fontfamily'])) ? '' : $post['main-text-fontfamily'];
            $fontString = empty($mainTextFontFamily) ? '' : $this->installAndGetCSSForFont($mainTextFontFamily);

            $mainTitleFontFamily = (empty($post['main-title-fontfamily']) || !is_string($post['main-title-fontfamily'])) ? '' : $post['main-title-fontfamily'];
            if ($mainTitleFontFamily != $mainTextFontFamily) {
                $newFontString = empty($mainTitleFontFamily) ? '' : $this->installAndGetCSSForFont($mainTitleFontFamily);
                if (!empty($newFontString)) {
                    $fontString .= "\n$newFontString";
                }
            }

            if (!empty($fontString)) {
                file_put_contents($filePath, "\n$fontString\n", FILE_APPEND);
            }
        }
        return $data;
    }

    /**
     * install font and get css
     * @param string $fontFamily
     * @return string $css
     */
    private function installAndGetCSSForFont(string $fontFamily): string
    {
        $css = '';
        $fontFamily = $this->cleanFont($fontFamily);
        if (!empty($fontFamily)) {
            $newCss = $this->getFontFiles($fontFamily);
            if (!empty($newCss)) {
                $css .= "\n$newCss";
            }
        }
        return $css;
    }

    protected function getFontFiles(string $fontFamily): string
    {
        $css = '';

        $fontFamilyForUrl = $this->convertFamilyToUrl($fontFamily);
        if (!empty($fontFamilyForUrl)) {
            $data = [];
            foreach (self::USER_AGENTS as $name => $value) {
                $data[$name] = $this->getFontDescription($fontFamilyForUrl, $name);
                if (empty($data[$name])) {
                    unset($data[$name]);
                }
            }
            $css = $this->formatCSS($data);
        }
        return $css;
    }

    protected function getFontDescription(string $fontFamily, string $userAgent): array
    {
        $data = [];
        $ch =  curl_init("https://fonts.googleapis.com/css?family=$fontFamily&subset=latin-ext");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $headers = ['Accept: text/css,*/*;q=0.1'];
        if (!empty(self::USER_AGENTS[$userAgent])) {
            $headers[] = 'User-Agent: '.self::USER_AGENTS[$userAgent];
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);
        $errorNb = curl_errno($ch);
        curl_close($ch);
        if (!$errorNb) {
            $data = $this->parseCSS($result);
        }
        return $data;
    }

    protected function cleanFont(string $fontFamily): string
    {
        $fontFamily = explode(',', $fontFamily)[0];
        return str_replace(
            ['\''],
            [''],
            $fontFamily
        );
    }

    protected function convertFamilyToUrl(string $fontFamily): string
    {
        $fontFamily = $this->cleanFont($fontFamily);
        return str_replace(
            [' '],
            ['+'],
            $fontFamily
        );
    }

    protected function parseCSS(string $css): array
    {
        $data = [];
        $this->parseFontFace($css, "", $data);
        $this->parseFontFace($css, "latin", $data);
        $this->parseFontFace($css, "latin-ext", $data);

        return $data;
    }

    protected function parseFontFace(string $css, string $subset, array &$data)
    {
        $formattedSubSet = empty($subset) ? '' : "\/\*\s*".preg_quote($subset, "/")."\s*\*\/\s*";
        if (preg_match("/$formattedSubSet@font-face \{([^}]*)src: url\((https:\/\/fonts\.gstatic\.com\/[A-Za-z0-9_\-.\/]+)\)(?: format\('([A-Za-z0-9 \-_]+)'\))?([^}]*)\}/", $css, $match)) {
            $format = empty($match[3]) ? 'eot' : $match[3];
            $data[$subset] = [
                'url' => [
                    $format => $match[2]
                ]
            ];
            if (preg_match("/font-family: '([A-Za-z0-9 ]+)';/", $match[1], $familyMatch)) {
                $data[$subset]['family'] = $familyMatch[1];
            }
            if (preg_match("/font-style: ([A-Za-z0-9 ]+);/", $match[1], $styleMatch)) {
                $data[$subset]['style'] = $styleMatch[1];
            }
            if (preg_match("/font-weight: ([A-Za-z0-9 ]+);/", $match[1], $weightMatch)) {
                $data[$subset]['weight'] = $weightMatch[1];
            }
            if (preg_match("/unicode-range: ([A-Za-z0-9 \+,\-]+);/", $match[4], $rangeMatch)) {
                $data[$subset]['unicode-range'] = $rangeMatch[1];
            }
        }
    }

    protected function formatCSS(array $data): string
    {
        $css= "";
        $formattedData = [];
        foreach ($data as $userAgent => $values) {
            foreach ($values as $charset => $raw) {
                if (!empty($raw['family']) && !empty($raw['style']) && !empty($raw['weight'])) {
                    $key = "{$raw['family']}-{$raw['style']}-{$raw['weight']}";
                    if (!isset($formattedData[$key])) {
                        $formattedData[$key] = [
                            'family' => $raw['family'],
                            'style' => $raw['style'],
                            'weight' => $raw['weight'],
                            'charsets' => []
                        ];
                    }
                    foreach ($raw['url'] as $format => $url) {
                        if (!isset($formattedData[$key]['charsets'][$charset])) {
                            $formattedData[$key]['charsets'][$charset] = [
                                'url' => []
                            ];
                        }
                        if (isset($raw['unicode-range']) &&
                            !isset($formattedData[$key]['charsets'][$charset]['unicode-range'])) {
                            $formattedData[$key]['charsets'][$charset]['unicode-range'] = $raw['unicode-range'];
                        }
                        if (!isset($formattedData[$key]['charsets'][$charset]['url'][$format])) {
                            $formattedData[$key]['charsets'][$charset]['url'][$format] = $url;
                        }
                    }
                }
            }
        }
        if (!empty($formattedData)) {
            foreach ($formattedData as $raw) {
                foreach ($raw['charsets'] as $charset => $val) {
                    $eotUrl = $val['url']['eot'] ?? '';
                    $woff2Url = $val['url']['woff2'] ?? '';
                    $woffUrl = $val['url']['woff'] ?? '';
                    $truetypeUrl = $val['url']['truetype'] ?? '';
                    if (!empty($eotUrl)) {
                        $eotUrl = $this->importFontFile(
                            $raw['family'],
                            $raw['style'],
                            $raw['weight'],
                            $charset,
                            'eot',
                            $eotUrl
                        );
                        $eotUrl =  "\n  src: url('$eotUrl');";
                    }
                    foreach (['woff2','woff','truetype'] as $name) {
                        $varName = "{$name}Url";
                        $var = ${$varName};
                        if (!empty($var)) {
                            $var = $this->importFontFile(
                                $raw['family'],
                                $raw['style'],
                                $raw['weight'],
                                $charset,
                                $name,
                                $var
                            );
                            ${$varName} = ",\n        url('$var') format('$name')";
                        }
                    }
                    $unicodeRange = $val['unicode-range'] ?? '';
                    if (!empty($unicodeRange)) {
                        $unicodeRange = "\n  unicode-range: $unicodeRange;";
                    }

                    if (!empty($charset)) {
                        $css .=
                        <<<CSS

                        /* $charset */

                        CSS;
                    }

                    $css .=
                    <<<CSS
                    @font-face {
                      font-family: '{$raw['family']}';
                      font-style: {$raw['style']};
                      font-weight: {$raw['weight']};$eotUrl
                      src: local('')$woff2Url$woffUrl$truetypeUrl;$unicodeRange
                    }
                    CSS;
                }
            }
        }
        return $css;
    }

    protected function importFontFile(string $family, string $style, string $weight, string $charset, string $format, string $url): string
    {
        $folderSystemName = sanitizeFilename($family);
        if (!is_dir(self::CUSTOM_FONT_PATH."/$folderSystemName")) {
            mkdir(self::CUSTOM_FONT_PATH."/$folderSystemName", 0777, true);
        }

        switch ($format) {
            case 'eot':
                $ext=".eot";
                break;
            case 'woff2':
                $ext=".woff2";
                break;
            case 'woff':
                $ext=".woff";
                break;
            case 'truetype':
                $ext=".ttf";
                break;

            default:
                $ext="";
                break;
        }
        $fileName = sanitizeFilename("$family-$style-$weight-$charset").$ext;

        $ch =  curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $result = curl_exec($ch);
        $errorNb = curl_errno($ch);
        curl_close($ch);
        if (!$errorNb && !empty($result)) {
            if (file_put_contents(self::CUSTOM_FONT_PATH."/$folderSystemName/$fileName", $result) &&
                file_exists(self::CUSTOM_FONT_PATH."/$folderSystemName/$fileName")) {
                return "../../".self::CUSTOM_FONT_PATH."/$folderSystemName/$fileName";
            }
        }

        return $url;
    }

    /**
     * get presets data
     * @return array
     *      [
     *          'themePresets' => [],
     *          'dataHtmlForPresets' => [],
     *          'selectedPresetName' => ''/null,
     *          'customCSSPresets' => [],
     *          'dataHtmlForCustomCSSPresets' => [],
     *          'selectedCustomPresetName' => ''/null,
     *          'currentCSSValues' => [],
     *      ]
     */
    public function getPresetsData(): ?array
    {
        $themePresets = $this->wiki->config['templates'][$this->wiki->config['favorite_theme']]['presets'] ?? [];
        $dataHtmlForPresets = array_map(function ($value) {
            return $this->extractDataFromPreset($value);
        }, $themePresets);
        $customCSSPresets = $this->getCustomCSSPresets() ;
        $dataHtmlForCustomCSSPresets = array_map(function ($value) {
            return $this->extractDataFromPreset($value);
        }, $customCSSPresets);
        if (!empty($this->wiki->config['favorite_preset'])) {
            $presetName = $this->wiki->config['favorite_preset'];
            if (substr($presetName, 0, strlen(self::CUSTOM_CSS_PRESETS_PREFIX)) == self::CUSTOM_CSS_PRESETS_PREFIX) {
                $presetName = substr($presetName, strlen(self::CUSTOM_CSS_PRESETS_PREFIX));
                if (in_array($presetName, array_keys($customCSSPresets))) {
                    $currentCSSValues = $this->extractPropValuesFromPreset($customCSSPresets[$presetName]);
                    $selectedCustomPresetName = $presetName;
                }
            } else {
                if (in_array($presetName, array_keys($themePresets))) {
                    $currentCSSValues = $this->extractPropValuesFromPreset($themePresets[$presetName]);
                    $selectedPresetName = $presetName;
                }
            }
        }

        return [
            'themePresets' => $themePresets,
            'dataHtmlForPresets' => $dataHtmlForPresets,
            'selectedPresetName' => $selectedPresetName ?? null,
            'customCSSPresets' => $customCSSPresets,
            'dataHtmlForCustomCSSPresets' => $dataHtmlForCustomCSSPresets,
            'selectedCustomPresetName' => $selectedCustomPresetName ?? null,
            'currentCSSValues' => $currentCSSValues ?? [],
        ];
    }

    /**
     * extract data from preset
     * @param string $presetContent
     * @return string data to put in html
     */
    private function extractDataFromPreset(string $presetContent): string
    {
        $data = '';
        $values = $this->extractPropValuesFromPreset($presetContent);
        foreach ($values as $prop => $value) {
            $data .= ' data-'.$prop.'="'.str_replace('"', '\'', $value).'"';
        }
        if (
            !empty($data)
            && !empty($values['primary-color'])
            && !empty($values['main-text-fontsize']
            && !empty($values['main-text-fontfamily']))
        ) {
            $data .= ' style="';
            $data .= 'color:'.$values['primary-color'].';';
            $data .= 'font-family:'.str_replace('"', '\'', $values['main-text-fontfamily']).';';
            $data .= 'font-size:'.$values['main-text-fontsize'].';';
            $data .= '"';
        }
        return $data;
    }

    /**
     * extract properties values from preset contents
     * @param string $presetContent
     * @return array
     */
    private function extractPropValuesFromPreset(string $presetContent): array
    {
        // extract root part
        $matches = [];
        $results = [];
        $error = false;
        if (preg_match('/^:root\s*{((?:.|\n)*)}\s*[^{]*/', $presetContent, $matches)) {
            $vars = $matches[1];


            if (preg_match_all('/\s*--([0-9a-z\-]*):\s*([^;]*);\s*/', $vars, $matches)) {
                foreach ($matches[0] as $index => $val) {
                    $newmatch = [];
                    if (preg_match('/[a-z\-]*color[a-z0-9\-]*/', $matches[1][$index], $newmatch)) {
                        if (!preg_match('/^#[A-Fa-f0-9]*$/', $matches[2][$index], $newmatch)) {
                            $error = true;
                        }
                    }
                    $results[$matches[1][$index]] = $matches[2][$index];
                }
            }
        }
        return $error ? [] : $results;
    }
}
