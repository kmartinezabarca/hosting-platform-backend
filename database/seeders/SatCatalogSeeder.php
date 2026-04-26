<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Siembra los catálogos SAT para CFDI 4.0:
 *  - Regímenes Fiscales (c_RegimenFiscal)
 *  - Usos de CFDI       (c_UsoCFDI)
 */
class SatCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedFiscalRegimes();
        $this->seedCfdiUses();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // c_RegimenFiscal  (vigente CFDI 4.0)
    // F = persona física  |  M = persona moral  |  B = ambas
    // ─────────────────────────────────────────────────────────────────────────
    private function seedFiscalRegimes(): void
    {
        $regimes = [
            // code  description                                                         física  moral
            ['601', 'General de Ley Personas Morales',                                   false,  true],
            ['603', 'Personas Morales con Fines no Lucrativos',                           false,  true],
            ['605', 'Sueldos y Salarios e Ingresos Asimilados a Salarios',                true,  false],
            ['606', 'Arrendamiento',                                                      true,  false],
            ['607', 'Régimen de Enajenación o Adquisición de Bienes',                    true,  false],
            ['608', 'Demás ingresos',                                                     true,  false],
            ['610', 'Residentes en el Extranjero sin Establecimiento Permanente en México', true, true],
            ['611', 'Ingresos por Dividendos (socios y accionistas)',                     true,  false],
            ['612', 'Personas Físicas con Actividades Empresariales y Profesionales',     true,  false],
            ['614', 'Ingresos por intereses',                                             true,  false],
            ['615', 'Régimen de los ingresos por obtención de premios',                  true,  false],
            ['616', 'Sin obligaciones fiscales',                                          true,  false],
            ['620', 'Sociedades Cooperativas de Producción que optan por diferir sus ingresos', false, true],
            ['621', 'Incorporación Fiscal',                                               true,  false],
            ['622', 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',           true,  true],
            ['623', 'Opcional para Grupos de Sociedades',                                 false,  true],
            ['624', 'Coordinados',                                                        false,  true],
            ['625', 'Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas', true, false],
            ['626', 'Régimen Simplificado de Confianza',                                  true,  true],
            ['628', 'Hidrocarburos',                                                      false,  true],
            ['629', 'De los Regímenes Fiscales Preferentes y de las Empresas Multinacionales', false, true],
            ['630', 'Enajenación de acciones en bolsa de valores',                        true,  false],
        ];

        $now = now();
        foreach ($regimes as [$code, $desc, $fisica, $moral]) {
            DB::table('fiscal_regimes')->updateOrInsert(
                ['code' => $code],
                [
                    'uuid'               => (string) Str::uuid(),
                    'description'        => $desc,
                    'applies_to_fisica'  => $fisica,
                    'applies_to_moral'   => $moral,
                    'is_active'          => true,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // c_UsoCFDI (vigente CFDI 4.0)
    // ─────────────────────────────────────────────────────────────────────────
    private function seedCfdiUses(): void
    {
        $uses = [
            // code   description                                                              física  moral
            ['G01',  'Adquisición de mercancias',                                              true,   true],
            ['G02',  'Devoluciones, descuentos o bonificaciones',                              true,   true],
            ['G03',  'Gastos en general',                                                      true,   true],
            ['I01',  'Construcciones',                                                         true,   true],
            ['I02',  'Mobilario y equipo de oficina por inversiones',                          true,   true],
            ['I03',  'Equipo de transporte',                                                   true,   true],
            ['I04',  'Equipo de computo y accesorios',                                         true,   true],
            ['I05',  'Dados, troqueles, moldes, matrices y herramental',                       true,   true],
            ['I06',  'Comunicaciones telefónicas',                                             true,   true],
            ['I07',  'Comunicaciones satelitales',                                             true,   true],
            ['I08',  'Otra maquinaria y equipo',                                               true,   true],
            ['D01',  'Honorarios médicos, dentales y gastos hospitalarios',                    true,  false],
            ['D02',  'Gastos médicos por incapacidad o discapacidad',                          true,  false],
            ['D03',  'Gastos funerales',                                                       true,  false],
            ['D04',  'Donativos',                                                              true,  false],
            ['D05',  'Intereses reales efectivamente pagados por créditos hipotecarios (casa habitación)', true, false],
            ['D06',  'Aportaciones voluntarias al SAR',                                        true,  false],
            ['D07',  'Primas por seguros de gastos médicos',                                   true,  false],
            ['D08',  'Gastos de transportación escolar obligatoria',                           true,  false],
            ['D09',  'Depósitos en cuentas para el ahorro, primas que tengan como base planes de pensiones', true, false],
            ['D10',  'Pagos por servicios educativos (colegiaturas)',                          true,  false],
            ['S01',  'Sin efectos fiscales',                                                   true,   true],
            ['CP01', 'Pagos',                                                                  true,   true],
            ['CN01', 'Nómina',                                                                 true,  false],
        ];

        $now = now();
        foreach ($uses as [$code, $desc, $fisica, $moral]) {
            DB::table('cfdi_uses')->updateOrInsert(
                ['code' => $code],
                [
                    'uuid'              => (string) Str::uuid(),
                    'description'       => $desc,
                    'applies_to_fisica' => $fisica,
                    'applies_to_moral'  => $moral,
                    'is_active'         => true,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]
            );
        }
    }
}
