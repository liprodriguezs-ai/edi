<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use Modules\Configuration\Services\TemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the {variable} substitution logic of TemplateRenderer.
 * (render() with Setting lookups is exercised in the feature test.)
 */
final class TemplateRendererTest extends TestCase
{
    public function test_substitutes_known_variables(): void
    {
        $renderer = new TemplateRenderer;

        $result = $renderer->substitute(
            'Hola {nombre_residente}, tu cuota de {concepto} por ${monto} venció.',
            [
                'nombre_residente' => 'Rosa',
                'concepto' => 'Mantenimiento',
                'monto' => '700',
            ]
        );

        $this->assertSame('Hola Rosa, tu cuota de Mantenimiento por $700 venció.', $result);
    }

    public function test_leaves_unknown_variables_untouched(): void
    {
        $renderer = new TemplateRenderer;

        $result = $renderer->substitute('Hola {nombre_residente} en {unidad}.', [
            'nombre_residente' => 'Rosa',
        ]);

        $this->assertSame('Hola Rosa en {unidad}.', $result);
    }

    public function test_empty_context_returns_text_verbatim(): void
    {
        $renderer = new TemplateRenderer;

        $this->assertSame('Sin variables', $renderer->substitute('Sin variables', []));
    }

    public function test_null_context_value_renders_as_empty_string(): void
    {
        $renderer = new TemplateRenderer;

        $result = $renderer->substitute('Saldo: ${saldo}', ['saldo' => null]);

        $this->assertSame('Saldo: $', $result);
    }
}
