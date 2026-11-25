<?php
/**
 * Lightweight, zero-dependency PDF 1.4 receipt generator for FundRaise.
 * Uses built-in Type1 Helvetica / Helvetica-Bold / Courier fonts with WinAnsiEncoding.
 */
class SimpleReceiptPdf
{
    private float $pageWidth = 595.28;
    private float $pageHeight = 841.89;
    private string $stream = '';

    private function toPdfY(float $topY): float
    {
        return $this->pageHeight - $topY;
    }

    private function sanitizeText(string $text): string
    {
        $text = str_replace(['₹', "\r\n", "\r", "\n", "\t"], ['INR ', ' ', ' ', ' ', ' '], $text);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    public function setFillColor(float $r, float $g, float $b): void
    {
        $this->stream .= sprintf("%.3f %.3f %.3f rg\n", $r / 255, $g / 255, $b / 255);
    }

    public function setDrawColor(float $r, float $g, float $b): void
    {
        $this->stream .= sprintf("%.3f %.3f %.3f RG\n", $r / 255, $g / 255, $b / 255);
    }

    public function drawRect(float $x, float $topY, float $w, float $h, string $style = 'F'): void
    {
        $bottomY = $this->toPdfY($topY + $h);
        $op = $style === 'FD' ? 'B' : ($style === 'D' ? 'S' : 'f');
        $this->stream .= sprintf("%.2f %.2f %.2f %.2f re %s\n", $x, $bottomY, $w, $h, $op);
    }

    public function drawLine(float $x1, float $topY1, float $x2, float $topY2, float $lineWidth = 0.75): void
    {
        $this->stream .= sprintf(
            "%.2f w %.2f %.2f m %.2f %.2f l S\n",
            $lineWidth,
            $x1,
            $this->toPdfY($topY1),
            $x2,
            $this->toPdfY($topY2)
        );
    }

    public function drawText(float $x, float $topY, string $text, string $font = 'F1', float $size = 11): void
    {
        $safe = $this->sanitizeText($text);
        $pdfY = $this->toPdfY($topY);
        $this->stream .= sprintf("BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n", $font, $size, $x, $pdfY, $safe);
    }

    /**
     * Wraps text at word boundaries and returns the next topY coordinate.
     */
    public function drawWrappedText(
        float $x,
        float $topY,
        string $text,
        int $maxChars = 60,
        float $lineHeight = 15,
        string $font = 'F1',
        float $size = 11
    ): float {
        $wrapped = wordwrap(trim($text), $maxChars, "\n", true);
        $lines = explode("\n", $wrapped);
        $currentY = $topY;
        foreach ($lines as $line) {
            $this->drawText($x, $currentY, $line, $font, $size);
            $currentY += $lineHeight;
        }
        return $currentY;
    }

    public function output(): string
    {
        $objects = [];

        // 1: Catalog
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        // 2: Pages
        $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        // 3: Page
        $objects[3] = sprintf(
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 4 0 R /F2 5 0 R /F3 6 0 R >> >> /Contents 7 0 R >>",
            $this->pageWidth,
            $this->pageHeight
        );
        // 4, 5, 6: Fonts
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
        $objects[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>";
        // 7: Content stream
        $streamLen = strlen($this->stream);
        $objects[7] = "<< /Length $streamLen >>\nstream\n" . $this->stream . "endstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 $count\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size $count /Root 1 0 R >>\n";
        $pdf .= "startxref\n$xrefOffset\n%%EOF";

        return $pdf;
    }
}

/**
 * Builds a formatted PDF receipt binary string for a donation record.
 */
function buildDonationReceiptPdf(array $receipt): string
{
    $pdf = new SimpleReceiptPdf();
    $status = strtoupper((string) $receipt['payment_status']);
    $isSuccess = ($status === 'SUCCESS');
    $isFailed = ($status === 'FAILED');

    // Outer page border
    $pdf->setDrawColor(210, 215, 222);
    $pdf->drawRect(36, 36, 523, 770, 'D');

    // Header banner
    if ($isSuccess) {
        $pdf->setFillColor(25, 135, 84);
    } elseif ($isFailed) {
        $pdf->setFillColor(220, 53, 69);
    } else {
        $pdf->setFillColor(13, 110, 253);
    }
    $pdf->drawRect(36, 36, 523, 86, 'F');

    $pdf->setFillColor(255, 255, 255);
    $pdf->drawText(60, 74, 'FundRaise Crowdfunding Platform', 'F2', 20);
    $pdf->drawText(60, 98, 'Official Donation Payment Receipt', 'F1', 12);

    // Status pill on right of banner
    $pdf->setFillColor(255, 255, 255);
    $pdf->drawRect(430, 62, 108, 30, 'F');
    if ($isSuccess) {
        $pdf->setFillColor(25, 135, 84);
    } elseif ($isFailed) {
        $pdf->setFillColor(220, 53, 69);
    } else {
        $pdf->setFillColor(108, 117, 125);
    }
    $pdf->drawText(448, 81, $status, 'F2', 12);

    // Highlighted amount summary box
    $pdf->setFillColor(246, 248, 250);
    $pdf->setDrawColor(222, 226, 230);
    $pdf->drawRect(56, 145, 483, 68, 'FD');

    $pdf->setFillColor(108, 117, 125);
    $pdf->drawText(74, 168, 'CONTRIBUTION AMOUNT', 'F2', 9);
    $pdf->setFillColor(33, 37, 41);
    $pdf->drawText(74, 195, 'INR ' . number_format((float) $receipt['amount'], 2), 'F2', 20);

    $pdf->setFillColor(108, 117, 125);
    $pdf->drawText(340, 168, 'TRANSACTION DATE', 'F2', 9);
    $pdf->setFillColor(33, 37, 41);
    $pdf->drawText(340, 193, (string) ($receipt['payment_date'] ?: date('Y-m-d H:i:s')), 'F1', 11);

    // Transaction Details Section
    $y = 248;
    $pdf->setFillColor(13, 110, 253);
    $pdf->drawText(56, $y, 'TRANSACTION DETAILS', 'F2', 11);
    $y += 10;
    $pdf->setDrawColor(13, 110, 253);
    $pdf->drawLine(56, $y, 539, $y, 1.2);
    $y += 24;

    $rows = [
        ['Order ID', (string) $receipt['sim_order_id'], 'F3'],
        ['Payment ID', $receipt['sim_payment_id'] ? (string) $receipt['sim_payment_id'] : 'N/A', 'F3'],
        ['Payment Status', $status, 'F2'],
        ['Donor Name', (string) $receipt['donor_name'], 'F1'],
        ['Donor Email', (string) $receipt['donor_email'], 'F1'],
        ['Campaign Category', (string) $receipt['category'], 'F1'],
        ['Campaign Creator', (string) $receipt['creator_name'], 'F1'],
    ];

    foreach ($rows as $row) {
        $pdf->setFillColor(90, 98, 106);
        $pdf->drawText(56, $y, $row[0] . ':', 'F2', 10.5);
        $pdf->setFillColor(33, 37, 41);
        $pdf->drawText(200, $y, $row[1], $row[2], 10.5);
        $y += 24;
        $pdf->setDrawColor(235, 238, 242);
        $pdf->drawLine(56, $y - 10, 539, $y - 10, 0.5);
    }

    // Campaign Title (wrapped safely)
    $pdf->setFillColor(90, 98, 106);
    $pdf->drawText(56, $y, 'Campaign Title:', 'F2', 10.5);
    $pdf->setFillColor(33, 37, 41);
    $y = $pdf->drawWrappedText(200, $y, (string) $receipt['campaign_title'], 48, 15, 'F1', 10.5);

    // Verification Note Box
    $noteY = max($y + 28, 520);
    $pdf->setFillColor(248, 249, 250);
    $pdf->setDrawColor(210, 215, 222);
    $pdf->drawRect(56, $noteY, 483, 76, 'FD');

    $pdf->setFillColor(33, 37, 41);
    $pdf->drawText(72, $noteY + 24, 'Receipt Verification Note', 'F2', 10.5);
    $pdf->setFillColor(90, 98, 106);
    $noteText = $isSuccess
        ? 'This simulated payment has been verified and credited to the campaign funding total.'
        : 'This transaction was not completed or failed verification. No funds were credited to the campaign.';
    $pdf->drawWrappedText(72, $noteY + 44, $noteText, 76, 14, 'F1', 9.5);

    // Footer
    $pdf->setDrawColor(220, 224, 230);
    $pdf->drawLine(56, 748, 539, 748, 0.75);
    $pdf->setFillColor(120, 128, 136);
    $pdf->drawText(56, 768, 'Generated on ' . date('Y-m-d H:i:s') . ' | FundRaise Platform', 'F1', 9);
    $pdf->drawText(410, 768, 'Document Ref: #' . (int) $receipt['id'], 'F3', 9);

    return $pdf->output();
}
