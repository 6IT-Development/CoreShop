<?php

declare(strict_types=1);

namespace CoreShop\Bundle\OrderReturnBundle\Renderer\Pdf;

use CoreShop\Bundle\OrderRetuenBundle\Renderer\Pdf\PdfRendererInterface;
use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

final class MpdfRenderer implements PdfRendererInterface
{
    public function __construct(
        private string $kernelCacheDir,
    ) {
    }

    public function fromString(string $string, string $header = '', string $footer = '', array $config = []): string
    {
        $defaultConfig = [
            'tempDir' => $this->kernelCacheDir . '/mpdf',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 16,
            'margin_bottom' => 16,
            'margin_header' => 9,
            'margin_footer' => 9,
        ];

        if (!is_dir($defaultConfig['tempDir'])) {
            mkdir($defaultConfig['tempDir'], 0777, true);
        }

        $mpdf = new Mpdf(array_merge($defaultConfig, $config));

        if (!empty($header)) {
            $mpdf->SetHTMLHeader($header);
        }

        if (!empty($footer)) {
            $mpdf->SetHTMLFooter($footer);
        }

        $mpdf->WriteHTML($string);

        return $mpdf->Output('', 'S');
    }
}
