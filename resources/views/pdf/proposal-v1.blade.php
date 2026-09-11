<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Proposal {{ $proposalNumber ?? 'Draft' }}</title>
<style>
    @page { margin: 28px 32px 46px 32px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2933; }
    table { border-collapse: collapse; width: 100%; }
    .letterhead { width: 100%; margin-bottom: 14px; }
    .letterhead td { vertical-align: top; }
    .org-name { font-size: 16px; font-weight: bold; color: #0f172a; }
    .org-meta { font-size: 9px; color: #52606d; line-height: 1.5; }
    .doc-title { text-align: right; }
    .doc-title .label { font-size: 14px; font-weight: bold; color: #0f172a; }
    .doc-title .ref { font-size: 9px; color: #52606d; margin-top: 4px; }
    .rule { border-top: 1px solid #cbd2d9; margin: 10px 0 14px 0; }
    .party-block { width: 100%; margin-bottom: 14px; }
    .party-block td { vertical-align: top; width: 50%; }
    .party-block .heading { font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; color: #7b8794; margin-bottom: 3px; }
    .party-block .name { font-size: 11px; font-weight: bold; color: #0f172a; }
    .party-block .detail { font-size: 9px; color: #3e4c59; line-height: 1.5; }
    .scope-block { margin-bottom: 14px; }
    .scope-block .heading { font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; color: #7b8794; margin-bottom: 3px; }
    .scope-block .body { font-size: 9.5px; color: #1f2933; line-height: 1.5; }
    .items-table { margin-bottom: 4px; }
    .items-table th { background: #f4f6f8; border: 1px solid #cbd2d9; padding: 5px 6px; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.03em; color: #52606d; text-align: left; }
    .items-table td { border: 1px solid #cbd2d9; padding: 5px 6px; font-size: 9px; vertical-align: top; }
    .items-table .num { text-align: right; white-space: nowrap; }
    .items-table .desc { color: #52606d; font-size: 8.5px; margin-top: 2px; }
    .items-table .tax-line { color: #7b8794; font-size: 8px; }
    .totals-table { width: 55%; margin-left: 45%; margin-top: 8px; }
    .totals-table td { padding: 3px 6px; font-size: 9.5px; }
    .totals-table .label { color: #52606d; }
    .totals-table .value { text-align: right; white-space: nowrap; }
    .totals-table .grand-total td { border-top: 1px solid #0f172a; font-weight: bold; font-size: 11px; color: #0f172a; padding-top: 6px; }
    .terms-block { margin-top: 16px; }
    .terms-block .heading { font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; color: #7b8794; margin-bottom: 3px; }
    .terms-block .body { font-size: 9px; color: #3e4c59; line-height: 1.5; white-space: pre-line; }
    .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 7.5px; color: #9aa5b1; border-top: 1px solid #e4e7eb; padding-top: 4px; }
</style>
</head>
<body>

<table class="letterhead">
    <tr>
        <td style="width: 60%;">
            @if(!empty($logoDataUri))
                <img src="{{ $logoDataUri }}" style="max-height: 40px; margin-bottom: 4px;">
            @endif
            <div class="org-name">{{ $identity['legal_name'] }}</div>
            <div class="org-meta">
                {{ $identity['registered_address'] }}<br>
                GSTIN: {{ $identity['gstin'] }}
                @if(!empty($identity['phone'])) &nbsp;|&nbsp; {{ $identity['phone'] }} @endif
                @if(!empty($identity['email'])) &nbsp;|&nbsp; {{ $identity['email'] }} @endif
                @if(!empty($identity['website'])) <br>{{ $identity['website'] }} @endif
            </div>
        </td>
        <td class="doc-title" style="width: 40%;">
            <div class="label">Commercial Proposal</div>
            <div class="ref">
                Proposal No: {{ $proposalNumber ?? 'Not assigned' }}<br>
                Version: V{{ $versionNumber }}<br>
                Date: {{ $documentDate }}
            </div>
        </td>
    </tr>
</table>
<div class="rule"></div>

<table class="party-block">
    <tr>
        <td>
            <div class="heading">Billed To</div>
            <div class="name">{{ $customer['name'] }}</div>
            <div class="detail">
                @if(!empty($customer['billing_address'])) {{ $customer['billing_address'] }}<br> @endif
                @if(!empty($customer['billing_state'])) State: {{ $customer['billing_state'] }}<br> @endif
                @if(!empty($customer['gstin'])) GSTIN: {{ $customer['gstin'] }}<br> @endif
                @if(!empty($customer['place_of_supply'])) Place of Supply: {{ $customer['place_of_supply'] }} @endif
            </div>
        </td>
        <td>
            <div class="heading">Currency</div>
            <div class="detail">{{ $currencyCode }}</div>
        </td>
    </tr>
</table>

@if(!empty($scopeNotes))
<div class="scope-block">
    <div class="heading">Scope</div>
    <div class="body">{{ $scopeNotes }}</div>
</div>
@endif

<table class="items-table">
    <thead>
        <tr>
            <th style="width: 4%;">#</th>
            <th style="width: 28%;">Item</th>
            <th style="width: 8%;">HSN/SAC</th>
            <th style="width: 7%;" class="num">Qty</th>
            <th style="width: 7%;">Unit</th>
            <th style="width: 11%;" class="num">Unit Price</th>
            <th style="width: 11%;" class="num">Discount</th>
            <th style="width: 11%;" class="num">Tax</th>
            <th style="width: 13%;" class="num">Line Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($lines as $line)
        <tr>
            <td>{{ $line['line_number'] }}</td>
            <td>
                {{ $line['item_name'] }}
                @if(!empty($line['description']))
                    <div class="desc">{{ $line['description'] }}</div>
                @endif
            </td>
            <td>{{ $line['hsn_sac'] ?? '—' }}</td>
            <td class="num">{{ $line['quantity'] }}</td>
            <td>{{ $line['unit'] ?? '—' }}</td>
            <td class="num">{{ $line['unit_price'] }}</td>
            <td class="num">{{ $line['discount_amount'] }}</td>
            <td class="num">
                {{ $line['tax_amount'] }}
                @foreach($line['tax_components'] as $component)
                    <div class="tax-line">{{ $component['type'] }} @ {{ $component['rate'] }}%: {{ $component['amount'] }}</div>
                @endforeach
            </td>
            <td class="num">{{ $line['line_total'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<table class="totals-table">
    <tr><td class="label">Subtotal</td><td class="value">{{ $currencyCode }} {{ $subtotal }}</td></tr>
    <tr><td class="label">Total Discount</td><td class="value">{{ $currencyCode }} {{ $totalDiscount }}</td></tr>
    <tr><td class="label">Tax Total</td><td class="value">{{ $currencyCode }} {{ $taxTotal }}</td></tr>
    <tr class="grand-total"><td class="label">Grand Total</td><td class="value">{{ $currencyCode }} {{ $grandTotal }}</td></tr>
</table>

<div class="terms-block">
    @if(!empty($paymentTerms))
        <div class="heading">Payment Terms</div>
        <div class="body">{{ $paymentTerms }}</div>
    @endif
    @if(!empty($validityTerms))
        <div class="heading" style="margin-top: 8px;">Validity</div>
        <div class="body">{{ $validityTerms }}</div>
    @endif
</div>

<div class="footer">
    Generated document — Proposal {{ $proposalNumber ?? 'Not assigned' }}, Version V{{ $versionNumber }} — {{ $identity['legal_name'] }}
</div>

</body>
</html>
