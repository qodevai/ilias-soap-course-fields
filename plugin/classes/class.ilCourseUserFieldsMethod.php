<?php

// SPDX-License-Identifier: GPL-3.0-only

declare(strict_types=1);

/**
 * Shared session, course lookup and field definition loading for the three methods.
 * Every method checks the rights of the authenticated SOAP user, never of a cached user.
 */
abstract class ilCourseUserFieldsMethod extends ilAbstractSoapMethod
{
    // ilSoapPluginException passes the code to Exception, which needs an int.
    protected const ERR_CLIENT = 400;
    protected const ERR_SERVER = 500;

    protected const TYPE_STRING = 'xsd:string';
    protected const TYPE_INT = 'xsd:int';

    public function getServiceNamespace(): string
    {
        return 'urn:' . ilCourseUserFieldsPlugin::PLUGIN_NAME;
    }

    /**
     * @param array<int, mixed> $params positional SOAP parameters, sid first
     */
    public function execute(array $params)
    {
        try {
            $this->checkParameters($params);
            $this->initIliasAndCheckSession((string) $params[0]);
            return $this->run($params);
        } catch (ilSoapPluginException $e) {
            throw $e;
        } catch (Throwable $e) {
            // ILIAS hands only ilSoapPluginException messages to the caller. Anything else would
            // reach the caller as a raw fault with SQL text and class names.
            $this->logError($e);
            $this->raiseError('Internal error. See the ILIAS log for details.', self::ERR_SERVER);
        }
    }

    protected function logError(Throwable $e): void
    {
        global $DIC;
        $DIC->logger()->root()->error(static::class . ': ' . $e);
    }

    /**
     * @param array<int, mixed> $params
     */
    abstract protected function run(array $params): string;

    /**
     * @return int obj_id of the course behind $ref_id
     * @throws ilSoapPluginException
     */
    protected function courseObjId(int $ref_id): int
    {
        if (ilObject::_lookupType($ref_id, true) !== 'crs') {
            $this->raiseError("Invalid ref_id $ref_id: not a course", self::ERR_CLIENT);
        }
        return (int) ilObject::_lookupObjId($ref_id);
    }

    protected function callerId(): int
    {
        global $DIC;
        return $DIC->user()->getId();
    }

    /**
     * @throws ilSoapPluginException
     */
    protected function requireManageMembers(int $ref_id): void
    {
        global $DIC;
        if (!$DIC->access()->checkAccessOfUser($this->callerId(), 'manage_members', '', $ref_id)) {
            $this->raiseError('Permission denied: manage_members is required on the course', self::ERR_CLIENT);
        }
    }

    /**
     * @return array<int, array{id:int, name:string, type:string, required:bool, options:array<int, array{text:string, open:bool}>}> by field id
     */
    protected function fieldDefinitions(int $obj_id): array
    {
        $definitions = [];
        foreach (ilCourseDefinedFieldDefinition::_getFields($obj_id) as $field) {
            $open_indexes = $field->getValueOptions();
            $options = [];
            if ($field->getType() === ilCourseDefinedFieldDefinition::IL_CDF_TYPE_SELECT) {
                foreach ($field->getValues() as $index => $text) {
                    $options[] = ['text' => (string) $text, 'open' => in_array($index, $open_indexes, false)];
                }
            }
            $definitions[$field->getId()] = [
                'id' => $field->getId(),
                'name' => $field->getName(),
                'type' => $field->getType() === ilCourseDefinedFieldDefinition::IL_CDF_TYPE_SELECT
                    ? ilCourseUserFieldsXml::TYPE_SELECT
                    : ilCourseUserFieldsXml::TYPE_TEXT,
                'required' => $field->isRequired(),
                'options' => $options,
            ];
        }
        return $definitions;
    }
}
