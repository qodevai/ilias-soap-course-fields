<?php

// SPDX-License-Identifier: GPL-3.0-only

declare(strict_types=1);

/**
 * Checks values against field definitions the way the web form does.
 * The UI stores the option TEXT of a select field (not its id); an "open answer"
 * option stores free text. Pure logic, no ILIAS globals.
 */
final class ilCourseUserFieldsValidator
{
    /** crs_user_data.value is varchar(4000) */
    public const MAX_LENGTH = 4000;

    /**
     * @param array<int, array{id:int, name:string, type:string, required:bool, options:array<int, array{text:string, open:bool}>}> $fields definitions of the course, by field id
     * @param array<int, string> $values field_id => value to write
     * @return string[] error messages; empty if valid
     */
    public static function validate(array $fields, array $values): array
    {
        $errors = [];
        foreach ($values as $field_id => $value) {
            if (!isset($fields[$field_id])) {
                $errors[] = "Field $field_id does not belong to this course";
                continue;
            }
            if (mb_strlen($value) > self::MAX_LENGTH) {
                $errors[] = "Value for field $field_id is longer than " . self::MAX_LENGTH . ' characters';
                continue;
            }
            $field = $fields[$field_id];
            if ($field['required'] && trim($value) === '') {
                $errors[] = "Field $field_id ({$field['name']}) is required";
                continue;
            }
            if ($field['type'] === ilCourseUserFieldsXml::TYPE_SELECT && $value !== '') {
                $has_open_answer = false;
                $matches = false;
                foreach ($field['options'] as $option) {
                    $has_open_answer = $has_open_answer || $option['open'];
                    if (!$option['open'] && $option['text'] === $value) {
                        $matches = true;
                    }
                }
                if (!$matches && !$has_open_answer) {
                    $errors[] = "Value for field $field_id ({$field['name']}) is not one of its options";
                }
            }
        }
        return $errors;
    }
}
