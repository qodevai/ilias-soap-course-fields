<?php

// SPDX-License-Identifier: GPL-3.0-only

declare(strict_types=1);

class ilCourseUserFieldsGetValuesMethod extends ilCourseUserFieldsMethod
{
    public function getName(): string
    {
        return 'getCourseUserFieldValues';
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
        return 'Returns the course-specific user field values of all course members as XML. '
            . 'Same access rule as the member export in the web UI: manage_members on the course, '
            . 'export_member_data in the privacy settings, and course export enabled there. '
            . 'If the privacy settings require course confirmation, only members who accepted the agreement are returned.';
    }

    protected function run(array $params): string
    {
        $ref_id = (int) $params[1];
        $obj_id = $this->courseObjId($ref_id);
        // the same gate ilMemberExportGUI uses for the web export
        if (!ilPrivacySettings::getInstance()->checkExportAccess($ref_id, $this->callerId())) {
            $this->raiseError('Permission denied: member export is not allowed for this course', self::ERR_CLIENT);
        }

        $members = ilCourseParticipants::_getInstanceByObjId($obj_id)->getParticipants();
        // same as the participants table: with "course confirmation" on, only members who accepted the agreement are shown
        if (ilPrivacySettings::getInstance()->courseConfirmationRequired()) {
            $members = array_intersect($members, ilMemberAgreement::lookupAcceptedAgreements($obj_id));
        }
        $values = [];
        foreach (ilCourseUserData::_getValuesByObjId($obj_id) as $usr_id => $fields) {
            if (in_array($usr_id, $members, false)) {
                $values[(int) $usr_id] = array_map('strval', $fields);
            }
        }
        return ilCourseUserFieldsXml::fieldValues($ref_id, $obj_id, $values);
    }
}
