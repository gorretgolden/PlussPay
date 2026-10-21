<style>
    .slip {
        --slip-ink: #1b241e;
        --slip-muted: #5d675f;
        --slip-rule: #1b241e;
        --slip-green: #14532d;
        --slip-paper: #fffdf6;
        --slip-line: #c9c4b4;
        position: relative;
        box-sizing: border-box;
        width: 100%;
        max-width: 210mm;
        margin: 0 auto;
        padding: clamp(16px, 3vw, 28px);
        overflow: hidden;
        border: 1px solid #d9d4c4;
        border-left: 6px solid var(--slip-green);
        border-radius: 4px;
        background: var(--slip-paper);
        color: var(--slip-ink);
        font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
        font-size: 12px;
        line-height: 1.35;
        box-shadow: 0 18px 45px rgb(20 37 28 / 12%);
    }
    .slip *,
    .slip *::before,
    .slip *::after { box-sizing: border-box; }
    .slip p { margin: 0; }

    .slip-head {
        display: grid;
        grid-template-columns: 1fr auto auto;
        align-items: center;
        gap: 14px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--slip-line);
    }
    .slip-pluspay { display: block; width: auto; height: 34px; }
    .slip-company { display: block; justify-self: end; width: auto; height: 58px; }
    .slip-ident { text-align: center; }
    .slip-pill {
        display: inline-block;
        padding: 4px 14px;
        border-radius: 2px;
        background: var(--slip-green);
        color: #fff;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .16em;
        text-transform: uppercase;
    }
    .slip-ident-line {
        display: flex;
        justify-content: center;
        gap: 5px;
        margin-top: 3px;
        font-size: 10.5px;
        white-space: nowrap;
    }
    .slip-ident-label {
        color: var(--slip-muted);
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
    }
    .slip-ident-value { font-weight: 700; font-variant-numeric: tabular-nums; }

    /* The route band reads from Uganda to China in one glance, the name of each country over its
       code, with the line between them carrying the direction. */
    .slip-route {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: clamp(8px, 2.4vw, 18px);
        margin: 13px 0 10px;
        padding: 7px 10px;
        border-top: 1px solid var(--slip-line);
        border-bottom: 1px solid var(--slip-line);
        background: #f5f8f2;
        font-size: inherit;
        font-weight: inherit;
    }
    .slip-route-end { display: inline-flex; align-items: center; gap: 7px; min-width: 0; }
    .slip-route-text { display: flex; flex-direction: column; min-width: 0; text-align: left; }
    .slip-route-kicker {
        color: var(--slip-muted);
        font-size: 8px;
        font-weight: 800;
        letter-spacing: .16em;
        line-height: 1.3;
        text-transform: uppercase;
    }
    .slip-route-name {
        overflow-wrap: anywhere;
        font-size: clamp(15px, 2.3vw, 21px);
        font-weight: 800;
        letter-spacing: .05em;
        line-height: 1.1;
        text-transform: uppercase;
        white-space: nowrap;
    }
    .slip-route-code {
        flex: none;
        padding: 1px 5px;
        border: 1px solid #b9cdb8;
        border-radius: 2px;
        background: #fff;
        color: var(--slip-green);
        font-size: 9.5px;
        font-weight: 800;
        letter-spacing: .08em;
    }
    .slip-route-thread { flex: 0 1 74px; min-width: 22px; height: 12px; color: var(--slip-green); }
    .slip-route-thread svg { display: block; width: 100%; height: 12px; }

    .slip-status {
        margin-top: 10px;
        padding: 6px 10px;
        border: 1px solid currentColor;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .04em;
        text-align: center;
    }
    .slip-status--pending { background: #fff7e0; color: #8a5a00; }
    .slip-status--cancelled { background: #fff1f1; color: #a01818; }

    .slip-parties {
        display: grid;
        grid-template-columns: 1fr 1fr;
        padding-bottom: 9px;
        border-bottom: 1px solid var(--slip-line);
    }
    .slip-party { min-width: 0; padding: 0; }
    .slip-party + .slip-party { padding-left: 16px; border-left: 1px solid var(--slip-line); }
    .slip-label {
        display: block;
        color: var(--slip-muted);
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .12em;
        text-transform: uppercase;
    }
    .slip-name {
        display: block;
        margin-top: 2px;
        overflow-wrap: anywhere;
        font-size: 15px;
        font-weight: 700;
        line-height: 1.25;
    }
    .slip-contact {
        display: block;
        color: var(--slip-muted);
        font-size: 11px;
        font-variant-numeric: tabular-nums;
    }

    .slip-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 3px 14px;
        padding-top: 7px;
        color: var(--slip-muted);
        font-size: 10.5px;
    }
    .slip-meta-item { display: inline-flex; flex-wrap: wrap; gap: 3px; }
    .slip-meta-value { color: var(--slip-ink); font-weight: 600; }

    .slip-well { position: relative; margin-top: 10px; }
    .slip-watermark {
        position: absolute;
        inset: 0;
        z-index: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 12px;
        overflow: hidden;
        color: var(--slip-green);
        font-size: clamp(18px, 4.5vw, 30px);
        font-weight: 800;
        letter-spacing: .18em;
        line-height: 1.2;
        text-align: center;
        text-transform: uppercase;
        opacity: .07;
        transform: rotate(-4deg);
        pointer-events: none;
    }

    .slip-table {
        position: relative;
        z-index: 1;
        width: 100%;
        border-collapse: collapse;
    }
    .slip-table th,
    .slip-table td {
        padding: 8px 9px;
        border: 1px solid var(--slip-rule);
        text-align: left;
        vertical-align: top;
    }
    .slip-table thead th {
        background: rgb(20 83 45 / 6%);
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
    }
    .slip-table tbody td { font-size: 11px; }
    .slip-col-particulars { width: 30%; }
    .slip-col-rate { width: 23%; }
    .slip-col-charge { width: 20%; }
    .slip-col-amount { width: 27%; }
    .slip-particulars strong { display: block; font-size: 12px; line-height: 1.3; }
    .slip-rate {
        display: block;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }
    .slip-rate + .slip-rate { margin-top: 2px; }
    .slip-rate-unit {
        display: block;
        color: var(--slip-muted);
        font-size: 9px;
        white-space: nowrap;
    }
    .slip-figure {
        display: block;
        overflow-wrap: anywhere;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
    }
    .slip-payout {
        display: block;
        margin-top: 2px;
        color: var(--slip-muted);
        font-size: 10.5px;
        font-variant-numeric: tabular-nums;
    }
    .slip-right { text-align: right !important; }
    .slip-table tfoot th,
    .slip-table tfoot td {
        background: rgb(20 83 45 / 6%);
        font-size: 11px;
        font-weight: 800;
    }
    .slip-total-label { letter-spacing: .06em; text-transform: uppercase; }
    .slip-total { font-variant-numeric: tabular-nums; }

    .slip-note {
        margin-top: 6px;
        color: var(--slip-muted);
        font-size: 9.5px;
        line-height: 1.4;
    }

    .slip-official { margin-top: 12px; border: 1px solid var(--slip-rule); }
    .slip-official-title {
        padding: 6px 9px;
        border-bottom: 1px solid var(--slip-rule);
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .12em;
        text-transform: uppercase;
    }
    .slip-signatures { display: grid; grid-template-columns: 1fr 1fr; }
    .slip-sign { min-width: 0; padding: 10px 9px 8px; }
    .slip-sign + .slip-sign { border-left: 1px solid var(--slip-rule); }
    .slip-sign-line { display: block; height: 18px; border-bottom: 1px dotted var(--slip-ink); }
    .slip-sign-name {
        display: block;
        margin-top: 3px;
        overflow-wrap: anywhere;
        font-size: 12px;
        font-weight: 700;
    }
    .slip-sign-caption { display: block; margin-top: 1px; color: var(--slip-muted); font-size: 9px; }

    .slip-reminder {
        margin-top: 8px;
        color: var(--slip-muted);
        font-size: 9px;
        text-align: center;
    }

    .slip-foot {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
        margin-top: 10px;
        padding-top: 8px;
        border-top: 2px solid var(--slip-rule);
        color: var(--slip-muted);
        font-size: 9.5px;
    }
    .slip-foot-title {
        display: block;
        margin-bottom: 2px;
        color: var(--slip-ink);
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .12em;
        text-transform: uppercase;
    }
    .slip-foot span { display: block; overflow-wrap: anywhere; }
    .slip-foot-address { text-align: right; }

    /* A5 landscape lays out at about 748px, so the phone breakpoint must stay below it or print stacks the slip. */
    @media (max-width: 640px) {
        .slip { padding: 16px 14px; border-left-width: 4px; }
        .slip-head { grid-template-columns: 1fr auto; gap: 10px; }
        .slip-company { height: 48px; }
        .slip-ident {
            order: 3;
            grid-column: 1 / -1;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 4px 10px;
            padding-top: 8px;
            border-top: 1px dashed #d5d0c0;
            text-align: left;
        }
        .slip-ident-line { justify-content: flex-start; margin-top: 0; }
        .slip-route { margin: 11px 0 9px; }
        .slip-parties { grid-template-columns: 1fr; }
        .slip-party + .slip-party {
            margin-top: 7px;
            padding-top: 7px;
            padding-left: 0;
            border-top: 1px solid var(--slip-line);
            border-left: 0;
        }
        .slip-table th,
        .slip-table td { padding: 6px 5px; }
        .slip-table thead th { font-size: 8px; letter-spacing: .04em; }
        .slip-table tbody td { font-size: 10px; }
        .slip-particulars strong { font-size: 10.5px; }
        .slip-rate { white-space: normal; }
        .slip-figure,
        .slip-payout,
        .slip-total { overflow-wrap: anywhere; }
        .slip-signatures { grid-template-columns: 1fr; }
        .slip-sign + .slip-sign { border-top: 1px solid var(--slip-rule); border-left: 0; }
        .slip-foot { grid-template-columns: 1fr; gap: 8px; }
        .slip-foot-address { text-align: left; }
    }

    /* On the counter phone the band keeps both countries on one line, so the direction is never
       lost to a wrapped second row. */
    @media (max-width: 380px) {
        .slip-route { gap: 7px; padding: 6px 7px; }
        .slip-route-end { gap: 5px; }
        .slip-route-thread { flex-basis: 18px; min-width: 12px; }
        .slip-route-name { font-size: 14px; letter-spacing: .03em; }
    }

    @media print {
        .slip-pluspay { height: 28px; }
        .slip-company { height: 44px; }
        .slip-head { padding-bottom: 6px; }
        .slip-pill { padding: 3px 12px; font-size: 10px; }
        .slip-ident-line { margin-top: 2px; }
        .slip-route { margin: 6px 0 5px; padding: 5px 8px; }
        .slip-route-name { font-size: 16px; }
        .slip-route-kicker { font-size: 7.5px; }
        .slip .slip-status { margin-top: 6px; padding: 4px 8px; font-size: 10px; }
        .slip-parties { padding-bottom: 5px; }
        .slip-name { font-size: 14px; }
        .slip-meta { padding-top: 5px; }
        .slip-well { margin-top: 8px; }
        .slip-table th,
        .slip-table td { padding: 4px 7px; }
        .slip .slip-note { margin-top: 4px; }
        .slip-official { margin-top: 7px; }
        .slip-sign { padding: 5px 8px 5px; }
        .slip-sign-line { height: 12px; }
        .slip .slip-reminder { margin-top: 4px; }
        .slip-foot { margin-top: 5px; padding-top: 5px; }
    }
</style>
