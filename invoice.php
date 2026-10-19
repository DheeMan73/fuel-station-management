<?php
/**
 * Invoice — printable receipt for a single sale transaction.
 *
 * Formats:
 *   invoice.php?id=..              -> 80mm thermal POS receipt (default)
 *   invoice.php?id=…&format=a4     -> full-page A4 invoice (for PDF export)
 *
 * Both views are print-optimised; the toolbar is hidden when printing.
 */
require_once __DIR__ . '/config.php';

$id     = (int)($_GET['id'] ?? 0);
$format = (($_GET['format'] ?? 'thermal') === 'a4') ? 'a4' : 'thermal';

$stmt = $pdo->prepare(
    "SELECT s.*, fi.fuel_type
     FROM sales s JOIN fuel_inventory fi ON fi.id = s.fuel_inventory_id
     WHERE s.id = ?"
);
$stmt->execute([$id]);
$sale = $stmt->fetch();

if (!$sale) {
    http_response_code(404);
    die('<div style="font-family:sans-serif;padding:3rem;background:#0F172A;color:#f87171;min-height:100vh;">
         <h2>Invoice Not Found</h2><p>No sale with ID #' . (int)$id . '.</p>
         <a href="sales.php" style="color:#22D3EE;">&larr; Back to Sales</a></div>');
}

$station   = get_setting($pdo, 'station_name', 'BlueWave Fuel Station');
$address   = get_setting($pdo, 'station_address', '');
$phone     = get_setting($pdo, 'station_phone', '');
$footerMsg = get_setting($pdo, 'receipt_footer', 'Thank you for fueling with us!');

$invoiceNo = $sale['invoice_no'] ?: ('#' . $sale['id']);
$unitPrice = (float)($sale['price_per_liter'] ?? 0);
$liters    = (float)$sale['liters_sold'];
$amount    = (float)$sale['total_amount'];
$tax       = 0.00;
$payment   = $sale['payment_method'] ?? 'Cash';
$customer  = $sale['customer_name'] ?: 'Walk-in';
$cashier   = $sale['recorded_by'] ?: 'Station Operator';
$shiftId   = !empty($sale['shift_id']) ? (int)$sale['shift_id'] : null;
$soldAt    = strtotime($sale['sold_at']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?= htmlspecialchars($invoiceNo) ?> — <?= htmlspecialchars($station) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #e2e8f0;
            color: #0f172a;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            padding: 24px 12px 48px;
        }
        .no-print { }

        /* ---------- Toolbar ---------- */
        .toolbar {
            max-width: 720px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            cursor: pointer;
            transition: all .15s;
        }
        .btn:hover { color: #0f172a; border-color: #94a3b8; }
        .btn-primary {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            border-color: transparent;
            color: #fff;
            box-shadow: 0 8px 20px -6px rgba(245,158,11,.5);
        }
        .btn-primary:hover { color: #fff; filter: brightness(1.06); }
        .btn-cyan { background: linear-gradient(135deg, #06b6d4, #0891b2); border-color: transparent; color: #fff; }
        .btn-cyan:hover { color: #fff; filter: brightness(1.06); }

        /* ---------- Thermal receipt (80mm) ---------- */
        .receipt {
            width: 80mm;
            max-width: 100%;
            margin: 0 auto;
            background: #fff;
            padding: 6mm 4mm;
            box-sizing: border-box;
            font-family: 'Courier New', ui-monospace, monospace;
            color: #000;
            font-size: 12px;
            line-height: 1.45;
            box-shadow: 0 10px 40px -12px rgba(15,23,42,.35);
            border-radius: 4px;
        }
        .receipt .center { text-align: center; }
        .receipt .brand { font-size: 17px; font-weight: 700; letter-spacing: .5px; }
        .receipt .muted { font-size: 11px; }
        .receipt .sep { border-top: 1px dashed #000; margin: 6px 0; }
        .receipt .sep-solid { border-top: 1px solid #000; margin: 6px 0; }
        .receipt table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .receipt table td, .receipt table th { padding: 1px 0; }
        .receipt .r { text-align: right; }
        .receipt .l { text-align: left; }
        .receipt .row { display: flex; justify-content: space-between; gap: 8px; }
        .receipt .total { font-size: 15px; font-weight: 700; }
        .receipt .big { font-size: 18px; font-weight: 700; }

        /* ---------- A4 invoice ---------- */
        .a4 {
            width: 210mm;
            max-width: 100%;
            min-height: 297mm;
            margin: 0 auto;
            background: #fff;
            padding: 16mm;
            box-sizing: border-box;
            color: #0f172a;
            font-family: 'Helvetica Neue', Arial, sans-serif;
            font-size: 13px;
            box-shadow: 0 10px 50px -12px rgba(15,23,42,.35);
        }
        .a4 h1 { font-size: 22px; margin: 0; }
        .a4 .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #0f172a; padding-bottom: 14px; }
        .a4 .badge { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; background: #0f172a; color: #fff; }
        .a4 .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin: 22px 0; }
        .a4 .meta .lbl { color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .6px; }
        .a4 .meta .val { font-weight: 600; margin-top: 2px; }
        .a4 table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .a4 table.items th { text-align: left; background: #f1f5f9; padding: 10px; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #475569; }
        .a4 table.items td { padding: 12px 10px; border-bottom: 1px solid #e2e8f0; }
        .a4 .totals { margin-top: 18px; margin-left: auto; width: 300px; }
        .a4 .totals .row { display: flex; justify-content: space-between; padding: 6px 0; }
        .a4 .totals .grand { border-top: 2px solid #0f172a; margin-top: 8px; padding-top: 10px; font-size: 18px; font-weight: 700; }
        .a4 .foot { margin-top: 40px; border-top: 1px solid #e2e8f0; padding-top: 14px; text-align: center; color: #64748b; font-size: 12px; }

        /* ---------- Print rules ---------- */
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; padding: 0; margin: 0; }
            .receipt, .a4 { box-shadow: none !important; border-radius: 0; margin: 0; }
        }
        <?php if ($format === 'thermal'): ?>
        @page { size: 80mm auto; margin: 3mm; }
        @media print { .a4 { display: none !important; } body { padding: 0; } }
        <?php else: ?>
        @page { size: A4; margin: 0; }
        .a4 { box-shadow: none; }
        @media print { .receipt { display: none !important; } @page { size: A4; margin: 14mm; } }
        <?php endif; ?>
    </style>
</head>
<body>

    <!-- Toolbar (hidden when printing) -->
    <div class="toolbar no-print">
        <a href="sales.php" class="btn"><i class="fa-solid fa-arrow-left"></i> Back to Sales</a>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php if ($format === 'thermal'): ?>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="fa-solid fa-print"></i> Print Receipt
                </button>
                <a href="invoice.php?id=<?= (int)$sale['id'] ?>&amp;format=a4" class="btn btn-cyan">
                    <i class="fa-solid fa-file-pdf"></i> A4 / Save PDF
                </a>
            <?php else: ?>
                <a href="invoice.php?id=<?= (int)$sale['id'] ?>" class="btn">
                    <i class="fa-solid fa-receipt"></i> 80mm Receipt
                </a>
                <button onclick="window.print()" class="btn btn-cyan">
                    <i class="fa-solid fa-file-pdf"></i> Print / Save as PDF
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($format === 'thermal'): ?>
    <!-- ============ 80mm THERMAL RECEIPT ============ -->
    <div class="receipt">
        <div class="center brand"><?= htmlspecialchars($station) ?></div>
        <?php if ($address !== ''): ?><div class="center muted"><?= htmlspecialchars($address) ?></div><?php endif; ?>
        <?php if ($phone !== ''): ?><div class="center muted">Tel: <?= htmlspecialchars($phone) ?></div><?php endif; ?>

        <div class="sep"></div>
        <div class="row"><span>Invoice</span><span><?= htmlspecialchars($invoiceNo) ?></span></div>
        <div class="row"><span>Date</span><span><?= date('Y-m-d H:i', strtotime($sale['sold_at'])) ?></span></div>
        <div class="row"><span>Cashier</span><span><?= htmlspecialchars($sale['recorded_by'] ?: 'Operator') ?></span></div>
        <?php if (!empty($sale['shift_id'])): ?>
        <div class="row"><span>Shift</span><span>#<?= (int)$sale['shift_id'] ?></span></div>
        <?php endif; ?>
        <div class="row"><span>Customer</span><span><?= htmlspecialchars($sale['customer_name'] ?: 'Walk-in') ?></span></div>
        <div class="row"><span>Payment</span><span><?= htmlspecialchars($sale['payment_method'] ?? 'Cash') ?></span></div>
        <div class="sep"></div>

        <table>
            <thead>
                <tr>
                    <th class="l">ITEM</th>
                    <th class="r">QTY</th>
                    <th class="r">RATE</th>
                    <th class="r">AMT</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="l"><?= htmlspecialchars($sale['fuel_type']) ?></td>
                    <td class="r"><?= fmt($liters) ?></td>
                    <td class="r"><?= money($unitPrice) ?></td>
                    <td class="r"><?= money($amount) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="sep"></div>
        <div class="row"><span>Subtotal</span><span><?= money($amount) ?></span></div>
        <div class="row"><span>Tax</span><span><?= money($tax) ?></span></div>
        <div class="sep-solid"></div>
        <div class="row total"><span>TOTAL</span><span>$<?= money($amount) ?></span></div>
        <div class="sep"></div>

        <div class="center"><?= htmlspecialchars($payment) ?> tendered</div>
        <div class="center big">$<?= money($amount) ?></div>

        <div class="sep"></div>
        <div class="center muted"><?= htmlspecialchars($footerMsg) ?></div>
        <div class="center muted" style="margin-top:4px;"><?= htmlspecialchars($invoiceNo) ?></div>
        <div class="center muted" style="margin-top:4px;"><?= date('d/m/Y H:i:s') ?></div>
        <div class="center" style="margin-top:8px;letter-spacing:2px;font-size:14px;">* * * * *</div>
    </div>
    <?php else: ?>
    <!-- ============ A4 INVOICE (print / PDF) ============ -->
    <div class="a4">
        <div class="head">
            <div>
                <h1><?= htmlspecialchars($station) ?></h1>
                <?php if ($address !== ''): ?><div style="color:#64748b;margin-top:4px;"><?= htmlspecialchars($address) ?></div><?php endif; ?>
                <?php if ($phone !== ''): ?><div style="color:#64748b;">Tel: <?= htmlspecialchars($phone) ?></div><?php endif; ?>
            </div>
            <div style="text-align:right;">
                <span class="badge">Invoice</span>
                <div style="font-size:20px;font-weight:700;margin-top:8px;"><?= htmlspecialchars($invoiceNo) ?></div>
                <div style="color:#64748b;font-size:12px;"><?= date('F d, Y — H:i', strtotime($sale['sold_at'])) ?></div>
            </div>
        </div>

        <div class="meta">
            <div>
                <div class="lbl">Billed To</div>
                <div class="val"><?= htmlspecialchars($sale['customer_name'] ?: 'Walk-in Customer') ?></div>
            </div>
            <div style="text-align:right;">
                <div class="lbl">Payment Method</div>
                <div class="val"><?= htmlspecialchars($sale['payment_method'] ?? 'Cash') ?></div>
                <div class="lbl" style="margin-top:10px;">Served By</div>
                <div class="val"><?= htmlspecialchars($sale['recorded_by'] ?: 'Station Operator') ?></div>
            </div>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align:right;">Quantity</th>
                    <th style="text-align:right;">Unit Price</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($sale['fuel_type']) ?> Fuel</strong>
                        <div style="color:#64748b;font-size:12px;">Fuel dispensed at the pump</div>
                    </td>
                    <td style="text-align:right;"><?= fmt($liters) ?> L</td>
                    <td style="text-align:right;">$<?= money($unitPrice) ?></td>
                    <td style="text-align:right;">$<?= money($amount) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="totals">
            <div class="row"><span style="color:#64748b;">Subtotal</span><span>$<?= money($amount) ?></span></div>
            <div class="row"><span style="color:#64748b;">Tax (0%)</span><span>$<?= money($tax) ?></span></div>
            <div class="row grand"><span>Total Due</span><span>$<?= money($amount + $tax) ?></span></div>
        </div>

        <div style="margin-top:60px;border-top:1px solid #e2e8f0;padding-top:16px;text-align:center;color:#64748b;">
            <p style="margin:0;font-weight:600;color:#334155;"><?= htmlspecialchars($footerMsg) ?></p>
            <p style="margin:6px 0 0;font-size:11px;">This is a computer-generated invoice. Generated on <?= date('M d, Y H:i') ?>.</p>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Convenience: pressing "p" (or Ctrl/Cmd+P handled by the browser) prints.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'p' && !e.ctrlKey && !e.metaKey && !e.altKey) { window.print(); }
        });
    </script>
</body>
</html>
