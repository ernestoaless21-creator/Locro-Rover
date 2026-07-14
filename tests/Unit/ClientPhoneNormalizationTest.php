<?php

namespace Tests\Unit;

use App\Models\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fase 7, seccion 5 y 15: distintos formatos habituales de un mismo celular
 * argentino (AMBA) deben normalizarse SIEMPRE al mismo valor canonico.
 */
class ClientPhoneNormalizationTest extends TestCase
{
    public static function equivalentFormatsProvider(): array
    {
        return [
            '10 digitos con espacio y guion' => ['11 1234-5678'],
            '10 digitos pegados' => ['1112345678'],
            'internacional con 9 y guion' => ['+54 9 11 1234-5678'],
            'internacional con 9 sin espacios' => ['5491112345678'],
            'internacional con 9 y espacios' => ['54 9 11 1234 5678'],
            'prefijo nacional 0' => ['011 1234-5678'],
            'uso historico local del 15' => ['011 15 1234-5678'],
        ];
    }

    #[DataProvider('equivalentFormatsProvider')]
    public function test_recognizes_equivalent_amba_mobile_formats(string $input): void
    {
        $this->assertSame('11-1234-5678', Client::normalizePhone($input));
    }

    public function test_does_not_destroy_unrecognized_formats(): void
    {
        // Fijo de otra provincia con codigo de area de 4 digitos: la app no
        // adivina donde termina el codigo de area, asi que no debe forzar
        // ningun formato ni truncar el numero.
        $original = '02202 456789';
        $this->assertSame('02202 456789', Client::normalizePhone($original));
    }

    public function test_null_and_empty_are_handled_safely(): void
    {
        $this->assertNull(Client::normalizePhone(null));
        $this->assertNull(Client::normalizePhone(''));
        $this->assertNull(Client::normalizePhone('   '));
    }

    public function test_collapses_whitespace_when_format_is_not_recognized(): void
    {
        // 8 digitos (numero fijo viejo sin codigo de area): no matchea el
        // patron reconocido de 10 digitos, asi que solo se colapsan espacios
        // repetidos, sin forzar ningun formato ni inventar un codigo de area.
        $this->assertSame('45 67 89 00', Client::normalizePhone('45   67    89  00'));
    }
}
