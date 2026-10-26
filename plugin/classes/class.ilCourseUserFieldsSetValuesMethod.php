<?php

// SPDX-License-Identifier: GPL-3.0-only

declare(strict_types=1);

class ilCourseUserFieldsSetValuesMethod extends ilCourseUserFieldsMethod
{
    public function getName(): string
    {
        return 'setCourseUserFieldValues';
    }

    public function getInputParams(): array
    {
        return [
            'sid' => self::TYPE_STRING,
            'ref_id' => self::TYPE_INT,
            'user_id' => self::TYPE_INT,
            'xml' => self::TYPE_STRING,
        ];
    }

    public function getOutputParams(): array
    {
        return ['success' => self::TYPE_INT];
    }

    public function getDocumentation(): string
    {
        return 'Sets course-specific user field values for one course member. XML: '
            . '<CourseUserFieldValues><Value field_id="12">text</Value>...</CourseUserFieldValues>. '
            . 'Select fields take the option text. Only the listed fields change. Needs manage_members on the course. '
            . 'Returns 1 on success.';
    }

    protected function run(array $params): string
    {
        [, $ref_id, $user_id, $xml] = [$params[0], (int) $params[1], (int) $params[2], (string) $params[3]];
        $obj_id = $this->courseObjId($ref_id);
        $this->requireManageMembers($ref_id);

        if (!ilCourseParticipants::_getInstanceByObjId($obj_id)->isAssigned($user_id)) {
            $this->raiseError("User $user_id is not a member of course $ref_id", self::ERR_CLIENT);
        }

        try {
            $values = ilCourseUserFieldsXml::parseValues($xml);
        } catch (InvalidArgumentException $e) {
            $this->raiseError('Invalid xml: ' . $e->getMessage(), self::ERR_CLIENT);
        }
        $errors = ilCourseUserFieldsValidator::validate($this->fieldDefinitions($obj_id), $values);
        if ($errors !== []) {
            // nothing is written unless every value is valid
            $this->raiseError(implode('; ', $errors), self::ERR_CLIENT);
        }

        // the participants table prints stored values unescaped, and the web form strips tags with
        // ilUtil::stripSlashes(). Refuse values that this function would change.
        foreach ($values as $field_id => $value) {
            if (ilUtil::stripSlashes($value) !== $value) {
                $this->raiseError("Value of field $field_id contains HTML tags, which ILIAS does not allow in user fields", self::ERR_CLIENT);
            }
        }

        // ILIAS reports no transaction support for its db types, and ilCourseUserData::update()
        // deletes the old row before it inserts the new one. Keep the old values and try to put
        // them back if a write fails.
        $previous = ilCourseUserData::_getValuesByObjId($obj_id)[$user_id] ?? [];
        $attempted = [];
        try {
            foreach ($values as $field_id => $value) {
                $attempted[] = $field_id;
                $data = new ilCourseUserData($user_id, $field_id);
                $data->setValue($value);
                $data->update();
            }
            // same as the admin edit form: the participants table shows who changed the data and when
            $history = new ilObjectCustomUserFieldHistory($obj_id, $user_id);
            $history->setEditingTime(new ilDateTime(time(), IL_CAL_UNIX));
            $history->setUpdateUser($this->callerId());
            $history->save();
        } catch (Throwable $e) {
            $this->logError($e);
            $not_restored = [];
            foreach ($attempted as $field_id) {
                try {
                    $data = new ilCourseUserData($user_id, $field_id);
                    if (array_key_exists($field_id, $previous)) {
                        $data->setValue($previous[$field_id]);
                        $data->update();
                    } else {
                        $data->delete();
                    }
                } catch (Throwable) {
                    $not_restored[] = $field_id;
                }
            }
            $this->raiseError(
                'Writing the values failed.' . ($not_restored === []
                    ? ' The previous values were restored.'
                    : ' The previous values of these fields could not be restored: ' . implode(', ', $not_restored)),
                self::ERR_SERVER
            );
        }
        return '1';
    }
}
