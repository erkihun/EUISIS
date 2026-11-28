@php
    /*
    | The temporary pilot week dashboard. One self-contained page: no script,
    | no external file, so it opens the same on a phone, a tablet and a desk.
    | Counts only; no person is named.
    */
    $labels = [
        'units' => 'የሥራ ክፍሎች',
        'positions' => 'የሥራ መደቦች',
        'employees' => 'ሠራተኞች',
        'users' => 'ተጠቃሚዎች',
        'services' => 'አገልግሎቶች',
        'registry' => 'የደብዳቤ መዝገብ ቤት',
    ];
    $planDay = [
        'units' => 'ቀን 1–2',
        'positions' => 'ቀን 2',
        'employees' => 'ቀን 3',
        'users' => 'ቀን 4',
        'services' => 'ቀን 4',
        'registry' => 'ቀን 4',
    ];
    $organizations = $data['organizations'];
    $maxDay = max(1, ...array_column($data['days'], 'count'));
    $weekTotal = array_sum(array_column($data['totals'], 'week'));
    $link = fn (?string $week) => route('pilot.week-dashboard', array_filter(['token' => $token, 'week' => $week]));
@endphp
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#1F4E9A">
    @if ($data['isCurrent'])
        <meta http-equiv="refresh" content="120">
    @endif
    <title>የሳምንቱ የሥራ ክንውን</title>
    <style>
        :root {
            --blue: #1F4E9A; --blue-dark: #173B75; --blue-soft: #EEF3FA;
            --orange: #E87722; --orange-soft: #FDF0E4;
            --ink: #1B2333; --muted: #5B6578; --line: #D9E0EC; --page: #F5F7FB; --card: #FFFFFF;
            --ok: #1F4E9A; --wait: #B7C0D0;
        }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; background: var(--page); color: var(--ink);
            font-family: "Noto Sans Ethiopic", "Nyala", "Ebrima", "Abyssinica SIL", system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 16px; line-height: 1.5;
        }
        a { color: inherit; }
        header {
            background: var(--blue); color: #fff;
            padding: calc(14px + env(safe-area-inset-top)) 16px 16px;
            border-bottom: 4px solid var(--orange);
        }
        .wrap { max-width: 1100px; margin: 0 auto; }
        .eyebrow { font-size: 12.5px; opacity: .85; margin: 0 0 2px; }
        h1 { font-size: 21px; line-height: 1.3; margin: 0; font-weight: 700; }
        .week { display: flex; align-items: center; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
        .week .label { font-size: 14.5px; font-weight: 600; flex: 1 1 auto; min-width: 0; }
        .nav { display: flex; gap: 8px; }
        .nav a, .nav span {
            display: inline-flex; align-items: center; justify-content: center;
            min-height: 44px; padding: 0 14px; border-radius: 10px; font-size: 14px; font-weight: 600;
            text-decoration: none; border: 1px solid rgba(255, 255, 255, .45); white-space: nowrap;
        }
        .nav a:active { background: rgba(255, 255, 255, .18); }
        .nav span { opacity: .4; }
        main { padding: 16px 16px calc(28px + env(safe-area-inset-bottom)); }
        h2 { font-size: 16px; margin: 22px 0 10px; color: var(--blue-dark); display: flex; align-items: baseline; gap: 8px; }
        h2 small { font-weight: 400; color: var(--muted); font-size: 13px; }
        .headline { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
        .big {
            background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 12px;
            border-top: 4px solid var(--orange);
        }
        .big b { display: block; font-size: 26px; line-height: 1.15; color: var(--blue-dark); font-variant-numeric: tabular-nums; }
        .big span { font-size: 12.5px; color: var(--muted); display: block; }
        .tiles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .tile { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 12px; }
        .tile .name { font-size: 13.5px; color: var(--muted); display: flex; justify-content: space-between; gap: 6px; }
        .tile .name i { font-style: normal; color: var(--orange); font-weight: 600; font-size: 12px; white-space: nowrap; }
        .tile .num { font-size: 26px; font-weight: 700; color: var(--blue-dark); line-height: 1.2; font-variant-numeric: tabular-nums; }
        .tile .plus { font-size: 13px; color: var(--orange); font-weight: 600; }
        .tile .plus.none { color: var(--muted); font-weight: 400; }
        .days {
            background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 14px 10px 10px;
            display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; align-items: end;
        }
        .day { text-align: center; }
        .day .count { font-size: 12.5px; font-weight: 700; color: var(--blue-dark); font-variant-numeric: tabular-nums; }
        .day .bar { height: 84px; display: flex; align-items: flex-end; justify-content: center; margin: 4px 0; }
        .day .bar div { width: 70%; max-width: 44px; min-height: 3px; background: var(--blue); border-radius: 6px 6px 2px 2px; }
        .day.today .bar div { background: var(--orange); }
        .day .dn { font-size: 11.5px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .day.today .dn { color: var(--orange); font-weight: 700; }
        .orgs { display: grid; grid-template-columns: 1fr; gap: 12px; }
        .org { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 14px; }
        .org.full { border-left: 5px solid var(--blue); }
        .org.none { opacity: .78; }
        .org-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
        .org-top h3 { margin: 0; font-size: 15.5px; line-height: 1.35; }
        .org-top .code { font-size: 12px; color: var(--muted); }
        .pct { font-size: 20px; font-weight: 700; color: var(--blue-dark); white-space: nowrap; font-variant-numeric: tabular-nums; }
        .track { height: 8px; background: var(--blue-soft); border-radius: 99px; margin: 10px 0 12px; overflow: hidden; }
        .track div { height: 100%; background: var(--blue); border-radius: 99px; }
        .org.full .track div { background: var(--orange); }
        .steps { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
        .step { border: 1px solid var(--line); border-radius: 10px; padding: 8px 10px; background: #fff; }
        .step.done { background: var(--blue-soft); border-color: #C9D6EC; }
        .step .sn { font-size: 12.5px; color: var(--muted); display: flex; align-items: center; gap: 6px; }
        .dot { width: 9px; height: 9px; border-radius: 50%; background: var(--wait); flex: none; }
        .step.done .dot { background: var(--ok); }
        .step .sv { font-size: 18px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .step .sw { font-size: 12px; color: var(--orange); font-weight: 600; }
        .empty { background: var(--orange-soft); border: 1px solid #F3CBA5; border-radius: 14px; padding: 16px; font-size: 14.5px; }
        footer { color: var(--muted); font-size: 12.5px; margin-top: 22px; border-top: 1px solid var(--line); padding-top: 12px; }
        @media (min-width: 600px) {
            h1 { font-size: 25px; }
            .tiles { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .steps { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .big b { font-size: 32px; }
            .day .bar { height: 110px; }
            .day .dn { font-size: 13px; }
        }
        @media (min-width: 900px) {
            main, header { padding-left: 28px; padding-right: 28px; }
            .tiles { grid-template-columns: repeat(6, minmax(0, 1fr)); }
            .orgs { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media print {
            header { background: #fff; color: var(--ink); }
            .nav { display: none; }
        }
    </style>
</head>
<body>
<header>
    <div class="wrap">
        <p class="eyebrow">የአሠልጣኞች ሥልጠና · የሙከራ ትግበራ ተቋማት</p>
        <h1>የሳምንቱ የሥራ ክንውን</h1>
        <div class="week">
            <div class="label">{{ $data['label'] }}</div>
            <nav class="nav" aria-label="ሳምንት መምረጫ">
                <a href="{{ $link($data['previous']) }}">‹ ያለፈው ሳምንት</a>
                @if ($data['next'])
                    <a href="{{ $link($data['next']) }}">ቀጣዩ ሳምንት ›</a>
                @else
                    <span aria-hidden="true">ቀጣዩ ሳምንት ›</span>
                @endif
            </nav>
        </div>
    </div>
</header>

<main>
    <div class="wrap">
        <div class="headline">
            <div class="big"><b>{{ number_format($weekTotal) }}</b><span>በዚህ ሳምንት የተመዘገቡ መረጃዎች</span></div>
            <div class="big"><b>{{ $data['started'] }} / {{ count($organizations) }}</b><span>ሥራ የጀመሩ ተቋማት</span></div>
            <div class="big"><b>{{ $data['complete'] }} / {{ count($organizations) }}</b><span>ስድስቱንም ያጠናቀቁ ተቋማት</span></div>
        </div>

        <h2>በዕቅዱ መሠረት የተመዘገበው <small>ጠቅላላ እና በዚህ ሳምንት የተጨመረ</small></h2>
        <div class="tiles">
            @foreach ($labels as $key => $label)
                <div class="tile">
                    <div class="name"><span>{{ $label }}</span><i>{{ $planDay[$key] }}</i></div>
                    <div class="num">{{ number_format($data['totals'][$key]['total']) }}</div>
                    @if ($data['totals'][$key]['week'] > 0)
                        <div class="plus">+{{ number_format($data['totals'][$key]['week']) }} በዚህ ሳምንት</div>
                    @else
                        <div class="plus none">በዚህ ሳምንት አልተጨመረም</div>
                    @endif
                </div>
            @endforeach
        </div>

        <h2>በየዕለቱ የተመዘገበ</h2>
        <div class="days" role="img" aria-label="በሳምንቱ በየዕለቱ የተመዘገቡ መረጃዎች ብዛት">
            @foreach ($data['days'] as $day)
                <div class="day {{ $day['isToday'] ? 'today' : '' }}">
                    <div class="count">{{ number_format($day['count']) }}</div>
                    <div class="bar"><div style="height: {{ (int) round($day['count'] / $maxDay * 100) }}%"></div></div>
                    <div class="dn">{{ $day['name'] }}</div>
                </div>
            @endforeach
        </div>

        <h2>በየተቋሙ <small>{{ count($organizations) }} ተቋማት</small></h2>
        @if ($organizations === [])
            <div class="empty">የሚታይ ተቋም የለም።</div>
        @else
            <div class="orgs">
                @foreach ($organizations as $organization)
                    <section class="org {{ $organization['percent'] === 100 ? 'full' : '' }} {{ $organization['done'] === 0 ? 'none' : '' }}">
                        <div class="org-top">
                            <div>
                                <h3>{{ $organization['name'] }}</h3>
                                <div class="code">{{ $organization['code'] }} · ከስድስቱ {{ $organization['done'] }} ተከናውኗል</div>
                            </div>
                            <div class="pct">{{ $organization['percent'] }}%</div>
                        </div>
                        <div class="track" aria-hidden="true"><div style="width: {{ $organization['percent'] }}%"></div></div>
                        <div class="steps">
                            @foreach ($labels as $key => $label)
                                @php($step = $organization['steps'][$key])
                                <div class="step {{ $step['total'] > 0 ? 'done' : '' }}">
                                    <div class="sn"><span class="dot"></span>{{ $label }}</div>
                                    <div class="sv">{{ number_format($step['total']) }}</div>
                                    @if ($step['week'] > 0)
                                        <div class="sw">+{{ number_format($step['week']) }} በዚህ ሳምንት</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif

        <footer>
            ይህ ገጽ ጊዜያዊ ነው፤ የሚያሳየው ብዛትን ብቻ ነው፣ የማንንም ሰው ስም ወይም መረጃ አያሳይም።
            አኃዞቹ የታደሱት በ{{ $data['generatedAt'] }} ሰዓት ነው@if ($data['isCurrent'])፤ ገጹ በየሁለት ደቂቃው ራሱን ያድሳል@endif።
        </footer>
    </div>
</main>
</body>
</html>
