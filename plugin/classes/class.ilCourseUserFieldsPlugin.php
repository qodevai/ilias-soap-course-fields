<?php

// SPDX-License-Identifier: GPL-3.0-only

declare(strict_types=1);

require_once __DIR__ . '/class.ilCourseUserFieldsXml.php';
require_once __DIR__ . '/class.ilCourseUserFieldsValidator.php';
require_once __DIR__ . '/class.ilCourseUserFieldsMethod.php';
require_once __DIR__ . '/class.ilCourseUserFieldsGetFieldsMethod.php';
require_once __DIR__ . '/class.ilCourseUserFieldsGetValuesMethod.php';
require_once __DIR__ . '/class.ilCourseUserFieldsSetValuesMethod.php';

/**
 * SOAP hook plugin: read and write course-specific user fields
 * ("Kursvereinbarung" fields, table crs_user_data).
 */
class ilCourseUserFieldsPlugin extends ilSoapHookPlugin
{
    public const PLUGIN_NAME = 'CourseUserFields';

    public function getPluginName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getSoapMethods(): array
    {
        return [
            new ilCourseUserFieldsGetFieldsMethod(),
            new ilCourseUserFieldsGetValuesMethod(),
            new ilCourseUserFieldsSetValuesMethod(),
        ];
    }

    public function getWsdlTypes(): array
    {
        return [];
    }
}
