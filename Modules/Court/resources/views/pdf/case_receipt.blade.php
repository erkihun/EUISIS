<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Case Receipt</title>
    <style>
        @page {
            margin: 28px 32px;
        }

        @font-face {
            font-family: 'Abyssinica';
            src: url('{{ public_path('fonts/AbyssinicaSIL-Regular.ttf') }}') format('truetype');
            font-style: normal;
            font-weight: normal;
        }

        body {
            font-family: 'Abyssinica', 'Nyala', DejaVu Serif, serif;
            color: #0f172a;
            font-size: 12px;
        }

        h1,
        h2,
        h3 {
            margin: 0;
        }

        .muted {
            color: #475569;
        }

        .row {
            display: flex;
            gap: 24px;
        }

        .col {
            flex: 1;
        }

        .box {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
        }

        .kv {
            margin: 4px 0;
        }

        .kv b {
            display: inline-block;
            min-width: 150px;
        }

        .section-title {
            margin-top: 18px;
            font-size: 13px;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        th,
        td {
            padding: 8px;
            border: 1px solid #e2e8f0;
            text-align: left;
        }

        .small {
            font-size: 11px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border: 1px solid #94a3b8;
            border-radius: 999px;
            font-size: 11px;
        }
    </style>
</head>

<body>
    <div class="header">
        <div>
            <h1>{{ config('app.name','Court-MS') }}</h1>
            <div class="muted small">Case Receipt (Applicant Copy)</div>
        </div>
        <div class="small muted">
            Generated: {{ \Modules\Court\Application\Support\EthiopianDate::format($generated, withTime: true) }}<br>
            Ref: {{ $case->case_number ?? ('#'.$case->id) }}
        </div>
    </div>

    <div class="box">
        <div class="row">
            <div class="col">
                <h3>Case</h3>
                <div class="kv"><b>Case No:</b> {{ $case->case_number ?? '—' }}</div>
                <div class="kv"><b>Title:</b> {{ $case->title }}</div>
                <div class="kv"><b>Type:</b> {{ $case->case_type ?? '—' }}</div>
                <div class="kv"><b>Court:</b> {{ $case->court_name ?? '—' }}</div>
                <div class="kv"><b>Filed on:</b> {{ \Modules\Court\Application\Support\EthiopianDate::format($case->filing_date) }}</div>
                <div class="kv"><b>Status:</b> <span class="badge">{{ ucfirst($case->status) }}</span></div>
            </div>
            <div class="col">
                <h3>Applicant</h3>
                <div class="kv"><b>Name:</b>
                    {{ trim(($case->first_name ?? '').' '.($case->middle_name ?? '').' '.($case->last_name ?? '')) ?: '—' }}
                </div>
                <div class="kv"><b>Email:</b> {{ $case->email ?? '—' }}</div>
                <div class="kv"><b>Phone:</b> {{ $case->phone ?? '—' }}</div>
                <div class="kv"><b>Address:</b> {{ $case->address ?? '—' }}</div>
            </div>
        </div>
    </div>

    <div class="box">
        <h3>Parties</h3>
        <div class="kv"><b>Respondent/Defendant:</b> {{ $case->respondent_name ?? '—' }}</div>
        <div class="kv"><b>Respondent Address:</b> {{ $case->respondent_address ?? '—' }}</div>
    </div>

    <div class="box">
        <h3>Case Details</h3>
        <div class="kv"><b>Relief Requested:</b> {{ $case->relief_requested ?? '—' }}</div>
        <div style="margin-top:8px;">
            <b>Description:</b><br>
            <div class="small" style="white-space:pre-wrap;">{{ $case->description }}</div>
        </div>
    </div>

    <div class="box">
        <h3>Evidence</h3>

        @php
        $docs = $evidences->where('type','document');
        $humans = $evidences->where('type','human');
        @endphp

        <div class="section-title">Document Evidence (PDF)</div>
        @if($docs->count())
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Title</th>
                    <th>File</th>
                    <th>Uploaded</th>
                </tr>
            </thead>
            <tbody>
                @foreach($docs as $i => $ev)
                <tr>
                    <td>{{ $i+1 }}</td>
                    <td>{{ $ev->title }}</td>
                    <td>{{ $ev->file_path ? basename($ev->file_path) : '—' }}</td>
                    <td>{{ \Modules\Court\Application\Support\EthiopianDate::format($ev->created_at, withTime: true) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="small muted">No documents attached.</div>
        @endif

        <div class="section-title" style="margin-top:14px;">Witnesses</div>
        @if($humans->count())
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Witness Name</th>
                </tr>
            </thead>
            <tbody>
                @foreach($humans as $i => $ev)
                <tr>
                    <td>{{ $i+1 }}</td>
                    <td>{{ $ev->title }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="small muted">No witnesses listed.</div>
        @endif
    </div>

    <p class="small muted" style="margin-top:12px;">
        This is a system-generated receipt for your records. Keep your case number for reference.
    </p>
</body>

</html>
