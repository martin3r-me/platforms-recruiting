<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #111827;
            margin: 2cm 2.5cm;
        }

        .contract-content {
            white-space: pre-line;
        }

        .contract-content table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0;
        }

        .contract-content th,
        .contract-content td {
            border: 1px solid #d1d5db;
            padding: 4px 8px;
            text-align: left;
            font-size: 10pt;
        }

        .contract-content th {
            background-color: #f3f4f6;
            font-weight: 600;
        }

        .signature-section {
            margin-top: 40px;
            page-break-inside: avoid;
        }

        .signature-section h3 {
            font-size: 12pt;
            margin-bottom: 8px;
        }

        .signature-image {
            max-height: 100px;
            margin: 8px 0;
        }

        .signature-date {
            font-size: 10pt;
            color: #6b7280;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    <div class="contract-content">
        {!! $contract->personalized_content !!}
    </div>

    @if($contract->signature_data)
        <div class="signature-section">
            <h3>Unterschrift{{ $candidateName ? ' — ' . $candidateName : '' }}</h3>
            <img src="{{ $contract->signature_data }}" alt="Unterschrift" class="signature-image">
            @if($contract->signed_at)
                <div class="signature-date">
                    Unterschrieben am {{ $contract->signed_at->format('d.m.Y \u\m H:i') }} Uhr
                </div>
            @endif
        </div>
    @endif
</body>
</html>
