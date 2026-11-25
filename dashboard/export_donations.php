<?php
require_once '../auth/auth_check.php';
checkRole(['user', 'donor', 'creator', 'admin']);
require_once '../config/db.php';

$user_id = (int) $_SESSION['user_id'];

$stmt = $conn->prepare(
    "SELECT d.sim_order_id, d.sim_payment_id, c.title AS campaign_title, c.category,
            d.amount, d.payment_status, d.payment_date
     FROM donations d
     JOIN campaigns c ON d.campaign_id = c.id
     WHERE d.donor_id = ?
     ORDER BY d.payment_date DESC"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

/**
 * Mitigates CSV spreadsheet formula injection by prefixing risky leading characters.
 */
function sanitizeCsvCell(string $value): string
{
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $value;
    }
    return $value;
}

$filename = 'my_donations_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
// UTF-8 BOM for clean Excel compatibility
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, [
    'Order ID',
    'Payment ID',
    'Campaign Title',
    'Category',
    'Amount (INR)',
    'Payment Status',
    'Payment Date'
]);

while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        sanitizeCsvCell((string) $row['sim_order_id']),
        sanitizeCsvCell($row['sim_payment_id'] ? (string) $row['sim_payment_id'] : 'N/A'),
        sanitizeCsvCell((string) $row['campaign_title']),
        sanitizeCsvCell((string) $row['category']),
        number_format((float) $row['amount'], 2, '.', ''),
        sanitizeCsvCell((string) $row['payment_status']),
        sanitizeCsvCell((string) $row['payment_date']),
    ]);
}

fclose($output);
$stmt->close();
exit();
