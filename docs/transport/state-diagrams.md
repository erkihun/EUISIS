# Transport — State Diagrams

## Trip
```
planned ─assign─▶ assigned ─start─▶ boarding ─depart─▶ in_progress ─complete─▶ completed
  │  └────────────────────┴──────────────┴─────────────────┴─ cancel ─▶ cancelled
  │                                      └─────────────────┴─ fail ───▶ failed
  └ cancel ▶ cancelled
```
Boardable: `boarding`, `in_progress`. Terminal: `completed`, `cancelled`, `failed`.

## Invoice (provider claim)
```
draft ─submit─▶ submitted ─take under review─▶ under_review ─approve─▶ approved
  │                │  └─ return ─▶ returned ─submit─▶ submitted
  │                └─ withdraw ─▶ withdrawn            under_review ─dispute─▶ disputed ─decided─▶ under_review
  └ withdraw ▶ withdrawn                               under_review ─return─▶ returned
```

## Settlement (statement)
```
draft ─submit─▶ under_review ─approve (distinct approver)─▶ approved ─record payment─▶ paid
  │  ▲               │  └─ reject (reason) ─▶ rejected (evidence released)
  │  └─ return ──────┘
  └ cancel ▶ cancelled (evidence released)       adjustments/disputes: pending ─▶ approved | rejected (before approval)
```

## Eligibility grant and route assignment
```
active ─suspend (reason: transfer, status, admin)─▶ suspended ─reactivate─▶ active
  └ end / expire ─▶ ended | expired | cancelled
```

## Device
```
active ─suspend─▶ suspended ─reactivate─▶ active
  └ revoke (reason) ─▶ revoked (final)      rotate: new credential, old one dead at once
```

## Boarding transaction
```
accepted ─void (reason, not settled)─▶ voided    rejected (evidence, final)
sync: online | offline_pending ─sync─▶ offline_reconciled | offline_rejected
```
