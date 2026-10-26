<?php

// SPDX-License-Identifier: GPL-3.0-only

declare(strict_types=1);

class ilCourseUserFieldsGetFieldsMethod extends ilCourseUserFieldsMethod
{
    public function getName(): string
    {
        return 'getCourseUserFields';
    }

    public function getInputParams(): array
    {
        return ['sid' => self::TYPE_STRING, 'ref_id' => self::TYPE_INT];
    }

    public function getOutputParams(): array
    {
        return ['xml' => self::TYPE_STRING];
    }

    public function getDocumentation(): string
    {
        return 'Returns the course-specific user field definitions (name, type, required, options) of a course as XML. '
            . 'Needs manage_members on the course.';
    }

    protected function run(array $params): string
    {
        $ref_id = (int) $params[1];
        $obj_id = $this->courseObjId($ref_id);
        $this->requireManageMembers($ref_id);
        return ilCourseUserFieldsXml::fieldDefinitions($ref_id, $obj_id, array_values($this->fieldDefinitions($obj_id)));
    }
}
