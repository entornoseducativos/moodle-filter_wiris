<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
//

/**
 * This filter doesn't make any calls to the wiris.net services, instead
 * converting safeXML into normal XML. Then, it injects a <script> tag
 * calling the WIRIS services, which then render and return the image.
 *
 * @package    filter
 * @subpackage wiris
 * @copyright  WIRIS Europe (Maths for more S.L)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();


class filter_wiris_js extends moodle_text_filter {

    /**
     * Set any context-specific configuration for this filter.
     *
     * @param context $context The current context.
     * @param array $localconfig Any context-specific configuration for this filter.
     */
    public function __construct($context, array $localconfig) {
        $this->context = $context;
        $this->localconfig = $localconfig;
    }

    public function filter($text, array $options = array()) {

        // From original php filter, act only when we detect a math formula
        // inside the text to filter.
        $n0 = mb_stripos($text, '«math');
        $n1 = stripos($text, '<math');
        $n2 = mb_stripos($text, '«applet');
        // Otherwise, we do nothing.
        if ($n0 === false && $n1 === false && $n2 === false) {
            // Nothing to do.
            return $text;
        }

        // MathJax and MathML:
        // Do nothing if the MathJax filter executes before this MathType filter.
        if ($n1 !== false && $this->mathjax_have_preference()) {
            return $text;
        }

        // Step 1.
        // Replace Wiris Graph constructions by placeholders
        $constructions = array();
        $construction_position = strpos($text, "data-wirisconstruction", 0);
        while ($construction_position !== false) {
            $i = 0;
            
            $construction_position += strlen("data-wirisconstruction=\"");
            $construction_end = strpos($text, "\"", $construction_position);
            $construction = substr($text, $construction_position, $construction_end - $construction_position);
            $constructions[$i] = $construction;

            $i++;
            if ($construction_end === false) {
                // This should not happen.
                break;
            }

            $construction_position = strpos($text, "data-wirisconstruction", $construction_end);
        }
        for ($i = 0; $i < count($constructions); $i++) {
            $text = $this->replace_first_occurrence($text, $constructions[$i], "construction-placeholder-" . $i);
        }

        // Step 2.
        // Decoding entities.
        // Step 2.1.
        // Entity decoding translation table values.
        $safexmlentities = [
            'tagOpener' => '&laquo;',
            'tagCloser' => '&raquo;',
            'doubleQuote' => '&uml;',
            'realDoubleQuote' => '&quot;',
        ];

        $safexml = [
            'tagOpener' => '«',
            'tagCloser' => '»',
            'doubleQuote' => '¨',
            'ampersand' => '§',
            'quote' => '`',
            'realDoubleQuote' => '¨',
        ];

        $xml = [
            'tagOpener' => '<',
            'tagCloser' => '>',
            'doubleQuote' => '"',
            'ampersand' => '&',
            'quote' => '\'',
        ];

        // Step 2.2.
        // Perform entity translations
        $text = implode($safexml['tagOpener'], explode($safexmlentities['tagOpener'], $text));
        $text = implode($safexml['tagCloser'], explode($safexmlentities['tagCloser'], $text));
        $text = implode($safexml['doubleQuote'], explode($safexmlentities['doubleQuote'], $text));
        $text = implode($safexml['realDoubleQuote'], explode($safexmlentities['realDoubleQuote'], $text));

        // Step 2.3.
        // Replace safe XML characters with actual XML characters.
        $text = implode($xml['tagOpener'], explode($safexml['tagOpener'], $text));
        $text = implode($xml['tagCloser'], explode($safexml['tagCloser'], $text));
        $text = implode($xml['doubleQuote'], explode($safexml['doubleQuote'], $text));
        $text = implode($xml['ampersand'], explode($safexml['ampersand'], $text));
        $text = implode($xml['quote'], explode($safexml['quote'], $text));

        // Step 3.
        // We are replacing '$' by '&' when its part of an entity for retrocompatibility.
        $return = '';
        $currententity = null;
        // Now, the standard is replace '§' by '&'.
        $array = str_split($text);
        for ($i = 0; $i < count($array); $i++) {
            $character = $array[$i];
            if ($currententity === null) {
                if ($character === '$') {
                    $currententity = '';
                } else {
                    $return .= $character;
                }
            } else if ($character === ';') {
                $return += "&$currententity";
                $currententity = null;
            } else if (preg_match("([a-zA-Z0-9#._-] | '-')", $character)) { // Character is part of an entity.
                $currententity .= $character;
            } else {
                $return .= "$$currententity"; // Is not an entity.
                $currententity = null;
                $i -= 1; // Parse again the current character.
            }
        }

        if ($currententity !== null) {
            // It was not an entity, so we add it to the returned text using the dollar
            $return .= "$$currententity";
        }

        // Step 4.
        // Replace the placeholders by the Wiris Graph constructions
        for ($i = 0; $i < count($constructions); $i++) {
            $return = $this->replace_first_occurrence($return, "construction-placeholder-" . $i, $constructions[$i]);
        }

        return $return;
    }

    
    // We replace only the first occurrence because otherwise replacing construction-placeholder-1 would also
    // replace the construction-placeholder-10. By replacing only the first occurence we avoid this problem.
    private function replace_first_occurrence($haystack, $needle, $replace) {
        $pos = strpos($haystack, $needle);
        if ($pos !== false) {
            $newstring = substr_replace($haystack, $replace, $pos, strlen($needle));
            return $newstring;
        }

        return $haystack;
    }

    /**
     * Inserts the WIRISplugin.js file from wiris.net.
     *
     * @param moodle_page $page the page we are going to add requirements to.
     * @param context $context the context which contents are going to be filtered.
     * @since Moodle 2.3
     */
    public function setup($page, $context) {
        $page->requires->js(
            new moodle_url(
                'https://www.wiris.net/demo/plugins/app/WIRISplugins.js', 
                array(
                    'viewer' => 'image'                    
                )
            )
        );
    }

    /**
     * Returns true if MathJax filter is active in active context and
     * have preference over MathType filter
     * @return [bool] true if MathJax have preference over MathType filter. False otherwise.
     */
    private function mathjax_have_preference() {
        // The complex logic is working out the active state in the parent context,
        // so strip the current context from the list. We need avoid to call
        // filter_get_avaliable_in_context method if the context
        // is system context only.
        $contextids = explode('/', trim($this->context->path, '/'));
        array_pop($contextids);
        $contextids = implode(',', $contextids);
        // System context only.
        if (empty($contextids)) {
            return false;
        }

        $mathjaxpreference = false;
        $mathjaxfilteractive = false;
        $avaliablecontextfilters = filter_get_available_in_context($this->context);

        // First we need to know if MathJax filter is active in active context.
        if (array_key_exists('mathjaxloader', $avaliablecontextfilters)) {
            $mathjaxfilter = $avaliablecontextfilters['mathjaxloader'];
            $mathjaxfilteractive = $mathjaxfilter->localstate == TEXTFILTER_ON ||
                                   ($mathjaxfilter->localstate == TEXTFILTER_INHERIT &&
                                    $mathjaxfilter->inheritedstate == TEXTFILTER_ON);
        }

        // Check filter orders.
        if ($mathjaxfilteractive) {
            $filterkeys = array_keys($avaliablecontextfilters);
            $mathjaxfilterorder = array_search('mathjaxloader', $filterkeys);
            $mathtypefilterorder = array_search('wiris', $filterkeys);

            if ($mathtypefilterorder > $mathjaxfilterorder) {
                $mathjaxpreference = true;
            }
        }

        return $mathjaxpreference;
    }


}
