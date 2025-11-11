<?php
/**
 * Template Structure Parser - Extracts HTML structure from templates
 * 
 * This class analyzes template HTML to identify its structure (Bootstrap layout)
 * and creates a template with placeholders for content. This allows the AI to
 * generate content while preserving the exact structure.
 *
 * @package   aiplacement_modgen
 * @copyright 2024 Tom Cripps
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

/**
 * Parser for extracting template structure and creating content placeholders
 */
class template_structure_parser {
    
    /**
     * Ensure that all id attributes in the provided HTML are unique by appending a suffix.
     * Also updates references to those ids in href (anchors) and aria-controls attributes.
     *
     * @param string $html Raw HTML to process
     * @param string $suffix Suffix to append (e.g. 'w2' or 't1')
     * @return string Processed HTML with unique ids and updated references
     */
    public static function ensure_unique_ids($html, $suffix) {
        if (empty($html) || empty($suffix)) {
            return $html;
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // Preserve UTF-8
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Build a map of old ids -> new ids
        $idmap = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $oldid = $node->getAttribute('id');
            // If id already contains the suffix, skip
            if (substr($oldid, - (strlen($suffix) + 1)) === '-' . $suffix) {
                $idmap[$oldid] = $oldid;
                continue;
            }
            $newid = $oldid . '-' . $suffix;
            $node->setAttribute('id', $newid);
            $idmap[$oldid] = $newid;
        }

        if (empty($idmap)) {
            // No ids to update
            // Return original HTML (without the xml wrapper)
            $outer = $dom->saveHTML();
            // Remove the XML encoding stub if present
            $outer = preg_replace('/^<\?xml.*?\?>\s*/', '', $outer);
            return $outer;
        }

        // Update href attributes that reference ids (anchors like href="#someid")
        foreach ($xpath->query('//*[@href]') as $node) {
            $href = $node->getAttribute('href');
            if (strlen($href) > 1 && $href[0] === '#') {
                $target = substr($href, 1);
                if (isset($idmap[$target])) {
                    $node->setAttribute('href', '#' . $idmap[$target]);
                }
            }
        }

        // Update aria-controls attributes referencing ids
        foreach ($xpath->query('//*[@aria-controls]') as $node) {
            $ac = $node->getAttribute('aria-controls');
            if (isset($idmap[$ac])) {
                $node->setAttribute('aria-controls', $idmap[$ac]);
            }
        }

        // Update data-bs-target attributes that reference ids (may start with '#')
        foreach ($xpath->query('//*[@data-bs-target]') as $node) {
            $t = $node->getAttribute('data-bs-target');
            if (strlen($t) > 1 && $t[0] === '#') {
                $target = substr($t, 1);
                if (isset($idmap[$target])) {
                    $node->setAttribute('data-bs-target', '#' . $idmap[$target]);
                }
            }
        }

        // Update data-toggle attribute references (Bootstrap 4 style href targets may be used)
        foreach ($xpath->query('//*[@data-toggle]') as $node) {
            // Some templates use href for target - handled above. Nothing more required here for now.
        }

        $outer = $dom->saveHTML();
        $outer = preg_replace('/^<\?xml.*?\?>\s*/', '', $outer);
        return $outer;
    }
}
