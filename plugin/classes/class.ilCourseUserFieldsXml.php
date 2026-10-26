<?php

// SPDX-License-Identifier: GPL-3.0-only

declare(strict_types=1);

/**
 * Builds and parses the XML of the course user field SOAP methods.
 * Pure logic: no ILIAS globals, so it runs under plain PHPUnit.
 */
final class ilCourseUserFieldsXml
{
    public const TYPE_TEXT = 'text';
    public const TYPE_SELECT = 'select';

    /**
     * @param array<int, array{id:int, name:string, type:string, required:bool, options:array<int, array{text:string, open:bool}>}> $fields
     */
    public static function fieldDefinitions(int $ref_id, int $obj_id, array $fields): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('CourseUserFields');
        $root->setAttribute('ref_id', (string) $ref_id);
        $root->setAttribute('obj_id', (string) $obj_id);
        $doc->appendChild($root);

        foreach ($fields as $field) {
            $el = $doc->createElement('Field');
            $el->setAttribute('id', (string) $field['id']);
            $el->setAttribute('name', $field['name']);
            $el->setAttribute('type', $field['type']);
            $el->setAttribute('required', $field['required'] ? '1' : '0');
            foreach ($field['options'] as $option) {
                $opt = $doc->createElement('Option');
                $opt->setAttribute('open', $option['open'] ? '1' : '0');
                $opt->appendChild($doc->createTextNode($option['text']));
                $el->appendChild($opt);
            }
            $root->appendChild($el);
        }
        return (string) $doc->saveXML();
    }

    /**
     * @param array<int, array<int, string>> $values usr_id => field_id => value
     */
    public static function fieldValues(int $ref_id, int $obj_id, array $values): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('CourseUserFieldValues');
        $root->setAttribute('ref_id', (string) $ref_id);
        $root->setAttribute('obj_id', (string) $obj_id);
        $doc->appendChild($root);

        ksort($values);
        foreach ($values as $usr_id => $fields) {
            $member = $doc->createElement('Member');
            $member->setAttribute('usr_id', (string) $usr_id);
            ksort($fields);
            foreach ($fields as $field_id => $value) {
                $val = $doc->createElement('Value');
                $val->setAttribute('field_id', (string) $field_id);
                $val->appendChild($doc->createTextNode($value));
                $member->appendChild($val);
            }
            $root->appendChild($member);
        }
        return (string) $doc->saveXML();
    }

    /**
     * Parses <CourseUserFieldValues><Value field_id="1">x</Value>...</CourseUserFieldValues>.
     *
     * @return array<int, string> field_id => value
     * @throws InvalidArgumentException on malformed XML, unknown elements or duplicate field ids
     */
    public static function parseValues(string $xml): array
    {
        if (trim($xml) === '') {
            throw new InvalidArgumentException('Malformed XML');
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET: never fetch external entities. No LIBXML_NOENT: do not expand them.
            $loaded = $doc->loadXML($xml, LIBXML_NONET);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $errors !== []) {
            throw new InvalidArgumentException('Malformed XML');
        }
        if ($doc->doctype !== null) {
            throw new InvalidArgumentException('DOCTYPE is not allowed');
        }
        $root = $doc->documentElement;
        if ($root === null || $root->nodeName !== 'CourseUserFieldValues') {
            throw new InvalidArgumentException('Root element must be CourseUserFieldValues');
        }

        $values = [];
        foreach ($root->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE && trim($node->textContent) === '') {
                continue;
            }
            if ($node->nodeType === XML_COMMENT_NODE) {
                continue;
            }
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                throw new InvalidArgumentException('Unexpected content in CourseUserFieldValues');
            }
            if ($node->nodeName !== 'Value') {
                throw new InvalidArgumentException('Unexpected element ' . $node->nodeName);
            }
            /** @var DOMElement $node */
            $raw_id = $node->getAttribute('field_id');
            if (!ctype_digit($raw_id) || (int) $raw_id === 0) {
                throw new InvalidArgumentException('Value needs a numeric field_id');
            }
            $field_id = (int) $raw_id;
            if (array_key_exists($field_id, $values)) {
                throw new InvalidArgumentException("Duplicate field_id $field_id");
            }
            foreach ($node->childNodes as $child) {
                if ($child->nodeType !== XML_TEXT_NODE && $child->nodeType !== XML_CDATA_SECTION_NODE) {
                    throw new InvalidArgumentException("Value $field_id may only contain text");
                }
            }
            $values[$field_id] = $node->textContent;
        }
        return $values;
    }
}
