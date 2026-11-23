@php
    $alignment = $direction === 'rtl' ? 'right' : 'left';
    $opposite = $direction === 'rtl' ? 'left' : 'right';
    $isBookings = $section === 'bookings';
    $columns = $isBookings
        ? [
            ['reports.exports.columns.reference', 13, true],
            ['reports.exports.columns.customer', 21, false],
            ['reports.exports.columns.service', 21, false],
            ['reports.exports.columns.staff', 17, false],
            ['report_pdf.appointment', 15, true],
            ['reports.exports.columns.status', 13, false],
        ]
        : [
            [$section === 'services' ? 'report_pdf.service_name' : 'report_pdf.staff_name', 31, false],
            ['reports.exports.columns.total', 14, true],
            ['reports.exports.columns.confirmed', 13, true],
            ['reports.exports.columns.completed', 13, true],
            ['reports.exports.columns.cancelled', 13, true],
            ['reports.metrics.cancellation_rate', 16, true],
        ];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}" dir="{{ $direction }}">
<head>
    <meta charset="UTF-8">
    <style>
        @page { header: html_reportHeader; footer: html_reportFooter; }
        body { font-family: dejavusans; font-size: 9pt; color: #172c42; }
        table { width: 100%; }
        .running { font-size: 8pt; color: #43586a; }
        .running td { padding: 1mm 0; }
        .running-header td { border-bottom: 0.35mm solid #227c77; }
        .running-footer td { border-top: 0.25mm solid #bac9d3; }
        h1 { font-size: 24pt; color: #173249; margin: 2mm 0 3mm; }
        h2 { font-size: 13pt; color: #173249; margin: 5mm 0 2mm; page-break-after: avoid; }
        .metadata { border-collapse: collapse; }
        .metadata td { width: 50%; padding: 2mm 3mm; background: #edf4f6; border: 0.25mm solid #d8e4eb; }
        .meta-label { font-size: 8pt; color: #43586a; }
        .meta-value { font-size: 10pt; font-weight: bold; }
        .cards { border-collapse: separate; border-spacing: 1mm; page-break-inside: avoid; }
        .cards td { width: 33.33%; padding: 2mm 3mm; background: #f2f7f7; border: 0.25mm solid #d5e2e4; }
        .metric { font-size: 17pt; font-weight: bold; color: #135e5a; }
        .metric-label { font-size: 8pt; color: #344d60; }
        .details { border-collapse: collapse; }
        .details th { background: #23435b; color: #fff; font-size: 8.5pt; }
        .details th, .details td { padding: 2mm; vertical-align: middle; }
        .details td { border-bottom: 0.25mm solid #d9e4ec; }
        .details .even td { background: #f1f6f8; }
        .note { padding: 2.5mm 3mm; background: #eff5f7; color: #203c51; border: 0.25mm solid #d8e4eb; }
        .technical { direction: ltr; unicode-bidi: embed; white-space: nowrap; }
    </style>
</head>
<body dir="{{ $direction }}">
    <htmlpageheader name="reportHeader">
        <table class="running running-header" dir="{{ $direction }}">
            <tr>
                <td width="50%" style="text-align: {{ $alignment }}"><b>{{ __('reports.title') }}</b></td>
                <td width="50%" style="text-align: {{ $opposite }}">{{ $title }}</td>
            </tr>
        </table>
    </htmlpageheader>
    <htmlpagefooter name="reportFooter">
        <table class="running running-footer" dir="{{ $direction }}">
            <tr>
                <td width="50%" style="text-align: {{ $alignment }}">{{ $title }}</td>
                <td width="50%" style="text-align: {{ $opposite }}">
                    {{ __('report_pdf.page') }} <span class="technical" dir="ltr">{PAGENO} / {nbpg}</span>
                </td>
            </tr>
        </table>
    </htmlpagefooter>

    <h1>{{ $title }}</h1>
    <table class="metadata" dir="{{ $direction }}">
        <tr>
            <td><span class="meta-label">{{ __('report_pdf.business') }}</span><br><span class="meta-value">{{ $businessName }}</span></td>
            <td><span class="meta-label">{{ __('report_pdf.branch') }}</span><br><span class="meta-value">{{ $branchName }}</span></td>
        </tr>
        <tr>
            <td>
                <span class="meta-label">{{ __('report_pdf.period') }}</span><br>
                <span class="meta-value technical" dir="ltr">{{ $start }} - {{ $end }}</span>
            </td>
            <td>
                <span class="meta-label">{{ __('report_pdf.generated') }}</span><br>
                <span class="meta-value technical" dir="ltr">{{ $generatedAt }}</span>
            </td>
        </tr>
    </table>

    <h2>{{ __('report_pdf.summary') }}</h2>
    <table class="cards" dir="{{ $direction }}">
        @foreach ([['total', 'confirmed', 'completed'], ['cancelled', 'pending', 'cancellation_rate']] as $metricKeys)
            <tr>
                @foreach ($metricKeys as $metric)
                    <td>
                        <span class="metric-label">{{ __('reports.metrics.'.$metric) }}</span><br>
                        <span class="metric technical" dir="ltr">{{ $statistics[$metric] }}{{ $metric === 'cancellation_rate' ? '%' : '' }}</span>
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>

    <h2>{{ $isBookings ? __('reports.bookings.details') : __('report_pdf.'.$section) }}</h2>
    @if ($detailsFiltered && ! $detailsOmitted)
        <p>{{ __('report_pdf.detail_search') }}</p>
    @endif

    @if ($detailsOmitted)
        <p class="note">{{ __('report_pdf.details_omitted', ['limit' => $rowLimit]) }}</p>
    @elseif ($rows === [])
        <p class="note">{{ __('reports.'.$section.'.empty') }}</p>
    @else
        <table class="details" dir="{{ $direction }}">
            <thead>
                <tr>
                    @foreach ($columns as [$label, $width, $technical])
                        <th style="width: {{ $width }}%; text-align: {{ $technical ? 'center' : $alignment }}">{{ __($label) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr @class(['even' => $loop->even])>
                        @foreach ($columns as $index => [$label, $width, $technical])
                            @if ($technical)
                                <td style="text-align: center"><span class="technical" dir="ltr">{{ $row[$index] }}</span></td>
                            @else
                                <td style="text-align: {{ $alignment }}">{{ $row[$index] }}</td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
