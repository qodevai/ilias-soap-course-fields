<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CourseUserFieldsTest extends TestCase
{
    private function fields(): array
    {
        return [
            1 => ['id' => 1, 'name' => 'Teilnahme', 'type' => 'select', 'required' => true, 'options' => [
                ['text' => 'Präsenz', 'open' => false],
                ['text' => 'Online', 'open' => false],
            ]],
            2 => ['id' => 2, 'name' => 'Rechnung', 'type' => 'select', 'required' => false, 'options' => [
                ['text' => 'Privat', 'open' => false],
                ['text' => 'Sonstiges', 'open' => true],
            ]],
            3 => ['id' => 3, 'name' => 'Bemerkung', 'type' => 'text', 'required' => false, 'options' => []],
        ];
    }

    public function testParseValues(): void
    {
        $xml = '<CourseUserFieldValues><Value field_id="1">Präsenz</Value><Value field_id="3">a &amp; b</Value></CourseUserFieldValues>';
        $this->assertSame([1 => 'Präsenz', 3 => 'a & b'], ilCourseUserFieldsXml::parseValues($xml));
    }

    #[DataProvider('badXml')]
    public function testParseValuesRejects(string $xml): void
    {
        $this->expectException(InvalidArgumentException::class);
        ilCourseUserFieldsXml::parseValues($xml);
    }

    public static function badXml(): array
    {
        return [
            'empty' => [''],
            'not xml' => ['nope'],
            'wrong root' => ['<x/>'],
            'unknown child' => ['<CourseUserFieldValues><Foo/></CourseUserFieldValues>'],
            'element inside value' => ['<CourseUserFieldValues><Value field_id="1">a<Foo>b</Foo></Value></CourseUserFieldValues>'],
            'text between values' => ['<CourseUserFieldValues>stray<Value field_id="1">a</Value></CourseUserFieldValues>'],
            'no field id' => ['<CourseUserFieldValues><Value>x</Value></CourseUserFieldValues>'],
            'non numeric id' => ['<CourseUserFieldValues><Value field_id="a">x</Value></CourseUserFieldValues>'],
            'duplicate id' => ['<CourseUserFieldValues><Value field_id="1">a</Value><Value field_id="1">b</Value></CourseUserFieldValues>'],
            'doctype / entity' => ['<!DOCTYPE r [<!ENTITY e SYSTEM "file:///etc/passwd">]><CourseUserFieldValues><Value field_id="1">&e;</Value></CourseUserFieldValues>'],
        ];
    }

    public function testValidatorAcceptsOptionTextAndOpenAnswer(): void
    {
        $this->assertSame([], ilCourseUserFieldsValidator::validate($this->fields(), [1 => 'Online', 2 => 'Firma X', 3 => '']));
    }

    public function testValidatorRejects(): void
    {
        $errors = ilCourseUserFieldsValidator::validate($this->fields(), [1 => 'Hybrid', 99 => 'x']);
        $this->assertCount(2, $errors);
        $this->assertCount(1, ilCourseUserFieldsValidator::validate($this->fields(), [1 => '  ']), 'required field must not be empty');
        $this->assertCount(1, ilCourseUserFieldsValidator::validate($this->fields(), [1 => 'Präsenz', 2 => ''] + [4 => 'x']), 'unknown field');
    }

    public function testValidatorRejectsFreeTextForSelectWithoutOpenAnswer(): void
    {
        $this->assertCount(1, ilCourseUserFieldsValidator::validate($this->fields(), [1 => 'Sonstiges']));
    }

    public function testValidatorLimitsLengthInCharacters(): void
    {
        $fields = [1 => ['id' => 1, 'name' => 'Notiz', 'type' => 'text', 'required' => false, 'options' => []]];
        $this->assertSame([], ilCourseUserFieldsValidator::validate($fields, [1 => str_repeat('ä', 4000)]));
        $this->assertCount(1, ilCourseUserFieldsValidator::validate($fields, [1 => str_repeat('ä', 4001)]));
    }

    public function testBuildersEscapeAndSort(): void
    {
        $xml = ilCourseUserFieldsXml::fieldValues(5, 9, [7 => [2 => '<b>', 1 => 'x'], 3 => [1 => 'y']]);
        $doc = simplexml_load_string($xml);
        $this->assertSame('3', (string) $doc->Member[0]['usr_id']);
        $this->assertSame('<b>', (string) $doc->Member[1]->Value[1]);

        $defs = simplexml_load_string(ilCourseUserFieldsXml::fieldDefinitions(5, 9, array_values($this->fields())));
        $this->assertSame('1', (string) $defs->Field[0]['required']);
        $this->assertSame('1', (string) $defs->Field[1]->Option[1]['open']);
    }
}
