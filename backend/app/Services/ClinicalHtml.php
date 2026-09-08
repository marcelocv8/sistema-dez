<?php

namespace App\Services;

/** Rebuilds clinical rich text from an allowlist; never copies executable markup. */
class ClinicalHtml
{
    public static function clean(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $body = $document->getElementsByTagName('body')->item(0);

            return $body ? self::children($body) : '';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function children(\DOMNode $node): string
    {
        $result = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $result .= htmlspecialchars($child->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } elseif ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'form', 'input', 'button', 'textarea', 'select', 'img', 'link', 'meta', 'base'], true)) {
                    continue;
                }
                $content = self::children($child);
                if (! in_array($tag, ['p', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'sub', 'sup', 'br', 'hr', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'h1', 'h2', 'h3', 'h4', 'blockquote', 'pre'], true)) {
                    $result .= $content;

                    continue;
                }
                $attributes = '';
                foreach (['colspan', 'rowspan'] as $attribute) {
                    $value = $child->getAttribute($attribute);
                    if (in_array($tag, ['td', 'th'], true) && preg_match('/^[1-9][0-9]{0,2}$/D', $value)) {
                        $attributes .= ' '.$attribute.'="'.$value.'"';
                    }
                }
                $styles = [];
                foreach (explode(';', $child->getAttribute('style')) as $declaration) {
                    $pair = explode(':', $declaration, 2);
                    if (count($pair) !== 2) {
                        continue;
                    }
                    [$property, $value] = array_map('trim', $pair);
                    $property = strtolower($property);
                    $value = strtolower($value);
                    $allowed = match ($property) {
                        'text-align' => (bool) preg_match('/^(left|right|center|justify)$/D', $value),
                        'font-weight' => (bool) preg_match('/^(normal|bold|[1-9]00)$/D', $value),
                        'font-style' => in_array($value, ['normal', 'italic'], true),
                        'text-decoration' => in_array($value, ['none', 'underline', 'line-through'], true),
                        'font-size','width','height','padding','margin-left' => (bool) preg_match('/^[0-9]{1,3}(\.[0-9]+)?(px|pt|em|%)$/D', $value),
                        'color','background-color' => (bool) preg_match('/^(#[0-9a-f]{3,8}|black|white|red|blue|green|gray)$/D', $value),
                        'border-collapse' => in_array($value, ['collapse', 'separate'], true),
                        'border' => (bool) preg_match('/^[0-9]{1,2}px solid (#[0-9a-f]{3,6}|black|gray)$/D', $value),
                        default => false,
                    };
                    if ($allowed) {
                        $styles[] = $property.':'.$value;
                    }
                }
                if ($styles !== []) {
                    $attributes .= ' style="'.implode(';', $styles).'"';
                }
                $result .= '<'.$tag.$attributes.'>'.(in_array($tag, ['br', 'hr'], true) ? '' : $content.'</'.$tag.'>');
            }
        }

        return $result;
    }
}
