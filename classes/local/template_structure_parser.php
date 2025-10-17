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
     * Analyzes template HTML and extracts its structure with content placeholders
     * 
     * @param string $template_html The raw HTML from the template
     * @return array Structure information with placeholders
     */
    public static function extract_structure_and_placeholders($template_html) {
        if (empty($template_html)) {
            return [
                'html' => '',
                'bootstrap_classes' => [],
                'text_content' => [],
                'structure_template' => '',
                'content_areas' => [],
            ];
        }
        
        // Extract structure information
        $structure = [
            'html' => $template_html,
            'bootstrap_classes' => self::extract_bootstrap_classes($template_html),
            'text_content' => self::extract_text_content($template_html),
            'structure_template' => self::create_structure_template($template_html),
            'content_areas' => self::count_content_areas($template_html),
        ];
        
        return $structure;
    }
    
    /**
     * Extract all Bootstrap-specific CSS classes from HTML
     */
    private static function extract_bootstrap_classes($html) {
        $bootstrap_classes = [];
        $pattern = '/class=["\']([^"\']*)/i';
        
        if (preg_match_all($pattern, $html, $matches)) {
            foreach ($matches[1] as $classes) {
                $class_list = explode(' ', $classes);
                foreach ($class_list as $class) {
                    $class = trim($class);
                    if (!empty($class) && self::is_bootstrap_class($class)) {
                        if (!isset($bootstrap_classes[$class])) {
                            $bootstrap_classes[$class] = 0;
                        }
                        $bootstrap_classes[$class]++;
                    }
                }
            }
        }
        
        return array_keys($bootstrap_classes);
    }
    
    /**
     * Check if a class is a Bootstrap class
     */
    private static function is_bootstrap_class($class) {
        $bootstrap_prefixes = [
            'col-', 'row', 'card', 'btn', 'nav', 'tab', 'accordion', 
            'alert', 'badge', 'list', 'grid', 'container', 'flex', 
            'justify', 'align', 'text-', 'bg-', 'border', 'shadow',
            'rounded', 'p-', 'm-', 'ml-', 'mr-', 'mt-', 'mb-',
            'd-', 'w-', 'h-', 'gap-', 'ms-', 'me-', 'ps-', 'pe-',
            'modal', 'form', 'input', 'label', 'dropdown', 'button'
        ];
        
        foreach ($bootstrap_prefixes as $prefix) {
            if (strpos($class, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Extract all text content from the template
     */
    private static function extract_text_content($html) {
        $text_content = [];
        
        // Create DOM document
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        
        $xpath = new \DOMXPath($dom);
        
        // Find all text nodes that aren't empty
        foreach ($xpath->query('//text()') as $node) {
            $text = trim($node->nodeValue);
            if (!empty($text) && strlen($text) > 2 && !is_numeric($text)) {
                $text_content[] = $text;
            }
        }
        
        return $text_content;
    }
    
    /**
     * Count distinct content areas (text nodes that could be replaced)
     */
    private static function count_content_areas($html) {
        return preg_match_all('/>([\s]*[^<]+[\s]*)</i', $html);
    }
    
    /**
     * Create a template structure with placeholders for content
     * 
     * This replaces actual text content with {{CONTENT_N}} markers while
     * preserving all HTML structure and Bootstrap classes exactly.
     */
    private static function create_structure_template($html) {
        $counter = 1;
        
        // Replace text content with numbered placeholders
        $placeholder_html = preg_replace_callback(
            '/>([\s]*[^<]+[\s]*)</i',
            function($matches) use (&$counter) {
                $content = trim($matches[1]);
                
                // Only replace if it's actual content (not just whitespace)
                if (!empty($content) && strlen($content) > 2 && !is_numeric($content)) {
                    $placeholder = '{{CONTENT_' . ($counter++) . '}}';
                    // Preserve indentation/whitespace from original
                    return '>' . $placeholder . '<';
                }
                return $matches[0];
            },
            $html
        );
        
        return $placeholder_html;
    }
    
    /**
     * Get a description of the template structure for AI guidance
     */
    public static function get_structure_description($structure) {
        $description = "Template Structure Analysis:\n";
        $description .= "- Bootstrap Components: " . (!empty($structure['bootstrap_classes']) 
            ? implode(', ', array_slice($structure['bootstrap_classes'], 0, 10))
            : 'None');
        $description .= "\n- Content Areas: " . $structure['content_areas'] . " text sections to fill";
        $description .= "\n- Original Content Topics: " . (!empty($structure['text_content'])
            ? implode('; ', array_slice($structure['text_content'], 0, 5))
            : 'None');
        
        return $description;
    }
    
    /**
     * Apply AI-generated content to the structure template
     * 
     * @param string $structure_template The template with {{CONTENT_N}} placeholders
     * @param array $content_pieces Array of content to fill in (indexed or associative)
     * @return string The final HTML with content filled in
     */
    public static function apply_content_to_template($structure_template, $content_pieces) {
        $result = $structure_template;
        
        // Handle array of content pieces
        if (is_array($content_pieces)) {
            foreach ($content_pieces as $index => $content) {
                $placeholder = '{{CONTENT_' . ($index + 1) . '}}';
                $result = str_replace($placeholder, $content, $result);
            }
        }
        
        // Handle single string content - replace all placeholders with variations
        if (is_string($content_pieces)) {
            $placeholder_count = preg_match_all('/{{CONTENT_\d+}}/', $result);
            for ($i = 1; $i <= $placeholder_count; $i++) {
                $placeholder = '{{CONTENT_' . $i . '}}';
                // Use the same content for all placeholders (AI generates it with context)
                $result = str_replace($placeholder, $content_pieces, $result, 1);
            }
        }
        
        // Remove any remaining unfilled placeholders
        $result = preg_replace('/{{CONTENT_\d+}}/', '', $result);
        
        return $result;
    }
}
