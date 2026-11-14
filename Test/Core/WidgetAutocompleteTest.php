<?php

namespace FacturaScripts\Test\Core;

use FacturaScripts\Core\Lib\Widget\WidgetAutocomplete;
use FacturaScripts\Core\Lib\Widget\WidgetSelect;
use FacturaScripts\Dinamic\Model\CodeModel;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class WidgetAutocompleteTest extends TestCase
{
    private $previousSource;
    private $sourceProperty;

    protected function setUp(): void
    {
        $this->sourceProperty = new ReflectionProperty(WidgetSelect::class, 'codeModel');
        $this->sourceProperty->setAccessible(true);
        $this->previousSource = $this->sourceProperty->getValue();
    }

    protected function tearDown(): void
    {
        $this->sourceProperty->setValue(null, $this->previousSource);
    }

    /** @dataProvider emptyValues */
    public function testUnselectedFieldsRemainEmptyWithoutReadingTheSource($value): void
    {
        $source = new AutocompleteSourceFixture();
        $widget = new AutocompleteWidgetFixture($source);
        $this->assertSame('', $widget->descriptionFor($value));
        $this->assertSame([], $source->reads);
    }

    public function emptyValues(): array
    {
        return [[null], ['']];
    }

    /** @dataProvider selectedValues */
    public function testSelectedCodesIncludingZeroStillResolveTheirDescription($value): void
    {
        $source = new AutocompleteSourceFixture();
        $widget = new AutocompleteWidgetFixture($source);
        $this->assertSame('Descripción del documento', $widget->descriptionFor($value));
        $this->assertSame([$value], $source->reads);
    }

    public function selectedValues(): array
    {
        return [[0], ['0'], ['clave-de-prueba']];
    }

    public function testExplicitDescriptionDoesNotNeedAQuery(): void
    {
        $source = new AutocompleteSourceFixture();
        $widget = new AutocompleteWidgetFixture($source);
        $widget->setSelected('Texto elegido');
        $this->assertSame('Texto elegido', $widget->descriptionFor(null));
        $this->assertSame([], $source->reads);
    }
}

final class AutocompleteSourceFixture extends CodeModel
{
    public $reads = [];

    public function __construct()
    {
    }

    public function getDescription(string $tableName, string $fieldCode, $code, $fieldDescription): string
    {
        $this->reads[] = $code;
        return 'Descripción del documento';
    }
}

final class AutocompleteWidgetFixture extends WidgetAutocomplete
{
    public function __construct(CodeModel $source)
    {
        static::$codeModel = $source;
        $this->source = 'FacturaClienteDocumentSigned';
        $this->fieldcode = 'key_access';
        $this->fieldtitle = 'numero2';
    }

    public function descriptionFor($value): string
    {
        $this->value = $value;
        return $this->getSelected();
    }
}
